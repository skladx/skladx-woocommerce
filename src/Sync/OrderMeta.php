<?php
/**
 * SkladX data stored on WooCommerce orders.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Meta keys and the one place that writes a SkladX order (API shape) onto a
 * WC order. Uses the CRUD API only, so it works with HPOS and legacy storage.
 */
final class OrderMeta {

	public const ID                = '_skladx_order_id';
	public const NUMBER            = '_skladx_order_number';
	public const STATUS            = '_skladx_status';
	public const STATUS_LABEL      = '_skladx_status_label';
	public const STATUS_CHANGED_AT = '_skladx_status_changed_at';
	public const TRACKING_NUMBER   = '_skladx_tracking_number';
	public const TRACKING_URL      = '_skladx_tracking_url';
	public const CARRIER           = '_skladx_carrier';
	public const TEST              = '_skladx_test';
	public const LAST_SYNC         = '_skladx_last_sync';
	public const EXPORT_STATE      = '_skladx_export_state';
	public const EXPORT_ERROR      = '_skladx_export_error';
	public const EXPORT_REVISION   = '_skladx_export_revision';
	public const ADDRESS_HASH      = '_skladx_address_hash';
	public const ADDRESS_WARNED    = '_skladx_address_warned';

	public static function is_exported( \WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( self::ID );
	}

	/**
	 * Copies a SkladX order (as returned by the API or inside an event) onto the WC order.
	 * Does not save.
	 *
	 * @param array<string, mixed> $remote SkladX order.
	 */
	public static function apply( \WC_Order $order, array $remote ): void {
		$shipping = is_array( $remote['shipping'] ?? null ) ? $remote['shipping'] : array();

		$values = array(
			self::ID                => $remote['id'] ?? null,
			self::NUMBER            => $remote['number'] ?? null,
			self::STATUS            => $remote['status'] ?? null,
			self::STATUS_LABEL      => $remote['status_label'] ?? null,
			self::STATUS_CHANGED_AT => $remote['status_changed_at'] ?? null,
			self::TRACKING_NUMBER   => $shipping['tracking_number'] ?? null,
			self::TRACKING_URL      => $shipping['tracking_url'] ?? null,
			self::CARRIER           => $shipping['carrier'] ?? null,
		);

		foreach ( $values as $key => $value ) {
			if ( null !== $value && '' !== $value ) {
				$order->update_meta_data( $key, (string) $value );
			}
		}

		if ( is_array( $remote['shipping_address'] ?? null ) ) {
			$order->update_meta_data( self::ADDRESS_HASH, OrderPayloadBuilder::address_fingerprint( $remote['shipping_address'] ) );
		}

		if ( array_key_exists( 'test', $remote ) ) {
			$order->update_meta_data( self::TEST, $remote['test'] ? 'yes' : 'no' );
		}

		$order->update_meta_data( self::LAST_SYNC, (string) time() );
		$order->update_meta_data( self::EXPORT_STATE, 'exported' );
		$order->delete_meta_data( self::EXPORT_ERROR );
	}

	/**
	 * Finds the WC order an incoming SkladX order belongs to: by our metadata
	 * (wc_order_id + site), then by the stored `ord_…` id, then by reference.
	 *
	 * @param array<string, mixed> $remote SkladX order.
	 */
	public static function find_order( array $remote, string $site ): ?\WC_Order {
		$metadata = is_array( $remote['metadata'] ?? null ) ? $remote['metadata'] : array();

		if ( ! empty( $metadata['wc_order_id'] ) && ( empty( $metadata['site'] ) || $metadata['site'] === $site ) ) {
			$order = wc_get_order( (int) $metadata['wc_order_id'] );

			if ( $order instanceof \WC_Order && self::matches( $order, $remote ) ) {
				return $order;
			}
		}

		if ( ! empty( $remote['id'] ) ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'type'       => 'shop_order',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => self::ID,
							'value' => (string) $remote['id'],
						),
					),
				)
			);

			if ( ! empty( $orders ) && $orders[0] instanceof \WC_Order ) {
				return $orders[0];
			}
		}

		/**
		 * Resolve a WC order from the SkladX reference (the WC order number by default).
		 * Sequential order number plugins can hook in here.
		 *
		 * @param \WC_Order|null       $order     Found order.
		 * @param string               $reference SkladX reference.
		 * @param array<string, mixed> $remote    SkladX order.
		 */
		$order = apply_filters( 'skladx_find_order_by_reference', null, (string) ( $remote['reference'] ?? '' ), $remote );

		if ( $order instanceof \WC_Order ) {
			return $order;
		}

		if ( ! empty( $remote['reference'] ) && ctype_digit( (string) $remote['reference'] ) ) {
			$order = wc_get_order( (int) $remote['reference'] );

			if ( $order instanceof \WC_Order && (string) $order->get_order_number() === (string) $remote['reference'] && self::matches( $order, $remote ) ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * An order already linked to another SkladX order is not a match.
	 *
	 * @param array<string, mixed> $remote SkladX order.
	 */
	private static function matches( \WC_Order $order, array $remote ): bool {
		$linked = (string) $order->get_meta( self::ID );

		return '' === $linked || empty( $remote['id'] ) || $linked === (string) $remote['id'];
	}
}
