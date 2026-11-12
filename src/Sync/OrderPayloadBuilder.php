<?php
/**
 * Maps a WooCommerce order to the SkladX `POST /orders` payload.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

/**
 * Two steps: `snapshot()` reads a WC_Order into a plain array (needs
 * WooCommerce), `build()` turns the snapshot into the API payload (pure —
 * unit tested).
 *
 * @phpstan-type Address array{first_name: string, last_name: string, company: string, address_1: string, address_2: string, city: string, postcode: string, country: string, phone: string}
 * @phpstan-type Snapshot array{id: int, number: string, currency: string, total: float, payment_method: string, customer_note: string, email: string, phone: string, shipping: Address, billing: Address, items: list<array{product_id: int, sku: string, name: string, quantity: int, total: float}>, shipping_lines: list<array{method_id: string, instance_id: string, meta: array<string, string>}>, meta: array<string, string>}
 * @phpstan-type Config array{carrier_map: array<string, string>, cod_gateways: list<string>, pickup_point_meta_key: string, site: string, site_key?: string, default_country: string}
 */
final class OrderPayloadBuilder {

	/**
	 * Reads everything the payload needs from a WooCommerce order.
	 *
	 * @param \WC_Order $order                 Order.
	 * @param string    $pickup_point_meta_key Meta key holding the pickup point id.
	 * @return array<string, mixed> Snapshot.
	 */
	public static function snapshot( \WC_Order $order, string $pickup_point_meta_key ): array {
		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			$items[] = array(
				'product_id' => (int) $item->get_variation_id() ?: (int) $item->get_product_id(),
				// Same SKU as the product sync (own SKU or the `WC-{id}` fallback).
				'sku'        => $product ? ProductSync::sku( $product ) : '',
				'name'       => (string) $item->get_name(),
				'quantity'   => (int) $item->get_quantity(),
				'total'      => (float) $item->get_total() + (float) $item->get_total_tax(),
			);
		}

		$shipping_lines = array();

		foreach ( $order->get_items( 'shipping' ) as $line ) {
			if ( ! $line instanceof \WC_Order_Item_Shipping ) {
				continue;
			}

			$meta = array();

			foreach ( $line->get_meta_data() as $meta_item ) {
				$data = $meta_item->get_data();

				if ( is_scalar( $data['value'] ) ) {
					$meta[ (string) $data['key'] ] = (string) $data['value'];
				}
			}

			$shipping_lines[] = array(
				'method_id'   => (string) $line->get_method_id(),
				'instance_id' => (string) $line->get_instance_id(),
				'meta'        => $meta,
			);
		}

		$address = static function ( string $type ) use ( $order ): array {
			$get = static fn ( string $field ): string => (string) ( is_callable( array( $order, "get_{$type}_{$field}" ) ) ? $order->{"get_{$type}_{$field}"}() : '' );

			return array(
				'first_name' => $get( 'first_name' ),
				'last_name'  => $get( 'last_name' ),
				'company'    => $get( 'company' ),
				'address_1'  => $get( 'address_1' ),
				'address_2'  => $get( 'address_2' ),
				'city'       => $get( 'city' ),
				'postcode'   => $get( 'postcode' ),
				'country'    => $get( 'country' ),
				'phone'      => $get( 'phone' ),
			);
		};

		$meta = array();

		if ( '' !== $pickup_point_meta_key ) {
			$meta[ $pickup_point_meta_key ] = (string) $order->get_meta( $pickup_point_meta_key );
		}

