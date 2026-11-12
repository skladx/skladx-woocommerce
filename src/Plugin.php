<?php
/**
 * Wires everything together.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce;

use SkladX\WooCommerce\Admin\Actions;
use SkladX\WooCommerce\Admin\Notices;
use SkladX\WooCommerce\Admin\OrderMetaBox;
use SkladX\WooCommerce\Admin\ProductFields;
use SkladX\WooCommerce\Admin\ProductStatus;
use SkladX\WooCommerce\Admin\SettingsPage;
use SkladX\WooCommerce\Cli\Command as CliCommand;
use SkladX\WooCommerce\Inbound\Receipts;
use SkladX\WooCommerce\Pickup\Checkout as PickupCheckout;
use SkladX\WooCommerce\Sync\AddressWatcher;
use SkladX\WooCommerce\Sync\OrderExporter;
use SkladX\WooCommerce\Sync\ProductSync;
use SkladX\WooCommerce\Sync\Tracking;
use SkladX\WooCommerce\Webhooks\EventPoller;
use SkladX\WooCommerce\Webhooks\EventProcessor;
use SkladX\WooCommerce\Webhooks\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap (runs on plugins_loaded when WooCommerce is active).
 */
final class Plugin {

	public const SHIPPED_STATUS = 'wc-skladx-shipped';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'init', array( $this, 'register_order_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_order_status' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'add_paid_status' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'add_paid_status' ) );
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SKLADX_WC_FILE ), array( $this, 'action_links' ) );
		add_action( 'update_option_skladx_api_key', array( $this, 'api_key_changed' ) );
		add_action( 'update_option_skladx_api_base_url', array( $this, 'api_key_changed' ) );

		OrderExporter::register();
		ProductSync::register();
		Tracking::register();
		AddressWatcher::register();
		PickupCheckout::register();
		WebhookController::register();
		EventProcessor::register();
		EventPoller::register();
		Receipts::register();
		CliCommand::register();

		if ( is_admin() ) {
			Notices::register();
			Actions::register();
			OrderMetaBox::register();
			ProductFields::register();
			ProductStatus::register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'skladx-woocommerce', false, dirname( plugin_basename( SKLADX_WC_FILE ) ) . '/languages' );
	}

	public function register_order_status(): void {
		register_post_status(
			self::SHIPPED_STATUS,
			array(
				'label'                     => _x( 'Shipped (SkladX)', 'Order status', 'skladx-woocommerce' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Shipped (SkladX) <span class="count">(%s)</span>', 'Shipped (SkladX) <span class="count">(%s)</span>', 'skladx-woocommerce' ),
			)
		);
	}

	/**
	 * Inserts the "Shipped (SkladX)" status after "Processing".
	 *
	 * @param array<string, string> $statuses Statuses.
	 * @return array<string, string>
	 */
	public function add_order_status( $statuses ): array {
		$result = array();

		foreach ( (array) $statuses as $key => $label ) {
			$result[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$result[ self::SHIPPED_STATUS ] = _x( 'Shipped (SkladX)', 'Order status', 'skladx-woocommerce' );
			}
		}

		$result[ self::SHIPPED_STATUS ] ??= _x( 'Shipped (SkladX)', 'Order status', 'skladx-woocommerce' );

		return $result;
	}

	/**
	 * @param list<string> $statuses Statuses without `wc-`.
	 * @return list<string>
	 */
	public function add_paid_status( $statuses ): array {
		$statuses   = (array) $statuses;
		$statuses[] = 'skladx-shipped';

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * @param array<int|string, \WC_Settings_Page> $pages Settings pages.
	 * @return array<int|string, \WC_Settings_Page>
	 */
	public function add_settings_page( $pages ): array {
		$pages[] = new SettingsPage();

		return $pages;
	}

	/**
	 * @param array<string, string> $links Links.
	 * @return array<string, string>
	 */
	public function action_links( $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=skladx' ) ) . '">' . esc_html__( 'Settings', 'skladx-woocommerce' ) . '</a>' );

		return $links;
	}

	/**
	 * A different key may mean a different client/environment: forget the
	 * connection info and the event cursor (an unknown `after` id would replay
	 * the whole history).
	 */
	public function api_key_changed(): void {
		Settings::set( 'connection_info', array() );
		Settings::set( 'events_cursor', '' );
	}
}
