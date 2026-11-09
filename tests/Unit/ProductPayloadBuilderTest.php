<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Sync\MappingException;
use SkladX\WooCommerce\Sync\ProductPayloadBuilder;

final class ProductPayloadBuilderTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function product( array $overrides = array() ): array {
		return array_replace(
			array(
				'sku'       => 'KOL-300',
				'name'      => 'Kolagén prášok 300 g',
				'ean'       => '8588 0000 0002 8',
				'weight'    => '0.35',
				'length'    => '12',
				'width'     => '8',
				'height'    => '8,5',
				'price'     => '24.90',
				'image_url' => '',
				'archived'  => false,
			),
			$overrides
		);
	}

	public function test_converts_units_from_kg_and_cm(): void {
		$payload = ProductPayloadBuilder::build( $this->product(), 'kg', 'cm', 'eur' );

		$this->assertSame(
			array(
				'sku'           => 'KOL-300',
				'name'          => 'Kolagén prášok 300 g',
				'ean'           => '8588000000028',
				'weight_grams'  => 350,
				'dimensions_mm' => array( 'length' => 120, 'width' => 80, 'height' => 85 ),
				'price_cents'   => 2490,
				'currency'      => 'EUR',
				'archived'      => false,
			),
			$payload
		);
	}

	public function test_converts_other_units(): void {
		$payload = ProductPayloadBuilder::build( $this->product( array( 'weight' => '1', 'length' => '1', 'width' => '', 'height' => '' ) ), 'lbs', 'in', 'EUR' );

		$this->assertSame( 454, $payload['weight_grams'] );
		$this->assertSame( array( 'length' => 25 ), $payload['dimensions_mm'] );

		$grams = ProductPayloadBuilder::build( $this->product( array( 'weight' => '120' ) ), 'g', 'mm', 'EUR' );
		$this->assertSame( 120, $grams['weight_grams'] );
		$this->assertSame( array( 'length' => 12, 'width' => 8, 'height' => 9 ), $grams['dimensions_mm'] );
	}

	public function test_omits_empty_values(): void {
		$payload = ProductPayloadBuilder::build(
			$this->product( array( 'ean' => '', 'weight' => '', 'length' => '', 'width' => '', 'height' => '', 'price' => '' ) ),
			'kg',
			'cm',
			'EUR'
		);

		$this->assertSame( array( 'sku' => 'KOL-300', 'name' => 'Kolagén prášok 300 g', 'archived' => false ), $payload );
	}

	public function test_requires_a_sku(): void {
		$this->expectException( MappingException::class );

		ProductPayloadBuilder::build( $this->product( array( 'sku' => '  ' ) ), 'kg', 'cm', 'EUR' );
	}
}
