<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Sync\StatusMapper;

final class StatusMapperTest extends TestCase {

	private function mapper(): StatusMapper {
		return new StatusMapper(
			array(
				'handed_over' => 'wc-skladx-shipped',
				'delivered'   => 'completed',
				'cancelled'   => 'wc-cancelled',
				'picking'     => '',
			)
		);
	}

	public function test_maps_with_or_without_the_wc_prefix(): void {
		$this->assertSame( 'skladx-shipped', $this->mapper()->to_woocommerce( 'handed_over' ) );
		$this->assertSame( 'completed', $this->mapper()->to_woocommerce( 'delivered' ) );
		$this->assertSame( 'cancelled', $this->mapper()->to_woocommerce( 'cancelled' ) );
	}

	public function test_unmapped_and_empty_statuses_keep_the_order_status(): void {
		$this->assertNull( $this->mapper()->to_woocommerce( 'picking' ) );
		$this->assertNull( $this->mapper()->to_woocommerce( 'allocated' ) );
		$this->assertFalse( $this->mapper()->needs_change( 'allocated', 'processing' ) );
	}

	public function test_needs_change_only_when_the_target_differs(): void {
		$this->assertTrue( $this->mapper()->needs_change( 'delivered', 'skladx-shipped' ) );
		$this->assertFalse( $this->mapper()->needs_change( 'delivered', 'completed' ) );
	}

	public function test_cancellable_statuses_match_skladx(): void {
		foreach ( array( 'draft', 'received', 'on_hold', 'awaiting_stock', 'ready' ) as $status ) {
			$this->assertTrue( StatusMapper::is_cancellable( $status ), $status );
		}

		foreach ( array( 'allocated', 'picking', 'packed', 'handed_over', 'delivered', 'cancelled' ) as $status ) {
			$this->assertFalse( StatusMapper::is_cancellable( $status ), $status );
		}
	}

	public function test_knows_every_skladx_status(): void {
		$this->assertCount( 19, StatusMapper::STATUSES );
		$this->assertTrue( StatusMapper::is_known( 'label_created' ) );
		$this->assertFalse( StatusMapper::is_known( 'shipped' ) );
	}
}
