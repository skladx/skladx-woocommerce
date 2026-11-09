<?php
/**
 * Error returned by the SkladX API (or a network failure).
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Api;

/**
 * Wraps the API error shape `{"error": {"code", "message", "request_id", "details"}}`.
 * Status 0 means the request never got a response (DNS, TLS, timeout…).
 */
class ApiException extends \RuntimeException {

	/**
	 * @param string               $message     Human readable message (from the API when available).
	 * @param int                  $status      HTTP status, 0 for network errors.
	 * @param string               $error_code  API error code, e.g. `validation_failed`.
	 * @param array<string, mixed> $details     API error details.
	 * @param string|null          $request_id  API request id for support.
	 */
	public function __construct(
		string $message,
		public readonly int $status = 0,
		public readonly string $error_code = 'network_error',
		public readonly array $details = array(),
		public readonly ?string $request_id = null
	) {
		parent::__construct( $message, $status );
	}

	/**
	 * Network errors, rate limiting and server errors are worth retrying.
	 */
	public function is_retryable(): bool {
		return 0 === $this->status || 429 === $this->status || $this->status >= 500;
	}

	/**
	 * Field validation errors from a 422 `validation_failed` response.
	 *
	 * @return array<string, list<string>>
	 */
	public function field_errors(): array {
		$fields = $this->details['fields'] ?? array();

		return is_array( $fields ) ? array_map( static fn ( $messages ): array => array_values( (array) $messages ), $fields ) : array();
	}

	/**
	 * One-line summary including field errors, for notes and notices.
	 */
	public function summary(): string {
		$parts = array();

		foreach ( $this->field_errors() as $field => $messages ) {
			$parts[] = $field . ': ' . implode( ' ', $messages );
		}

		$text = $this->getMessage() . ( array() !== $parts ? ' (' . implode( '; ', $parts ) . ')' : '' );

		return $this->status > 0 ? sprintf( '[%d %s] %s', $this->status, $this->error_code, $text ) : $text;
	}
}
