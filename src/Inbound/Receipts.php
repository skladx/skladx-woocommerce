<?php
/**
 * Inbound receipts module: wiring, labels, cache updates and the refresh job.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

use SkladX\WooCommerce\Admin\Notices;
use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Api\Client;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;
use SkladX\WooCommerce\Sync\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * SkladX is the source of truth for receipts: nothing is stored in
 * WordPress except a small status cache (OptionReceiptStore) used for admin
 * notices about status changes.
 */
final class Receipts {

	public const CAPABILITY   = 'manage_woocommerce';
	public const REFRESH_HOOK = 'skladx_inbound_refresh';
	public const GROUP        = 'skladx';

	/** Seconds between refreshes of open receipts (fallback for missed events). */
	public const REFRESH_INTERVAL = 1800;

	public static function register(): void {
		add_action( self::REFRESH_HOOK, array( self::class, 'refresh' ) );
		add_action( 'action_scheduler_init', array( self::class, 'schedule' ) );

		if ( is_admin() ) {
			AdminPage::register();
			Ajax::register();
		}
	}

	public static function schedule(): void {
		if ( Settings::is_configured() && ! as_has_scheduled_action( self::REFRESH_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 300, self::REFRESH_INTERVAL, self::REFRESH_HOOK, array(), self::GROUP );
		}
	}

	public static function events(): InboundEvents {
		return new InboundEvents( new OptionReceiptStore(), array( self::class, 'notify' ) );
	}

	/**
	 * EventProcessor entry point for `inbound.*` events.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $data Event data.
	 * @return string processed|ignored
	 */
	public static function handle_event( string $type, array $data ): string {
		return self::events()->handle_event( $type, $data );
	}

	/**
	 * Keeps the cache in sync with receipts loaded or changed in the admin
	 * (no notice: the shop manager sees the status on the screen).
	 *
	 * @param array<string, mixed> $receipt Normalised receipt.
	 */
	public static function remember( array $receipt ): void {
		self::events()->apply( $receipt, InboundEvents::SOURCE_ADMIN );
	}

	/**
	 * Action Scheduler job: reloads the first page of receipts and applies
	 * them to the cache, so a status change is noticed even when both the
	 * webhook and the event poll missed it. Stops quietly while SkladX does
	 * not support receipts (404).
	 */
	public static function refresh(): void {
		if ( ! Settings::is_configured() ) {
			return;
		}

		$open = array_filter( ( new OptionReceiptStore() )->all(), static fn ( array $summary ): bool => ! Receipt::is_final( (string) ( $summary['status'] ?? '' ) ) );

		if ( array() === $open ) {
			return;
		}

		try {
			$page = Client::from_settings()->inbound_receipts( array( 'limit' => 50 ) );
		} catch ( ApiException $exception ) {
			if ( 404 !== $exception->status ) {
				Logger::warning( 'Refreshing SkladX receipts failed: ' . $exception->summary() );
			}

			return;
		}

		$events = self::events();

		foreach ( $page['data'] as $raw ) {
			$events->apply( Receipt::normalize( $raw ), InboundEvents::SOURCE_REFRESH );
		}
	}

	/**
	 * Admin notice about a status change.
	 *
	 * @param array<string, mixed>      $receipt  Normalised receipt.
	 * @param array<string, mixed>|null $previous Previous summary.
	 */
	public static function notify( array $receipt, ?array $previous ): void {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( AdminPage::url( array( 'receipt' => (string) $receipt['id'] ) ) ),
			esc_html( '' !== (string) $receipt['number'] ? (string) $receipt['number'] : (string) $receipt['name'] )
		);
		$status = esc_html( self::status_label( (string) $receipt['status'], (string) $receipt['status_label'] ) );

		if ( Receipt::RECEIVED === $receipt['status'] && ! empty( $receipt['has_differences'] ) ) {
			/* translators: 1: receipt number (link), 2: received pieces, 3: expected pieces */
			$message = sprintf( __( 'Receipt %1$s was received with differences: %2$d of %3$d pieces.', 'skladx-woocommerce' ), $link, (int) $receipt['totals']['received'], (int) $receipt['totals']['expected'] );
			$type    = 'warning';
		} else {
			/* translators: 1: receipt number (link), 2: new status */
			$message = sprintf( __( 'Receipt %1$s: %2$s.', 'skladx-woocommerce' ), $link, $status );
			$type    = Receipt::CANCELLED === $receipt['status'] ? 'warning' : 'success';
		}

