<?php
/**
 * SkladX sync status of products in wp-admin.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Admin;

use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the stored sync error (`_skladx_product_error`) of a product and its
 * variations as an error notice on the product edit screen, with a
 * "Retry sync" link, and adds a SkladX column to the product list.
 */
final class ProductStatus {

	public const COLUMN = 'skladx';

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_filter( 'manage_edit-product_columns', array( self::class, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_action( 'admin_head-edit.php', array( self::class, 'column_style' ) );
	}

	/**
	 * Error notice on the edit screen of a product whose sync failed.
	 */
	public static function render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'product' !== $screen->id || 'post' !== $screen->base || ! current_user_can( 'edit_products' ) || ! Settings::product_sync_enabled() ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only.
		$product = $post_id ? wc_get_product( $post_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$errors = self::errors( $product );

		if ( array() === $errors ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'SkladX: this product could not be synced.', 'skladx-woocommerce' ) . '</strong></p><ul>';

		foreach ( $errors as $error ) {
			printf(
				'<li>%1$s %2$s <a href="%3$s">%4$s</a></li>',
				'' !== $error['sku'] ? '<code>' . esc_html( $error['sku'] ) . '</code>' : '',
				esc_html( $error['message'] ),
				esc_url( Actions::url( 'skladx_product_resync', array( 'product_id' => $error['id'] ) ) ),
				esc_html__( 'Retry sync', 'skladx-woocommerce' )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function add_column( $columns ): array {
		$result = array();

		foreach ( (array) $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( 'sku' === $key ) {
				$result[ self::COLUMN ] = __( 'SkladX', 'skladx-woocommerce' );
			}
		}

		$result[ self::COLUMN ] ??= __( 'SkladX', 'skladx-woocommerce' );

		return $result;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Product id.
	 */
	public static function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$product = wc_get_product( (int) $post_id );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$errors = self::errors( $product );

		if ( array() !== $errors ) {
			printf(
				'<a class="skladx-badge skladx-badge--error" href="%1$s" title="%2$s">%3$s</a>',
				esc_url( (string) get_edit_post_link( $product->get_id() ) ),
				esc_attr( implode( "\n", array_map( static fn ( array $error ): string => ( '' !== $error['sku'] ? $error['sku'] . ': ' : '' ) . $error['message'], $errors ) ) ),
				esc_html__( 'SkladX: error', 'skladx-woocommerce' )
			);

			return;
		}

		$ids    = $product->is_type( 'variable' ) ? array_map( 'intval', $product->get_children() ) : array( (int) $product->get_id() );
		$synced = array() !== $ids && array() === array_filter( $ids, static fn ( int $id ): bool => '' === (string) get_post_meta( $id, ProductSync::LAST_SYNC_META, true ) );

		if ( $synced ) {
			printf( '<span class="skladx-badge skladx-badge--ok" title="%1$s">%2$s</span>', esc_attr__( 'Synced to SkladX', 'skladx-woocommerce' ), esc_html__( 'OK', 'skladx-woocommerce' ) );
		} else {
			printf( '<span class="skladx-badge" title="%1$s">&ndash;</span>', esc_attr__( 'Not synced to SkladX yet (products without SKU are skipped)', 'skladx-woocommerce' ) );
		}
	}

	public static function column_style(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-product' !== $screen->id ) {
			return;
		}

		echo '<style>.column-skladx{width:7em}.skladx-badge{display:inline-block;padding:0 6px;border-radius:3px;line-height:1.8;font-size:12px;color:#50575e}.skladx-badge--error{background:#d63638;color:#fff}.skladx-badge--error:hover,.skladx-badge--error:focus{color:#fff}.skladx-badge--ok{background:#edfaef;color:#00a32a}</style>';
	}

	/**
	 * Sync errors of a product, or of the variations of a variable product.
	 *
	 * @return list<array{id: int, sku: string, message: string}>
	 */
	public static function errors( \WC_Product $product ): array {
		$ids    = $product->is_type( 'variable' ) ? array_map( 'intval', $product->get_children() ) : array( (int) $product->get_id() );
		$errors = array();

		foreach ( $ids as $id ) {
			$message = (string) get_post_meta( $id, ProductSync::ERROR_META, true );

			if ( '' !== $message ) {
				$errors[] = array(
					'id'      => $id,
					'sku'     => (string) get_post_meta( $id, '_sku', true ),
					'message' => $message,
				);
			}
		}

		return $errors;
	}
}
