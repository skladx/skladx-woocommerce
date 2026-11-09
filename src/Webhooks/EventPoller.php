<?php
/**
 * Pull fallback for missed webhooks: GET /events?after=<last evt_…>.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Webhooks;

use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Api\Client;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Every INTERVAL seconds fetches the events newer than the last one seen,
 * stores the ones the webhook did not deliver (same inbox, same dedupe) and
 * queues them for processing in chronological order.
 *
 * The API lists newest first, so all pages are collected and reversed. On the
 * very first run the cursor is only initialised (no history backfill).
 */
final class EventPoller {

	public const HOOK      = 'skladx_poll_events';
	public const PRUNE     = 'skladx_prune_events';
	public const GROUP     = 'skladx';
	public const INTERVAL  = 600;
	public const MAX_PAGES = 10;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'poll' ) );
		add_action( self::PRUNE, array( EventStore::class, 'prune' ) );
		add_action( 'action_scheduler_init', array( self::class, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! Settings::is_configured() ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + 60, self::INTERVAL, self::HOOK, array(), self::GROUP );
		}

		if ( ! as_has_scheduled_action( self::PRUNE, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::PRUNE, array(), self::GROUP );
		}
	}

	/**
	 * @return int Number of new events found.
	 */
	public static function poll(): int {
		if ( ! Settings::is_configured() ) {
			return 0;
		}

		$client = Client::from_settings();
		$cursor = (string) Settings::get( 'events_cursor' );

		try {
			if ( '' === $cursor ) {
				$latest = $client->events( null, 1 )['data'][0]['id'] ?? '';
				Settings::set( 'events_cursor', (string) $latest );

				return 0;
			}

			$events = array();
			$page   = null;

			for ( $i = 0; $i < self::MAX_PAGES; $i++ ) {
				$response = $client->events( $cursor, 100, $page );
				$events   = array_merge( $events, $response['data'] );
				$page     = $response['meta']['next_cursor'] ?? null;

				if ( empty( $response['meta']['has_more'] ) || empty( $page ) ) {
					break;
				}
			}
		} catch ( ApiException $exception ) {
			Logger::error( 'Polling SkladX events failed: ' . $exception->summary() );

			return 0;
		}

		if ( array() === $events ) {
			return 0;
		}

		$new = 0;

		foreach ( array_reverse( $events ) as $envelope ) {
			$event_id = (string) ( $envelope['id'] ?? '' );
			$type     = (string) ( $envelope['type'] ?? '' );

			if ( '' === $event_id || '' === $type ) {
				continue;
			}

			if ( EventStore::insert( $event_id, $type, $envelope, 'poll' ) ) {
				++$new;
				'ping' === $type ? EventStore::mark( $event_id, 'processed' ) : EventProcessor::queue( $event_id );
			}
		}

		Settings::set( 'events_cursor', (string) ( $events[0]['id'] ?? $cursor ) );

		if ( $new > 0 ) {
			Logger::warning( sprintf( 'Polling found %d SkladX event(s) not delivered by webhook.', $new ) );
		}

		return $new;
	}
}
