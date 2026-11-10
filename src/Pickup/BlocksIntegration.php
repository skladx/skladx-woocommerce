<?php
/**
 * Checkout block integration (loads the picker for the block checkout).
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Pickup;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Registers assets/js/pickup-blocks.js (a plain script using the globals
 * wp.plugins, wp.element, wp.data and wc.blocksCheckout — no build step), which
 * adds the picker below the shipping options of the Checkout block.
 */
final class BlocksIntegration implements IntegrationInterface {

	public function get_name(): string {
		return 'skladx-pickup';
	}

	public function initialize(): void {
		Checkout::register_assets();

		wp_register_script(
			'skladx-pickup-blocks',
			plugins_url( 'assets/js/pickup-blocks.js', SKLADX_WC_FILE ),
			array( 'skladx-pickup', 'wp-plugins', 'wp-element', 'wp-data', 'wc-blocks-checkout' ),
			SKLADX_WC_VERSION,
			true
		);

		wp_enqueue_style( 'skladx-pickup' );
	}

	/**
	 * @return list<string>
	 */
	public function get_script_handles(): array {
		return array( 'skladx-pickup-blocks' );
	}

	/**
	 * @return list<string>
	 */
	public function get_editor_script_handles(): array {
		return array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_script_data(): array {
		return array();
	}
}
