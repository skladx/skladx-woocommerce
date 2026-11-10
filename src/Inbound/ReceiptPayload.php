<?php
/**
 * Validates receipt form input and builds the SkladX inbound receipt payload.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

/**
 * Pure PHP (no WordPress) — unit tested.
 *
 * Input (from the receipt form, already JSON-decoded):
 * `{name, reference, expected_at, carrier, carrier_other, tracking_number,
 * is_blind, note, warehouse_code, items: [{sku, quantity, batch, expires_at}]}`.
 *
 * Output is the body of `POST /inbound-receipts` and `PUT /inbound-receipts/{id}`.
 * Empty optional fields are sent as null so a PUT clears them. Lines with
 * the same SKU, batch and expiry are merged (quantities added up); the same
 * product with another batch or expiry stays a separate line.
 */
final class ReceiptPayload {

	/** Carrier value for goods brought to the warehouse in person. */
	public const CARRIER_PERSONAL = 'personal';

	/** Carrier value for a carrier that is not in the list (name in `carrier_other`). */
	public const CARRIER_OTHER = 'other';

	public const MAX_NAME      = 191;
	public const MAX_REFERENCE = 64;
	public const MAX_TRACKING  = 64;
	public const MAX_CARRIER   = 64;
	public const MAX_NOTE      = 1000;
	public const MAX_SKU       = 64;
	public const MAX_BATCH     = 64;
	public const MAX_QUANTITY  = 1000000;
	public const MAX_ITEMS     = 1000;

	/**
	 * @param array<string, mixed> $input    Form input.
	 * @param list<string>         $carriers Carrier codes offered in the select (Settings::CARRIERS).
	 * @return array<string, mixed>
	 *
	 * @throws ValidationException When anything is invalid (all errors at once).
	 */
	public static function build( array $input, array $carriers ): array {
		$errors = array();

		$name = self::text( $input['name'] ?? '' );

		if ( '' === $name ) {
			$errors[] = ValidationException::error( 'name', ValidationException::REQUIRED );
		} elseif ( self::length( $name ) > self::MAX_NAME ) {
			$errors[] = ValidationException::error( 'name', ValidationException::TOO_LONG, null, '', self::MAX_NAME );
		}

		$expected_raw = self::text( $input['expected_at'] ?? '' );
		$expected_at  = self::normalize_date( $expected_raw );

		if ( '' === $expected_raw ) {
			$errors[] = ValidationException::error( 'expected_at', ValidationException::REQUIRED );
		} elseif ( null === $expected_at ) {
			$errors[] = ValidationException::error( 'expected_at', ValidationException::INVALID_DATE, null, $expected_raw );
		}

		$carrier = self::text( $input['carrier'] ?? '' );

		if ( self::CARRIER_OTHER === $carrier ) {
			$carrier = self::text( $input['carrier_other'] ?? '' );

			if ( '' === $carrier ) {
				$errors[] = ValidationException::error( 'carrier_other', ValidationException::REQUIRED );
			} elseif ( self::length( $carrier ) > self::MAX_CARRIER ) {
				$errors[] = ValidationException::error( 'carrier_other', ValidationException::TOO_LONG, null, '', self::MAX_CARRIER );
			}
		} elseif ( '' !== $carrier && self::CARRIER_PERSONAL !== $carrier && ! in_array( $carrier, $carriers, true ) ) {
			$errors[] = ValidationException::error( 'carrier', ValidationException::INVALID_CARRIER, null, $carrier );
		}

		$tracking = self::CARRIER_PERSONAL === $carrier ? '' : self::text( $input['tracking_number'] ?? '' );

		if ( self::length( $tracking ) > self::MAX_TRACKING ) {
			$errors[] = ValidationException::error( 'tracking_number', ValidationException::TOO_LONG, null, '', self::MAX_TRACKING );
		} elseif ( '' !== $tracking && ! preg_match( '~^[\p{L}\p{N}][\p{L}\p{N} ./_-]*$~u', $tracking ) ) {
			$errors[] = ValidationException::error( 'tracking_number', ValidationException::INVALID_TRACKING, null, $tracking );
		}

		$reference = self::text( $input['reference'] ?? '' );

		if ( self::length( $reference ) > self::MAX_REFERENCE ) {
			$errors[] = ValidationException::error( 'reference', ValidationException::TOO_LONG, null, '', self::MAX_REFERENCE );
		}

		$note = trim( str_replace( "\r\n", "\n", (string) ( is_scalar( $input['note'] ?? null ) ? $input['note'] : '' ) ) );

		if ( self::length( $note ) > self::MAX_NOTE ) {
			$errors[] = ValidationException::error( 'note', ValidationException::TOO_LONG, null, '', self::MAX_NOTE );
		}

		$items     = array();
		$raw_items = is_array( $input['items'] ?? null ) ? array_values( $input['items'] ) : array();

		if ( count( $raw_items ) > self::MAX_ITEMS ) {
			$errors[] = ValidationException::error( 'items', ValidationException::TOO_MANY_LINES, null, '', self::MAX_ITEMS );
			$raw_items = array();
		}

		foreach ( $raw_items as $index => $raw ) {
			$item = self::item( is_array( $raw ) ? $raw : array(), $index, $errors );

			if ( null === $item ) {
				continue;
			}

			$key = $item['sku'] . "\0" . ( $item['batch'] ?? '' ) . "\0" . ( $item['expires_at'] ?? '' );

			if ( isset( $items[ $key ] ) ) {
				$items[ $key ]['quantity'] += $item['quantity'];
			} else {
				$items[ $key ] = $item;
			}
		}

		foreach ( $items as $item ) {
			if ( $item['quantity'] > self::MAX_QUANTITY ) {
				$errors[] = ValidationException::error( 'items', ValidationException::QUANTITY_TOO_LARGE, null, $item['sku'], self::MAX_QUANTITY );
			}
		}

		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}

