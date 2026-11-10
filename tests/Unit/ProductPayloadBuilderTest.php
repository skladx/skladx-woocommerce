<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Sync\ExternalId;
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
				'external_id' => 'wc:shop.example:12',
				'sku'         => 'KOL-300',
				'name'        => 'Kolagén prášok 300 g',
				'ean'         => '8588 0000 0002 8',
				'weight'      => '0.35',
				'length'      => '12',
				'width'       => '8',
				'height'      => '8,5',
				'price'       => '24.90',
				'image_url'   => '',
				'images'      => array(),
				'archived'    => false,
				'home_url'    => 'https://shop.example/',
			),
			$overrides
		);
	}

	public function test_converts_units_from_kg_and_cm(): void {
		$payload = ProductPayloadBuilder::build( $this->product(), 'kg', 'cm', 'eur' );

		$this->assertSame(
			array(
				'sku'           => 'KOL-300',
				'external_id'   => 'wc:shop.example:12',
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

		$this->assertSame( array( 'sku' => 'KOL-300', 'external_id' => 'wc:shop.example:12', 'name' => 'Kolagén prášok 300 g', 'archived' => false ), $payload );
	}

	public function test_requires_a_sku(): void {
		$this->expectException( MappingException::class );

		ProductPayloadBuilder::build( $this->product( array( 'sku' => '  ' ) ), 'kg', 'cm', 'EUR' );
	}

	public function test_sends_the_external_id_of_simple_products_and_variations(): void {
		$simple    = ProductPayloadBuilder::build( $this->product( array( 'external_id' => ExternalId::format( 'shop.example', 12 ) ) ), 'kg', 'cm', 'EUR' );
		$variation = ProductPayloadBuilder::build( $this->product( array( 'sku' => 'KOL-300-V', 'external_id' => ExternalId::format( 'shop.example', 57 ) ) ), 'kg', 'cm', 'EUR' );

		$this->assertSame( 'wc:shop.example:12', $simple['external_id'] );
		$this->assertSame( 'wc:shop.example:57', $variation['external_id'] );
	}

	public function test_sends_absolute_image_urls_and_up_to_ten_gallery_images_without_the_main_one(): void {
		$gallery = array( 'https://shop.example/wp-content/uploads/main.jpg', '/wp-content/uploads/back.jpg', 'http://shop.example/wp-content/uploads/side.jpg', 'data:image/png;base64,AAAA', '', 'https://cdn.example/top.jpg', 'https://cdn.example/top.jpg' );

		for ( $i = 1; $i <= 12; $i++ ) {
			$gallery[] = 'https://cdn.example/g' . $i . '.jpg';
		}

		$payload = ProductPayloadBuilder::build(
			$this->product( array( 'image_url' => 'http://shop.example/wp-content/uploads/main.jpg', 'images' => $gallery ) ),
			'kg',
			'cm',
			'EUR'
		);

		$this->assertSame( 'https://shop.example/wp-content/uploads/main.jpg', $payload['image_url'] );
		$this->assertCount( ProductPayloadBuilder::MAX_IMAGES, $payload['images'] );
		$this->assertSame(
			array( 'https://shop.example/wp-content/uploads/back.jpg', 'https://shop.example/wp-content/uploads/side.jpg', 'https://cdn.example/top.jpg', 'https://cdn.example/g1.jpg' ),
			array_slice( $payload['images'], 0, 4 )
		);
	}

	public function test_image_urls_are_absolute_http_or_https_only(): void {
		$this->assertSame( 'https://shop.example/a.jpg', ProductPayloadBuilder::absolute_url( '//shop.example/a.jpg', 'https://shop.example/' ) );
		$this->assertSame( 'http://shop.test:8080/a.jpg', ProductPayloadBuilder::absolute_url( '/a.jpg', 'http://shop.test:8080/' ) );
		$this->assertSame( 'http://cdn.example/a.jpg', ProductPayloadBuilder::absolute_url( 'http://cdn.example/a.jpg', 'http://shop.test/' ), 'No upgrade on an http site.' );
		$this->assertNull( ProductPayloadBuilder::absolute_url( 'ftp://shop.example/a.jpg', 'https://shop.example/' ) );
		$this->assertNull( ProductPayloadBuilder::absolute_url( '/a.jpg', '' ), 'A relative URL needs the site address.' );
		$this->assertNull( ProductPayloadBuilder::absolute_url( 'uploads/a.jpg', 'https://shop.example/' ) );
		$this->assertNull( ProductPayloadBuilder::absolute_url( '  ', 'https://shop.example/' ) );
	}
}
