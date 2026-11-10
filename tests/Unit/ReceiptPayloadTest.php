<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Inbound\ReceiptPayload;
use SkladX\WooCommerce\Inbound\ValidationException;

final class ReceiptPayloadTest extends TestCase {

	private const CARRIERS = array( 'packeta', 'dpd_sk', 'gls_sk', 'sps', 'posta_sk', 'balikovo' );

	/**
	 * @param array<string, mixed> $overrides Input overrides.
	 * @return array<string, mixed>
	 */
	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'            => 'Jesenná kolekcia',
				'reference'       => 'PO-2026-15',
				'expected_at'     => '2026-11-20',
				'carrier'         => 'dpd_sk',
				'carrier_other'   => '',
				'tracking_number' => '01234567890',
				'is_blind'        => false,
				'note'            => '',
				'warehouse_code'  => '',
				'items'           => array(
					array( 'sku' => 'RF-TRIKO-M', 'quantity' => '24', 'batch' => '', 'expires_at' => '' ),
					array( 'sku' => 'RF-FLASA', 'quantity' => 5, 'batch' => 'B7', 'expires_at' => '31.03.2028' ),
				),
			),
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return list<array<string, mixed>>
	 */
	private function errors( array $input ): array {
		try {
			ReceiptPayload::build( $input, self::CARRIERS );
		} catch ( ValidationException $exception ) {
			return $exception->errors;
		}

		$this->fail( 'Expected a ValidationException.' );
	}

	public function test_builds_the_api_payload(): void {
		$payload = ReceiptPayload::build( $this->input(), self::CARRIERS );

		$this->assertSame(
			array(
				'name'            => 'Jesenná kolekcia',
				'reference'       => 'PO-2026-15',
				'expected_at'     => '2026-11-20',
				'carrier'         => 'dpd_sk',
				'tracking_number' => '01234567890',
				'is_blind'        => false,
				'note'            => null,
				'warehouse_code'  => null,
				'items'           => array(
					array( 'sku' => 'RF-TRIKO-M', 'quantity' => 24, 'batch' => null, 'expires_at' => null ),
					array( 'sku' => 'RF-FLASA', 'quantity' => 5, 'batch' => 'B7', 'expires_at' => '2028-03-31' ),
				),
			),
			$payload
		);
	}

	public function test_merges_identical_lines_but_keeps_other_batches_separate(): void {
		$payload = ReceiptPayload::build(
			$this->input(
				array(
					'items' => array(
						array( 'sku' => 'A', 'quantity' => 2, 'batch' => 'L1', 'expires_at' => '2027-01-31' ),
						array( 'sku' => 'A', 'quantity' => 3, 'batch' => 'L1', 'expires_at' => '31.1.2027' ),
						array( 'sku' => 'A', 'quantity' => 4, 'batch' => 'L2', 'expires_at' => '2027-01-31' ),
						array( 'sku' => 'A', 'quantity' => 1, 'batch' => 'L1', 'expires_at' => '' ),
					),
				)
			),
			self::CARRIERS
		);

		$this->assertSame(
			array(
				array( 'sku' => 'A', 'quantity' => 5, 'batch' => 'L1', 'expires_at' => '2027-01-31' ),
				array( 'sku' => 'A', 'quantity' => 4, 'batch' => 'L2', 'expires_at' => '2027-01-31' ),
				array( 'sku' => 'A', 'quantity' => 1, 'batch' => 'L1', 'expires_at' => null ),
			),
			$payload['items']
		);
	}

	public function test_personal_delivery_drops_tracking_and_other_carrier_uses_its_name(): void {
		$personal = ReceiptPayload::build( $this->input( array( 'carrier' => 'personal' ) ), self::CARRIERS );

		$this->assertSame( 'personal', $personal['carrier'] );
		$this->assertNull( $personal['tracking_number'] );

		$other = ReceiptPayload::build( $this->input( array( 'carrier' => 'other', 'carrier_other' => 'Raben' ) ), self::CARRIERS );

		$this->assertSame( 'Raben', $other['carrier'] );

		$none = ReceiptPayload::build( $this->input( array( 'carrier' => '', 'tracking_number' => '' ) ), self::CARRIERS );

		$this->assertNull( $none['carrier'] );
		$this->assertNull( $none['tracking_number'] );
	}

	public function test_blind_flag_and_empty_draft_are_allowed(): void {
		$payload = ReceiptPayload::build( $this->input( array( 'is_blind' => '1', 'items' => array() ) ), self::CARRIERS );

		$this->assertTrue( $payload['is_blind'] );
		$this->assertSame( array(), $payload['items'] );
	}

	public function test_header_validation(): void {
		$errors = $this->errors(
			$this->input(
				array(
					'name'            => '  ',
					'expected_at'     => '2026-02-30',
					'carrier'         => 'ups',
					'tracking_number' => 'abc<script>',
					'reference'       => str_repeat( 'x', 65 ),
				)
			)
		);

		$this->assertSame(
			array(
				array( 'name', ValidationException::REQUIRED ),
				array( 'expected_at', ValidationException::INVALID_DATE ),
				array( 'carrier', ValidationException::INVALID_CARRIER ),
				array( 'tracking_number', ValidationException::INVALID_TRACKING ),
				array( 'reference', ValidationException::TOO_LONG ),
			),
			array_map( static fn ( array $error ): array => array( $error['field'], $error['reason'] ), $errors )
		);
	}

	public function test_expected_date_is_required_and_other_carrier_needs_a_name(): void {
		$errors = $this->errors( $this->input( array( 'expected_at' => '', 'carrier' => 'other' ) ) );

		$this->assertSame( array( 'expected_at', 'carrier_other' ), array_column( $errors, 'field' ) );
		$this->assertSame( array( ValidationException::REQUIRED, ValidationException::REQUIRED ), array_column( $errors, 'reason' ) );
	}

	public function test_item_validation_reports_line_numbers(): void {
		$errors = $this->errors(
			$this->input(
				array(
					'items' => array(
						array( 'sku' => 'A', 'quantity' => 1 ),
						array( 'sku' => 'B', 'quantity' => 0 ),
						array( 'sku' => 'C', 'quantity' => '2.5' ),
						array( 'sku' => 'D', 'quantity' => -3 ),
						array( 'sku' => '', 'quantity' => 1 ),
						array( 'sku' => 'E', 'quantity' => 1, 'batch' => str_repeat( 'B', 65 ) ),
						array( 'sku' => 'F', 'quantity' => 1, 'expires_at' => '2027-13-01' ),
						array( 'sku' => 'G', 'quantity' => 1, 'expires_at' => 'zajtra' ),
						array( 'sku' => 'H', 'quantity' => '' ),
						array( 'sku' => 'I', 'quantity' => 1000001 ),
					),
				)
			)
		);

		$this->assertSame(
			array(
				array( 2, 'items.1.quantity', ValidationException::QUANTITY_POSITIVE ),
				array( 3, 'items.2.quantity', ValidationException::INVALID_QUANTITY ),
				array( 4, 'items.3.quantity', ValidationException::QUANTITY_POSITIVE ),
				array( 5, 'items.4.sku', ValidationException::REQUIRED ),
				array( 6, 'items.5.batch', ValidationException::TOO_LONG ),
				array( 7, 'items.6.expires_at', ValidationException::INVALID_DATE ),
				array( 8, 'items.7.expires_at', ValidationException::INVALID_DATE ),
				array( 9, 'items.8.quantity', ValidationException::REQUIRED ),
				array( 10, 'items.9.quantity', ValidationException::QUANTITY_TOO_LARGE ),
			),
			array_map( static fn ( array $error ): array => array( $error['line'], $error['field'], $error['reason'] ), $errors )
		);
	}

	public function test_merged_lines_cannot_exceed_the_maximum_quantity(): void {
		$errors = $this->errors(
			$this->input(
				array(
					'items' => array(
						array( 'sku' => 'A', 'quantity' => 600000 ),
						array( 'sku' => 'A', 'quantity' => 600000 ),
					),
				)
			)
		);

		$this->assertSame( ValidationException::QUANTITY_TOO_LARGE, $errors[0]['reason'] );
	}

	public function test_localized_messages_fall_back_to_english_without_wordpress(): void {
		try {
			ReceiptPayload::build( $this->input( array( 'items' => array( array( 'sku' => 'A', 'quantity' => 0 ) ) ) ), self::CARRIERS );
			$this->fail( 'Expected a ValidationException.' );
		} catch ( ValidationException $exception ) {
			$this->assertSame(
				array(
					array(
						'field'   => 'items.0.quantity',
						'line'    => 1,
						'message' => 'Line 1: Quantity must be greater than 0.',
					),
				),
				$exception->localized()
			);
		}
	}

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function dates(): array {
		return array(
			'iso'             => array( '2027-03-31', '2027-03-31' ),
			'iso short'       => array( '2027-3-1', '2027-03-01' ),
			'slovak'          => array( '31.03.2027', '2027-03-31' ),
			'slovak spaces'   => array( '1. 3. 2027', '2027-03-01' ),
			'slash'           => array( '31/03/2027', '2027-03-31' ),
			'month slash'     => array( '02/2028', '2028-02-29' ),
			'month dot'       => array( '11.2027', '2027-11-30' ),
			'iso month'       => array( '2027-04', '2027-04-30' ),
			'feb 30'          => array( '30.02.2027', null ),
			'month 13'        => array( '2027-13-01', null ),
			'two-digit year'  => array( '31.03.27', null ),
			'text'            => array( 'next week', null ),
			'empty'           => array( '', null ),
		);
	}

	/**
	 * @dataProvider dates
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'dates' )]
	public function test_normalizes_dates( string $input, ?string $expected ): void {
		$this->assertSame( $expected, ReceiptPayload::normalize_date( $input ) );
	}

	public function test_announce_requires_items_unless_blind(): void {
		ReceiptPayload::assert_announceable( array( 'is_blind' => true, 'items' => array() ) );
		ReceiptPayload::assert_announceable( array( 'is_blind' => false, 'items' => array( array( 'sku' => 'A' ) ) ) );

		$this->expectException( ValidationException::class );
		ReceiptPayload::assert_announceable( array( 'is_blind' => false, 'items' => array() ) );
	}
}
