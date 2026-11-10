<?php
/**
 * Cached pickup point search and lookup against SkladX.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Pickup;

use SkladX\WooCommerce\Api\ApiException;

/**
 * Pure PHP with injected API calls and cache — unit tested.
 *
 * Every search answer is cached per query; every point it contains is also
 * cached on its own, so choosing a point from the list and validating it at
 * checkout normally needs no further API call.
 */
final class PickupPointService {

	/** Seconds a search answer is cached. */
	public const SEARCH_TTL = 3600;

	/** Seconds a single point is cached. */
	public const POINT_TTL = 21600;

	/** @var callable(array<string, scalar>): array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
	private $search_api;

	/** @var callable(string, string): array<string, mixed> */
	private $point_api;

	/**
	 * @param callable $search_api `GET /pickup-points` (query → {data, meta}); throws ApiException.
	 * @param callable $point_api  `GET /pickup-points/{carrier}/{id}` (→ point); throws ApiException.
	 * @param Cache    $cache      Cache.
	 */
	public function __construct( callable $search_api, callable $point_api, private readonly Cache $cache ) {
		$this->search_api = $search_api;
		$this->point_api  = $point_api;
	}

	/**
	 * @param array<string, scalar> $query Sanitised query (PickupPoint::sanitize_query()).
	 * @return array{points: list<array<string, mixed>>, next_cursor: string|null, has_more: bool}
	 *
	 * @throws ApiException When SkladX cannot be reached or rejects the query.
	 */
	public function search( array $query ): array {
		$key    = PickupPoint::cache_key( $query );
		$cached = $this->cache->get( $key );

		if ( is_array( $cached ) && isset( $cached['points'] ) ) {
			return $cached;
		}

		$response = ( $this->search_api )( $query );
		$points   = array();

		foreach ( $response['data'] as $raw ) {
			$point = PickupPoint::normalize( $raw, (string) ( $query['carrier'] ?? '' ) );

			if ( null !== $point && $point['active'] ) {
				$points[] = $point;
				$this->cache->set( PickupPoint::point_cache_key( $point['carrier'], $point['id'] ), $point, self::POINT_TTL );
			}
		}

		$next   = $response['meta']['next_cursor'] ?? null;
		$result = array(
			'points'      => $points,
			'next_cursor' => is_string( $next ) && '' !== $next ? $next : null,
			'has_more'    => ! empty( $response['meta']['has_more'] ) && is_string( $next ) && '' !== $next,
		);

		$this->cache->set( $key, $result, self::SEARCH_TTL );

		return $result;
	}

	/**
	 * One point by carrier and id (plain or `carrier:id`).
	 *
	 * @param bool $fresh Skip the cache (and refresh it).
	 * @return array<string, mixed>|null Null when SkladX does not know the point.
	 *
	 * @throws ApiException On network errors and unexpected API errors (not 404).
	 */
	public function find( string $carrier, string $id, bool $fresh = false ): ?array {
		$id = str_starts_with( $id, $carrier . ':' ) ? substr( $id, strlen( $carrier ) + 1 ) : $id;

		if ( '' === $carrier || '' === $id ) {
			return null;
		}

		$key = PickupPoint::point_cache_key( $carrier, $id );

		if ( ! $fresh ) {
			$cached = $this->cache->get( $key );

			if ( is_array( $cached ) && isset( $cached['id'] ) ) {
				return $cached;
			}
		}

		try {
			$point = PickupPoint::normalize( ( $this->point_api )( $carrier, $id ), $carrier );
		} catch ( ApiException $exception ) {
			if ( 404 === $exception->status ) {
				return null;
			}

			throw $exception;
		}

		if ( null !== $point ) {
			$this->cache->set( $key, $point, self::POINT_TTL );
		}

		return $point;
	}
}
