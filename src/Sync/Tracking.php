<?php
/**
 * Tracking number and URL shown to customers.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the SkladX tracking number on the customer's order page and in
 * WooCommerce emails. When SkladX does not send a tracking URL, a public
 * tracking page of the carrier is used (filter `skladx_tracking_url`).
 */
final class Tracking {

	/** Public tracking pages; `%s` is the tracking number. */
	private const CARRIER_URLS = array(
		'packeta'  => 'https://tracking.packeta.com/sk/?id=%s',
		'dpd_sk'   => 'https://tracking.dpd.de/status/sk_SK/parcel/%s',
		'gls_sk'   => 'https://gls-group.com/SK/sk/sledovanie-zasielok?match=%s',
		'posta_sk' => 'https://tandt.posta.sk/zasielky/%s',
	);

	public static function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'render_order_details' ), 5 );
		add_action( 'woocommerce_email_order_meta', array( self::class, 'render_email' ), 20, 3 );
	}

	/**
	 * @return array{number: string, url: string}|null
	 */
	public static function for_order( \WC_Order $order ): ?array {
		$number = (string) $order->get_meta( OrderMeta::TRACKING_NUMBER );

		if ( '' === $number ) {
			return null;
		}

		$url     = (string) $order->get_meta( OrderMeta::TRACKING_URL );
		$carrier = (string) $order->get_meta( OrderMeta::CARRIER );

		if ( '' === $url && isset( self::CARRIER_URLS[ $carrier ] ) ) {
			$url = sprintf( self::CARRIER_URLS[ $carrier ], rawurlencode( $number ) );
		}

		/**
		 * Filters the tracking URL shown to the customer.
		 *
		 * @param string    $url     Tracking URL ('' for none).
		 * @param string    $number  Tracking number.
		 * @param string    $carrier SkladX carrier code.
		 * @param \WC_Order $order   Order.
		 */
		$url = (string) apply_filters( 'skladx_tracking_url', $url, $number, $carrier, $order );

		return array(
			'number' => $number,
			'url'    => $url,
		);
	}

	/**
	 * @param \WC_Order $order Order.
	 */
	public static function render_order_details( $order ): void {
		$tracking = $order instanceof \WC_Order ? self::for_order( $order ) : null;

		if ( null === $tracking ) {
			return;
		}

		echo '<section class="skladx-tracking"><h2>' . esc_html__( 'Shipment tracking', 'skladx-woocommerce' ) . '</h2><p>';
		echo esc_html__( 'Tracking number:', 'skladx-woocommerce' ) . ' ';

		if ( '' !== $tracking['url'] ) {
			echo '<a href="' . esc_url( $tracking['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $tracking['number'] ) . '</a>';
		} else {
			echo esc_html( $tracking['number'] );
		}

		echo '</p></section>';
	}

	/**
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Plain text email.
	 */
	public static function render_email( $order, $sent_to_admin = false, $plain_text = false ): void {
		$tracking = $order instanceof \WC_Order ? self::for_order( $order ) : null;

		if ( null === $tracking ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Tracking number:', 'skladx-woocommerce' ) . ' ' . esc_html( $tracking['number'] ) . ( '' !== $tracking['url'] ? ' — ' . esc_url_raw( $tracking['url'] ) : '' ) . "\n";

			return;
		}

		echo '<h2>' . esc_html__( 'Shipment tracking', 'skladx-woocommerce' ) . '</h2><p>' . esc_html__( 'Tracking number:', 'skladx-woocommerce' ) . ' ';
		echo '' !== $tracking['url']
			? '<a href="' . esc_url( $tracking['url'] ) . '">' . esc_html( $tracking['number'] ) . '</a>'
			: esc_html( $tracking['number'] );
		echo '</p>';
	}
}
