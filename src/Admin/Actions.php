<?php
/**
 * Admin buttons: test connection, register webhook, sync, order actions.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Admin;

use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Api\Client;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;
use SkladX\WooCommerce\Sync\OrderExporter;
use SkladX\WooCommerce\Sync\OrderMeta;
use SkladX\WooCommerce\Sync\OrderUpdater;
use SkladX\WooCommerce\Sync\ProductSync;
use SkladX\WooCommerce\Webhooks\EventPoller;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=skladx_*` handlers. Each checks the nonce and the
 * `manage_woocommerce` capability, does its work and redirects back with a notice.
 */
final class Actions {

	private const ACTIONS = array(
		'skladx_test_connection' => 'test_connection',
		'skladx_register_webhook' => 'register_webhook',
		'skladx_sync_products'   => 'sync_products',
		'skladx_poll_events'     => 'poll_events',
		'skladx_order_send'      => 'order_send',
		'skladx_order_refresh'   => 'order_refresh',
		'skladx_order_cancel'    => 'order_cancel',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action( 'admin_post_' . $action, array( self::class, $method ) );
		}
	}

	/**
	 * Nonce-protected URL of an action.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $args   Extra query args.
	 */
	public static function url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	public static function test_connection(): void {
		self::guard( 'skladx_test_connection' );

		try {
			$info = Client::from_settings()->ping();
			Settings::set( 'connection_info', $info + array( 'checked_at' => time() ) );

			$missing = array_diff( array( 'orders:read', 'orders:write', 'products:write', 'events:read', 'webhooks:write' ), (array) ( $info['scopes'] ?? array() ) );

			if ( array() !== $missing ) {
				/* translators: %s: missing scopes */
				Notices::add( sprintf( __( 'Connected, but the API key is missing scopes: %s', 'skladx-woocommerce' ), implode( ', ', $missing ) ), 'warning' );
			} else {
				Notices::add( __( 'Connection works.', 'skladx-woocommerce' ), 'success' );
			}
		} catch ( ApiException $exception ) {
			Settings::set(
				'connection_info',
				array(
					'error'      => $exception->summary(),
					'checked_at' => time(),
				)
			);
			/* translators: %s: error */
			Notices::add( sprintf( __( 'Connection failed: %s', 'skladx-woocommerce' ), esc_html( $exception->summary() ) ) );
		}

		self::back_to_settings();
	}

	public static function register_webhook(): void {
		self::guard( 'skladx_register_webhook' );

		$client   = Client::from_settings();
		$previous = (string) Settings::get( 'webhook_id' );

		try {
			$webhook = $client->create_webhook(
				Settings::webhook_url(),
				Settings::WEBHOOK_EVENTS,
				sprintf( 'WooCommerce – %s', Settings::site_host() )
			);

			if ( empty( $webhook['secret'] ) || empty( $webhook['id'] ) ) {
				throw new ApiException( __( 'SkladX did not return the webhook secret.', 'skladx-woocommerce' ), 200, 'unexpected_response' );
			}

			Settings::set( 'webhook_secret', (string) $webhook['secret'] );
			Settings::set( 'webhook_id', (string) $webhook['id'] );

			if ( '' !== $previous && $previous !== $webhook['id'] ) {
				try {
					$client->delete_webhook( $previous );
				} catch ( ApiException $exception ) {
					Logger::warning( 'Old webhook could not be deleted: ' . $exception->summary() );
				}
			}

			/* translators: %s: webhook id */
			Notices::add( sprintf( __( 'Webhook registered in SkladX (%s), signing secret saved.', 'skladx-woocommerce' ), esc_html( (string) $webhook['id'] ) ), 'success' );
		} catch ( ApiException $exception ) {
			/* translators: %s: error */
			Notices::add( sprintf( __( 'Registering the webhook failed: %s', 'skladx-woocommerce' ), esc_html( $exception->summary() ) ) );
		}

		self::back_to_settings();
	}

	public static function sync_products(): void {
		self::guard( 'skladx_sync_products' );

		ProductSync::queue_all();
		Notices::add( __( 'All products with a SKU are being sent to SkladX in the background.', 'skladx-woocommerce' ), 'success' );

		self::back_to_settings();
	}

	public static function poll_events(): void {
		self::guard( 'skladx_poll_events' );

		$new = EventPoller::poll();
		/* translators: %d: number of events */
		Notices::add( sprintf( _n( '%d missed event found.', '%d missed events found.', $new, 'skladx-woocommerce' ), $new ), 'success' );

		self::back_to_settings();
	}

	public static function order_send(): void {
		$order = self::order( 'skladx_order_send' );

		if ( OrderExporter::queue( $order ) ) {
			Notices::add( __( 'The order is being sent to SkladX.', 'skladx-woocommerce' ), 'success' );
		} else {
			Notices::add( __( 'The order is already in SkladX or queued.', 'skladx-woocommerce' ), 'warning' );
		}

		self::back_to_order( $order );
	}

	public static function order_refresh(): void {
		$order = self::order( 'skladx_order_refresh' );

		try {
			$remote = Client::from_settings()->get_order( (string) $order->get_meta( OrderMeta::ID ) );
			OrderUpdater::apply( $order, $remote );
			Notices::add( __( 'SkladX data refreshed.', 'skladx-woocommerce' ), 'success' );
		} catch ( ApiException $exception ) {
			/* translators: %s: error */
			Notices::add( sprintf( __( 'Refreshing from SkladX failed: %s', 'skladx-woocommerce' ), esc_html( $exception->summary() ) ) );
		}

		self::back_to_order( $order );
	}

	public static function order_cancel(): void {
		$order = self::order( 'skladx_order_cancel' );

		try {
			/* translators: %s: user name */
			$remote = Client::from_settings()->cancel_order( (string) $order->get_meta( OrderMeta::ID ), sprintf( __( 'Cancelled in WooCommerce by %s', 'skladx-woocommerce' ), wp_get_current_user()->display_name ) );
			$order->add_order_note( __( 'Order cancelled in SkladX.', 'skladx-woocommerce' ), 0, true );
			OrderUpdater::apply( $order, $remote );
			Notices::add( __( 'Order cancelled in SkladX.', 'skladx-woocommerce' ), 'success' );
		} catch ( ApiException $exception ) {
			/* translators: %s: error */
			Notices::add( sprintf( __( 'Cancelling in SkladX failed: %s', 'skladx-woocommerce' ), esc_html( $exception->summary() ) ) );
		}

		self::back_to_order( $order );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'skladx-woocommerce' ), 403 );
		}

		check_admin_referer( $action );
	}

	private static function order( string $action ): \WC_Order {
		self::guard( $action );

		$order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked in guard().

		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'skladx-woocommerce' ), 404 );
		}

		return $order;
	}

	private static function back_to_settings(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=skladx' ) );
		exit;
	}

	private static function back_to_order( \WC_Order $order ): void {
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
