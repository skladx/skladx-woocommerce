<?php
/**
 * SkladX inbound receipt: normalisation and status rules.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

/**
 * Pure PHP (no WordPress) — unit tested.
 *
 * Defensive about the API shape so the admin works with every SkladX
 * version: `is_blind` or `blind`, `warehouse` as an object or a code,
 * item `product_name` or `name`, missing `quantity_received`.
 */
final class Receipt {

	public const DRAFT      = 'draft';
	public const ANNOUNCED  = 'announced';
	public const IN_TRANSIT = 'in_transit';
	public const ARRIVED    = 'arrived';
	public const RECEIVING  = 'receiving';
	public const RECEIVED   = 'received';
	public const CANCELLED  = 'cancelled';

	/** Statuses in their natural order (list filter). */
	public const STATUSES = array( self::DRAFT, self::ANNOUNCED, self::IN_TRANSIT, self::ARRIVED, self::RECEIVING, self::RECEIVED, self::CANCELLED );

	/** Statuses after which nothing changes any more. */
	public const FINAL_STATUSES = array( self::RECEIVED, self::CANCELLED );

	/**
	 * @param array<string, mixed> $raw Receipt from the API.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $raw ): array {
		$status    = self::text( $raw['status'] ?? '' );
		$warehouse = $raw['warehouse'] ?? null;
		$items     = array();
		$expected  = 0;
		$received  = null;

		foreach ( is_array( $raw['items'] ?? null ) ? $raw['items'] : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$quantity_expected = (int) ( $item['quantity_expected'] ?? ( $item['quantity'] ?? 0 ) );
			$quantity_received = is_numeric( $item['quantity_received'] ?? null ) ? (int) $item['quantity_received'] : null;
			$expected         += $quantity_expected;

			if ( null !== $quantity_received ) {
				$received = (int) $received + $quantity_received;
			}

			$items[] = array(
				'id'                => self::text( $item['id'] ?? '' ),
				'sku'               => self::text( $item['sku'] ?? '' ),
				'name'              => self::text( $item['product_name'] ?? ( $item['name'] ?? '' ) ),
				'quantity_expected' => $quantity_expected,
				'quantity_received' => $quantity_received,
				'difference'        => null === $quantity_received ? null : $quantity_received - $quantity_expected,
				'batch'             => self::text( $item['batch'] ?? '' ),
				'expires_at'        => substr( self::text( $item['expires_at'] ?? '' ), 0, 10 ),
			);
		}

		$attachments = array();

		foreach ( is_array( $raw['attachments'] ?? null ) ? $raw['attachments'] : array() as $attachment ) {
			if ( is_array( $attachment ) ) {
				$attachments[] = array(
					'id'   => self::text( $attachment['id'] ?? '' ),
					'name' => self::text( $attachment['name'] ?? ( $attachment['filename'] ?? '' ) ),
					'size' => (int) ( $attachment['size'] ?? 0 ),
				);
			}
		}

		/*
		 * Received quantities only mean something once the warehouse has
		 * started counting: SkladX reports 0 for every line of an announced
		 * receipt, which must not read as "received 0, difference -20".
		 */
		$has_received = self::RECEIVED === $status || ( self::RECEIVING === $status && null !== $received );

		return array(
			'id'              => self::text( $raw['id'] ?? '' ),
			'number'          => self::text( $raw['number'] ?? '' ),
			'name'            => self::text( $raw['name'] ?? '' ),
			'reference'       => self::text( $raw['reference'] ?? '' ),
			'status'          => $status,
			'status_label'    => self::text( $raw['status_label'] ?? '' ),
			'expected_at'     => substr( self::text( $raw['expected_at'] ?? '' ), 0, 10 ),
			'carrier'         => self::text( $raw['carrier'] ?? '' ),
			'tracking_number' => self::text( $raw['tracking_number'] ?? '' ),
			'tracking_url'    => self::url( $raw['tracking_url'] ?? '' ),
			'is_blind'        => (bool) ( $raw['is_blind'] ?? ( $raw['blind'] ?? false ) ),
			'note'            => is_scalar( $raw['note'] ?? null ) ? (string) $raw['note'] : '',
			'warehouse'       => array(
				'code' => is_array( $warehouse ) ? self::text( $warehouse['code'] ?? '' ) : self::text( $warehouse ),
				'name' => is_array( $warehouse ) ? self::text( $warehouse['name'] ?? '' ) : '',
			),
			'items'           => $items,
			'attachments'     => $attachments,
			'totals'          => array(
				'lines'      => count( $items ),
				'expected'   => $expected,
				'received'   => $has_received ? (int) $received : null,
				'difference' => $has_received ? (int) $received - $expected : null,
			),
			'has_differences' => $has_received && array() !== array_filter( $items, static fn ( array $item ): bool => 0 !== (int) $item['difference'] || null === $item['quantity_received'] ),
			'editable'        => self::is_editable( $status ),
			'cancellable'     => self::is_cancellable( $status ),
			'announced_at'    => self::text( $raw['announced_at'] ?? '' ),
			'received_at'     => self::text( $raw['received_at'] ?? '' ),
			'created_at'      => self::text( $raw['created_at'] ?? '' ),
			'updated_at'      => self::text( $raw['updated_at'] ?? '' ),
		);
	}

	/**
	 * Only a draft can be changed (PUT) or announced.
	 */
	public static function is_editable( string $status ): bool {
		return self::DRAFT === $status;
	}

	/**
	 * Nothing has been received yet.
	 */
	public static function is_cancellable( string $status ): bool {
		return in_array( $status, array( self::DRAFT, self::ANNOUNCED, self::IN_TRANSIT ), true );
	}

	public static function is_final( string $status ): bool {
		return in_array( $status, self::FINAL_STATUSES, true );
	}

	/**
	 * Badge colour class suffix.
	 */
	public static function tone( string $status ): string {
		switch ( $status ) {
			case self::DRAFT:
				return 'draft';
			case self::ANNOUNCED:
			case self::IN_TRANSIT:
				return 'waiting';
			case self::ARRIVED:
			case self::RECEIVING:
				return 'receiving';
			case self::RECEIVED:
				return 'done';
			case self::CANCELLED:
				return 'cancelled';
			default:
				return 'unknown';
		}
	}

	/**
	 * The receipt inside a webhook/event payload: `{receipt: …}`,
	 * `{inbound_receipt: …}` or the receipt itself.
	 *
	 * @param array<string, mixed> $data Event data.
	 * @return array<string, mixed>|null
	 */
	public static function from_event( array $data ): ?array {
		foreach ( array( 'receipt', 'inbound_receipt' ) as $key ) {
			if ( is_array( $data[ $key ] ?? null ) && '' !== self::text( $data[ $key ]['id'] ?? '' ) ) {
				return $data[ $key ];
			}
		}

		return str_starts_with( self::text( $data['id'] ?? '' ), 'inr_' ) ? $data : null;
	}

	/**
	 * @param mixed $value Raw value.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Only http(s) URLs are kept.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function url( $value ): string {
		$url = self::text( $value );

		return preg_match( '~^https?://~i', $url ) ? $url : '';
	}
}
