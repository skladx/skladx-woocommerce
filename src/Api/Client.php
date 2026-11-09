<?php
/**
 * SkladX REST API v1 client.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Api;

use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Thin client over the WordPress HTTP API. Successful calls return the decoded
 * JSON body (`{data, meta}`); anything else throws an ApiException.
 */
final class Client {

	private const TIMEOUT = 20;

	public function __construct(
		private readonly string $base_url,
		private readonly string $api_key
	) {}

	public static function from_settings(): self {
		return new self( Settings::api_base_url(), Settings::api_key() );
	}

	/**
	 * GET /ping — which client, environment and scopes the key works with.
	 *
	 * @return array<string, mixed>
	 */
	public function ping(): array {
		return $this->request( 'GET', 'ping' )['data'] ?? array();
	}

	/**
	 * @param array<string, mixed> $payload Order payload.
	 * @return array<string, mixed>
	 */
	public function create_order( array $payload, string $idempotency_key ): array {
		return $this->request( 'POST', 'orders', $payload, array(), $idempotency_key )['data'] ?? array();
	}

	/**
	 * @param string $id `ord_…` id or the order reference.
	 * @return array<string, mixed>
	 */
	public function get_order( string $id ): array {
		return $this->request( 'GET', 'orders/' . rawurlencode( $id ) )['data'] ?? array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function cancel_order( string $id, ?string $reason = null ): array {
		return $this->request( 'POST', 'orders/' . rawurlencode( $id ) . '/cancel', array_filter( array( 'reason' => $reason ) ) )['data'] ?? array();
	}

	/**
	 * Sandbox only: move an order to a status.
	 *
	 * @return array<string, mixed>
	 */
	public function simulate_status( string $id, string $status, ?string $tracking_number = null ): array {
		return $this->request( 'POST', 'sandbox/orders/' . rawurlencode( $id ) . '/status', array_filter( array( 'status' => $status, 'tracking_number' => $tracking_number ) ) )['data'] ?? array();
	}

	/**
	 * Create or update a product by SKU.
	 *
	 * @param array<string, mixed> $payload Product payload.
	 * @return array<string, mixed>
	 */
	public function upsert_product( array $payload ): array {
		return $this->request( 'POST', 'products', $payload )['data'] ?? array();
	}

	/**
	 * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function events( ?string $after = null, int $limit = 100, ?string $cursor = null ): array {
		$response = $this->request( 'GET', 'events', null, array_filter( array( 'after' => $after, 'limit' => $limit, 'cursor' => $cursor ) ) );

		return array(
			'data' => (array) ( $response['data'] ?? array() ),
			'meta' => (array) ( $response['meta'] ?? array() ),
		);
	}

	/**
	 * @param list<string> $events Event types.
	 * @return array<string, mixed> Includes the one-time `secret`.
	 */
	public function create_webhook( string $url, array $events, string $description ): array {
		return $this->request( 'POST', 'webhooks', array( 'url' => $url, 'events' => $events, 'description' => $description ) )['data'] ?? array();
	}

	public function delete_webhook( string $id ): void {
		$this->request( 'DELETE', 'webhooks/' . rawurlencode( $id ) );
	}

	/**
	 * Sends one request.
	 *
	 * @param string                    $method          HTTP method.
	 * @param string                    $path            Path relative to the API base, e.g. `orders`.
	 * @param array<string, mixed>|null $body            JSON body.
	 * @param array<string, mixed>      $query           Query string.
	 * @param string|null               $idempotency_key Idempotency-Key header.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException On network errors and non-2xx responses.
	 */
	public function request( string $method, string $path, ?array $body = null, array $query = array(), ?string $idempotency_key = null ): array {
		if ( '' === $this->base_url || '' === $this->api_key ) {
			throw new ApiException( __( 'SkladX API URL or API key is not configured.', 'skladx-woocommerce' ), 0, 'not_configured' );
		}

		$url = $this->base_url . '/' . ltrim( $path, '/' );

		if ( array() !== $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Accept'        => 'application/json',
			'User-Agent'    => sprintf( 'SkladX-WooCommerce/%s (WordPress %s; WooCommerce %s)', SKLADX_WC_VERSION, get_bloginfo( 'version' ), defined( 'WC_VERSION' ) ? WC_VERSION : '?' ),
		);

		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$started  = microtime( true );
		$response = wp_remote_request( $url, $args );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'SkladX %s %s failed: %s', $method, $path, $response->get_error_message() ) );

			throw new ApiException( $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		Logger::debug(
			sprintf( 'SkladX %s %s → %d (%d ms)', $method, $path, $status, $duration ),
			array(
				'request'  => $body,
				'response' => $decoded,
			)
		);

		if ( $status >= 200 && $status < 300 ) {
			return $decoded;
		}

		$error = is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array();

		throw new ApiException(
			(string) ( $error['message'] ?? sprintf( 'HTTP %d', $status ) ),
			$status,
			(string) ( $error['code'] ?? 'http_' . $status ),
			is_array( $error['details'] ?? null ) ? $error['details'] : array(),
			isset( $error['request_id'] ) ? (string) $error['request_id'] : null
		);
	}
}
