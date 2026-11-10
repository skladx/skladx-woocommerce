<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Inbound\Receipt;

final class ReceiptTest extends TestCase {

	public function test_normalizes_the_contract_shape_with_differences(): void {
		$receipt = Receipt::normalize(
			array(
				'id'              => 'inr_01',
				'number'          => 'PR-2026-0007',
				'name'            => 'Jeseň',
				'status'          => 'received',
				'status_label'    => 'Prijatá',
				'expected_at'     => '2026-11-20',
				'carrier'         => 'dpd_sk',
				'tracking_number' => '0123',
				'tracking_url'    => 'javascript:alert(1)',
				'is_blind'        => false,
				'warehouse'       => array( 'code' => 'BA1', 'name' => 'Bratislava' ),
				'items'           => array(
					array( 'id' => 'iri_1', 'sku' => 'A', 'product_name' => 'Tričko', 'quantity_expected' => 10, 'quantity_received' => 9, 'batch' => 'L1', 'expires_at' => '2027-01-31' ),
					array( 'id' => 'iri_2', 'sku' => 'B', 'product_name' => 'Fľaša', 'quantity_expected' => 5, 'quantity_received' => 5, 'batch' => null, 'expires_at' => null ),
				),
				'received_at'     => '2026-11-21T10:00:00Z',
			)
		);

		$this->assertSame( array( 'code' => 'BA1', 'name' => 'Bratislava' ), $receipt['warehouse'] );
		$this->assertSame( '', $receipt['tracking_url'] );
		$this->assertSame( -1, $receipt['items'][0]['difference'] );
		$this->assertSame( 0, $receipt['items'][1]['difference'] );
		$this->assertSame( array( 'lines' => 2, 'expected' => 15, 'received' => 14, 'difference' => -1 ), $receipt['totals'] );
		$this->assertTrue( $receipt['has_differences'] );
		$this->assertFalse( $receipt['editable'] );
		$this->assertFalse( $receipt['cancellable'] );
	}

	public function test_normalizes_the_older_shape(): void {
		$receipt = Receipt::normalize(
			array(
				'id'        => 'inr_02',
				'status'    => 'draft',
				'blind'     => true,
				'warehouse' => 'BA1',
				'items'     => array( array( 'sku' => 'A', 'name' => 'Tričko', 'quantity_expected' => 3, 'quantity_received' => null ) ),
			)
		);

		$this->assertTrue( $receipt['is_blind'] );
		$this->assertSame( array( 'code' => 'BA1', 'name' => '' ), $receipt['warehouse'] );
		$this->assertSame( 'Tričko', $receipt['items'][0]['name'] );
		$this->assertNull( $receipt['items'][0]['difference'] );
		$this->assertNull( $receipt['totals']['received'] );
		$this->assertFalse( $receipt['has_differences'] );
		$this->assertTrue( $receipt['editable'] );
		$this->assertTrue( $receipt['cancellable'] );
	}

	public function test_received_without_differences(): void {
		$receipt = Receipt::normalize(
			array(
				'id'     => 'inr_03',
				'status' => 'received',
				'items'  => array( array( 'sku' => 'A', 'quantity_expected' => 2, 'quantity_received' => 2 ) ),
			)
		);

		$this->assertFalse( $receipt['has_differences'] );
		$this->assertSame( 0, $receipt['totals']['difference'] );
	}

	public function test_status_rules(): void {
		$this->assertSame( 'draft', Receipt::tone( 'draft' ) );
		$this->assertSame( 'waiting', Receipt::tone( 'announced' ) );
		$this->assertSame( 'receiving', Receipt::tone( 'receiving' ) );
		$this->assertSame( 'done', Receipt::tone( 'received' ) );
		$this->assertSame( 'cancelled', Receipt::tone( 'cancelled' ) );
		$this->assertSame( 'unknown', Receipt::tone( 'lost' ) );
		$this->assertTrue( Receipt::is_cancellable( 'in_transit' ) );
		$this->assertFalse( Receipt::is_cancellable( 'arrived' ) );
		$this->assertTrue( Receipt::is_final( 'cancelled' ) );
		$this->assertFalse( Receipt::is_editable( 'announced' ) );
	}

	public function test_finds_the_receipt_in_event_payloads(): void {
		$receipt = array( 'id' => 'inr_01', 'status' => 'announced' );

		$this->assertSame( $receipt, Receipt::from_event( array( 'receipt' => $receipt ) ) );
		$this->assertSame( $receipt, Receipt::from_event( array( 'inbound_receipt' => $receipt ) ) );
		$this->assertSame( $receipt, Receipt::from_event( $receipt ) );
		$this->assertNull( Receipt::from_event( array( 'order' => array( 'id' => 'ord_1' ) ) ) );
	}

	public function test_announced_receipt_with_zero_counts_shows_no_differences(): void {
		$receipt = Receipt::normalize(
			array(
				'id'     => 'inr_1',
				'status' => 'announced',
				'items'  => array(
					array( 'sku' => 'A', 'quantity_expected' => 20, 'quantity_received' => 0 ),
					array( 'sku' => 'B', 'quantity_expected' => 20, 'quantity_received' => 0 ),
				),
			)
		);

		$this->assertNull( $receipt['totals']['received'] );
		$this->assertNull( $receipt['totals']['difference'] );
		$this->assertFalse( $receipt['has_differences'] );
	}
}
