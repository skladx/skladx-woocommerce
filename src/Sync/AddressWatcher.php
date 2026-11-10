<?php
/**
 * Warns when an exported order's address is changed in WooCommerce.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

use SkladX\WooCommerce\Admin\Notices;
use SkladX\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The SkladX API has no order update: an address edited in WooCommerce after
 * the export never reaches the warehouse. Instead of failing silently, the
 * admin gets a notice and a private order note (once per changed address).
 */
final class AddressWatcher {

	public static function register(): void {
		// After WooCommerce saved the address fields of the order edit screen (priority 40).
		add_action( 'woocommerce_process_shop_order_meta', array( self::class, 'check' ), 100, 1 );
	}

	/**
	 * @param int $order_id Order id.
	 */
	public static function check( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || ! Settings::is_configured() || ! OrderMeta::is_exported( $order ) ) {
			return;
		}

		$sent = (string) $order->get_meta( OrderMeta::ADDRESS_HASH );

		if ( '' === $sent || in_array( (string) $order->get_meta( OrderMeta::STATUS ), array( 'cancelled', 'delivered', 'returned' ), true ) ) {
			return;
		}

		try {
			$current = OrderPayloadBuilder::address_fingerprint( (array) ( OrderExporter::payload( $order )['shipping_address'] ?? array() ) );
		} catch ( MappingException $exception ) {
			$current = 'invalid';
		}

		if ( $current === $sent || $current === (string) $order->get_meta( OrderMeta::ADDRESS_WARNED ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: SkladX order number */
			__( 'The shipping address was changed after the order was sent to SkladX (%s). SkladX does not take over changes from the shop: ask the warehouse to correct the address before the parcel is shipped.', 'skladx-woocommerce' ),
			(string) $order->get_meta( OrderMeta::NUMBER )
		);

		$order->update_meta_data( OrderMeta::ADDRESS_WARNED, $current );
		$order->add_order_note( $message );
		$order->save();

		Notices::add( esc_html( $message ), 'warning' );
	}
}
