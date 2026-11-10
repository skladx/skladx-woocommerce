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

	/** Most gallery images sent besides the main image. */
	public const MAX_IMAGES = 10;

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
	 * @param \WC_Product $product     Product or variation.
	 * @param string      $external_id Stable external id (ExternalId::format()).
	 * @return array<string, mixed>
	 */
	public static function snapshot( \WC_Product $product, string $external_id = '' ): array {
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$parent = $parent instanceof \WC_Product ? $parent : null;
		$ean    = (string) $product->get_meta( self::EAN_META_KEY );

		if ( '' === $ean && is_callable( array( $product, 'get_global_unique_id' ) ) ) {
			$ean = (string) $product->get_global_unique_id();
		}

		if ( '' === $ean && null !== $parent ) {
			$ean = (string) $parent->get_meta( self::EAN_META_KEY );
		}

		$image_id    = (int) $product->get_image_id();
		$gallery_ids = array_map( 'intval', (array) $product->get_gallery_image_ids() );

		if ( array() === $gallery_ids && null !== $parent ) {
			$gallery_ids = array_map( 'intval', (array) $parent->get_gallery_image_ids() );
		}

		$images = array();

		foreach ( array_slice( array_diff( $gallery_ids, array( $image_id ) ), 0, self::MAX_IMAGES ) as $gallery_id ) {
			$images[] = (string) wp_get_attachment_url( $gallery_id );
		}

		return array(
			'external_id' => $external_id,
			'sku'         => (string) $product->get_sku(),
			'name'        => wp_strip_all_tags( (string) $product->get_name() ),
			'ean'         => $ean,
			'weight'      => (string) $product->get_weight(),
			'length'      => (string) $product->get_length(),
			'width'       => (string) $product->get_width(),
			'height'      => (string) $product->get_height(),
			'price'       => (string) $product->get_regular_price() ?: (string) $product->get_price(),
			'image_url'   => $image_id ? (string) wp_get_attachment_url( $image_id ) : '',
			'images'      => $images,
			'archived'    => 'trash' === $product->get_status() || ( null !== $parent && 'trash' === $parent->get_status() ),
			'home_url'    => home_url( '/' ),
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

		$ean         = preg_replace( '/\s+/', '', (string) $product['ean'] );
		$price       = self::convert( $product['price'], 100.0 );
		$external_id = mb_substr( trim( (string) ( $product['external_id'] ?? '' ) ), 0, ExternalId::MAX_LENGTH );
		$home_url    = (string) ( $product['home_url'] ?? '' );
		$image_url   = self::absolute_url( (string) ( $product['image_url'] ?? '' ), $home_url );
		$images      = array();

		foreach ( (array) ( $product['images'] ?? array() ) as $url ) {
			$url = is_string( $url ) ? self::absolute_url( $url, $home_url ) : null;

			if ( null !== $url && $url !== $image_url && ! in_array( $url, $images, true ) && count( $images ) < self::MAX_IMAGES ) {
				$images[] = $url;
			}
		}

		$payload = array(
			'sku'           => mb_substr( $sku, 0, 64 ),
			'external_id'   => '' !== $external_id ? $external_id : null,
			'name'          => mb_substr( trim( (string) $product['name'] ) ?: $sku, 0, 255 ),
			'ean'           => '' !== $ean ? mb_substr( $ean, 0, 32 ) : null,
			'weight_grams'  => self::convert( $product['weight'], self::WEIGHT_TO_GRAMS[ $weight_unit ] ?? null ),
			'dimensions_mm' => array() !== $dimensions ? $dimensions : null,
			'price_cents'   => $price,
			'currency'      => null !== $price ? strtoupper( $currency ) : null,
			'image_url'     => $image_url,
			'images'        => array() !== $images ? $images : null,
			'archived'      => (bool) ( $product['archived'] ?? false ),
		);

		return array_filter( $payload, static fn ( $value ): bool => null !== $value );
	}

	/**
	 * Absolute http(s) URL of an image, or null when it is not one.
	 *
	 * Relative URLs are resolved against the site address; on an HTTPS site
	 * `http://` URLs (a stale `siteurl`, old uploads) are upgraded to
	 * `https://`. Other schemes (data:, ftp:…) are skipped.
	 *
	 * @param string $url      Image URL.
	 * @param string $home_url Site address (home_url()), '' when unknown.
	 */
	public static function absolute_url( string $url, string $home_url = '' ): ?string {
		$url  = trim( $url );
		$home = parse_url( $home_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress in unit tests.
		$home = is_array( $home ) ? $home : array();

		$home_scheme = strtolower( (string) ( $home['scheme'] ?? '' ) );

		if ( '' === $url ) {
			return null;
		}

		if ( str_starts_with( $url, '//' ) ) {
			$url = ( '' !== $home_scheme ? $home_scheme : 'https' ) . ':' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			if ( empty( $home['host'] ) || '' === $home_scheme ) {
				return null;
			}

			$url = $home_scheme . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $url;
		}

		$parts  = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress in unit tests.
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return null;
		}

		if ( 'http' === $scheme && 'https' === $home_scheme ) {
			$url = 'https' . substr( $url, strlen( 'http' ) );
		}

		return $url;
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
