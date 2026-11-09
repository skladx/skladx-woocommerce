<?php
/**
 * Typed access to the plugin options.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Every option of the plugin is stored as its own `skladx_*` WordPress option
 * (that is how WooCommerce settings pages save fields). This class is the
 * only place that knows the option names and defaults.
 */
final class Settings {

	public const PREFIX = 'skladx_';

	/** Carrier codes accepted by SkladX. */
	public const CARRIERS = array( 'packeta', 'dpd_sk', 'gls_sk', 'sps', 'posta_sk', 'balikovo' );

	/** Events the plugin subscribes to when it registers its webhook. */
	public const WEBHOOK_EVENTS = array(
		'order.status_changed',
		'order.shipped',
		'order.delivered',
		'order.delivery_failed',
		'order.cancelled',
		'order.returned',
		'shipment.tracking_updated',
		'stock.changed',
		'product.created',
		'product.updated',
	);

	/**
	 * Default value of every option (without the prefix).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_base_url'          => '',
			'api_key'               => '',
			'webhook_secret'        => '',
			'webhook_id'            => '',
			'export_statuses'       => array( 'wc-processing' ),
			'carrier_map'           => array(),
			'pickup_point_meta_key' => '_skladx_pickup_point_id',
			'cod_gateways'          => array( 'cod' ),
			'product_sync'          => 'yes',
			'stock_sync'            => 'no',
			'status_map'            => array(
				'handed_over' => 'wc-skladx-shipped',
				'delivered'   => 'wc-completed',
				'cancelled'   => 'wc-cancelled',
			),
			'debug'                 => 'no',
			'events_cursor'         => '',
			'connection_info'       => array(),
		);
	}

	/**
	 * @param string $key Option name without the prefix.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$defaults = self::defaults();

		return get_option( self::PREFIX . $key, $defaults[ $key ] ?? null );
	}

	/**
	 * @param string $key   Option name without the prefix.
	 * @param mixed  $value New value.
	 */
	public static function set( string $key, $value ): void {
		update_option( self::PREFIX . $key, $value, false );
	}

	public static function api_base_url(): string {
		return untrailingslashit( trim( (string) self::get( 'api_base_url' ) ) );
	}

	public static function api_key(): string {
		return trim( (string) self::get( 'api_key' ) );
	}

	public static function webhook_secret(): string {
		return trim( (string) self::get( 'webhook_secret' ) );
	}

	public static function is_configured(): bool {
		return '' !== self::api_base_url() && '' !== self::api_key();
	}

	/**
	 * WooCommerce order statuses (without the `wc-` prefix) that export an order.
	 *
	 * @return list<string>
	 */
	public static function export_statuses(): array {
		return array_values(
			array_map(
				static fn ( $status ): string => str_starts_with( (string) $status, 'wc-' ) ? substr( (string) $status, 3 ) : (string) $status,
				(array) self::get( 'export_statuses' )
			)
		);
	}

	/**
	 * @return array<string, string> `method_id:instance_id` or `method_id` => carrier code.
	 */
	public static function carrier_map(): array {
		return array_filter( array_map( 'strval', (array) self::get( 'carrier_map' ) ) );
	}

	/**
	 * @return array<string, string> SkladX status => WooCommerce status (with `wc-` prefix).
	 */
	public static function status_map(): array {
		return array_filter( array_map( 'strval', (array) self::get( 'status_map' ) ) );
	}

	/**
	 * @return list<string>
	 */
	public static function cod_gateways(): array {
		return array_values( array_map( 'strval', (array) self::get( 'cod_gateways' ) ) );
	}

	public static function pickup_point_meta_key(): string {
		return trim( (string) self::get( 'pickup_point_meta_key' ) );
	}

	public static function product_sync_enabled(): bool {
		return 'yes' === self::get( 'product_sync' );
	}

	public static function stock_sync_enabled(): bool {
		return 'yes' === self::get( 'stock_sync' );
	}

	public static function debug_enabled(): bool {
		return 'yes' === self::get( 'debug' );
	}

	/**
	 * Host of this site, used in idempotency keys and order metadata.
	 */
	public static function site_host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	public static function webhook_url(): string {
		return rest_url( 'skladx/v1/webhook' );
	}
}
