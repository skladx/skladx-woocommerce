<?php
/**
 * Inbox of received SkladX events (webhooks and polling), used for dedupe.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * One row per `evt_…` id. The primary key makes `INSERT IGNORE` an atomic
 * "first time we see this event?" check, so a webhook delivered twice, or a
 * webhook and the polling fallback seeing the same event, is processed once.
 */
final class EventStore {

	public const RETENTION_DAYS = 30;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'skladx_events';
	}

	/**
	 * Stores the event. Returns false when it was already stored (duplicate).
	 *
	 * @param array<string, mixed> $envelope Full event envelope `{id, type, created_at, test, data}`.
	 */
	public static function insert( string $event_id, string $type, array $envelope, string $source ): bool {
		global $wpdb;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . ' (event_id, type, source, payload, status, received_at) VALUES (%s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$event_id,
				$type,
				$source,
				wp_json_encode( $envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'pending',
				current_time( 'mysql', true )
			)
		);

		return 1 === (int) $inserted;
	}

	/**
	 * @return array{event_id: string, type: string, status: string, envelope: array<string, mixed>}|null
	 */
	public static function find( string $event_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT event_id, type, status, payload FROM ' . self::table() . ' WHERE event_id = %s', $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $row ) ) {
			return null;
		}

		$envelope = json_decode( (string) $row['payload'], true );

		return array(
			'event_id' => (string) $row['event_id'],
			'type'     => (string) $row['type'],
			'status'   => (string) $row['status'],
			'envelope' => is_array( $envelope ) ? $envelope : array(),
		);
	}

	public static function mark( string $event_id, string $status, ?string $error = null ): void {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'       => $status,
				'error'        => null !== $error ? mb_substr( $error, 0, 1000 ) : null,
				'processed_at' => current_time( 'mysql', true ),
			),
			array( 'event_id' => $event_id )
		);
	}

	/**
	 * @return array<string, int> status => count
	 */
	public static function counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY status', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	public static function prune(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE received_at < %s', gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
