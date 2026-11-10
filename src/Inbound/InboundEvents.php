<?php
/**
 * Applies `inbound.*` events (webhook, polling) and refreshed receipts to the receipt cache.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

/**
 * Pure PHP (no WordPress) — unit tested.
 *
 * Every source ends here: webhook and `GET /events` polling (via
 * EventProcessor) and the periodic receipt refresh. The cache keeps the last
 * known summary per receipt; `notify` is called once per status change, so a
 * webhook and the poll delivering the same change produce one admin notice.
 * Stale data (older `updated_at` than cached) is ignored. The periodic
 * refresh does not announce receipts it sees for the first time, and what
 * the shop manager does or sees in the admin (SOURCE_ADMIN) is only cached.
 */
final class InboundEvents {

	public const TYPES = array( 'inbound.announced', 'inbound.arrived', 'inbound.received', 'inbound.cancelled' );

	public const SOURCE_EVENT   = 'event';
	public const SOURCE_REFRESH = 'refresh';

	/** Receipt loaded or changed by the shop manager in the admin: cached, never announced. */
	public const SOURCE_ADMIN = 'admin';

	/** @var callable(array<string, mixed>, array<string, mixed>|null): void */
	private $notify;

	/**
	 * @param ReceiptStore                                                $store  Cache.
	 * @param callable(array<string, mixed>, array<string, mixed>|null):void $notify Called with (normalised receipt, previous summary|null) on a status change.
	 */
	public function __construct( private readonly ReceiptStore $store, callable $notify ) {
		$this->notify = $notify;
	}

	public static function handles( string $type ): bool {
		return str_starts_with( $type, 'inbound.' );
	}

	/**
	 * @param string               $type Event type (`inbound.received`…).
	 * @param array<string, mixed> $data Event data.
	 * @return string processed|ignored
	 */
	public function handle_event( string $type, array $data ): string {
		$raw = self::handles( $type ) ? Receipt::from_event( $data ) : null;

		return null === $raw ? 'ignored' : $this->apply( Receipt::normalize( $raw ), self::SOURCE_EVENT );
	}

	/**
	 * @param array<string, mixed> $receipt Normalised receipt.
	 * @param string               $source  SOURCE_EVENT|SOURCE_REFRESH|SOURCE_ADMIN.
	 * @return string processed|ignored
	 */
	public function apply( array $receipt, string $source = self::SOURCE_REFRESH ): string {
		$id = (string) ( $receipt['id'] ?? '' );

		if ( '' === $id || '' === (string) ( $receipt['status'] ?? '' ) ) {
			return 'ignored';
		}

		$previous = $this->store->get( $id );

		if ( null !== $previous && self::is_older( (string) ( $receipt['updated_at'] ?? '' ), (string) ( $previous['updated_at'] ?? '' ) ) ) {
			return 'ignored';
		}

		$summary = self::summary( $receipt );

		if ( null !== $previous && self::same( $summary, $previous ) ) {
			return 'ignored';
		}

		$this->store->put( $id, $summary );

		$status_changed = null === $previous || $previous['status'] !== $summary['status'];

		if ( $status_changed && self::SOURCE_ADMIN !== $source && ( null !== $previous || self::SOURCE_EVENT === $source ) ) {
			( $this->notify )( $receipt, $previous );
		}

		return 'processed';
	}

	/**
	 * What the cache keeps about a receipt.
	 *
	 * @param array<string, mixed> $receipt Normalised receipt.
	 * @return array<string, mixed>
	 */
	public static function summary( array $receipt ): array {
		$totals = (array) ( $receipt['totals'] ?? array() );

		return array(
			'id'              => (string) ( $receipt['id'] ?? '' ),
			'number'          => (string) ( $receipt['number'] ?? '' ),
			'name'            => (string) ( $receipt['name'] ?? '' ),
			'status'          => (string) ( $receipt['status'] ?? '' ),
			'status_label'    => (string) ( $receipt['status_label'] ?? '' ),
			'expected_at'     => (string) ( $receipt['expected_at'] ?? '' ),
			'carrier'         => (string) ( $receipt['carrier'] ?? '' ),
			'tracking_number' => (string) ( $receipt['tracking_number'] ?? '' ),
			'expected'        => (int) ( $totals['expected'] ?? 0 ),
			'received'        => isset( $totals['received'] ) ? (int) $totals['received'] : null,
			'has_differences' => (bool) ( $receipt['has_differences'] ?? false ),
			'created_at'      => (string) ( $receipt['created_at'] ?? '' ),
			'updated_at'      => (string) ( $receipt['updated_at'] ?? '' ),
		);
	}

	/**
	 * @param array<string, mixed> $a Summary.
	 * @param array<string, mixed> $b Summary.
	 */
	private static function same( array $a, array $b ): bool {
		ksort( $a );
		ksort( $b );

		return $a == $b; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- arrays compared by value.
	}

	private static function is_older( string $candidate, string $known ): bool {
		$candidate_time = '' !== $candidate ? strtotime( $candidate ) : false;
		$known_time     = '' !== $known ? strtotime( $known ) : false;

		return false !== $candidate_time && false !== $known_time && $candidate_time < $known_time;
	}
}
