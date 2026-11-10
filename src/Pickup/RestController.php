<?php
/**
 * Public REST proxy for the pickup point picker.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Pickup;

use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /wp-json/skladx/v1/pickup-points?carrier=packeta&q=…&near=lat,lng&cod=1&cursor=…&country=SK`
 *
 * The browser never talks to SkladX (the API key stays on the server). Only
 * carriers of pickup point shipping methods are allowed, answers are cached
 * per query, and calls that miss the cache are rate limited per visitor.
 */
final class RestController {

	public const ROUTE = '/pickup-points';

	/** SkladX calls (cache misses) per visitor and minute. */
	private const RATE_LIMIT = 30;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'skladx/v1',
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'search' ),
				// Public data needed by checkout visitors; restricted and rate limited in search().
				'permission_callback' => '__return_true',
				'args'                => array(
					'carrier' => array(
						'type'     => 'string',
						'required' => true,
					),
					'q'       => array( 'type' => 'string' ),
					'near'    => array( 'type' => 'string' ),
					'cod'     => array( 'type' => array( 'boolean', 'string', 'integer' ) ),
					'cursor'  => array( 'type' => 'string' ),
					'country' => array( 'type' => 'string' ),
				),
			)
		);
	}

	public static function service(): PickupPointService {
		return new PickupPointService(
			static fn ( array $query ): array => \SkladX\WooCommerce\Api\Client::from_settings()->pickup_points( $query ),
			static fn ( string $carrier, string $id ): array => \SkladX\WooCommerce\Api\Client::from_settings()->pickup_point( $carrier, $id ),
			new TransientCache()
		);
	}

	public static function search( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Settings::is_configured() || ! Settings::pickup_picker_enabled() ) {
			return self::error( 'skladx_pickup_disabled', __( 'Pickup point search is not available.', 'skladx-woocommerce' ), 404 );
		}

		try {
			$query = PickupPoint::sanitize_query( $request->get_params(), Settings::pickup_carriers(), (string) WC()->countries->get_base_country() );
		} catch ( \InvalidArgumentException $exception ) {
			return self::error( 'skladx_' . $exception->getMessage(), __( 'Invalid pickup point search.', 'skladx-woocommerce' ), 400 );
		}

		/**
		 * Filters the SkladX pickup point query sent by the checkout picker.
		 *
		 * @param array<string, scalar> $query Query for GET /pickup-points.
		 */
		$query   = (array) apply_filters( 'skladx_pickup_points_query', $query );
		$service = self::service();

		if ( null === ( new TransientCache() )->get( PickupPoint::cache_key( $query ) ) && ! self::within_rate_limit() ) {
			return self::error( 'skladx_rate_limited', __( 'Too many searches, please wait a moment.', 'skladx-woocommerce' ), 429 );
		}

		try {
			$result = $service->search( $query );
		} catch ( ApiException $exception ) {
			Logger::error( 'Pickup point search failed: ' . $exception->summary() );

			return self::error( 'skladx_unavailable', __( 'Pickup points cannot be loaded right now. Please try again in a moment.', 'skladx-woocommerce' ), 502 );
		}

		$labels   = Checkout::day_labels();
		$response = new \WP_REST_Response(
			array(
				'points'      => array_map( static fn ( array $point ): array => PickupPoint::for_browser( $point, $labels ), $result['points'] ),
				'next_cursor' => $result['next_cursor'],
				'has_more'    => $result['has_more'],
			)
		);
		$response->header( 'Cache-Control', 'private, max-age=300' );

		return $response;
	}

	/**
	 * Fixed one-minute window per visitor (IP hash).
	 */
	private static function within_rate_limit(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'skladx_pp_rl_' . md5( $ip . wp_salt( 'nonce' ) ) . '_' . (int) floor( time() / 60 );

		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, 120 );

		return true;
	}

	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
