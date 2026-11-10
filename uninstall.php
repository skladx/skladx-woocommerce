<?php
/**
 * Uninstall: removes plugin options, the event inbox table and scheduled
 * actions. Order and product meta (SkladX ids, tracking numbers) are kept on
 * purpose — they are part of the order history.
 *
 * @package SkladX\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$skladx_options = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'skladx_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( $skladx_options as $skladx_option ) {
	delete_option( $skladx_option );
}

// Cached pickup point searches.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_skladx_pp' ) . '%', $wpdb->esc_like( '_transient_timeout_skladx_pp' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}skladx_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'skladx_export_order', 'skladx_sync_product', 'skladx_sync_products_batch', 'skladx_pull_stock', 'skladx_process_event', 'skladx_poll_events', 'skladx_prune_events' ) as $skladx_hook ) {
		as_unschedule_all_actions( $skladx_hook );
	}
}
