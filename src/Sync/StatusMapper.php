<?php
/**
 * SkladX order statuses and their mapping to WooCommerce statuses.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

/**
 * Pure helper (no WordPress calls) — unit tested.
 */
final class StatusMapper {

	/** Every SkladX order status, happy path first. */
	public const STATUSES = array(
		'draft',
		'received',
		'on_hold',
		'awaiting_stock',
		'ready',
		'allocated',
		'picking',
		'picked',
		'packing',
		'packed',
		'label_created',
		'handed_over',
		'in_transit',
		'out_for_delivery',
		'delivered',
		'delivery_failed',
		'returning',
		'returned',
		'cancelled',
	);

	/** Statuses in which the client may still cancel the order through the API. */
	public const CANCELLABLE = array( 'draft', 'received', 'on_hold', 'awaiting_stock', 'ready' );

	/**
	 * @param array<string, string> $map SkladX status => WooCommerce status (with or without `wc-`).
	 */
	public function __construct( private readonly array $map ) {}

	/**
	 * WooCommerce status (without `wc-`) the order should move to, or null to keep it.
	 */
	public function to_woocommerce( string $skladx_status ): ?string {
		$target = trim( (string) ( $this->map[ $skladx_status ] ?? '' ) );

		if ( '' === $target ) {
			return null;
		}

		return str_starts_with( $target, 'wc-' ) ? substr( $target, 3 ) : $target;
	}

	/**
	 * Whether the WooCommerce order needs a status change.
	 */
	public function needs_change( string $skladx_status, string $current_wc_status ): bool {
		$target = $this->to_woocommerce( $skladx_status );

		return null !== $target && $target !== $current_wc_status;
	}

	public static function is_cancellable( string $skladx_status ): bool {
		return in_array( $skladx_status, self::CANCELLABLE, true );
	}

	public static function is_known( string $skladx_status ): bool {
		return in_array( $skladx_status, self::STATUSES, true );
	}
}
