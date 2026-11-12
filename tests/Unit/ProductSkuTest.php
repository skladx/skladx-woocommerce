<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Sync\ProductPayloadBuilder;
use SkladX\WooCommerce\Sync\ProductSku;

final class ProductSkuTest extends TestCase {

	public function test_fallback_sku_is_the_product_id(): void {
		$this->assertSame( 'WC-57', ProductSku::fallback( 57 ) );
		$this->assertSame( '', ProductSku::fallback( 0 ) );
		$this->assertSame( '', ProductSku::fallback( -3 ) );
	}

	public function test_fallback_id_round_trips(): void {
		$this->assertSame( 57, ProductSku::fallback_id( ProductSku::fallback( 57 ) ) );
		$this->assertSame( 57, ProductSku::fallback_id( ' wc-57 ' ) );
	}

	public function test_other_skus_are_not_fallbacks(): void {
		foreach ( array( '', 'WC-', 'WC-0', 'WC-012', 'WC-57-A', 'XWC-57', 'KOL-300', '57' ) as $sku ) {
			$this->assertNull( ProductSku::fallback_id( $sku ), $sku );
		}
	}

	public function test_fallback_sku_fits_the_skladx_limit(): void {
		$this->assertLessThanOrEqual( 64, strlen( ProductSku::fallback( PHP_INT_MAX ) ) );
	}

	public function test_first_ean_candidate_wins_and_whitespace_is_removed(): void {
		$this->assertSame( '8588000000028', ProductPayloadBuilder::first_code( array( '', null, ' 8588 0000 0002 8 ', '4006381333931' ) ) );
		$this->assertSame( '', ProductPayloadBuilder::first_code( array( '', '   ', array( '123' ), false ) ) );
	}

	public function test_ean_plugin_keys_do_not_repeat_the_own_field(): void {
		$keys = ProductPayloadBuilder::plugin_meta_keys();

		$this->assertNotContains( ProductPayloadBuilder::EAN_META_KEY, $keys );
		$this->assertContains( '_alg_ean', $keys );
		$this->assertContains( '_wpm_gtin_code', $keys );
		$this->assertContains( 'hwp_product_gtin', $keys );
	}
}
