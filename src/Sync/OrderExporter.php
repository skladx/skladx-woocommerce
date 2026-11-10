<?php
/**
 * Exports WooCommerce orders to SkladX.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

use SkladX\WooCommerce\Admin\Notices;
use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Api\Client;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Order status trigger → Action Scheduler job → `POST /orders`.
 *
 * The Idempotency-Key is `wc:<host>:order:<id>` (plus `:r<n>` after a manual
 * resend or a rejected attempt), so a retry after a timeout never creates a
 * second SkladX order. Network/5xx/429 errors are retried with backoff;
 * validation errors (422) stop and show an admin notice.
 */
final class OrderExporter {

	public const HOOK  = 'skladx_export_order';
	public const GROUP = 'skladx';

	/** Seconds before retry n+1. */
	public const BACKOFF = array( 60, 300, 900, 3600, 10800, 21600 );

	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status_changed' ), 20, 4 );
		add_action( self::HOOK, array( self::class, 'export' ), 10, 2 );
	}

	/**
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status.
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof \WC_Order || ! Settings::is_configured() ) {
			return;
		}

		if ( in_array( (string) $to, Settings::export_statuses(), true ) ) {
			self::queue( $order );
		}
	}

	/**
	 * Queues the export unless the order is already exported or queued.
	 *
	 */
	public static function queue( \WC_Order $order ): bool {
		if ( OrderMeta::is_exported( $order ) ) {
			return false;
		}

		$args = array( (int) $order->get_id(), 0 );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return false;
		}

		$order->update_meta_data( OrderMeta::EXPORT_STATE, 'queued' );
		$order->save();

		as_enqueue_async_action( self::HOOK, $args, self::GROUP );

		return true;
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $order_id Order id.
	 * @param int $attempt  0 for the first attempt.
	 */
	public static function export( $order_id, $attempt = 0 ): void {
		$order   = wc_get_order( (int) $order_id );
		$attempt = (int) $attempt;

		if ( ! $order instanceof \WC_Order || OrderMeta::is_exported( $order ) ) {
			return;
		}

		try {
			$payload = self::payload( $order );
		} catch ( MappingException $exception ) {
			self::fail( $order, $exception->localized_message(), false );

			return;
		}

		try {
			$remote = Client::from_settings()->create_order( $payload, self::idempotency_key( $order ) );
			/* translators: 1: SkladX order number, 2: SkladX order id, 3: SkladX status label */
			self::linked( $order, $remote, __( 'Order sent to SkladX as %1$s (%2$s), status: %3$s.', 'skladx-woocommerce' ) );
		} catch ( ApiException $exception ) {
			if ( self::link_existing( $order, $exception ) ) {
				return;
			}

			if ( $exception->is_retryable() && $attempt < count( self::BACKOFF ) ) {
				$delay = self::BACKOFF[ $attempt ];
				$order->update_meta_data( OrderMeta::EXPORT_STATE, 'retrying' );
				$order->update_meta_data( OrderMeta::EXPORT_ERROR, $exception->summary() );
				$order->save();

				as_schedule_single_action( time() + $delay, self::HOOK, array( (int) $order->get_id(), $attempt + 1 ), self::GROUP );

				Logger::warning( sprintf( 'Order #%s export failed (attempt %d), retrying in %d s: %s', $order->get_order_number(), $attempt + 1, $delay, $exception->summary() ) );

				return;
			}

			self::fail( $order, $exception->summary(), true );
		}
	}

	/**
	 * Payload for an order (also used by the admin "preview").
	 *
	 * @return array<string, mixed>
	 *
	 * @throws MappingException When the order cannot be mapped.
	 */
	public static function payload( \WC_Order $order ): array {
		$payload = OrderPayloadBuilder::build(
			OrderPayloadBuilder::snapshot( $order, Settings::pickup_point_meta_key() ),
			array(
				'carrier_map'           => Settings::carrier_map(),
				'cod_gateways'          => Settings::cod_gateways(),
				'pickup_point_meta_key' => Settings::pickup_point_meta_key(),
				'site'                  => Settings::site_host(),
				'site_key'              => Settings::site_key(),
				'default_country'       => (string) WC()->countries->get_base_country(),
			)
		);

		/**
		 * Filters the SkladX order payload before it is sent.
		 *
		 * @param array<string, mixed> $payload Payload for POST /orders.
		 * @param \WC_Order            $order   Order.
		 */
		return (array) apply_filters( 'skladx_order_payload', $payload, $order );
	}

	public static function idempotency_key( \WC_Order $order ): string {
		$revision = (int) $order->get_meta( OrderMeta::EXPORT_REVISION );

		return sprintf( 'wc:%s:order:%d', Settings::site_host(), $order->get_id() ) . ( $revision > 0 ? ':r' . $revision : '' );
	}

	/**
	 * When SkladX already has an order with this reference that belongs to this
	 * WC order (e.g. the first response was lost), link it instead of failing.
	 */
	private static function link_existing( \WC_Order $order, ApiException $exception ): bool {
		if ( 422 !== $exception->status || ! isset( $exception->field_errors()['reference'] ) ) {
			return false;
		}

		try {
			$remote = Client::from_settings()->get_order( (string) $order->get_order_number() );
		} catch ( ApiException $lookup ) {
			return false;
		}

		$metadata = is_array( $remote['metadata'] ?? null ) ? $remote['metadata'] : array();

		if ( (int) ( $metadata['wc_order_id'] ?? 0 ) !== (int) $order->get_id() || ( $metadata['site'] ?? '' ) !== Settings::site_host() ) {
			return false;
		}

		/* translators: 1: SkladX order number, 2: SkladX order id, 3: SkladX status label */
		self::linked( $order, $remote, __( 'Order already existed in SkladX as %1$s (%2$s), status: %3$s — linked.', 'skladx-woocommerce' ) );

		return true;
	}

	/**
	 * @param array<string, mixed> $remote SkladX order.
	 * @param string               $note   Note format with number, id and status label.
	 */
	private static function linked( \WC_Order $order, array $remote, string $note ): void {
		$order->add_order_note( sprintf( $note, (string) ( $remote['number'] ?? '?' ), (string) ( $remote['id'] ?? '?' ), (string) ( $remote['status_label'] ?? $remote['status'] ?? '' ) ) );
		OrderUpdater::apply( $order, $remote, false );

		Logger::info( sprintf( 'Order #%s exported as %s.', $order->get_order_number(), (string) ( $remote['id'] ?? '?' ) ) );
	}

	private static function fail( \WC_Order $order, string $message, bool $from_api ): void {
		$order->update_meta_data( OrderMeta::EXPORT_STATE, 'failed' );
		$order->update_meta_data( OrderMeta::EXPORT_ERROR, $message );

		if ( $from_api ) {
			// The rejected request is stored under its Idempotency-Key; the next attempt needs a new one.
			$order->update_meta_data( OrderMeta::EXPORT_REVISION, (int) $order->get_meta( OrderMeta::EXPORT_REVISION ) + 1 );
		}

		/* translators: %s: error message */
		$order->add_order_note( sprintf( __( 'Sending the order to SkladX failed: %s', 'skladx-woocommerce' ), $message ) );
		$order->save();

		Logger::error( sprintf( 'Order #%s export failed: %s', $order->get_order_number(), $message ) );

		Notices::add(
			sprintf(
				/* translators: 1: order edit link, 2: order number, 3: error */
				__( 'Order <a href="%1$s">#%2$s</a> could not be sent to SkladX: %3$s', 'skladx-woocommerce' ),
				esc_url( $order->get_edit_order_url() ),
				esc_html( (string) $order->get_order_number() ),
				esc_html( $message )
			)
		);
	}
}
