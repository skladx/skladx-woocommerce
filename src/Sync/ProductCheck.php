<?php
/**
 * Periodic catalogue check: sends products SkladX does not have yet.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * "Periodic product check" (setting, per shop: on/off and how often).
 *
 * Products are pushed when they are saved (ProductSync). Products that never
 * trigger a save hook — imports writing straight into the database, feed /
 * ERP plugins, a push that failed while SkladX was unreachable — would stay
 * missing in the warehouse, which then cannot receive goods the client
 * brings without a receipt. The check walks the whole catalogue in Action
 * Scheduler batches and calls ProductSync::sync() WITHOUT force: unchanged
 * products already in SkladX cost no request, only new or changed ones are
 * sent.
 */
final class ProductCheck {

	public const HOOK       = 'skladx_product_check';
	public const BATCH_HOOK = 'skladx_product_check_batch';
	public const GROUP      = 'skladx';

	/** Allowed intervals in hours. */
	public const INTERVALS = array( 1, 6, 12, 24, 168 );

	public const DEFAULT_INTERVAL = 24;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'start' ) );
		add_action( self::BATCH_HOOK, array( self::class, 'batch' ), 10, 1 );
		add_action( 'action_scheduler_init', array( self::class, 'schedule' ) );

		foreach ( array( 'skladx_product_check', 'skladx_product_check_interval', 'skladx_product_sync' ) as $option ) {
			add_action( 'update_option_' . $option, array( self::class, 'reschedule' ) );
			add_action( 'add_option_' . $option, array( self::class, 'reschedule' ) );
		}
	}

	/**
	 * Interval of the check in hours (one of INTERVALS).
	 */
	public static function interval_hours(): int {
		$hours = (int) Settings::get( 'product_check_interval' );

		return in_array( $hours, self::INTERVALS, true ) ? $hours : self::DEFAULT_INTERVAL;
	}

	/**
	 * Whether the check runs: switched on, product sync on, plugin connected.
	 */
	public static function enabled(): bool {
		return 'yes' === Settings::get( 'product_check' ) && Settings::product_sync_enabled() && Settings::is_configured();
	}

	/**
	 * Keeps exactly one recurring action with the configured interval (or none).
	 */
	public static function schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$seconds   = self::interval_hours() * HOUR_IN_SECONDS;
		$scheduled = (int) Settings::get( 'product_check_scheduled' );
		$exists    = as_has_scheduled_action( self::HOOK, array(), self::GROUP );

		if ( ! self::enabled() ) {
			if ( $exists ) {
				as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			}

			return;
		}

		if ( $exists && $scheduled === $seconds ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		// First run shortly after switching on, then every interval.
		as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, $seconds, self::HOOK, array(), self::GROUP );
		Settings::set( 'product_check_scheduled', $seconds );
	}

	/**
	 * A setting changed: apply the new schedule.
	 */
	public static function reschedule(): void {
		Settings::set( 'product_check_scheduled', 0 );
		self::schedule();
	}

	/**
	 * Recurring action: starts a pass over the catalogue (unless one runs).
	 */
	public static function start(): void {
		if ( ! self::enabled() || as_has_scheduled_action( self::BATCH_HOOK, null, self::GROUP ) ) {
			return;
		}

		Settings::set(
			'product_check_state',
			array(
				'started_at'  => time(),
				'finished_at' => 0,
				'counts'      => array(),
			)
		);
		as_enqueue_async_action( self::BATCH_HOOK, array( 1 ), self::GROUP );
	}

	/**
	 * One page of the catalogue: new or changed products are sent.
	 *
	 * @param int $page 1-based page.
	 */
	public static function batch( $page ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$ids   = ProductSync::catalogue_page( (int) $page );
		$state = (array) Settings::get( 'product_check_state' );
		$count = (array) ( $state['counts'] ?? array() );

		foreach ( $ids as $id ) {
			$result           = ProductSync::sync( $id, false, false );
			$count[ $result ] = (int) ( $count[ $result ] ?? 0 ) + 1;
		}

		$state['counts'] = $count;
		$last            = count( $ids ) < ProductSync::BATCH_SIZE;

		if ( $last ) {
			$state['finished_at'] = time();
			Logger::info( 'Periodic product check finished: ' . wp_json_encode( $count ) );
		} else {
			as_enqueue_async_action( self::BATCH_HOOK, array( (int) $page + 1 ), self::GROUP );
		}

		Settings::set( 'product_check_state', $state );
	}
}
