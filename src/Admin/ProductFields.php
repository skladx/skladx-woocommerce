<?php
/**
 * EAN field on the product inventory tab.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Admin;

use SkladX\WooCommerce\Sync\ProductPayloadBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * `_skladx_ean` on simple products and variations. When empty, the
 * WooCommerce GTIN/EAN field (global unique id) is used instead.
 */
final class ProductFields {

	public static function register(): void {
		add_action( 'woocommerce_product_options_sku', array( self::class, 'render_simple' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save_simple' ) );
		add_action( 'woocommerce_variation_options_inventory', array( self::class, 'render_variation' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( self::class, 'save_variation' ), 10, 2 );
	}

	public static function render_simple(): void {
		woocommerce_wp_text_input(
			array(
				'id'          => ProductPayloadBuilder::EAN_META_KEY,
				'label'       => __( 'EAN for SkladX', 'skladx-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'Barcode used by the SkladX warehouse. Leave empty to use the GTIN/EAN field.', 'skladx-woocommerce' ),
			)
		);
	}

	public static function save_simple( \WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product nonce.
		if ( isset( $_POST[ ProductPayloadBuilder::EAN_META_KEY ] ) ) {
			$product->update_meta_data( ProductPayloadBuilder::EAN_META_KEY, sanitize_text_field( wp_unslash( $_POST[ ProductPayloadBuilder::EAN_META_KEY ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/**
	 * @param int      $loop           Variation index.
	 * @param array    $variation_data Variation data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function render_variation( $loop, $variation_data, $variation ): void {
		woocommerce_wp_text_input(
			array(
				'id'            => ProductPayloadBuilder::EAN_META_KEY . '_' . $loop,
				'name'          => ProductPayloadBuilder::EAN_META_KEY . '[' . $loop . ']',
				'value'         => (string) get_post_meta( $variation->ID, ProductPayloadBuilder::EAN_META_KEY, true ),
				'label'         => __( 'EAN for SkladX', 'skladx-woocommerce' ),
				'wrapper_class' => 'form-row form-row-full',
			)
		);
	}

	/**
	 * @param \WC_Product_Variation $variation Variation.
	 * @param int                   $index     Variation index.
	 */
	public static function save_variation( $variation, $index ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the nonce.
		$values = isset( $_POST[ ProductPayloadBuilder::EAN_META_KEY ] ) ? (array) wp_unslash( $_POST[ ProductPayloadBuilder::EAN_META_KEY ] ) : array();

		if ( isset( $values[ $index ] ) ) {
			$variation->update_meta_data( ProductPayloadBuilder::EAN_META_KEY, sanitize_text_field( (string) $values[ $index ] ) );
		}
	}
}
