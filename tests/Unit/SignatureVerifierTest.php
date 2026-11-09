<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Webhooks\SignatureVerifier;

final class SignatureVerifierTest extends TestCase {

	private const SECRET = 'whsec_testsecret0123456789';
	private const BODY   = '{"id":"evt_01","type":"order.shipped","data":{"order":{"id":"ord_1"}}}';
	private const NOW    = 1794268800;

	public function test_matches_the_skladx_signature_format(): void {
		// Same algorithm as SkladX WebhookSigner: hex(hmac_sha256(secret, "t.body")).
		$expected = hash_hmac( 'sha256', self::NOW . '.' . self::BODY, self::SECRET );

		$this->assertSame( 't=' . self::NOW . ',v1=' . $expected, SignatureVerifier::header( self::SECRET, self::BODY, self::NOW ) );
	}

	public function test_accepts_a_valid_signature(): void {
		$header = SignatureVerifier::header( self::SECRET, self::BODY, self::NOW );

		$this->assertTrue( SignatureVerifier::verify( self::SECRET, self::BODY, $header, self::NOW + 10 ) );
	}

	public function test_accepts_whitespace_and_multiple_v1_values(): void {
		$valid  = SignatureVerifier::sign( self::SECRET, self::BODY, self::NOW );
		$header = 't=' . self::NOW . ', v1=' . str_repeat( '0', 64 ) . ', v1=' . $valid;

		$this->assertTrue( SignatureVerifier::verify( self::SECRET, self::BODY, $header, self::NOW ) );
	}

	public function test_rejects_a_tampered_body(): void {
		$header = SignatureVerifier::header( self::SECRET, self::BODY, self::NOW );

		$this->assertFalse( SignatureVerifier::verify( self::SECRET, str_replace( 'ord_1', 'ord_2', self::BODY ), $header, self::NOW ) );
	}

	public function test_rejects_a_wrong_secret(): void {
		$header = SignatureVerifier::header( 'whsec_other', self::BODY, self::NOW );

		$this->assertFalse( SignatureVerifier::verify( self::SECRET, self::BODY, $header, self::NOW ) );
	}

	public function test_rejects_timestamps_outside_the_tolerance(): void {
		$header = SignatureVerifier::header( self::SECRET, self::BODY, self::NOW );

		$this->assertTrue( SignatureVerifier::verify( self::SECRET, self::BODY, $header, self::NOW + 300 ) );
		$this->assertFalse( SignatureVerifier::verify( self::SECRET, self::BODY, $header, self::NOW + 301 ) );
		$this->assertFalse( SignatureVerifier::verify( self::SECRET, self::BODY, $header, self::NOW - 301 ) );
	}

	#[DataProvider( 'malformed_headers' )]
	public function test_rejects_malformed_headers( string $header ): void {
		$this->assertFalse( SignatureVerifier::verify( self::SECRET, self::BODY, $header, self::NOW ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformed_headers(): array {
		$signature = hash_hmac( 'sha256', self::NOW . '.' . self::BODY, self::SECRET );

		return array(
			'empty'            => array( '' ),
			'no timestamp'     => array( 'v1=' . $signature ),
			'no signature'     => array( 't=' . self::NOW ),
			'non-numeric time' => array( 't=abc,v1=' . $signature ),
			'garbage'          => array( 'nonsense' ),
		);
	}

	public function test_rejects_when_no_secret_is_configured(): void {
		$header = SignatureVerifier::header( '', self::BODY, self::NOW );

		$this->assertFalse( SignatureVerifier::verify( '', self::BODY, $header, self::NOW ) );
	}
}
