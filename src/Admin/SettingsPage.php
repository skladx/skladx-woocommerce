<?php
/**
 * WooCommerce → Settings → SkladX.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Admin;

use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Redactor;
use SkladX\WooCommerce\Sync\StatusMapper;
use SkladX\WooCommerce\Webhooks\EventStore;

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab. Standard WooCommerce fields plus a few custom field types:
 * `skladx_secret` (masked, never printed back), `skladx_carrier_map`,
 * `skladx_status_map` and display-only blocks for connection, webhook and tools.
 */
final class SettingsPage extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = 'skladx';
		$this->label = 'SkladX';

		parent::__construct();

		add_action( 'woocommerce_admin_field_skladx_secret', array( $this, 'render_secret' ) );
		add_action( 'woocommerce_admin_field_skladx_carrier_map', array( $this, 'render_carrier_map' ) );
		add_action( 'woocommerce_admin_field_skladx_status_map', array( $this, 'render_status_map' ) );
		add_action( 'woocommerce_admin_field_skladx_connection', array( $this, 'render_connection' ) );
		add_action( 'woocommerce_admin_field_skladx_webhook', array( $this, 'render_webhook' ) );
		add_action( 'woocommerce_admin_field_skladx_tools', array( $this, 'render_tools' ) );

		add_filter( 'woocommerce_admin_settings_sanitize_option_skladx_api_key', array( $this, 'sanitize_secret' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_skladx_webhook_secret', array( $this, 'sanitize_secret' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_skladx_carrier_map', array( $this, 'sanitize_carrier_map' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_skladx_status_map', array( $this, 'sanitize_status_map' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_skladx_api_base_url', array( $this, 'sanitize_url' ), 10, 3 );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_default_section() {
		$gateways = array();

		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			$gateways[ $gateway->id ] = wp_strip_all_tags( $gateway->get_method_title() ?: $gateway->id );
		}

		return array(
			array(
				'type'  => 'title',
				'id'    => 'skladx_connection_section',
				'title' => __( 'Connection', 'skladx-woocommerce' ),
				'desc'  => __( 'API key from the SkladX client portal (Integrations → API keys). Keys starting with skx_test_ work with the sandbox, skx_live_ with production.', 'skladx-woocommerce' ),
			),
			array(
				'type'              => 'text',
				'id'                => 'skladx_api_base_url',
				'title'             => __( 'API base URL', 'skladx-woocommerce' ),
				'placeholder'       => 'https://skladx.example/api/v1',
				'default'           => '',
				'desc_tip'          => __( 'Including /api/v1, without a trailing slash.', 'skladx-woocommerce' ),
				'custom_attributes' => array( 'spellcheck' => 'false' ),
				'css'               => 'min-width: 360px;',
			),
			array(
				'type'  => 'skladx_secret',
				'id'    => 'skladx_api_key',
				'title' => __( 'API key', 'skladx-woocommerce' ),
				'desc'  => __( 'Needs scopes orders:read/write, products:write, events:read and webhooks:write (stock:read optional). Leave empty to keep the saved key.', 'skladx-woocommerce' ),
			),
			array( 'type' => 'skladx_connection' ),
			array(
				'type' => 'sectionend',
				'id'   => 'skladx_connection_section',
			),

			array(
				'type'  => 'title',
				'id'    => 'skladx_webhook_section',
				'title' => __( 'Webhook', 'skladx-woocommerce' ),
				'desc'  => __( 'SkladX pushes order status, tracking and stock changes to this shop. Register the endpoint automatically, or add it in the SkladX portal and paste the signing secret below.', 'skladx-woocommerce' ),
			),
			array( 'type' => 'skladx_webhook' ),
			array(
				'type'  => 'skladx_secret',
				'id'    => 'skladx_webhook_secret',
				'title' => __( 'Webhook signing secret', 'skladx-woocommerce' ),
				'desc'  => __( 'whsec_… — filled in automatically when the webhook is registered from here.', 'skladx-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'skladx_webhook_section',
			),

			array(
				'type'  => 'title',
				'id'    => 'skladx_orders_section',
				'title' => __( 'Orders', 'skladx-woocommerce' ),
			),
			array(
				'type'    => 'multiselect',
				'id'      => 'skladx_export_statuses',
				'title'   => __( 'Send orders in status', 'skladx-woocommerce' ),
				'desc'    => __( 'An order is sent to SkladX once, when it reaches one of these statuses.', 'skladx-woocommerce' ),
				'class'   => 'wc-enhanced-select',
				'options' => wc_get_order_statuses(),
				'default' => array( 'wc-processing' ),
			),
			array(
				'type'    => 'multiselect',
				'id'      => 'skladx_cod_gateways',
				'title'   => __( 'Cash on delivery payment methods', 'skladx-woocommerce' ),
				'desc'    => __( 'Orders paid with these methods are sent with the order total as the COD amount.', 'skladx-woocommerce' ),
				'class'   => 'wc-enhanced-select',
				'options' => $gateways,
				'default' => array( 'cod' ),
			),
			array(
				'type'  => 'skladx_carrier_map',
				'id'    => 'skladx_carrier_map',
				'title' => __( 'Shipping methods → carriers', 'skladx-woocommerce' ),
			),
			array(
				'type'        => 'text',
				'id'          => 'skladx_pickup_point_meta_key',
				'title'       => __( 'Pickup point meta key', 'skladx-woocommerce' ),
				'desc'        => __( 'Order (or shipping line) meta field holding the pickup point id, e.g. from a Packeta widget.', 'skladx-woocommerce' ),
				'default'     => '_skladx_pickup_point_id',
				'placeholder' => '_skladx_pickup_point_id',
			),
			array(
				'type'  => 'skladx_status_map',
				'id'    => 'skladx_status_map',
				'title' => __( 'SkladX status → WooCommerce status', 'skladx-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'skladx_orders_section',
			),

			array(
				'type'  => 'title',
				'id'    => 'skladx_products_section',
				'title' => __( 'Products & stock', 'skladx-woocommerce' ),
			),
			array(
				'type'    => 'checkbox',
				'id'      => 'skladx_product_sync',
				'title'   => __( 'Product sync', 'skladx-woocommerce' ),
				'desc'    => __( 'Send products (SKU, name, EAN, weight, dimensions, price) to SkladX when they are saved.', 'skladx-woocommerce' ),
				'default' => 'yes',
			),
			array(
				'type'    => 'checkbox',
				'id'      => 'skladx_stock_sync',
				'title'   => __( 'Stock sync', 'skladx-woocommerce' ),
				'desc'    => __( 'Set WooCommerce stock to the quantity available in the SkladX warehouse (SkladX is the source of truth). Only products with "Manage stock" enabled are updated.', 'skladx-woocommerce' ),
				'default' => 'no',
			),
			array( 'type' => 'skladx_tools' ),
			array(
				'type' => 'sectionend',
				'id'   => 'skladx_products_section',
			),

			array(
				'type'  => 'title',
				'id'    => 'skladx_advanced_section',
				'title' => __( 'Advanced', 'skladx-woocommerce' ),
			),
			array(
				'type'    => 'checkbox',
				'id'      => 'skladx_debug',
				'title'   => __( 'Debug log', 'skladx-woocommerce' ),
				'desc'    => sprintf(
					/* translators: %s: link to WooCommerce logs */
					__( 'Log every API call and webhook to WooCommerce → Status → <a href="%s">Logs</a> (source "skladx"). API keys and secrets are always masked.', 'skladx-woocommerce' ),
					esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=skladx' ) )
				),
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'skladx_advanced_section',
			),
		);
	}

	/**
	 * Masked secret input: the stored value is never printed into the page.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public function render_secret( array $field ): void {
		$stored = (string) get_option( $field['id'], '' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label></th>
			<td class="forminp">
				<input type="password" name="<?php echo esc_attr( $field['id'] ); ?>" id="<?php echo esc_attr( $field['id'] ); ?>" value="" autocomplete="new-password" spellcheck="false" style="min-width:360px"
					placeholder="<?php echo esc_attr( '' !== $stored ? sprintf( /* translators: %s: masked secret */ __( 'Saved: %s', 'skladx-woocommerce' ), Redactor::mask( $stored ) ) : '' ); ?>" />
				<?php if ( '' !== $stored ) : ?>
					<label style="margin-left:8px"><input type="checkbox" name="<?php echo esc_attr( $field['id'] ); ?>_clear" value="1" /> <?php esc_html_e( 'Remove', 'skladx-woocommerce' ); ?></label>
				<?php endif; ?>
				<p class="description"><?php echo esc_html( $field['desc'] ?? '' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Keeps the stored secret when the field is submitted empty.
	 *
	 * @param mixed                $value     Sanitized value.
	 * @param array<string, mixed> $option    Field.
	 * @param mixed                $raw_value Raw value.
	 * @return string|null
	 */
	public function sanitize_secret( $value, $option, $raw_value ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the settings nonce.
		if ( ! empty( $_POST[ $option['id'] . '_clear' ] ) ) {
			return '';
		}

		$value = trim( (string) $raw_value );

		return '' === $value ? null : $value;
	}

	/**
	 * @param mixed                $value     Sanitized value.
	 * @param array<string, mixed> $option    Field.
	 * @param mixed                $raw_value Raw value.
	 */
	public function sanitize_url( $value, $option, $raw_value ): string {
		return untrailingslashit( esc_url_raw( trim( (string) $raw_value ) ) );
	}

	/**
	 * Every shipping method instance of every zone, as `method_id:instance_id`.
	 *
	 * @return array<string, string> key => label
	 */
	private function shipping_methods(): array {
		$zones   = \WC_Shipping_Zones::get_zones();
		$zones[] = array(
			'zone_id'          => 0,
			'zone_name'        => __( 'Locations not covered by your other zones', 'skladx-woocommerce' ),
			'shipping_methods' => ( new \WC_Shipping_Zone( 0 ) )->get_shipping_methods(),
		);

		$methods = array();

		foreach ( $zones as $zone ) {
			foreach ( (array) $zone['shipping_methods'] as $method ) {
				$methods[ $method->id . ':' . $method->get_instance_id() ] = sprintf( '%s — %s (%s)', $zone['zone_name'], $method->get_title(), $method->id );
			}
		}

		return $methods;
	}

	/**
	 * @param array<string, mixed> $field Field.
	 */
	public function render_carrier_map( array $field ): void {
		$map     = Settings::carrier_map();
		$methods = $this->shipping_methods();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $field['title'] ); ?></th>
			<td class="forminp">
				<?php if ( array() === $methods ) : ?>
					<p><?php esc_html_e( 'No shipping methods configured yet.', 'skladx-woocommerce' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:720px">
						<thead><tr><th><?php esc_html_e( 'Shipping method', 'skladx-woocommerce' ); ?></th><th><?php esc_html_e( 'SkladX carrier', 'skladx-woocommerce' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $methods as $key => $label ) : ?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td>
									<select name="skladx_carrier_map[<?php echo esc_attr( $key ); ?>]">
										<option value=""><?php esc_html_e( '— warehouse decides —', 'skladx-woocommerce' ); ?></option>
										<?php foreach ( Settings::CARRIERS as $carrier ) : ?>
											<option value="<?php echo esc_attr( $carrier ); ?>" <?php selected( $map[ $key ] ?? '', $carrier ); ?>><?php echo esc_html( $carrier ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Sent as "carrier" with every order. For Packeta pickup points also set the pickup point meta key below.', 'skladx-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param mixed                $value     Sanitized value.
	 * @param array<string, mixed> $option    Field.
	 * @param mixed                $raw_value Raw value.
	 * @return array<string, string>
	 */
	public function sanitize_carrier_map( $value, $option, $raw_value ): array {
		$clean = array();

		foreach ( (array) $raw_value as $key => $carrier ) {
			$carrier = sanitize_key( (string) $carrier );

			if ( in_array( $carrier, Settings::CARRIERS, true ) ) {
				$clean[ sanitize_text_field( (string) $key ) ] = $carrier;
			}
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $field Field.
	 */
	public function render_status_map( array $field ): void {
		$map      = Settings::status_map();
		$statuses = wc_get_order_statuses();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $field['title'] ); ?></th>
			<td class="forminp">
				<table class="widefat striped" style="max-width:720px">
					<thead><tr><th><?php esc_html_e( 'SkladX status', 'skladx-woocommerce' ); ?></th><th><?php esc_html_e( 'Change WooCommerce order to', 'skladx-woocommerce' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( StatusMapper::STATUSES as $status ) : ?>
						<tr>
							<td><code><?php echo esc_html( $status ); ?></code> <?php echo esc_html( self::status_label( $status ) ); ?></td>
							<td>
								<select name="skladx_status_map[<?php echo esc_attr( $status ); ?>]">
									<option value=""><?php esc_html_e( '— keep (order note only) —', 'skladx-woocommerce' ); ?></option>
									<?php foreach ( $statuses as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $map[ $status ] ?? '', $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Every SkladX status change is added as an order note; mapped statuses also change the WooCommerce order status (which may send the usual customer emails).', 'skladx-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param mixed                $value     Sanitized value.
	 * @param array<string, mixed> $option    Field.
	 * @param mixed                $raw_value Raw value.
	 * @return array<string, string>
	 */
	public function sanitize_status_map( $value, $option, $raw_value ): array {
		$statuses = wc_get_order_statuses();
		$clean    = array();

		foreach ( (array) $raw_value as $status => $target ) {
			if ( StatusMapper::is_known( (string) $status ) && isset( $statuses[ (string) $target ] ) ) {
				$clean[ (string) $status ] = (string) $target;
			}
		}

		return $clean;
	}

	public static function status_label( string $status ): string {
		$labels = array(
			'draft'            => __( 'Draft', 'skladx-woocommerce' ),
			'received'         => __( 'Received', 'skladx-woocommerce' ),
			'on_hold'          => __( 'On hold', 'skladx-woocommerce' ),
			'awaiting_stock'   => __( 'Awaiting stock', 'skladx-woocommerce' ),
			'ready'            => __( 'Ready for fulfilment', 'skladx-woocommerce' ),
			'allocated'        => __( 'Stock allocated', 'skladx-woocommerce' ),
			'picking'          => __( 'Picking', 'skladx-woocommerce' ),
			'picked'           => __( 'Picked', 'skladx-woocommerce' ),
			'packing'          => __( 'Packing', 'skladx-woocommerce' ),
			'packed'           => __( 'Packed', 'skladx-woocommerce' ),
			'label_created'    => __( 'Label created', 'skladx-woocommerce' ),
			'handed_over'      => __( 'Handed over to carrier', 'skladx-woocommerce' ),
			'in_transit'       => __( 'In transit', 'skladx-woocommerce' ),
			'out_for_delivery' => __( 'Out for delivery', 'skladx-woocommerce' ),
			'delivered'        => __( 'Delivered', 'skladx-woocommerce' ),
			'delivery_failed'  => __( 'Delivery failed', 'skladx-woocommerce' ),
			'returning'        => __( 'Returning', 'skladx-woocommerce' ),
			'returned'         => __( 'Returned', 'skladx-woocommerce' ),
			'cancelled'        => __( 'Cancelled', 'skladx-woocommerce' ),
		);

		return $labels[ $status ] ?? $status;
	}

	public function render_connection(): void {
		$info = (array) Settings::get( 'connection_info' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Connection status', 'skladx-woocommerce' ); ?></th>
			<td class="forminp">
				<?php if ( ! empty( $info['ok'] ) ) : ?>
					<p>
						<span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>
						<?php
						printf(
							/* translators: 1: client name, 2: environment name, 3: sandbox/production */
							esc_html__( 'Connected to client %1$s, environment %2$s (%3$s).', 'skladx-woocommerce' ),
							'<strong>' . esc_html( (string) ( $info['client']['name'] ?? '?' ) ) . '</strong>',
							'<strong>' . esc_html( (string) ( $info['environment']['name'] ?? '?' ) ) . '</strong>',
							! empty( $info['environment']['sandbox'] ) ? esc_html__( 'sandbox', 'skladx-woocommerce' ) : esc_html__( 'production', 'skladx-woocommerce' )
						);
						?>
					</p>
					<p class="description">
						<?php
						/* translators: 1: scopes, 2: date */
						printf( esc_html__( 'Scopes: %1$s · checked %2$s', 'skladx-woocommerce' ), esc_html( implode( ', ', (array) ( $info['scopes'] ?? array() ) ) ), esc_html( wp_date( 'j. n. Y H:i', (int) ( $info['checked_at'] ?? 0 ) ) ) );
						?>
					</p>
				<?php elseif ( ! empty( $info['error'] ) ) : ?>
					<p><span class="dashicons dashicons-warning" style="color:#d63638"></span> <?php echo esc_html( (string) $info['error'] ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Not tested yet. Save the settings, then test the connection.', 'skladx-woocommerce' ); ?></p>
				<?php endif; ?>
				<p><a class="button" href="<?php echo esc_url( Actions::url( 'skladx_test_connection' ) ); ?>"><?php esc_html_e( 'Test connection', 'skladx-woocommerce' ); ?></a></p>
			</td>
		</tr>
		<?php
	}

	public function render_webhook(): void {
		$webhook_id = (string) Settings::get( 'webhook_id' );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Endpoint URL', 'skladx-woocommerce' ); ?></th>
			<td class="forminp">
				<input type="text" readonly class="regular-text code" style="min-width:480px" value="<?php echo esc_attr( Settings::webhook_url() ); ?>" onclick="this.select()" />
				<p class="description">
					<?php
					/* translators: %s: comma separated event types */
					printf( esc_html__( 'Events: %s', 'skladx-woocommerce' ), '<code>' . esc_html( implode( ', ', Settings::WEBHOOK_EVENTS ) ) . '</code>' );
					?>
				</p>
				<p>
					<?php if ( '' !== $webhook_id ) : ?>
						<?php
						/* translators: %s: webhook id */
						printf( esc_html__( 'Registered in SkladX as %s.', 'skladx-woocommerce' ), '<code>' . esc_html( $webhook_id ) . '</code>' );
						?>
						<br />
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( Actions::url( 'skladx_register_webhook' ) ); ?>"><?php echo '' !== $webhook_id ? esc_html__( 'Re-register webhook', 'skladx-woocommerce' ) : esc_html__( 'Register webhook in SkladX', 'skladx-woocommerce' ); ?></a>
				</p>
			</td>
		</tr>
		<?php
	}

	public function render_tools(): void {
		$counts = EventStore::counts();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Tools', 'skladx-woocommerce' ); ?></th>
			<td class="forminp">
				<a class="button" href="<?php echo esc_url( Actions::url( 'skladx_sync_products' ) ); ?>"><?php esc_html_e( 'Sync all products', 'skladx-woocommerce' ); ?></a>
				<a class="button" href="<?php echo esc_url( Actions::url( 'skladx_poll_events' ) ); ?>"><?php esc_html_e( 'Fetch missed events now', 'skladx-woocommerce' ); ?></a>
				<p class="description">
					<?php
					printf(
						/* translators: 1: processed count, 2: ignored count, 3: failed count, 4: pending count */
						esc_html__( 'Received events (last 30 days): %1$d processed, %2$d ignored, %3$d failed, %4$d pending. Missed webhooks are fetched automatically every 10 minutes.', 'skladx-woocommerce' ),
						(int) ( $counts['processed'] ?? 0 ),
						(int) ( $counts['ignored'] ?? 0 ),
						(int) ( $counts['failed'] ?? 0 ),
						(int) ( $counts['pending'] ?? 0 )
					);
					?>
				</p>
			</td>
		</tr>
		<?php
	}
}
