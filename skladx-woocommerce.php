<?php
/**
 * Plugin Name:          SkladX for WooCommerce
 * Plugin URI:           https://github.com/skladx/skladx-woocommerce
 * Description:          Connects WooCommerce with the SkladX fulfilment warehouse: exports orders, syncs products and stock, and receives order status and tracking updates via signed webhooks.
 * Version:              0.2.0
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               SkladX
 * Author URI:           https://github.com/skladx
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          skladx-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.2
 * WC tested up to:      11.2
 *
 * @package SkladX\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'SKLADX_WC_VERSION', '0.2.0' );
define( 'SKLADX_WC_FILE', __FILE__ );
define( 'SKLADX_WC_DIR', __DIR__ );

require_once __DIR__ . '/src/autoload.php';

/*
 * Declare compatibility with WooCommerce features (HPOS = custom order tables,
 * Cart & Checkout blocks) before WooCommerce initialises.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SKLADX_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SKLADX_WC_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \SkladX\WooCommerce\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \SkladX\WooCommerce\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'SkladX for WooCommerce requires WooCommerce to be installed and active.', 'skladx-woocommerce' ) . '</p></div>';
				}
			);

			return;
		}

		\SkladX\WooCommerce\Plugin::instance()->boot();
	}
);
