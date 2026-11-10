<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Sync\SkuConflict;

final class SkuConflictTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function details(): array {
		return array(
			'sku'                 => 'KOL-500',
			'external_id'         => 'wc:shop.example:12',
			'conflicting_product' => array(
				'id'          => 'prd_01JCONFLICT',
				'sku'         => 'KOL-500',
				'name'        => 'Kolagén 500 g',
				'external_id' => 'wc:shop.example:44',
			),
			'product'             => array(
				'id'   => 'prd_01JOWN',
				'sku'  => 'KOL-300',
				'name' => 'Kolagén 300 g',
			),
		);
	}

	public function test_a_sku_conflict_is_final(): void {
		$exception = new ApiException( 'SKU KOL-500 už patrí inému produktu.', 409, 'sku_conflict', $this->details() );

		$this->assertTrue( $exception->is_sku_conflict() );
		$this->assertTrue( SkuConflict::matches( $exception ) );
		$this->assertFalse( $exception->is_retryable() );
	}

	public function test_other_errors_are_not_sku_conflicts(): void {
		$this->assertFalse( SkuConflict::matches( new ApiException( 'Conflict', 409, 'idempotency_conflict' ) ) );
		$this->assertFalse( SkuConflict::matches( new ApiException( 'Server error', 503, 'sku_conflict' ) ) );
		$this->assertTrue( ( new ApiException( 'Server error', 503, 'server_error' ) )->is_retryable() );
	}

	public function test_message_names_the_product_that_owns_the_sku(): void {
		$message = SkuConflict::message( $this->details(), 'SKU KOL-500 už patrí inému produktu.' );

		$this->assertStringContainsString( 'SKU KOL-500 already belongs to another SkladX product: Kolagén 500 g (SKU KOL-500, ID prd_01JCONFLICT).', $message );
		$this->assertStringContainsString( 'linked to WooCommerce product #44 (shop.example)', $message );
		$this->assertStringContainsString( 'stays in SkladX under SKU KOL-300 (ID prd_01JOWN)', $message );
		$this->assertStringContainsString( 'Retry sync', $message );
	}

	public function test_message_without_link_or_previous_product(): void {
		$details                                       = $this->details();
		$details['conflicting_product']['external_id'] = null;
		$details['product']                            = null;

		$message = SkuConflict::message( $details );

		$this->assertStringContainsString( 'Kolagén 500 g (SKU KOL-500, ID prd_01JCONFLICT)', $message );
		$this->assertStringNotContainsString( 'linked', $message );
		$this->assertStringNotContainsString( 'stays in SkladX', $message );
	}

	public function test_falls_back_to_the_server_message_without_details(): void {
		$this->assertStringStartsWith( 'SKU KOL-500 už patrí inému produktu.', SkuConflict::message( array(), 'SKU KOL-500 už patrí inému produktu.' ) );
	}
}
