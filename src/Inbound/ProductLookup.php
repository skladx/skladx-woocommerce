<?php
/**
 * Finds WooCommerce products for receipt lines (autocomplete, scan, paste).
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

use SkladX\WooCommerce\Sync\ProductPayloadBuilder;
use SkladX\WooCommerce\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Only products with a SkladX SKU can be received (SkladX matches by SKU):
 * the own SKU or the `WC-{id}` fallback (ProductSync::sku()).
 * Variable and grouped parents are skipped — their variations are listed.
 */
final class ProductLookup {

	public const LIMIT = 20;

	/**
	 * Autocomplete: name, SKU or EAN.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function search( string $term, int $limit = self::LIMIT ): array {
		$term = trim( $term );

		if ( '' === $term ) {
			return array();
		}

		$ids = array();
		$id  = self::find_id( $term );

		if ( $id ) {
			$ids[] = $id;
		}

		$data_store = \WC_Data_Store::load( 'product' );
		$ids        = array_merge( $ids, array_map( 'intval', (array) $data_store->search_products( $term, '', true, true, $limit * 2 ) ) );
		$results    = array();

		foreach ( array_unique( array_filter( $ids ) ) as $product_id ) {
			$product = wc_get_product( $product_id );
			$info    = $product instanceof \WC_Product ? self::info( $product ) : null;

			if ( null !== $info ) {
				$results[] = $info;
			}

			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Exact match of a scanned or pasted code: SKU first, then EAN.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function resolve( string $code ): ?array {
		$id      = self::find_id( trim( $code ) );
		$product = $id ? wc_get_product( $id ) : null;

		return $product instanceof \WC_Product ? self::info( $product ) : null;
	}

	/**
	 * Products of the given SKUs (for receipts loaded from SkladX).
	 *
	 * @param list<string> $skus SKUs.
	 * @return array<string, array<string, mixed>> SKU => product info.
	 */
	public static function by_skus( array $skus ): array {
		$found = array();

		foreach ( array_unique( array_filter( array_map( 'strval', $skus ) ) ) as $sku ) {
			$id      = ProductSync::product_id_by_sku( $sku );
			$product = $id ? wc_get_product( $id ) : null;
			$info    = $product instanceof \WC_Product ? self::info( $product ) : null;

			if ( null !== $info ) {
				$found[ $sku ] = $info;
			}
		}

		return $found;
	}

	/**
	 * What the receipt editor needs to know about a product, or null when it
	 * cannot be received (no SKU, variable/grouped parent).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function info( \WC_Product $product ): ?array {
		$sku = ProductSync::sku( $product );

		if ( '' === $sku || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) || 'trash' === $product->get_status() ) {
			return null;
		}

		$error  = (string) $product->get_meta( ProductSync::ERROR_META );
		$synced = '' !== (string) $product->get_meta( ProductSync::LAST_SYNC_META );

		return array(
			'id'         => $product->get_id(),
			'sku'        => $sku,
			'name'       => wp_strip_all_tags( (string) $product->get_name() ),
			'ean'        => self::ean( $product ),
			'in_skladx'  => $synced && '' === $error,
			'sync_error' => $error,
			'edit_url'   => (string) get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id(), 'raw' ),
		);
	}

	private static function find_id( string $code ): int {
		if ( '' === $code ) {
			return 0;
		}

		$id = ProductSync::product_id_by_sku( $code );

		if ( $id ) {
			return $id;
		}

		if ( function_exists( 'wc_get_product_id_by_global_unique_id' ) ) {
			$id = (int) wc_get_product_id_by_global_unique_id( $code );

			if ( $id ) {
				return $id;
			}
		}

		$ean_query = array( 'relation' => 'OR' );

		foreach ( array_merge( array( ProductPayloadBuilder::EAN_META_KEY ), ProductPayloadBuilder::plugin_meta_keys() ) as $key ) {
			$ean_query[] = array(
				'key'   => $key,
				'value' => $code,
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- exact match on a few EAN meta keys, admin only.
				'meta_query'     => $ean_query,
			)
		);

		return (int) ( $posts[0] ?? 0 );
	}

	private static function ean( \WC_Product $product ): string {
		return ProductPayloadBuilder::ean( $product );
	}
}
