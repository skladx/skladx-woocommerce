<?php
/**
 * A WooCommerce object cannot be mapped to a SkladX payload.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

/**
 * Thrown before any API call, e.g. when an order line has no SKU.
 *
 * The message stays English (logs, unit tests); `localized_message()` is what
 * the shop admin sees in order notes and notices.
 */
class MappingException extends \RuntimeException {

	public const NO_SKU         = 'no_sku';
	public const NO_ITEMS       = 'no_items';
	public const NO_ADDRESS     = 'no_address';
	public const PRODUCT_NO_SKU = 'product_no_sku';

	/**
	 * @param string $message English message.
	 * @param string $reason  One of the constants ('' when unknown).
	 * @param string $subject What the reason is about (e.g. the order line name).
	 */
	public function __construct( string $message, public readonly string $reason = '', public readonly string $subject = '' ) {
		parent::__construct( $message );
	}

	/**
	 * Translated message with a hint how to fix the problem.
	 */
	public function localized_message(): string {
		if ( ! function_exists( '__' ) ) {
			return $this->getMessage();
		}

		switch ( $this->reason ) {
			case self::NO_SKU:
				/* translators: %s: order line (product) name */
				return sprintf( __( 'Order line "%s" has no SKU. Add the SKU to the product (SkladX matches products by SKU), then resend the order.', 'skladx-woocommerce' ), $this->subject );
			case self::NO_ITEMS:
				return __( 'The order has no products to ship.', 'skladx-woocommerce' );
			case self::NO_ADDRESS:
				return __( 'The order has no shipping or billing street address. Fill in the address, then resend the order.', 'skladx-woocommerce' );
			case self::PRODUCT_NO_SKU:
				return __( 'The product has no SKU, it cannot be sent to SkladX.', 'skladx-woocommerce' );
			default:
				return $this->getMessage();
		}
	}
}
