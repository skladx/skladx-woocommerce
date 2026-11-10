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
	 * GET /stock — one page of stock levels (`sku`, `on_hand`, `reserved`, `available`).
	 *
	 * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function stock( ?string $cursor = null, int $limit = 100 ): array {
		$response = $this->request( 'GET', 'stock', null, array_filter( array( 'limit' => $limit, 'cursor' => $cursor ) ) );

		return array(
			'data' => (array) ( $response['data'] ?? array() ),
			'meta' => (array) ( $response['meta'] ?? array() ),
		);
	}

	/**
	 * GET /pickup-points — search pickup points.
	 *
	 * @param array<string, scalar> $query carrier, country, q, near, radius_km, cod, limit, cursor.
	 * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function pickup_points( array $query ): array {
		$response = $this->request( 'GET', 'pickup-points', null, $query );

		return array(
			'data' => array_values( array_filter( (array) ( $response['data'] ?? array() ), 'is_array' ) ),
			'meta' => (array) ( $response['meta'] ?? array() ),
		);
	}

	/**
	 * GET /pickup-points/{carrier}/{id}.
	 *
	 * @return array<string, mixed>
	 */
	public function pickup_point( string $carrier, string $id ): array {
		return (array) ( $this->request( 'GET', 'pickup-points/' . rawurlencode( $carrier ) . '/' . rawurlencode( $id ) )['data'] ?? array() );
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
	 * GET /inbound-receipts — one page of inbound receipts, newest first.
	 *
	 * @param array<string, scalar> $query status, cursor, limit.
	 * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
	 */
	public function inbound_receipts( array $query = array() ): array {
		$response = $this->request( 'GET', 'inbound-receipts', null, array_filter( $query, static fn ( $value ): bool => '' !== (string) $value ) );

		return array(
			'data' => array_values( array_filter( (array) ( $response['data'] ?? array() ), 'is_array' ) ),
			'meta' => (array) ( $response['meta'] ?? array() ),
		);
	}

	/**
	 * GET /inbound-receipts/{id}.
	 *
	 * @return array<string, mixed>
	 */
	public function inbound_receipt( string $id ): array {
		return (array) ( $this->request( 'GET', 'inbound-receipts/' . rawurlencode( $id ) )['data'] ?? array() );
	}

	/**
	 * POST /inbound-receipts — creates a draft.
	 *
	 * @param array<string, mixed> $payload ReceiptPayload::build().
	 * @return array<string, mixed>
	 */
	public function create_inbound_receipt( array $payload, string $idempotency_key ): array {
		return (array) ( $this->request( 'POST', 'inbound-receipts', $payload, array(), $idempotency_key )['data'] ?? array() );
	}

	/**
	 * PUT /inbound-receipts/{id} — replaces header and items of a draft.
	 *
	 * @param array<string, mixed> $payload ReceiptPayload::build().
	 * @return array<string, mixed>
	 */
	public function update_inbound_receipt( string $id, array $payload ): array {
		return (array) ( $this->request( 'PUT', 'inbound-receipts/' . rawurlencode( $id ), $payload )['data'] ?? array() );
	}

	/**
	 * POST /inbound-receipts/{id}/announce — draft → announced.
	 *
	 * @return array<string, mixed>
	 */
	public function announce_inbound_receipt( string $id ): array {
		return (array) ( $this->request( 'POST', 'inbound-receipts/' . rawurlencode( $id ) . '/announce', array() )['data'] ?? array() );
	}

	/**
	 * POST /inbound-receipts/{id}/cancel.
	 *
	 * @return array<string, mixed>
	 */
	public function cancel_inbound_receipt( string $id, ?string $reason = null ): array {
		return (array) ( $this->request( 'POST', 'inbound-receipts/' . rawurlencode( $id ) . '/cancel', array_filter( array( 'reason' => $reason ) ) )['data'] ?? array() );
	}

	/**
	 * POST /inbound-receipts/{id}/attachments (multipart/form-data, field `file`).
	 *
	 * @param string $id        Receipt id.
	 * @param string $file_path Local file (an uploaded temp file).
	 * @param string $filename  File name sent to SkladX.
	 * @param string $mime_type Verified MIME type.
	 * @return array<string, mixed> Attachment or receipt, as SkladX returns it.
	 *
	 * @throws ApiException When the file cannot be read or SkladX rejects it.
	 */
	public function upload_inbound_attachment( string $id, string $file_path, string $filename, string $mime_type ): array {
		$contents = is_readable( $file_path ) ? file_get_contents( $file_path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.

		if ( false === $contents ) {
			throw new ApiException( __( 'The uploaded file cannot be read.', 'skladx-woocommerce' ), 0, 'file_unreadable' );
		}

		$boundary = 'skladx' . bin2hex( random_bytes( 12 ) );
		$filename = str_replace( array( '"', "\r", "\n" ), '', $filename );
		$body     = '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n"
			. 'Content-Type: ' . $mime_type . "\r\n\r\n"
			. $contents . "\r\n"
			. '--' . $boundary . "--\r\n";

		$response = $this->send( 'POST', 'inbound-receipts/' . rawurlencode( $id ) . '/attachments', array(), $body, 'multipart/form-data; boundary=' . $boundary );

		return (array) ( $response['decoded']['data'] ?? array() );
	}

	/**
	 * GET /inbound-receipts/{id}/label — the label to stick on the parcel.
	 *
	 * @param string $format pdf|zpl.
	 * @return array{body: string, content_type: string}
	 */
	public function inbound_receipt_label( string $id, string $format = 'pdf' ): array {
		$response = $this->send( 'GET', 'inbound-receipts/' . rawurlencode( $id ) . '/label', array( 'format' => $format ), null, null, null, 'zpl' === $format ? 'application/zpl, text/plain' : 'application/pdf' );

		return array(
			'body'         => $response['body'],
			'content_type' => $response['content_type'],
		);
	}

	/**
	 * Sends one JSON request.
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
		$encoded = null === $body ? null : (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return $this->send( $method, $path, $query, $encoded, null === $body ? null : 'application/json', $idempotency_key, 'application/json', $body )['decoded'];
	}

	/**
	 * Sends one request with a raw body.
	 *
	 * @param string                    $method          HTTP method.
	 * @param string                    $path            Path relative to the API base.
	 * @param array<string, mixed>      $query           Query string.
	 * @param string|null               $body            Raw body.
	 * @param string|null               $content_type    Content-Type of the body.
	 * @param string|null               $idempotency_key Idempotency-Key header.
	 * @param string                    $accept          Accept header.
	 * @param array<string, mixed>|null $log_body        Body for the debug log (raw bodies are not logged).
	 * @return array{status: int, body: string, content_type: string, decoded: array<string, mixed>}
	 *
	 * @throws ApiException On network errors and non-2xx responses.
	 */
	private function send( string $method, string $path, array $query, ?string $body, ?string $content_type, ?string $idempotency_key = null, string $accept = 'application/json', ?array $log_body = null ): array {
		if ( '' === $this->base_url || '' === $this->api_key ) {
			throw new ApiException( __( 'SkladX API URL or API key is not configured.', 'skladx-woocommerce' ), 0, 'not_configured' );
		}

		$url = $this->base_url . '/' . ltrim( $path, '/' );

		if ( array() !== $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Accept'        => $accept,
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
			$args['headers']['Content-Type'] = (string) $content_type;
			$args['body']                    = $body;
		}

		$started  = microtime( true );
		$response = wp_remote_request( $url, $args );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			Logger::error( sprintf( 'SkladX %s %s failed: %s', $method, $path, $response->get_error_message() ) );

			throw new ApiException( $response->get_error_message() );
		}

		$status        = (int) wp_remote_retrieve_response_code( $response );
		$raw           = (string) wp_remote_retrieve_body( $response );
		$response_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$decoded       = str_contains( $response_type, 'json' ) || '' === $response_type ? json_decode( $raw, true ) : null;
		$decoded       = is_array( $decoded ) ? $decoded : array();

		Logger::debug(
			sprintf( 'SkladX %s %s → %d (%d ms)', $method, $path, $status, $duration ),
			array(
				'request'  => null !== $log_body ? $log_body : ( null !== $body ? sprintf( '[%s, %d bytes]', (string) $content_type, strlen( $body ) ) : null ),
				'response' => array() !== $decoded ? $decoded : sprintf( '[%s, %d bytes]', $response_type, strlen( $raw ) ),
			)
		);

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'status'       => $status,
				'body'         => $raw,
				'content_type' => $response_type,
				'decoded'      => $decoded,
			);
		}

		if ( array() === $decoded ) {
			$decoded = json_decode( $raw, true );
			$decoded = is_array( $decoded ) ? $decoded : array();
		}

		$error = is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array();

		throw new ApiException(
			(string) ( $error['message'] ?? ( is_string( $decoded['message'] ?? null ) ? $decoded['message'] : sprintf( 'HTTP %d', $status ) ) ),
			$status,
			(string) ( $error['code'] ?? 'http_' . $status ),
			is_array( $error['details'] ?? null ) ? $error['details'] : array(),
			isset( $error['request_id'] ) ? (string) $error['request_id'] : null
		);
	}
}
