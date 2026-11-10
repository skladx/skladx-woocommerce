<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Pickup\Cache;
use SkladX\WooCommerce\Pickup\PickupPoint;
use SkladX\WooCommerce\Pickup\PickupPointService;

final class PickupPointServiceTest extends TestCase {

	private ArrayCache $cache;

	/** @var list<array<string, scalar>> */
	private array $searches = array();

	/** @var list<string> */
	private array $lookups = array();

	/** @var \Throwable|null */
	private $search_error = null;

	/** @var \Throwable|null */
	private $lookup_error = null;

	protected function setUp(): void {
		$this->cache = new ArrayCache();
	}

	private function service(): PickupPointService {
		return new PickupPointService(
			function ( array $query ): array {
				$this->searches[] = $query;

				if ( $this->search_error ) {
					throw $this->search_error;
				}

				return array(
					'data' => array(
						PickupPointTest::api_point(),
						PickupPointTest::api_point( array( 'id' => 'packeta:2', 'external_id' => '2', 'is_active' => false ) ),
						array( 'name' => 'broken, no id' ),
					),
					'meta' => array( 'next_cursor' => 'abc', 'has_more' => true ),
				);
			},
			function ( string $carrier, string $id ): array {
				$this->lookups[] = $carrier . ':' . $id;

				if ( $this->lookup_error ) {
					throw $this->lookup_error;
				}

				return PickupPointTest::api_point( array( 'id' => $carrier . ':' . $id, 'external_id' => $id ) );
			},
			$this->cache
		);
	}

	public function test_search_returns_active_normalized_points_and_caches_per_query(): void {
		$query  = array( 'carrier' => 'packeta', 'country' => 'SK', 'limit' => 20, 'q' => 'Bratislava' );
		$result = $this->service()->search( $query );

		$this->assertCount( 1, $result['points'] );
		$this->assertSame( '18579', $result['points'][0]['id'] );
		$this->assertSame( 'abc', $result['next_cursor'] );
		$this->assertTrue( $result['has_more'] );

		$this->assertSame( $result, $this->service()->search( $query ) );
		$this->assertCount( 1, $this->searches, 'Second identical search is served from the cache.' );

		$this->service()->search( array_merge( $query, array( 'q' => 'Nitra' ) ) );
		$this->assertCount( 2, $this->searches );
		$this->assertSame( PickupPointService::SEARCH_TTL, $this->cache->ttl[ PickupPoint::cache_key( $query ) ] );
	}

	public function test_points_from_a_search_are_found_without_an_api_call(): void {
		$this->service()->search( array( 'carrier' => 'packeta', 'country' => 'SK', 'limit' => 20 ) );

		$point = $this->service()->find( 'packeta', 'packeta:18579' );

		$this->assertSame( 'Z-BOX Bratislava, Obchodná 12', $point['name'] );
		$this->assertSame( array(), $this->lookups );
	}

	public function test_find_asks_skladx_once_and_returns_null_for_unknown_points(): void {
		$this->assertSame( '55', $this->service()->find( 'packeta', '55' )['id'] );
		$this->service()->find( 'packeta', '55' );
		$this->assertSame( array( 'packeta:55' ), $this->lookups );

		$this->service()->find( 'packeta', '55', true );
		$this->assertCount( 2, $this->lookups, 'A fresh lookup skips the cache.' );

		$this->lookup_error = new ApiException( 'Not found', 404, 'not_found' );
		$this->assertNull( $this->service()->find( 'packeta', '404404' ) );
	}

	public function test_network_errors_are_not_hidden(): void {
		$this->lookup_error = new ApiException( 'timeout' );

		$this->expectException( ApiException::class );
		$this->service()->find( 'packeta', '77' );
	}

	public function test_failed_search_is_not_cached(): void {
		$this->search_error = new ApiException( 'Server error', 500, 'server_error' );

		try {
			$this->service()->search( array( 'carrier' => 'packeta' ) );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 500, $exception->status );
		}

		$this->search_error = null;
		$this->assertCount( 1, $this->service()->search( array( 'carrier' => 'packeta' ) )['points'] );
		$this->assertCount( 2, $this->searches );
	}
}

/**
 * In-memory cache for the tests.
 */
final class ArrayCache implements Cache {

	/** @var array<string, mixed> */
	public array $data = array();

	/** @var array<string, int> */
	public array $ttl = array();

	public function get( string $key ) {
		return $this->data[ $key ] ?? null;
	}

	public function set( string $key, $value, int $ttl ): void {
		$this->data[ $key ] = $value;
		$this->ttl[ $key ]  = $ttl;
	}
}