		return array(
			'id'             => (int) $order->get_id(),
			'number'         => (string) $order->get_order_number(),
			'currency'       => (string) $order->get_currency(),
			'total'          => (float) $order->get_total(),
			'payment_method' => (string) $order->get_payment_method(),
			'customer_note'  => (string) $order->get_customer_note(),
			'email'          => (string) $order->get_billing_email(),
			'phone'          => (string) $order->get_billing_phone(),
			'shipping'       => $address( 'shipping' ),
			'billing'        => $address( 'billing' ),
			'items'          => $items,
			'shipping_lines' => $shipping_lines,
			'meta'           => $meta,
		);
	}

	/**
	 * Builds the `POST /orders` payload.
	 *
	 * @param array<string, mixed> $order  Snapshot from snapshot().
	 * @param array<string, mixed> $config Carrier map, COD gateways, pickup point key, site host, site key (external ids), default country.
	 * @return array<string, mixed>
	 *
	 * @throws MappingException When the order cannot be exported (no items, missing SKU, no address).
	 */
	public static function build( array $order, array $config ): array {
		$items    = array();
		$site_key = (string) ( $config['site_key'] ?? '' );

		foreach ( $order['items'] as $item ) {
			$sku = trim( (string) $item['sku'] );

			if ( '' === $sku ) {
				throw new MappingException( sprintf( 'Order line "%s" has no SKU.', $item['name'] ), MappingException::NO_SKU, (string) $item['name'] );
			}

			$quantity = (int) $item['quantity'];

			if ( $quantity < 1 ) {
				continue;
			}

			if ( isset( $items[ $sku ] ) ) {
				$items[ $sku ]['quantity'] += $quantity;
				$items[ $sku ]['line_total'] += (float) $item['total'];
				continue;
			}

			$items[ $sku ] = array(
				'sku'         => $sku,
				'external_id' => ExternalId::format( $site_key, (int) ( $item['product_id'] ?? 0 ) ),
				'name'        => mb_substr( (string) $item['name'], 0, 255 ),
				'quantity'    => $quantity,
				'line_total'  => (float) $item['total'],
			);
		}

		if ( array() === $items ) {
			throw new MappingException( 'Order has no products to ship.', MappingException::NO_ITEMS );
		}

		$items = array_values(
			array_map(
				static fn ( array $item ): array => array_filter(
					array(
						'sku'              => $item['sku'],
						'external_id'      => '' !== $item['external_id'] ? $item['external_id'] : null,
						'name'             => $item['name'],
						'quantity'         => $item['quantity'],
						'unit_price_cents' => self::cents( $item['line_total'] / $item['quantity'] ),
					),
					static fn ( $value ): bool => null !== $value
				),
				$items
			)
		);

		$address = '' !== trim( (string) $order['shipping']['address_1'] ) ? $order['shipping'] : $order['billing'];

		if ( '' === trim( (string) $address['address_1'] ) ) {
			throw new MappingException( 'Order has no shipping address.', MappingException::NO_ADDRESS );
		}

		$shipping_address = array_filter(
			array(
				'name'        => self::limit( trim( $address['first_name'] . ' ' . $address['last_name'] ) ?: (string) $address['company'], 255 ),
				'company'     => self::limit( (string) $address['company'], 255 ),
				'street'      => self::limit( trim( $address['address_1'] . ' ' . $address['address_2'] ), 255 ),
				'city'        => self::limit( (string) $address['city'], 128 ),
				'postal_code' => self::limit( (string) $address['postcode'], 16 ),
				'country'     => strtoupper( (string) ( $address['country'] ?: $config['default_country'] ) ),
			),
			static fn ( string $value ): bool => '' !== $value
		);

		[ $carrier, $pickup_point ] = self::shipping( $order, $config );

		$phone = trim( (string) ( $order['shipping']['phone'] ?? '' ) ) ?: trim( (string) $order['phone'] );
		$cod   = in_array( $order['payment_method'], $config['cod_gateways'], true ) ? self::cents( (float) $order['total'] ) : null;

		$payload = array(
			'reference'        => self::limit( (string) $order['number'], 64 ),
			'items'            => $items,
			'shipping_address' => $shipping_address,
			'carrier'          => $carrier,
			'pickup_point_id'  => $pickup_point,
			'cod_cents'        => $cod,
			'total_cents'      => self::cents( (float) $order['total'] ),
			'currency'         => strtoupper( (string) $order['currency'] ),
			'customer_email'   => '' !== trim( (string) $order['email'] ) ? trim( (string) $order['email'] ) : null,
			'customer_phone'   => '' !== $phone ? self::limit( $phone, 32 ) : null,
			'note'             => '' !== trim( (string) $order['customer_note'] ) ? self::limit( trim( (string) $order['customer_note'] ), 2000 ) : null,
			'metadata'         => array(
				'wc_order_id'     => (int) $order['id'],
				'wc_order_number' => (string) $order['number'],
				'site'            => (string) $config['site'],
			),
		);

		return array_filter( $payload, static fn ( $value ): bool => null !== $value );
	}

	/**
	 * Carrier code and pickup point id from the first shipping line.
	 *
	 * @param array<string, mixed> $order  Snapshot.
	 * @param array<string, mixed> $config Config.
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function shipping( array $order, array $config ): array {
		$carrier      = null;
		$pickup_point = null;
		$key          = (string) $config['pickup_point_meta_key'];

		foreach ( $order['shipping_lines'] as $line ) {
			$carrier ??= $config['carrier_map'][ $line['method_id'] . ':' . $line['instance_id'] ]
				?? $config['carrier_map'][ $line['method_id'] ]
				?? null;

			if ( '' !== $key && null === $pickup_point && '' !== trim( (string) ( $line['meta'][ $key ] ?? '' ) ) ) {
				$pickup_point = trim( (string) $line['meta'][ $key ] );
			}
		}

		if ( '' !== $key && null === $pickup_point && '' !== trim( (string) ( $order['meta'][ $key ] ?? '' ) ) ) {
			$pickup_point = trim( (string) $order['meta'][ $key ] );
		}

		return array(
			null !== $carrier && '' !== $carrier ? (string) $carrier : null,
			null !== $pickup_point ? self::limit( $pickup_point, 64 ) : null,
		);
	}

	/**
	 * Fingerprint of a SkladX shipping address, to notice that the address in
	 * WooCommerce changed after the export. Empty fields and key order do not
	 * matter.
	 *
	 * @param array<string, mixed> $address `shipping_address` of the payload or of a SkladX order.
	 */
	public static function address_fingerprint( array $address ): string {
		$fields = array();

		foreach ( array( 'name', 'company', 'street', 'city', 'postal_code', 'country' ) as $key ) {
			$value = is_scalar( $address[ $key ] ?? null ) ? trim( (string) $address[ $key ] ) : '';

			if ( '' !== $value ) {
				$fields[ $key ] = 'country' === $key ? strtoupper( $value ) : $value;
			}
		}

		return md5( (string) json_encode( $fields ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure helper, no WordPress in unit tests.
	}

	private static function cents( float $amount ): int {
		return max( 0, (int) round( $amount * 100 ) );
	}

	private static function limit( string $value, int $length ): string {
		return mb_substr( $value, 0, $length );
	}
}
