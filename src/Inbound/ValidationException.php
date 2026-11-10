<?php
/**
 * Invalid receipt input (header fields, items or pasted lines).
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

/**
 * Thrown before any API call. Every error has a machine `reason`, the form
 * `field` it belongs to (`name`, `items.2.quantity`…) and, for items and
 * pasted lines, the 1-based `line` number. `localized()` turns them into
 * translated messages for the shop manager; the exception message stays
 * English (logs, unit tests).
 */
class ValidationException extends \RuntimeException {

	public const REQUIRED           = 'required';
	public const TOO_LONG           = 'too_long';
	public const INVALID_DATE       = 'invalid_date';
	public const INVALID_CARRIER    = 'invalid_carrier';
	public const INVALID_TRACKING   = 'invalid_tracking';
	public const INVALID_QUANTITY   = 'invalid_quantity';
	public const QUANTITY_POSITIVE  = 'quantity_positive';
	public const QUANTITY_TOO_LARGE = 'quantity_too_large';
	public const TOO_MANY_COLUMNS   = 'too_many_columns';
	public const TOO_MANY_LINES     = 'too_many_lines';
	public const NO_ITEMS           = 'no_items';
	public const UNKNOWN_PRODUCT    = 'unknown_product';

	/**
	 * @param list<array{field: string, reason: string, line: int|null, value: string, max: int|null}> $errors Errors.
	 */
	public function __construct( public readonly array $errors ) {
		parent::__construct(
			implode(
				'; ',
				array_map(
					static fn ( array $error ): string => ( null !== $error['line'] ? 'line ' . $error['line'] . ': ' : '' ) . $error['field'] . ' ' . $error['reason'],
					$errors
				)
			)
		);
	}

	/**
	 * One error entry.
	 *
	 * @return array{field: string, reason: string, line: int|null, value: string, max: int|null}
	 */
	public static function error( string $field, string $reason, ?int $line = null, string $value = '', ?int $max = null ): array {
		return array(
			'field'  => $field,
			'reason' => $reason,
			'line'   => $line,
			'value'  => $value,
			'max'    => $max,
		);
	}

	/**
	 * Translated messages, one per error, keyed like the errors.
	 *
	 * @return list<array{field: string, line: int|null, message: string}>
	 */
	public function localized(): array {
		return array_map(
			static fn ( array $error ): array => array(
				'field'   => $error['field'],
				'line'    => $error['line'],
				'message' => self::message( $error ),
			),
			$this->errors
		);
	}

	/**
	 * @param array{field: string, reason: string, line: int|null, value: string, max: int|null} $error Error.
	 */
	public static function message( array $error ): string {
		$text = self::reason_text( $error );

		if ( null === $error['line'] ) {
			return $text;
		}

		/* translators: 1: line number, 2: error message */
		return sprintf( self::t( 'Line %1$d: %2$s' ), $error['line'], $text );
	}

	/**
	 * @param array{field: string, reason: string, line: int|null, value: string, max: int|null} $error Error.
	 */
	private static function reason_text( array $error ): string {
		$field = self::field_label( $error['field'] );

		switch ( $error['reason'] ) {
			case self::REQUIRED:
				/* translators: %s: field name */
				return sprintf( self::t( '%s is required.' ), $field );
			case self::TOO_LONG:
				/* translators: 1: field name, 2: maximum number of characters */
				return sprintf( self::t( '%1$s can have at most %2$d characters.' ), $field, (int) $error['max'] );
			case self::INVALID_DATE:
				/* translators: 1: field name, 2: entered value */
				return sprintf( self::t( '%1$s "%2$s" is not a valid date (use YYYY-MM-DD or DD.MM.YYYY).' ), $field, $error['value'] );
			case self::INVALID_CARRIER:
				return self::t( 'Choose a carrier from the list.' );
			case self::INVALID_TRACKING:
				return self::t( 'The tracking number may only contain letters, digits, spaces, dashes and slashes.' );
			case self::INVALID_QUANTITY:
				/* translators: %s: entered value */
				return sprintf( self::t( 'Quantity "%s" is not a whole number.' ), $error['value'] );
			case self::QUANTITY_POSITIVE:
				return self::t( 'Quantity must be greater than 0.' );
			case self::QUANTITY_TOO_LARGE:
				/* translators: %d: maximum quantity */
				return sprintf( self::t( 'Quantity can be at most %d.' ), (int) $error['max'] );
			case self::TOO_MANY_COLUMNS:
				return self::t( 'Too many columns. Use: code;quantity[;batch;expiry].' );
			case self::TOO_MANY_LINES:
				/* translators: %d: maximum number of lines */
				return sprintf( self::t( 'At most %d lines can be added at once.' ), (int) $error['max'] );
			case self::NO_ITEMS:
				return self::t( 'Add at least one item.' );
			case self::UNKNOWN_PRODUCT:
				/* translators: %s: SKU or EAN */
				return sprintf( self::t( 'No product with SKU or EAN "%s" (products without a SKU cannot be received).' ), $error['value'] );
			default:
				return $error['field'] . ' ' . $error['reason'];
		}
	}

