<?php
/**
 * Maps a WooCommerce product to the SkladX `POST /products` payload.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

/**
 * `snapshot()` reads a WC_Product (needs WooCommerce), `build()` is pure and
 * converts units: weight → grams, dimensions → millimetres, price → cents.
 */
final class ProductPayloadBuilder {

	public const EAN_META_KEY = '_skladx_ean';

	/** Grams per store weight unit. */
	private const WEIGHT_TO_GRAMS = array(
		'g'   => 1.0,
		'kg'  => 1000.0,
		'lbs' => 453.59237,
		'oz'  => 28.349523125,
	);

	/** Millimetres per store dimension unit. */
	private const LENGTH_TO_MM = array(
		'mm' => 1.0,
		'cm' => 10.0,
		'm'  => 1000.0,
		'in' => 25.4,
		'yd' => 914.4,
	);

	/**
	 * @return array<string, mixed>
	 */
	public static function snapshot( \WC_Product $product ): array {
		$ean = (string) $product->get_meta( self::EAN_META_KEY );

		if ( '' === $ean && is_callable( array( $product, 'get_global_unique_id' ) ) ) {
			$ean = (string) $product->get_global_unique_id();
		}

		if ( '' === $ean && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$ean    = $parent ? (string) $parent->get_meta( self::EAN_META_KEY ) : '';
		}

		$image_id = (int) $product->get_image_id();

		return array(
			'sku'       => (string) $product->get_sku(),
			'name'      => wp_strip_all_tags( (string) $product->get_name() ),
			'ean'       => $ean,
			'weight'    => (string) $product->get_weight(),
			'length'    => (string) $product->get_length(),
			'width'     => (string) $product->get_width(),
			'height'    => (string) $product->get_height(),
			'price'     => (string) $product->get_regular_price() ?: (string) $product->get_price(),
			'image_url' => $image_id ? (string) wp_get_attachment_url( $image_id ) : '',
			'archived'  => 'trash' === $product->get_status(),
		);
	}

	/**
	 * @param array<string, mixed> $product     Snapshot.
	 * @param string               $weight_unit Store weight unit (woocommerce_weight_unit).
	 * @param string               $length_unit Store dimension unit (woocommerce_dimension_unit).
	 * @param string               $currency    Store currency.
	 * @return array<string, mixed>
	 *
	 * @throws MappingException When the product has no SKU.
	 */
	public static function build( array $product, string $weight_unit, string $length_unit, string $currency ): array {
		$sku = trim( (string) $product['sku'] );

		if ( '' === $sku ) {
			throw new MappingException( 'Product has no SKU.', MappingException::PRODUCT_NO_SKU );
		}

		$dimensions = array_filter(
			array(
				'length' => self::convert( $product['length'], self::LENGTH_TO_MM[ $length_unit ] ?? null ),
				'width'  => self::convert( $product['width'], self::LENGTH_TO_MM[ $length_unit ] ?? null ),
				'height' => self::convert( $product['height'], self::LENGTH_TO_MM[ $length_unit ] ?? null ),
			),
			static fn ( ?int $value ): bool => null !== $value
		);

		$ean   = preg_replace( '/\s+/', '', (string) $product['ean'] );
		$price = self::convert( $product['price'], 100.0 );

		$payload = array(
			'sku'           => mb_substr( $sku, 0, 64 ),
			'name'          => mb_substr( trim( (string) $product['name'] ) ?: $sku, 0, 255 ),
			'ean'           => '' !== $ean ? mb_substr( $ean, 0, 32 ) : null,
			'weight_grams'  => self::convert( $product['weight'], self::WEIGHT_TO_GRAMS[ $weight_unit ] ?? null ),
			'dimensions_mm' => array() !== $dimensions ? $dimensions : null,
			'price_cents'   => $price,
			'currency'      => null !== $price ? strtoupper( $currency ) : null,
			'image_url'     => '' !== (string) ( $product['image_url'] ?? '' ) ? (string) $product['image_url'] : null,
			'archived'      => (bool) ( $product['archived'] ?? false ),
		);

		return array_filter( $payload, static fn ( $value ): bool => null !== $value );
	}

	/**
	 * Converts a decimal string (dot or comma) by a factor to a rounded integer.
	 *
	 * @param mixed      $value  Raw value.
	 * @param float|null $factor Multiplier, null when the unit is unknown.
	 */
	private static function convert( $value, ?float $factor ): ?int {
		$value = str_replace( ',', '.', trim( (string) $value ) );

		if ( null === $factor || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0, (int) round( (float) $value * $factor ) );
	}
}
