<?php
/**
 * Typed access to the plugin options.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Every option of the plugin is stored as its own `skladx_*` WordPress option
 * (that is how WooCommerce settings pages save fields). This class is the
 * only place that knows the option names and defaults.
 */
final class Settings {

	public const PREFIX = 'skladx_';

	/** Carrier codes accepted by SkladX. */
	public const CARRIERS = array( 'packeta', 'dpd_sk', 'gls_sk', 'sps', 'posta_sk', 'balikovo' );

	/** Carriers whose shipping methods deliver to a pickup point by default (others can be ticked in the settings). */
	public const PICKUP_CARRIERS = array( 'packeta' );

	/** Events the plugin subscribes to when it registers its webhook. */
	public const WEBHOOK_EVENTS = array(
		'order.status_changed',
		'order.shipped',
		'order.delivered',
		'order.delivery_failed',
		'order.cancelled',
		'order.returned',
		'shipment.tracking_updated',
		'stock.changed',
		'product.created',
		'product.updated',
	);

	/**
	 * Default value of every option (without the prefix).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_base_url'          => '',
			'api_key'               => '',
			'webhook_secret'        => '',
			'webhook_id'            => '',
			'export_statuses'       => array( 'wc-processing' ),
			'carrier_map'           => array(),
			'pickup_point_meta_key' => '_skladx_pickup_point_id',
			'pickup_picker'         => 'yes',
			'pickup_methods'        => null,
			'packeta_widget_key'    => '',
			'cod_gateways'          => array( 'cod' ),
			'product_sync'          => 'yes',
			'stock_sync'            => 'no',
			'status_map'            => array(
				'handed_over' => 'wc-skladx-shipped',
				'delivered'   => 'wc-completed',
				'cancelled'   => 'wc-cancelled',
			),
			'debug'                 => 'no',
			'events_cursor'         => '',
			'connection_info'       => array(),
		);
	}

	/**
	 * @param string $key Option name without the prefix.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$defaults = self::defaults();

		return get_option( self::PREFIX . $key, $defaults[ $key ] ?? null );
	}

	/**
	 * @param string $key   Option name without the prefix.
	 * @param mixed  $value New value.
	 */
	public static function set( string $key, $value ): void {
		update_option( self::PREFIX . $key, $value, false );
	}

	public static function api_base_url(): string {
		return untrailingslashit( trim( (string) self::get( 'api_base_url' ) ) );
	}

	public static function api_key(): string {
		return trim( (string) self::get( 'api_key' ) );
	}

	public static function webhook_secret(): string {
		return trim( (string) self::get( 'webhook_secret' ) );
	}

	public static function is_configured(): bool {
		return '' !== self::api_base_url() && '' !== self::api_key();
	}

	/**
	 * WooCommerce order statuses (without the `wc-` prefix) that export an order.
	 *
	 * @return list<string>
	 */
	public static function export_statuses(): array {
		return array_values(
			array_map(
				static fn ( $status ): string => str_starts_with( (string) $status, 'wc-' ) ? substr( (string) $status, 3 ) : (string) $status,
				(array) self::get( 'export_statuses' )
			)
		);
	}

	/**
	 * @return array<string, string> `method_id:instance_id` or `method_id` => carrier code.
	 */
	public static function carrier_map(): array {
		return array_filter( array_map( 'strval', (array) self::get( 'carrier_map' ) ) );
	}

	/**
	 * @return array<string, string> SkladX status => WooCommerce status (with `wc-` prefix).
	 */
	public static function status_map(): array {
		return array_filter( array_map( 'strval', (array) self::get( 'status_map' ) ) );
	}

	/**
	 * @return list<string>
	 */
	public static function cod_gateways(): array {
		return array_values( array_map( 'strval', (array) self::get( 'cod_gateways' ) ) );
	}

	public static function pickup_point_meta_key(): string {
		return trim( (string) self::get( 'pickup_point_meta_key' ) );
	}

	public static function pickup_picker_enabled(): bool {
		return 'yes' === self::get( 'pickup_picker' );
	}

	/**
	 * Shipping methods that deliver to a pickup point, with their carrier.
	 *
	 * Until the merchant saves the setting, every method mapped to a carrier
	 * in PICKUP_CARRIERS (filter `skladx_pickup_carriers`) counts.
	 *
	 * @return array<string, string> `method_id:instance_id` (or `method_id`) => carrier code.
	 */
	public static function pickup_methods(): array {
		$map     = self::carrier_map();
		$flagged = self::get( 'pickup_methods' );

		if ( ! is_array( $flagged ) ) {
			/**
			 * Carriers whose mapped shipping methods are pickup point methods by default.
			 *
			 * @param list<string> $carriers Carrier codes.
			 */
			$carriers = (array) apply_filters( 'skladx_pickup_carriers', self::PICKUP_CARRIERS );

			return array_filter( $map, static fn ( string $carrier ): bool => in_array( $carrier, $carriers, true ) );
		}

		return array_intersect_key( $map, array_flip( array_map( 'strval', $flagged ) ) );
	}

	/**
	 * Carriers that have at least one pickup point shipping method.
	 *
	 * @return list<string>
	 */
	public static function pickup_carriers(): array {
		return array_values( array_unique( array_values( self::pickup_methods() ) ) );
	}

	/**
	 * Public Packeta widget API key ('' = use the built-in picker).
	 */
	public static function packeta_widget_key(): string {
		return trim( (string) self::get( 'packeta_widget_key' ) );
	}

	public static function product_sync_enabled(): bool {
		return 'yes' === self::get( 'product_sync' );
	}

	public static function stock_sync_enabled(): bool {
		return 'yes' === self::get( 'stock_sync' );
	}

	public static function debug_enabled(): bool {
		return 'yes' === self::get( 'debug' );
	}

	/**
	 * Host of this site, used in idempotency keys and order metadata.
	 */
	public static function site_host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Public URL of the webhook endpoint registered in SkladX.
	 *
	 * Built from the `home` option like every WordPress REST URL. Behind a
	 * proxy, or when `home` is not the public address, use the
	 * `skladx_webhook_url` filter; the settings page warns about a URL that
	 * SkladX most likely cannot reach (see webhook_url_problem()).
	 */
	public static function webhook_url(): string {
		/**
		 * Filters the webhook URL the plugin registers in SkladX.
		 *
		 * @param string $url Default: rest_url( 'skladx/v1/webhook' ).
		 */
		return (string) apply_filters( 'skladx_webhook_url', rest_url( 'skladx/v1/webhook' ) );
	}

	/**
	 * Why SkladX probably cannot reach the webhook URL, or '' when it looks
	 * fine. Pure PHP — unit tested.
	 *
	 * @param string $url     Webhook URL.
	 * @param string $abspath WordPress root directory (ABSPATH).
	 * @return string ''|not_absolute|not_https|filesystem_path
	 */
	public static function webhook_url_problem( string $url, string $abspath ): string {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress in unit tests.

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return 'not_absolute';
		}

		$root = rtrim( str_replace( '\\', '/', $abspath ), '/' );

		// A `home` option saved with the server path (e.g. by an install run from the CLI).
		if ( '' !== $root && str_contains( (string) ( $parts['path'] ?? '' ), $root . '/' ) ) {
			return 'filesystem_path';
		}

		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return 'not_https';
		}

		return '';
	}
}
