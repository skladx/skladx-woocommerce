<?php
/**
 * Fallback SKU of products without a SKU.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

/**
 * SkladX needs a SKU for every product. A product (or variation) without its
 * own SKU is sent as `WC-{id}` — the product id for simple products, the
 * variation id for variations. The id never changes, so the fallback SKU is
 * stable; the product is linked by its `external_id` (`wc:{site}:{id}`), so
 * when a real SKU is added later SkladX renames the SKU of the same product
 * instead of creating a new one. Pure PHP — unit tested.
 */
final class ProductSku {

	public const FALLBACK_PREFIX = 'WC-';

	/**
	 * Fallback SKU of a product or variation id ('' for an invalid id).
	 *
	 * @param int $product_id Product id, or the variation id for variations.
	 */
	public static function fallback( int $product_id ): string {
		return $product_id > 0 ? self::FALLBACK_PREFIX . $product_id : '';
	}

	/**
	 * Product id encoded in a fallback SKU, null for other SKUs.
	 *
	 * @param string $sku SKU (e.g. from a SkladX stock event).
	 */
	public static function fallback_id( string $sku ): ?int {
		if ( ! preg_match( '/^' . preg_quote( self::FALLBACK_PREFIX, '/' ) . '([1-9]\d{0,19})$/i', trim( $sku ), $matches ) ) {
			return null;
		}

		return (int) $matches[1];
	}
}
