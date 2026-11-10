<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Sync\ExternalId;

final class ExternalIdTest extends TestCase {

	public function test_format_for_simple_products_and_variations(): void {
		$this->assertSame( 'wc:shop.example:12', ExternalId::format( 'shop.example', 12 ) );
		$this->assertSame( 'wc:shop.example:57', ExternalId::format( 'shop.example', 57 ), 'Variations use their own id.' );
	}

	public function test_no_external_id_without_site_key_or_id(): void {
		$this->assertSame( '', ExternalId::format( '', 12 ) );
		$this->assertSame( '', ExternalId::format( 'shop.example', 0 ) );
	}

	public function test_site_key_is_the_lowercase_host(): void {
		$this->assertSame( 'www.shop.example', ExternalId::site_key_from_url( 'https://WWW.Shop.Example/eshop/' ), 'www. is kept, path dropped.' );
		$this->assertSame( 'shop.test', ExternalId::site_key_from_url( 'http://shop.test:8080' ), 'No port.' );
		$this->assertSame( '', ExternalId::site_key_from_url( '/relative/path' ) );
	}

	public function test_external_ids_stay_within_the_limit(): void {
		$key = ExternalId::site_key_from_url( 'https://' . str_repeat( 'a', 250 ) . '.example' );

		$this->assertSame( ExternalId::MAX_SITE_KEY_LENGTH, strlen( $key ) );
		$this->assertLessThanOrEqual( ExternalId::MAX_LENGTH, strlen( ExternalId::format( $key, PHP_INT_MAX ) ) );
	}

	public function test_parse(): void {
		$this->assertSame( array( 'site' => 'shop.example', 'id' => 57 ), ExternalId::parse( 'wc:shop.example:57' ) );
		$this->assertNull( ExternalId::parse( 'shopify:123' ) );
	}
}
