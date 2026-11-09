<?php
/**
 * Applies SkladX events to WooCommerce.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Webhooks;

use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;
use SkladX\WooCommerce\Sync\OrderMeta;
use SkladX\WooCommerce\Sync\ProductSync;
use SkladX\WooCommerce\Sync\OrderUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Action Scheduler job processing one stored event.
 *
 * SkladX emits `order.status_changed` and a specific event (e.g.
 * `order.shipped`) for the same transition; both carry the full order, so
 * whichever arrives first applies it and the second is a no-op (same status,
 * same tracking). Events older than what the order already shows are ignored.
 */
final class EventProcessor {

	public const HOOK  = 'skladx_process_event';
	public const GROUP = 'skladx';

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'process' ), 10, 1 );
	}

	public static function queue( string $event_id ): void {
		as_enqueue_async_action( self::HOOK, array( $event_id ), self::GROUP );
	}

	/**
	 * @param string $event_id `evt_…` id.
	 */
	public static function process( $event_id ): void {
		$event = EventStore::find( (string) $event_id );

		if ( null === $event || 'processed' === $event['status'] || 'ignored' === $event['status'] ) {
			return;
		}

		try {
			$data   = is_array( $event['envelope']['data'] ?? null ) ? $event['envelope']['data'] : array();
			$result = self::dispatch( $event['type'], $data );

			EventStore::mark( $event['event_id'], $result );
		} catch ( \Throwable $exception ) {
			EventStore::mark( $event['event_id'], 'failed', $exception->getMessage() );
			Logger::error( sprintf( 'Processing event %s (%s) failed: %s', $event['event_id'], $event['type'], $exception->getMessage() ) );
		}
	}

	/**
	 * @param array<string, mixed> $data Event data.
	 * @return string processed|ignored
	 */
	public static function dispatch( string $type, array $data ): string {
		if ( str_starts_with( $type, 'order.' ) || 'shipment.tracking_updated' === $type ) {
			return is_array( $data['order'] ?? null ) ? self::apply_order( $data['order'], $type ) : 'ignored';
		}

		if ( in_array( $type, array( 'stock.changed', 'product.created', 'product.updated' ), true ) ) {
			return self::apply_stock( $data );
		}

		return 'ignored';
	}

	/**
	 * @param array<string, mixed> $remote SkladX order.
	 */
	private static function apply_order( array $remote, string $type ): string {
		$order = OrderMeta::find_order( $remote, Settings::site_host() );

		if ( ! $order instanceof \WC_Order ) {
			Logger::debug( sprintf( 'Event %s for unknown order %s (%s) ignored.', $type, (string) ( $remote['id'] ?? '?' ), (string) ( $remote['reference'] ?? '?' ) ) );

			return 'ignored';
		}

		$known_changed_at  = (string) $order->get_meta( OrderMeta::STATUS_CHANGED_AT );
		$remote_changed_at = (string) ( $remote['status_changed_at'] ?? '' );

		if ( '' !== $known_changed_at && '' !== $remote_changed_at && strtotime( $remote_changed_at ) < strtotime( $known_changed_at ) ) {
			Logger::debug( sprintf( 'Stale event %s for order #%s ignored.', $type, $order->get_order_number() ) );

			return 'ignored';
		}

		OrderUpdater::apply( $order, $remote );

		Logger::info( sprintf( 'Order #%s updated from %s: %s.', $order->get_order_number(), $type, (string) ( $remote['status'] ?? '' ) ) );

		return 'processed';
	}

	/**
	 * @param array<string, mixed> $data Event data.
	 */
	private static function apply_stock( array $data ): string {
		if ( ! Settings::stock_sync_enabled() ) {
			return 'ignored';
		}

		$changed = false;

		foreach ( self::stock_levels( $data ) as $sku => $available ) {
			$changed = ProductSync::apply_stock( $sku, $available ) || $changed;
		}

		return $changed ? 'processed' : 'ignored';
	}

	/**
	 * Extracts `sku => available` from the supported payload shapes:
	 * `{product: {sku, stock: {available}}}`, `{sku, available}`,
	 * `{stock: {sku, available}}` and `{stock: [{sku, available}, …]}`.
	 *
	 * @param array<string, mixed> $data Event data.
	 * @return array<string, int>
	 */
	public static function stock_levels( array $data ): array {
		$candidates = array();

		if ( is_array( $data['product'] ?? null ) ) {
			$candidates[] = array(
				'sku'       => $data['product']['sku'] ?? null,
				'available' => $data['product']['stock']['available'] ?? null,
			);
		}

		if ( isset( $data['sku'] ) ) {
			$candidates[] = array(
				'sku'       => $data['sku'],
				'available' => $data['available'] ?? ( $data['stock']['available'] ?? null ),
			);
		} elseif ( is_array( $data['stock'] ?? null ) ) {
			$rows = array_is_list( $data['stock'] ) ? $data['stock'] : array( $data['stock'] );

			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$candidates[] = array(
						'sku'       => $row['sku'] ?? null,
						'available' => $row['available'] ?? null,
					);
				}
			}
		}

		$levels = array();

		foreach ( $candidates as $candidate ) {
			if ( is_scalar( $candidate['sku'] ) && '' !== (string) $candidate['sku'] && is_numeric( $candidate['available'] ) ) {
				$levels[ (string) $candidate['sku'] ] = (int) $candidate['available'];
			}
		}

		return $levels;
	}
}
