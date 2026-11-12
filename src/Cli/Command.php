<?php
/**
 * WP-CLI commands: `wp skladx …`.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Cli;

use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the SkladX integration.
 */
final class Command {

	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'skladx', self::class );
		}
	}

	/**
	 * Sends all products and variations to SkladX.
	 *
	 * Every status except trash; products without a SKU are sent as WC-{id}
	 * unless "Products without SKU" is off.
	 *
	 * ## OPTIONS
	 *
	 * [--queue]
	 * : Queue the sync in Action Scheduler (like the "Sync all products" button) instead of running it now.
	 *
	 * [--batch-size=<n>]
	 * : Products per database page when running now.
	 * ---
	 * default: 100
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp skladx sync-products
	 *     wp skladx sync-products --queue
	 *
	 * @subcommand sync-products
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function sync_products( $args, $assoc_args ): void {
		if ( ! Settings::is_configured() ) {
			\WP_CLI::error( 'SkladX is not configured (API base URL and API key).' );
		}

		if ( ! empty( $assoc_args['queue'] ) ) {
			ProductSync::queue_all();
			\WP_CLI::success( 'Full product sync queued (Action Scheduler group "skladx").' );

			return;
		}

		$size   = max( 1, (int) ( $assoc_args['batch-size'] ?? 100 ) );
		$page   = 1;
		$counts = array();

		ProductSync::start_full_sync();

		do {
			$ids = ProductSync::catalogue_page( $page, $size );

			foreach ( $ids as $id ) {
				$result            = ProductSync::sync( $id, false, true );
				$counts[ $result ] = ( $counts[ $result ] ?? 0 ) + 1;

				if ( ProductSync::RESULT_FAILED === $result ) {
					\WP_CLI::warning( sprintf( 'Product %d: %s', $id, (string) get_post_meta( $id, ProductSync::ERROR_META, true ) ) );
				} elseif ( ProductSync::RESULT_NO_SKU === $result ) {
					\WP_CLI::log( sprintf( 'Product %d skipped: no SKU.', $id ) );
				}
			}

			\WP_CLI::log( sprintf( 'Page %d: %d products.', $page, count( $ids ) ) );
			++$page;

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( count( $ids ) === $size );

		Settings::set(
			'full_sync',
			array(
				'started_at'  => (int) ( ( (array) Settings::get( 'full_sync' ) )['started_at'] ?? time() ),
				'finished_at' => time(),
				'counts'      => $counts,
			)
		);

		$summary = sprintf(
			'%d sent, %d failed, %d without SKU skipped.',
			$counts[ ProductSync::RESULT_SYNCED ] ?? 0,
			$counts[ ProductSync::RESULT_FAILED ] ?? 0,
			$counts[ ProductSync::RESULT_NO_SKU ] ?? 0
		);

		if ( ! empty( $counts[ ProductSync::RESULT_FAILED ] ) ) {
			\WP_CLI::warning( $summary );
		} else {
			\WP_CLI::success( $summary );
		}
	}
}
