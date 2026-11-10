<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Webhooks\EventProcessor;

final class EventPayloadTest extends TestCase {

	public function test_reads_stock_from_product_events(): void {
		$data = array( 'product' => array( 'sku' => 'KOL-300', 'stock' => array( 'on_hand' => 10, 'reserved' => 2, 'available' => 8 ) ) );

		$this->assertSame( array( 'KOL-300' => 8 ), EventProcessor::stock_levels( $data ) );
	}

	public function test_reads_stock_changed_payload_sent_by_skladx(): void {
		$data = array(
			'stock'            => array( 'on_hand' => 10, 'reserved' => 3, 'available' => 7 ),
			'product'          => array( 'id' => 'prd_01m72dcrz0hdz351re3c7y9mgf', 'ean' => '8588000000062', 'sku' => 'RF-PONOZKY' ),
			'previous_on_hand' => 7,
		);

		$this->assertSame( array( 'RF-PONOZKY' => 7 ), EventProcessor::stock_levels( $data ) );
	}

	public function test_reads_flat_and_list_stock_payloads(): void {
		$this->assertSame( array( 'A' => 5 ), EventProcessor::stock_levels( array( 'sku' => 'A', 'available' => 5 ) ) );
		$this->assertSame( array( 'A' => 1 ), EventProcessor::stock_levels( array( 'stock' => array( 'sku' => 'A', 'available' => '1' ) ) ) );
		$this->assertSame(
			array( 'A' => 1, 'B' => 0 ),
			EventProcessor::stock_levels( array( 'stock' => array( array( 'sku' => 'A', 'available' => 1 ), array( 'sku' => 'B', 'available' => 0 ), array( 'sku' => 'C' ) ) ) )
		);
	}

	public function test_ignores_payloads_without_stock(): void {
		$this->assertSame( array(), EventProcessor::stock_levels( array( 'product' => array( 'sku' => 'A' ) ) ) );
		$this->assertSame( array(), EventProcessor::stock_levels( array() ) );
	}

	public function test_api_exception_retry_rules_and_summary(): void {
		$validation = new ApiException( 'Požiadavka obsahuje neplatné dáta.', 422, 'validation_failed', array( 'fields' => array( 'items' => array( 'Neznáme SKU: X.' ) ) ) );

		$this->assertFalse( $validation->is_retryable() );
		$this->assertSame( array( 'items' => array( 'Neznáme SKU: X.' ) ), $validation->field_errors() );
		$this->assertSame( '[422 validation_failed] Požiadavka obsahuje neplatné dáta. (items: Neznáme SKU: X.)', $validation->summary() );

		$this->assertTrue( ( new ApiException( 'timeout' ) )->is_retryable() );
		$this->assertTrue( ( new ApiException( 'down', 503, 'server_error' ) )->is_retryable() );
		$this->assertTrue( ( new ApiException( 'slow down', 429, 'rate_limited' ) )->is_retryable() );
		$this->assertFalse( ( new ApiException( 'bad key', 401, 'invalid_api_key' ) )->is_retryable() );
	}
}
