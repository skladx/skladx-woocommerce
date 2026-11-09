<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Support\Redactor;

final class RedactorTest extends TestCase {

	public function test_masks_api_keys_secrets_and_bearer_tokens_in_text(): void {
		$text = 'key skx_test_AbC123xyz and skx_live_ZZZ999, secret whsec_abcDEF123, header Bearer abc.def-123';

		$this->assertSame(
			'key skx_test_[redacted] and skx_live_[redacted], secret whsec_[redacted], header Bearer [redacted]',
			Redactor::redact( $text )
		);
	}

	public function test_masks_known_secrets_and_sensitive_keys_in_arrays(): void {
		$result = Redactor::redact(
			array(
				'secret'  => 'whsec_x',
				'nested'  => array( 'Authorization' => 'Bearer x', 'note' => 'contains my-own-secret-value here' ),
				'count'   => 3,
			),
			array( 'my-own-secret-value' )
		);

		$this->assertSame(
			array(
				'secret' => '[redacted]',
				'nested' => array( 'Authorization' => '[redacted]', 'note' => 'contains [redacted] here' ),
				'count'  => 3,
			),
			$result
		);
	}

	public function test_mask_keeps_only_the_last_four_characters_of_long_secrets(): void {
		$this->assertSame( '••••••••WXYZ', Redactor::mask( 'skx_test_abcdefghWXYZ' ) );
		$this->assertSame( '••••••••', Redactor::mask( 'short' ) );
		$this->assertSame( '', Redactor::mask( '' ) );
	}
}
