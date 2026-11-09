<?php
/**
 * Applies a SkladX order to its WooCommerce order.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

use SkladX\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The single path for "SkladX says the order now looks like this" — used by
 * webhooks, polling, the export response and the admin Refresh/Cancel buttons:
 * stores the SkladX data, adds a note for a new status, applies the status
 * mapping and tells the customer about a new tracking number.
 */
final class OrderUpdater {

	/**
	 * @param \WC_Order            $order       Order.
	 * @param array<string, mixed> $remote      SkladX order.
	 * @param bool                 $status_note Add the "SkladX: order status is now …" note.
	 * @return bool Whether the SkladX status changed.
	 */
	public static function apply( \WC_Order $order, array $remote, bool $status_note = true ): bool {
		$previous_status   = (string) $order->get_meta( OrderMeta::STATUS );
		$previous_tracking = (string) $order->get_meta( OrderMeta::TRACKING_NUMBER );
		$status            = (string) ( $remote['status'] ?? '' );
		$label             = (string) ( $remote['status_label'] ?? $status );
		$changed           = '' !== $status && $status !== $previous_status;

		OrderMeta::apply( $order, $remote );

		if ( $changed ) {
			/* translators: 1: SkladX status label, 2: SkladX status code */
			$note   = $status_note ? sprintf( __( 'SkladX: order status is now "%1$s" (%2$s).', 'skladx-woocommerce' ), $label, $status ) : '';
			$mapper = new StatusMapper( Settings::status_map() );

			if ( $mapper->needs_change( $status, $order->get_status() ) ) {
				$order->set_status( (string) $mapper->to_woocommerce( $status ), '' !== $note ? $note . ' ' : '' );
			} elseif ( '' !== $note ) {
				$order->add_order_note( $note );
			}
		}

		$tracking = Tracking::for_order( $order );

		if ( null !== $tracking && $tracking['number'] !== $previous_tracking ) {
			$message = sprintf(
				/* translators: %s: carrier tracking number */
				__( 'Your order has been handed over to the carrier. Tracking number: %s', 'skladx-woocommerce' ),
				$tracking['number']
			);

			if ( '' !== $tracking['url'] ) {
				/* translators: %s: tracking URL */
				$message .= "\n" . sprintf( __( 'Track your parcel: %s', 'skladx-woocommerce' ), $tracking['url'] );
			}

			$order->add_order_note( $message, 1 );
		}

		$order->save();

		return $changed;
	}
}
