<?php

namespace SkladX\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SkladX\WooCommerce\Sync\MappingException;
use SkladX\WooCommerce\Sync\OrderPayloadBuilder;

final class OrderPayloadBuilderTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides Snapshot overrides.
	 * @return array<string, mixed>
	 */
	private function snapshot( array $overrides = array() ): array {
		$address = array(
			'first_name' => 'Jana',
			'last_name'  => 'Testovacia',
			'company'    => '',
			'address_1'  => 'Obchodná 12',
			'address_2'  => 'byt 4',
			'city'       => 'Bratislava',
			'postcode'   => '81106',
			'country'    => 'SK',
			'phone'      => '',
		);

		return array_replace(
			array(
				'id'             => 16,
				'number'         => '16',
				'currency'       => 'EUR',
				'total'          => 62.2,
				'payment_method' => 'cod',
				'customer_note'  => 'Volať pred doručením.',
				'email'          => 'jana@example.com',
				'phone'          => '+421900123456',
				'shipping'       => $address,
				'billing'        => $address,
				'items'          => array(
					array( 'product_id' => 12, 'sku' => 'KOL-300', 'name' => 'Kolagén', 'quantity' => 2, 'total' => 49.8 ),
					array( 'product_id' => 14, 'sku' => 'SHK-700', 'name' => 'Šejker', 'quantity' => 1, 'total' => 7.5 ),
				),
				'shipping_lines' => array(
					array( 'method_id' => 'flat_rate', 'instance_id' => '2', 'meta' => array() ),
				),
				'meta'           => array( '_skladx_pickup_point_id' => '' ),
			),
			$overrides
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function config(): array {
		return array(
			'carrier_map'           => array(
				'flat_rate:1' => 'dpd_sk',
				'flat_rate:2' => 'packeta',
				'local_pickup' => 'sps',
			),
			'cod_gateways'          => array( 'cod' ),
			'pickup_point_meta_key' => '_skladx_pickup_point_id',
			'site'                  => 'shop.example',
			'site_key'              => 'shop.example',
			'default_country'       => 'SK',
		);
	}

	public function test_builds_the_full_payload(): void {
		$payload = OrderPayloadBuilder::build( $this->snapshot(), $this->config() );

		$this->assertSame( '16', $payload['reference'] );
		$this->assertSame(
			array(
				array( 'sku' => 'KOL-300', 'external_id' => 'wc:shop.example:12', 'name' => 'Kolagén', 'quantity' => 2, 'unit_price_cents' => 2490 ),
				array( 'sku' => 'SHK-700', 'external_id' => 'wc:shop.example:14', 'name' => 'Šejker', 'quantity' => 1, 'unit_price_cents' => 750 ),
			),
			$payload['items']
		);
		$this->assertSame(
			array(
				'name'        => 'Jana Testovacia',
				'street'      => 'Obchodná 12 byt 4',
				'city'        => 'Bratislava',
				'postal_code' => '81106',
				'country'     => 'SK',
			),
			$payload['shipping_address']
		);
		$this->assertSame( 'packeta', $payload['carrier'] );
		$this->assertSame( 6220, $payload['cod_cents'] );
		$this->assertSame( 6220, $payload['total_cents'] );
		$this->assertSame( 'EUR', $payload['currency'] );
		$this->assertSame( 'jana@example.com', $payload['customer_email'] );
		$this->assertSame( '+421900123456', $payload['customer_phone'] );
		$this->assertSame( 'Volať pred doručením.', $payload['note'] );
		$this->assertSame( array( 'wc_order_id' => 16, 'wc_order_number' => '16', 'site' => 'shop.example' ), $payload['metadata'] );
		$this->assertArrayNotHasKey( 'pickup_point_id', $payload );
	}

	public function test_no_cod_for_other_payment_methods(): void {
		$payload = OrderPayloadBuilder::build( $this->snapshot( array( 'payment_method' => 'bacs' ) ), $this->config() );

		$this->assertArrayNotHasKey( 'cod_cents', $payload );
		$this->assertSame( 6220, $payload['total_cents'] );
	}

	public function test_pickup_point_from_order_meta_or_shipping_line(): void {
		$from_order = OrderPayloadBuilder::build( $this->snapshot( array( 'meta' => array( '_skladx_pickup_point_id' => ' 12345 ' ) ) ), $this->config() );
		$this->assertSame( '12345', $from_order['pickup_point_id'] );

		$from_line = OrderPayloadBuilder::build(
			$this->snapshot(
				array(
					'shipping_lines' => array(
						array( 'method_id' => 'flat_rate', 'instance_id' => '2', 'meta' => array( '_skladx_pickup_point_id' => '999' ) ),
					),
					'meta'           => array( '_skladx_pickup_point_id' => '12345' ),
				)
			),
			$this->config()
		);
		$this->assertSame( '999', $from_line['pickup_point_id'], 'Shipping line meta wins over order meta.' );
	}

	public function test_carrier_falls_back_to_the_method_id_and_may_be_missing(): void {
		$by_method = OrderPayloadBuilder::build(
			$this->snapshot( array( 'shipping_lines' => array( array( 'method_id' => 'local_pickup', 'instance_id' => '7', 'meta' => array() ) ) ) ),
			$this->config()
		);
		$this->assertSame( 'sps', $by_method['carrier'] );

		$unmapped = OrderPayloadBuilder::build(
			$this->snapshot( array( 'shipping_lines' => array( array( 'method_id' => 'free_shipping', 'instance_id' => '3', 'meta' => array() ) ) ) ),
			$this->config()
		);
		$this->assertArrayNotHasKey( 'carrier', $unmapped );
	}

	public function test_merges_duplicate_skus_and_skips_zero_quantities(): void {
		$payload = OrderPayloadBuilder::build(
			$this->snapshot(
				array(
					'items' => array(
						array( 'product_id' => 1, 'sku' => 'A', 'name' => 'A', 'quantity' => 1, 'total' => 10.0 ),
						array( 'product_id' => 1, 'sku' => 'A', 'name' => 'A', 'quantity' => 2, 'total' => 14.0 ),
						array( 'product_id' => 2, 'sku' => 'B', 'name' => 'B', 'quantity' => 0, 'total' => 0.0 ),
					),
				)
			),
			$this->config()
		);

		$this->assertSame( array( array( 'sku' => 'A', 'external_id' => 'wc:shop.example:1', 'name' => 'A', 'quantity' => 3, 'unit_price_cents' => 800 ) ), $payload['items'] );
	}

	public function test_uses_the_billing_address_when_there_is_no_shipping_address(): void {
		$snapshot                          = $this->snapshot();
		$snapshot['shipping']['address_1'] = '';
		$snapshot['billing']['address_1']  = 'Fakturačná 1';
		$snapshot['billing']['address_2']  = '';
		$snapshot['billing']['country']    = '';

		$payload = OrderPayloadBuilder::build( $snapshot, $this->config() );

		$this->assertSame( 'Fakturačná 1', $payload['shipping_address']['street'] );
		$this->assertSame( 'SK', $payload['shipping_address']['country'], 'Store country is the fallback.' );
	}

	public function test_shipping_phone_wins_over_billing_phone(): void {
		$snapshot                      = $this->snapshot();
		$snapshot['shipping']['phone'] = '+421911000111';

		$this->assertSame( '+421911000111', OrderPayloadBuilder::build( $snapshot, $this->config() )['customer_phone'] );
	}

	public function test_refuses_lines_without_sku(): void {
		$this->expectException( MappingException::class );
		$this->expectExceptionMessage( 'Order line "Bez SKU" has no SKU.' );

		OrderPayloadBuilder::build(
			$this->snapshot( array( 'items' => array( array( 'product_id' => 3, 'sku' => '', 'name' => 'Bez SKU', 'quantity' => 1, 'total' => 5.0 ) ) ) ),
			$this->config()
		);
	}

	public function test_refuses_orders_without_address(): void {
		$snapshot                          = $this->snapshot();
		$snapshot['shipping']['address_1'] = '';
		$snapshot['billing']['address_1']  = '';

		try {
			OrderPayloadBuilder::build( $snapshot, $this->config() );
			$this->fail( 'MappingException expected.' );
		} catch ( MappingException $exception ) {
			$this->assertSame( MappingException::NO_ADDRESS, $exception->reason );
		}
	}

	public function test_a_line_without_sku_names_the_line_for_the_admin_message(): void {
		try {
			OrderPayloadBuilder::build(
				$this->snapshot( array( 'items' => array( array( 'product_id' => 3, 'sku' => ' ', 'name' => 'Darčeková taška', 'quantity' => 1, 'total' => 1.5 ) ) ) ),
				$this->config()
			);
			$this->fail( 'MappingException expected.' );
		} catch ( MappingException $exception ) {
			$this->assertSame( MappingException::NO_SKU, $exception->reason );
			$this->assertSame( 'Darčeková taška', $exception->subject );
		}
	}

	public function test_address_fingerprint_ignores_empty_fields_key_order_and_country_case(): void {
		$sent = array( 'name' => 'Jana Testová', 'street' => 'Obchodná 12', 'city' => 'Bratislava', 'postal_code' => '811 06', 'country' => 'SK' );
		$back = array( 'country' => 'sk', 'city' => 'Bratislava', 'company' => null, 'postal_code' => '811 06', 'street' => 'Obchodná 12 ', 'name' => 'Jana Testová' );

		$this->assertSame( OrderPayloadBuilder::address_fingerprint( $sent ), OrderPayloadBuilder::address_fingerprint( $back ) );
		$this->assertNotSame( OrderPayloadBuilder::address_fingerprint( $sent ), OrderPayloadBuilder::address_fingerprint( array( 'street' => 'Nová 99' ) + $sent ) );
	}

	public function test_items_carry_the_external_id_of_the_variation_or_product(): void {
		$payload = OrderPayloadBuilder::build(
			$this->snapshot(
				array(
					'items' => array(
						array( 'product_id' => 57, 'sku' => 'TRI-M', 'name' => 'Tričko – M', 'quantity' => 1, 'total' => 12.0 ),
						array( 'product_id' => 0, 'sku' => 'GONE', 'name' => 'Zmazaný produkt', 'quantity' => 1, 'total' => 3.0 ),
					),
				)
			),
			$this->config()
		);

		$this->assertSame( 'wc:shop.example:57', $payload['items'][0]['external_id'] );
		$this->assertSame( 'TRI-M', $payload['items'][0]['sku'], 'The SKU is still sent.' );
		$this->assertArrayNotHasKey( 'external_id', $payload['items'][1], 'No product id, no external id.' );
	}
}
