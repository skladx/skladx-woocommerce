<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Settings;

final class WebhookUrlTest extends TestCase {

	public function test_a_public_https_url_is_fine(): void {
		$this->assertSame( '', Settings::webhook_url_problem( 'https://shop.example.com/wp-json/skladx/v1/webhook', '/var/www/shop/' ) );
	}

	public function test_a_home_option_with_the_server_path_is_detected(): void {
		$this->assertSame(
			'filesystem_path',
			Settings::webhook_url_problem( 'https://shop.test/Users/me/sites/shop/wp-json/skladx/v1/webhook', '/Users/me/sites/shop/' )
		);
	}

	public function test_plain_http_and_relative_urls_are_flagged(): void {
		$this->assertSame( 'not_https', Settings::webhook_url_problem( 'http://shop.example.com/wp-json/skladx/v1/webhook', '/var/www/shop/' ) );
		$this->assertSame( 'not_absolute', Settings::webhook_url_problem( '/wp-json/skladx/v1/webhook', '/var/www/shop/' ) );
	}
}
