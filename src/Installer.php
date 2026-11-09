<?php
/**
 * Activation, deactivation and schema.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce;

use SkladX\WooCommerce\Webhooks\EventStore;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the event inbox table and removes scheduled actions on deactivation.
 */
final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'skladx_db_version';

	/** Action Scheduler hooks owned by the plugin. */
	public const HOOKS = array(
		'skladx_export_order',
		'skladx_sync_product',
		'skladx_sync_products_batch',
		'skladx_process_event',
		'skladx_poll_events',
		'skladx_prune_events',
	);

	public static function activate(): void {
		self::install_schema();
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'skladx_poll_events' );
			as_unschedule_all_actions( 'skladx_prune_events' );
		}
	}

	/**
	 * Runs on every load; cheap when the schema is current.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install_schema();
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
