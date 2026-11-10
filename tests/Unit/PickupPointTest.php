<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Pickup\PickupPoint;

final class PickupPointTest extends TestCase {

	/**
	 * Point as returned by GET /pickup-points.
	 *
	 * @return array<string, mixed>
	 */
	public static function api_point( array $overrides = array() ): array {
		return array_replace(
			array(
				'id'            => 'packeta:18579',
				'carrier'       => 'packeta',
				'network'       => 'packeta_zbox',
				'external_id'   => '18579',
				'type'          => 'locker',
				'name'          => 'Z-BOX Bratislava, Obchodná 12',
				'address'       => array( 'street' => 'Obchodná 12', 'city' => 'Bratislava', 'postal_code' => '811 06', 'country' => 'SK' ),
				'location'      => array( 'lat' => 48.1486, 'lng' => 17.1077 ),
				'distance_km'   => 0.8412,
				'opening_hours' => array( 'mon' => array( array( '00:00', '24:00' ) ) ),
				'supports'      => array( 'cod' => true, 'card' => true, 'drop_off' => true ),
				'photo_url'     => null,
				'is_active'     => true,
				'closing_on'    => null,
			),
			$overrides
		);
	}

	public function test_normalizes_api_point(): void {
		$point = PickupPoint::normalize( self::api_point() );

		$this->assertSame( '18579', $point['id'] );
		$this->assertSame( 'packeta', $point['carrier'] );
		$this->assertSame( 'packeta:18579', $point['ref'] );
		$this->assertSame( 'locker', $point['type'] );
		$this->assertSame( '811 06', $point['postal_code'] );
		$this->assertSame( 'SK', $point['country'] );
		$this->assertSame( 48.1486, $point['lat'] );
		$this->assertSame( 0.84, $point['distance_km'] );
		$this->assertTrue( $point['cod'] );
		$this->assertTrue( $point['active'] );
		$this->assertSame( 'Obchodná 12, 811 06 Bratislava', PickupPoint::address_line( $point ) );
	}

	public function test_normalizes_other_shapes_defensively(): void {
		// Documented draft shape: address.zip, composite id only, no supports.
		$point = PickupPoint::normalize(
			array(
				'id'      => 'packeta:100',
				'name'    => 'Výdajné miesto',
				'address' => array( 'street' => 'Hlavná 1', 'city' => 'Košice', 'zip' => '040 01', 'country' => 'sk' ),
			)
		);

		$this->assertSame( '100', $point['id'] );
		$this->assertSame( 'packeta', $point['carrier'] );
		$this->assertSame( '040 01', $point['postal_code'] );
		$this->assertSame( 'SK', $point['country'] );
		$this->assertNull( $point['cod'] );
		$this->assertNull( $point['lat'] );

		// Flat fields and the carrier taken from the query.
		$flat = PickupPoint::normalize( array( 'id' => '7', 'name' => 'X', 'city' => 'Nitra', 'lat' => '48.3', 'lng' => '18.08', 'supports_cod' => false ), 'packeta' );

		$this->assertSame( 'packeta:7', $flat['ref'] );
		$this->assertSame( 'Nitra', $flat['city'] );
		$this->assertSame( 48.3, $flat['lat'] );
		$this->assertFalse( $flat['cod'] );

		$this->assertNull( PickupPoint::normalize( array( 'name' => 'no id' ), 'packeta' ) );
		$this->assertFalse( PickupPoint::normalize( self::api_point( array( 'is_active' => false ) ) )['active'] );
	}

	public function test_sanitizes_proxy_query(): void {
		$query = PickupPoint::sanitize_query(
			array( 'carrier' => 'Packeta', 'q' => "  Banská   Bystrica ", 'cod' => '1', 'cursor' => 'eyJpZCI6MjB9', 'foo' => 'bar' ),
			array( 'packeta' ),
			'SK'
		);

		$this->assertSame(
			array( 'carrier' => 'packeta', 'country' => 'SK', 'limit' => PickupPoint::PAGE_SIZE, 'q' => 'Banská Bystrica', 'cod' => 1, 'cursor' => 'eyJpZCI6MjB9' ),
			$query
		);

		$near = PickupPoint::sanitize_query( array( 'carrier' => 'packeta', 'near' => '48.148612, 17.107712', 'country' => 'cz', 'cod' => 'false', 'cursor' => 'bad cursor!' ), array( 'packeta' ), 'SK' );

		$this->assertSame( '48.149,17.108', $near['near'] );
		$this->assertSame( PickupPoint::NEAR_RADIUS_KM, $near['radius_km'] );
		$this->assertSame( 'CZ', $near['country'] );
		$this->assertArrayNotHasKey( 'cod', $near );
		$this->assertArrayNotHasKey( 'cursor', $near );
		$this->assertSame( 100, mb_strlen( PickupPoint::sanitize_query( array( 'carrier' => 'packeta', 'q' => str_repeat( 'á', 300 ) ), array( 'packeta' ), 'SK' )['q'] ) );
	}