	private static function field_label( string $field ): string {
		$key = preg_replace( '/^items\.\d+\./', '', $field );

		$labels = array(
			'name'            => 'Name',
			'expected_at'     => 'Expected delivery date',
			'carrier'         => 'Carrier',
			'carrier_other'   => 'Carrier name',
			'tracking_number' => 'Tracking number',
			'reference'       => 'Reference',
			'note'            => 'Note',
			'warehouse_code'  => 'Warehouse',
			'sku'             => 'Product',
			'code'            => 'SKU / EAN',
			'quantity'        => 'Quantity',
			'batch'           => 'Batch',
			'expires_at'      => 'Expiry',
		);

		return isset( $labels[ $key ] ) ? self::field_t( $labels[ $key ] ) : (string) $key;
	}

	/**
	 * Translates a field label (literal strings so `wp i18n make-pot` finds them).
	 */
	private static function field_t( string $label ): string {
		if ( ! function_exists( '__' ) ) {
			return $label;
		}

		switch ( $label ) {
			case 'Name':
				return __( 'Name', 'skladx-woocommerce' );
			case 'Expected delivery date':
				return __( 'Expected delivery date', 'skladx-woocommerce' );
			case 'Carrier':
				return __( 'Carrier', 'skladx-woocommerce' );
			case 'Carrier name':
				return __( 'Carrier name', 'skladx-woocommerce' );
			case 'Tracking number':
				return __( 'Tracking number', 'skladx-woocommerce' );
			case 'Reference':
				return __( 'Reference', 'skladx-woocommerce' );
			case 'Note':
				return __( 'Note', 'skladx-woocommerce' );
			case 'Warehouse':
				return __( 'Warehouse', 'skladx-woocommerce' );
			case 'Product':
				return __( 'Product', 'skladx-woocommerce' );
			case 'SKU / EAN':
				return __( 'SKU / EAN', 'skladx-woocommerce' );
			case 'Quantity':
				return __( 'Quantity', 'skladx-woocommerce' );
			case 'Batch':
				return __( 'Batch', 'skladx-woocommerce' );
			case 'Expiry':
				return __( 'Expiry', 'skladx-woocommerce' );
			default:
				return $label;
		}
	}

	/**
	 * Translates a message (English when WordPress is not loaded, e.g. in unit tests).
	 */
	private static function t( string $text ): string {
		if ( ! function_exists( '__' ) ) {
			return $text;
		}

		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- every literal is listed in strings() for make-pot.
		return __( $text, 'skladx-woocommerce' );
	}

	/**
	 * Never called: lists the messages translated through t() so that
	 * `wp i18n make-pot` extracts them.
	 *
	 * @codeCoverageIgnore
	 */
	private static function strings(): void {
		/* translators: 1: line number, 2: error message */
		__( 'Line %1$d: %2$s', 'skladx-woocommerce' );
		/* translators: %s: field name */
		__( '%s is required.', 'skladx-woocommerce' );
		/* translators: 1: field name, 2: maximum number of characters */
		__( '%1$s can have at most %2$d characters.', 'skladx-woocommerce' );
		/* translators: 1: field name, 2: entered value */
		__( '%1$s "%2$s" is not a valid date (use YYYY-MM-DD or DD.MM.YYYY).', 'skladx-woocommerce' );
		__( 'Choose a carrier from the list.', 'skladx-woocommerce' );
		__( 'The tracking number may only contain letters, digits, spaces, dashes and slashes.', 'skladx-woocommerce' );
		/* translators: %s: entered value */
		__( 'Quantity "%s" is not a whole number.', 'skladx-woocommerce' );
		__( 'Quantity must be greater than 0.', 'skladx-woocommerce' );
		/* translators: %d: maximum quantity */
		__( 'Quantity can be at most %d.', 'skladx-woocommerce' );
		__( 'Too many columns. Use: code;quantity[;batch;expiry].', 'skladx-woocommerce' );
		/* translators: %d: maximum number of lines */
		__( 'At most %d lines can be added at once.', 'skladx-woocommerce' );
		__( 'Add at least one item.', 'skladx-woocommerce' );
		/* translators: %s: SKU or EAN */
		__( 'No product with SKU or EAN "%s" (products without a SKU cannot be received).', 'skladx-woocommerce' );
	}
}