		Notices::add( $message, $type );
		Logger::info( sprintf( 'Receipt %s: %s → %s.', (string) $receipt['id'], (string) ( $previous['status'] ?? '-' ), (string) $receipt['status'] ) );
	}

	/**
	 * Status label shown in the admin.
	 *
	 * @param string $status   SkladX status.
	 * @param string $fallback `status_label` from SkladX for unknown statuses.
	 */
	public static function status_label( string $status, string $fallback = '' ): string {
		$labels = self::status_labels();

		return $labels[ $status ] ?? ( '' !== $fallback ? $fallback : $status );
	}

	/**
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			Receipt::DRAFT      => _x( 'Draft', 'Receipt status', 'skladx-woocommerce' ),
			Receipt::ANNOUNCED  => _x( 'Awaiting receipt', 'Receipt status', 'skladx-woocommerce' ),
			Receipt::IN_TRANSIT => _x( 'In transit', 'Receipt status', 'skladx-woocommerce' ),
			Receipt::ARRIVED    => _x( 'Arrived at warehouse', 'Receipt status', 'skladx-woocommerce' ),
			Receipt::RECEIVING  => _x( 'Receiving', 'Receipt status', 'skladx-woocommerce' ),
			Receipt::RECEIVED   => _x( 'Received', 'Receipt status', 'skladx-woocommerce' ),
			Receipt::CANCELLED  => _x( 'Cancelled', 'Receipt status', 'skladx-woocommerce' ),
		);
	}

	/**
	 * Carriers of the "Via carrier" select.
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function carrier_options(): array {
		$names = array(
			'packeta'  => 'Packeta',
			'dpd_sk'   => 'DPD',
			'gls_sk'   => 'GLS',
			'sps'      => 'SPS – Slovak Parcel Service',
			'posta_sk' => __( 'Slovak Post', 'skladx-woocommerce' ),
			'balikovo' => 'Balíkovo',
		);

		$options = array();

		foreach ( Settings::CARRIERS as $carrier ) {
			$options[ $carrier ] = $names[ $carrier ] ?? $carrier;
		}

		$options[ ReceiptPayload::CARRIER_PERSONAL ] = __( 'In person to the warehouse', 'skladx-woocommerce' );
		$options[ ReceiptPayload::CARRIER_OTHER ]    = __( 'Other', 'skladx-woocommerce' );

		return $options;
	}

	public static function carrier_label( string $carrier ): string {
		$options = self::carrier_options();

		return '' === $carrier ? '' : ( $options[ $carrier ] ?? $carrier );
	}

	/**
	 * Tracking link: SkladX `tracking_url`, else the carrier's public page.
	 *
	 * @param array<string, mixed> $receipt Normalised receipt.
	 */
	public static function tracking_url( array $receipt ): string {
		$url = (string) ( $receipt['tracking_url'] ?? '' );

		return '' !== $url ? $url : Tracking::carrier_url( (string) ( $receipt['carrier'] ?? '' ), (string) ( $receipt['tracking_number'] ?? '' ) );
	}

	/**
	 * Translated explanation of an API error for the receipts screens.
	 */
	public static function error_message( ApiException $exception ): string {
		if ( 403 === $exception->status ) {
			return __( 'The SkladX API key is missing the scopes inbound:read and inbound:write. Issue a new key with these scopes.', 'skladx-woocommerce' );
		}

		if ( 'not_configured' === $exception->error_code ) {
			return __( 'SkladX is not configured. Fill in the API URL and key in WooCommerce → Settings → SkladX.', 'skladx-woocommerce' );
		}

		return $exception->summary();
	}

	public static function unsupported_message(): string {
		return __( 'SkladX does not support this module yet.', 'skladx-woocommerce' );
	}

	/**
	 * Whether a failed `GET /inbound-receipts` (the list) means that this
	 * SkladX instance has no receipts API yet.
	 */
	public static function is_unsupported( ApiException $exception ): bool {
		return 404 === $exception->status || 405 === $exception->status;
	}
}