	/**
	 * @return iterable<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalid_queries(): iterable {
		yield 'carrier not allowed' => array( array( 'carrier' => 'dpd_sk' ), 'invalid_carrier' );
		yield 'no carrier' => array( array(), 'invalid_carrier' );
		yield 'bad near' => array( array( 'carrier' => 'packeta', 'near' => 'Bratislava' ), 'invalid_near' );
		yield 'near out of range' => array( array( 'carrier' => 'packeta', 'near' => '95,17' ), 'invalid_near' );
		yield 'bad country' => array( array( 'carrier' => 'packeta', 'country' => 'Slovakia' ), 'invalid_country' );
	}

	/**
	 * @dataProvider invalid_queries
	 * @param array<string, mixed> $params Params.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_queries' )]
	public function test_rejects_invalid_queries( array $params, string $code ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $code );

		PickupPoint::sanitize_query( $params, array( 'packeta' ), 'SK' );
	}

	public function test_cache_key_ignores_argument_order(): void {
		$this->assertSame(
			PickupPoint::cache_key( array( 'carrier' => 'packeta', 'q' => 'Nitra' ) ),
			PickupPoint::cache_key( array( 'q' => 'Nitra', 'carrier' => 'packeta' ) )
		);
		$this->assertNotSame( PickupPoint::cache_key( array( 'q' => 'Nitra' ) ), PickupPoint::cache_key( array( 'q' => 'Trnava' ) ) );
	}

	public function test_validates_chosen_point(): void {
		$point = PickupPoint::normalize( self::api_point() );

		$this->assertSame( '', PickupPoint::problem( $point, 'packeta', true ) );
		$this->assertSame( 'missing', PickupPoint::problem( null, 'packeta', false ) );
		$this->assertSame( 'wrong_carrier', PickupPoint::problem( $point, 'gls_sk', false ) );
		$this->assertSame( 'inactive', PickupPoint::problem( array_merge( $point, array( 'active' => false ) ), 'packeta', false ) );
		$this->assertSame( 'no_cod', PickupPoint::problem( array_merge( $point, array( 'cod' => false ) ), 'packeta', true ) );
		$this->assertSame( '', PickupPoint::problem( array_merge( $point, array( 'cod' => false ) ), 'packeta', false ) );
		// Unknown COD support is left to SkladX.
		$this->assertSame( '', PickupPoint::problem( array_merge( $point, array( 'cod' => null ) ), 'packeta', true ) );
	}

	public function test_finds_carrier_of_chosen_rate(): void {
		$methods = array( 'flat_rate:1' => 'packeta', 'packeta_pickup' => 'packeta' );

		$this->assertSame( 'packeta', PickupPoint::carrier_for_rate( 'flat_rate:1', 'flat_rate', '1', $methods ) );
		$this->assertSame( 'packeta', PickupPoint::carrier_for_rate( 'flat_rate:1', '', '', $methods ) );
		$this->assertSame( '', PickupPoint::carrier_for_rate( 'flat_rate:2', 'flat_rate', '2', $methods ) );
		$this->assertSame( 'packeta', PickupPoint::carrier_for_rate( 'packeta_pickup:5:zbox', 'packeta_pickup', '5', $methods ) );
	}

	public function test_formats_opening_hours(): void {
		$labels = array( 'mon' => 'Po', 'tue' => 'Ut', 'wed' => 'St', 'thu' => 'Št', 'fri' => 'Pi', 'sat' => 'So', 'sun' => 'Ne', 'closed' => 'zatvorené', 'nonstop' => 'nonstop' );
		$week   = array( array( '09:00', '12:00' ), array( '13:00', '17:00' ) );

		$this->assertSame(
			array( 'Po–Pi 09:00–12:00, 13:00–17:00', 'So 09:00–12:00', 'Ne zatvorené' ),
			PickupPoint::opening_hours_lines(
				array( 'mon' => $week, 'tue' => $week, 'wed' => $week, 'thu' => $week, 'fri' => $week, 'sat' => array( array( '09:00', '12:00' ) ), 'sun' => array(), 'exceptions' => array() ),
				$labels
			)
		);

		$nonstop = array_fill_keys( PickupPoint::DAYS, array( array( '00:00', '24:00' ) ) );
		$this->assertSame( array( 'Po–Ne nonstop' ), PickupPoint::opening_hours_lines( $nonstop, $labels ) );
		$this->assertSame( array( 'Po nonstop' ), PickupPoint::opening_hours_lines( array( 'mon' => array( array( '00:00', '24:00' ) ) ), $labels ) );
		$this->assertSame( array(), PickupPoint::opening_hours_lines( null, $labels ) );
		$this->assertSame( array(), PickupPoint::opening_hours_lines( array( 'exceptions' => array() ), $labels ) );
	}

	public function test_widget_fallback_is_marked_unverified(): void {
		$point = PickupPoint::from_widget( 'packeta', '123', array( 'name' => 'Packeta Nitra', 'street' => 'Štefánikova 1', 'city' => 'Nitra', 'zip' => '949 01', 'country' => 'sk' ) );

		$this->assertSame( '123', $point['id'] );
		$this->assertSame( '949 01', $point['postal_code'] );
		$this->assertTrue( $point['unverified'] );
		$this->assertNull( PickupPoint::from_widget( 'packeta', '123', array() ) );
	}
}