		$warehouse = self::text( $input['warehouse_code'] ?? '' );

		return array(
			'name'            => $name,
			'reference'       => self::nullable( $reference ),
			'expected_at'     => $expected_at,
			'carrier'         => self::nullable( $carrier ),
			'tracking_number' => self::nullable( $tracking ),
			'is_blind'        => self::truthy( $input['is_blind'] ?? false ),
			'note'            => self::nullable( $note ),
			'warehouse_code'  => self::nullable( $warehouse ),
			'items'           => array_values( $items ),
		);
	}

	/**
	 * A receipt can only be announced with at least one item (unless it is
	 * a blind receipt, where the warehouse counts whatever arrives).
	 *
	 * @param array<string, mixed> $receipt Normalised receipt (Receipt::normalize()).
	 *
	 * @throws ValidationException When there is nothing to announce.
	 */
	public static function assert_announceable( array $receipt ): void {
		if ( empty( $receipt['is_blind'] ) && array() === (array) ( $receipt['items'] ?? array() ) ) {
			throw new ValidationException( array( ValidationException::error( 'items', ValidationException::NO_ITEMS ) ) );
		}
	}

	/**
	 * Validates one item; adds errors (with the 1-based line number) to $errors.
	 *
	 * @param array<string, mixed>                                                                     $raw    Item input.
	 * @param int                                                                                      $index  0-based index.
	 * @param list<array{field: string, reason: string, line: int|null, value: string, max: int|null}> $errors Errors.
	 * @return array{sku: string, quantity: int, batch: string|null, expires_at: string|null}|null
	 */
	private static function item( array $raw, int $index, array &$errors ): ?array {
		$line   = $index + 1;
		$prefix = 'items.' . $index . '.';
		$before = count( $errors );

		$sku = self::text( $raw['sku'] ?? '' );

		if ( '' === $sku ) {
			$errors[] = ValidationException::error( $prefix . 'sku', ValidationException::REQUIRED, $line );
		} elseif ( self::length( $sku ) > self::MAX_SKU ) {
			$errors[] = ValidationException::error( $prefix . 'sku', ValidationException::TOO_LONG, $line, '', self::MAX_SKU );
		}

		$quantity = self::parse_quantity( $raw['quantity'] ?? '', $reason );

		if ( null === $quantity ) {
			$errors[] = ValidationException::error( $prefix . 'quantity', $reason, $line, self::text( $raw['quantity'] ?? '' ), self::MAX_QUANTITY );
		}

		$batch = self::text( $raw['batch'] ?? '' );

		if ( self::length( $batch ) > self::MAX_BATCH ) {
			$errors[] = ValidationException::error( $prefix . 'batch', ValidationException::TOO_LONG, $line, '', self::MAX_BATCH );
		}

		$expires_raw = self::text( $raw['expires_at'] ?? '' );
		$expires_at  = '' === $expires_raw ? null : self::normalize_date( $expires_raw );

		if ( '' !== $expires_raw && null === $expires_at ) {
			$errors[] = ValidationException::error( $prefix . 'expires_at', ValidationException::INVALID_DATE, $line, $expires_raw );
		}

		if ( count( $errors ) > $before ) {
			return null;
		}

		return array(
			'sku'        => $sku,
			'quantity'   => (int) $quantity,
			'batch'      => self::nullable( $batch ),
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Parses a quantity in pieces: a whole number 1…MAX_QUANTITY.
	 *
	 * @param mixed       $value  Raw value.
	 * @param string|null $reason Set to the ValidationException reason when invalid.
	 */
	public static function parse_quantity( $value, ?string &$reason = null ): ?int {
		$reason = null;
		$text   = is_int( $value ) ? (string) $value : self::text( $value );

		if ( is_float( $value ) && floor( $value ) === $value ) {
			$text = (string) (int) $value;
		}

		if ( '' === $text ) {
			$reason = ValidationException::REQUIRED;

			return null;
		}

		if ( ! preg_match( '/^[+-]?\d+$/', $text ) ) {
			$reason = ValidationException::INVALID_QUANTITY;

			return null;
		}

		$quantity = (int) $text;

		if ( $quantity <= 0 ) {
			$reason = ValidationException::QUANTITY_POSITIVE;

			return null;
		}

		if ( $quantity > self::MAX_QUANTITY || strlen( ltrim( $text, '+0' ) ) > 7 ) {
			$reason = ValidationException::QUANTITY_TOO_LARGE;

			return null;
		}

		return $quantity;
	}

	/**
	 * Normalises a date to `YYYY-MM-DD`.
	 *
	 * Accepts `YYYY-MM-DD`, `DD.MM.YYYY` / `D. M. YYYY`, `DD/MM/YYYY`, and
	 * month precision as printed on packaging — `MM/YYYY`, `MM.YYYY`,
	 * `YYYY-MM` — which means the last day of that month.
	 *
	 * @return string|null Null when the value is not a real calendar date.
	 */
	public static function normalize_date( string $value ): ?string {
		$value = preg_replace( '/\s+/', '', trim( $value ) );

		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m ) ) {
			[ , $year, $month, $day ] = $m;
		} elseif ( preg_match( '~^(\d{1,2})[./](\d{1,2})[./](\d{4})$~', $value, $m ) ) {
			[ , $day, $month, $year ] = $m;
		} elseif ( preg_match( '~^(\d{1,2})[./](\d{4})$~', $value, $m ) ) {
			[ , $month, $year ] = $m;
			$day                = 'last';
		} elseif ( preg_match( '/^(\d{4})-(\d{1,2})$/', $value, $m ) ) {
			[ , $year, $month ] = $m;
			$day                = 'last';
		} else {
			return null;
		}

		$year  = (int) $year;
		$month = (int) $month;

		if ( $month < 1 || $month > 12 || $year < 2000 || $year > 2200 ) {
			return null;
		}

		$day = 'last' === $day ? (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) ) : (int) $day;

		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value ) ?? '' ) : '';
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	private static function nullable( string $value ): ?string {
		return '' === $value ? null : $value;
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private static function truthy( $value ): bool {
		return true === $value || 1 === $value || in_array( is_scalar( $value ) ? strtolower( (string) $value ) : '', array( '1', 'true', 'yes', 'on' ), true );
	}
}
