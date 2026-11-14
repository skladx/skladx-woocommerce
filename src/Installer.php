<?php
/**
 * Activation, deactivation and schema.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce;

use SkladX\WooCommerce\Sync\ProductSync;
use SkladX\WooCommerce\Webhooks\EventStore;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the event inbox table and removes scheduled actions on deactivation.
 */
final class Installer {

	public const DB_VERSION        = '3';
	public const DB_VERSION_OPTION = 'skladx_db_version';

	/** Action Scheduler hooks owned by the plugin. */
	public const HOOKS = array(
		'skladx_export_order',
		'skladx_sync_product',
		'skladx_sync_products_batch',
		'skladx_process_event',
		'skladx_poll_events',
		'skladx_prune_events',
		'skladx_inbound_refresh',
	);

	public static function activate(): void {
		self::install_schema();
		Settings::site_key();
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'skladx_poll_events' );
			as_unschedule_all_actions( 'skladx_prune_events' );
			as_unschedule_all_actions( 'skladx_inbound_refresh' );
			as_unschedule_all_actions( 'skladx_product_check' );
			as_unschedule_all_actions( 'skladx_product_check_batch' );
		}
	}

	/**
	 * Runs on every load; cheap when the schema is current.
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );

		if ( $installed !== self::DB_VERSION ) {
			// Fix the site key of product external ids before the domain can change.
			Settings::site_key();
			self::install_schema();

			if ( '' !== $installed && version_compare( $installed, '3', '<' ) ) {
				// 0.4.1: products without a SKU are sent as WC-{id} — send them once.
				add_action( 'init', array( self::class, 'queue_sync_of_products_without_sku' ), 20 );
			}
		}
	}

	/**
	 * Queues "Sync all products" once after the update to 0.4.1 when the
	 * shop has products without a SKU (they were skipped before).
	 */
	public static function queue_sync_of_products_without_sku(): void {
		if ( function_exists( 'as_enqueue_async_action' ) && Settings::is_configured() && Settings::product_sync_enabled() && Settings::fallback_sku_enabled() && ProductSync::count_without_sku() > 0 ) {
			ProductSync::queue_all();
		}
	}

	private static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = EventStore::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				event_id varchar(64) NOT NULL,
				type varchar(64) NOT NULL,
				source varchar(16) NOT NULL DEFAULT 'webhook',
				payload longtext NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				error text NULL,
				received_at datetime NOT NULL,
				processed_at datetime NULL,
				PRIMARY KEY  (event_id),
				KEY status (status),
				KEY received_at (received_at)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
