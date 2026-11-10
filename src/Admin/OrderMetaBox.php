<?php
/**
 * SkladX box on the order edit screen (HPOS and legacy).
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use SkladX\WooCommerce\Pickup\Checkout as PickupCheckout;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Sync\OrderMeta;
use SkladX\WooCommerce\Sync\StatusMapper;
use SkladX\WooCommerce\Sync\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the SkladX status, number, tracking and last sync, with Send/Resend,
 * Refresh and "Cancel in SkladX" (only while SkladX still allows it).
 */
final class OrderMetaBox {

	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ) );
	}

	public static function add(): void {
		$screen = class_exists( CustomOrdersTableController::class ) && function_exists( 'wc_get_page_screen_id' )
			&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box( 'skladx-order', 'SkladX', array( self::class, 'render' ), $screen, 'side', 'high' );
	}

	/**
	 * @param \WP_Post|\WC_Order $post_or_order Post (legacy) or order (HPOS).
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! Settings::is_configured() ) {
			printf( '<p>%s</p>', esc_html__( 'SkladX is not configured.', 'skladx-woocommerce' ) );

			return;
		}

		$args      = array( 'order_id' => $order->get_id() );
		$remote_id = (string) $order->get_meta( OrderMeta::ID );
		$state     = (string) $order->get_meta( OrderMeta::EXPORT_STATE );
		$error     = (string) $order->get_meta( OrderMeta::EXPORT_ERROR );

		$pickup = PickupCheckout::for_order( $order );

		if ( '' === $remote_id ) {
			if ( null !== $pickup ) {
				printf( '<p><strong>%1$s</strong> %2$s<br /><small>%3$s</small></p>', esc_html__( 'Pickup point:', 'skladx-woocommerce' ), esc_html( $pickup['name'] ), esc_html( $pickup['address'] ) );
			}

			$labels = array(
				'queued'   => __( 'Queued for sending…', 'skladx-woocommerce' ),
				'retrying' => __( 'Sending failed, will retry automatically.', 'skladx-woocommerce' ),
				'failed'   => __( 'Sending failed.', 'skladx-woocommerce' ),
			);

			printf( '<p>%s</p>', esc_html( $labels[ $state ] ?? __( 'Not sent to SkladX yet.', 'skladx-woocommerce' ) ) );

			if ( '' !== $error ) {
				printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $error ) );
			}

			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( Actions::url( 'skladx_order_send', $args ) ),
				'failed' === $state ? esc_html__( 'Resend to SkladX', 'skladx-woocommerce' ) : esc_html__( 'Send to SkladX', 'skladx-woocommerce' )
			);

			return;
		}

		$status   = (string) $order->get_meta( OrderMeta::STATUS );
		$tracking = Tracking::for_order( $order );
		$synced   = (int) $order->get_meta( OrderMeta::LAST_SYNC );

		echo '<table class="widefat striped" style="border:0"><tbody>';
		self::row( __( 'Status', 'skladx-woocommerce' ), esc_html( (string) $order->get_meta( OrderMeta::STATUS_LABEL ) ?: $status ) . ' <code>' . esc_html( $status ) . '</code>' );
		self::row( __( 'Number', 'skladx-woocommerce' ), esc_html( (string) $order->get_meta( OrderMeta::NUMBER ) ) );
		self::row( 'ID', '<code>' . esc_html( $remote_id ) . '</code>' . ( 'yes' === $order->get_meta( OrderMeta::TEST ) ? ' <span style="color:#996800">(' . esc_html__( 'sandbox', 'skladx-woocommerce' ) . ')</span>' : '' ) );
		self::row(
			__( 'Tracking', 'skladx-woocommerce' ),
			null === $tracking ? '—' : ( '' !== $tracking['url'] ? '<a href="' . esc_url( $tracking['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $tracking['number'] ) . '</a>' : esc_html( $tracking['number'] ) )
		);
		if ( null !== $pickup ) {
			self::row( __( 'Pickup point', 'skladx-woocommerce' ), esc_html( $pickup['name'] ) . '<br /><small>' . esc_html( $pickup['address'] ) . ( '' !== $pickup['id'] ? ' · <code>' . esc_html( $pickup['id'] ) . '</code>' : '' ) . '</small>' );
		}

		self::row( __( 'Last sync', 'skladx-woocommerce' ), $synced ? esc_html( wp_date( 'j. n. Y H:i:s', $synced ) ) : '—' );
		echo '</tbody></table>';

		echo '<p>';

		if ( null !== $tracking && '' !== $tracking['url'] ) {
			printf(
				'<a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s <span class="dashicons dashicons-external" style="vertical-align:text-bottom;font-size:16px" aria-hidden="true"></span></a> ',
				esc_url( $tracking['url'] ),
				esc_html__( 'Track shipment', 'skladx-woocommerce' )
			);
		}

		printf( '<a class="button" href="%s">%s</a> ', esc_url( Actions::url( 'skladx_order_refresh', $args ) ), esc_html__( 'Refresh', 'skladx-woocommerce' ) );

		if ( StatusMapper::is_cancellable( $status ) ) {
			printf(
				'<a class="button" style="color:#b32d2e;border-color:#b32d2e" href="%s" onclick="return confirm(%s)">%s</a>',
				esc_url( Actions::url( 'skladx_order_cancel', $args ) ),
				esc_attr( wp_json_encode( __( 'Cancel this order in the SkladX warehouse?', 'skladx-woocommerce' ) ) ),
				esc_html__( 'Cancel in SkladX', 'skladx-woocommerce' )
			);
		}

		echo '</p>';
	}

	private static function row( string $label, string $html ): void {
		printf( '<tr><th style="padding:4px 6px;width:35%%">%s</th><td style="padding:4px 6px">%s</td></tr>', esc_html( $label ), $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by callers.
	}
}
