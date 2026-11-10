<?php
/**
 * Pickup point picker in the classic checkout and the Checkout block.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Pickup;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Flow (both checkouts):
 *
 * 1. When the chosen shipping method is a pickup point method, a
 *    "Choose pickup point" button opens the picker (assets/js/pickup.js),
 *    which searches through the plugin's REST proxy.
 * 2. The chosen point is sent to the Store API (`cart/extensions`, namespace
 *    `skladx-pickup`), verified against SkladX and kept in the WC session.
 * 3. At checkout the point is validated again (chosen, active, right carrier,
 *    accepts cash on delivery when a COD gateway is used) and written to the
 *    order: the configured pickup point meta key (id), plus carrier, name and
 *    address for admin screens and emails.
 */
final class Checkout {

	public const NAMESPACE   = 'skladx-pickup';
	public const SESSION_KEY = 'skladx_pickup_points';

	public const META_POINT   = '_skladx_pickup_point';
	public const META_CARRIER = '_skladx_pickup_point_carrier';
	public const META_NAME    = '_skladx_pickup_point_name';
	public const META_ADDRESS = '_skladx_pickup_point_address';

	public static function register(): void {
		// Display of an already chosen point works even when the picker is turned off later.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( self::class, 'render_admin' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( self::class, 'render_order_details' ), 4 );
		add_action( 'woocommerce_email_order_meta', array( self::class, 'render_email' ), 15, 3 );

		if ( ! Settings::pickup_picker_enabled() || ! Settings::is_configured() ) {
			return;
		}

		RestController::register();

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'woocommerce_after_shipping_rate', array( self::class, 'render_classic' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'save_classic' ), 10, 2 );

		add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_store_api' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( self::class, 'register_block_integration' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'save_blocks' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		self::register_assets();
		wp_enqueue_script( 'skladx-pickup' );
		wp_enqueue_style( 'skladx-pickup' );
	}

	public static function register_assets(): void {
		if ( wp_script_is( 'skladx-pickup', 'registered' ) ) {
			return;
		}

		$url = plugins_url( 'assets/', SKLADX_WC_FILE );

		wp_register_style( 'skladx-pickup', $url . 'css/pickup.css', array(), SKLADX_WC_VERSION );
		wp_register_script( 'skladx-pickup', $url . 'js/pickup.js', array(), SKLADX_WC_VERSION, true );
		wp_localize_script( 'skladx-pickup', 'skladxPickupConfig', self::script_config() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function script_config(): array {
		return array(
			'restUrl'        => esc_url_raw( rest_url( 'skladx/v1' . RestController::ROUTE ) ),
			'storeApiUrl'    => esc_url_raw( rest_url( 'wc/store/v1/cart/extensions' ) ),
			'storeApiNonce'  => wp_create_nonce( 'wc_store_api' ),
			'namespace'      => self::NAMESPACE,
			'methods'        => (object) Settings::pickup_methods(),
			'codGateways'    => Settings::cod_gateways(),
			'packetaKey'     => Settings::packeta_widget_key(),
			'defaultCountry' => (string) WC()->countries->get_base_country(),
			'language'       => substr( determine_locale(), 0, 2 ),
			'i18n'           => array(
				'choose'        => __( 'Choose pickup point', 'skladx-woocommerce' ),
				'change'        => __( 'Change', 'skladx-woocommerce' ),
				'chosen'        => __( 'Pickup point:', 'skladx-woocommerce' ),
				'title'         => __( 'Choose a pickup point', 'skladx-woocommerce' ),
				'search'        => __( 'City, postcode or name', 'skladx-woocommerce' ),
				'searchButton'  => __( 'Search', 'skladx-woocommerce' ),
				'nearMe'        => __( 'Near me', 'skladx-woocommerce' ),
				'select'        => __( 'Select', 'skladx-woocommerce' ),
				'close'         => __( 'Close', 'skladx-woocommerce' ),
				'loading'       => __( 'Loading…', 'skladx-woocommerce' ),
				'more'          => __( 'Show more', 'skladx-woocommerce' ),
				'empty'         => __( 'No pickup points found. Try another city or postcode.', 'skladx-woocommerce' ),
				'error'         => __( 'Pickup points cannot be loaded right now. Please try again in a moment.', 'skladx-woocommerce' ),
				'geoError'      => __( 'Your location is not available. Search by city or postcode instead.', 'skladx-woocommerce' ),
				'cod'           => __( 'Cash on delivery', 'skladx-woocommerce' ),
				'noCod'         => __( 'No cash on delivery', 'skladx-woocommerce' ),
				'card'          => __( 'Card payment', 'skladx-woocommerce' ),
				'locker'        => __( 'Parcel locker', 'skladx-woocommerce' ),
				'point'         => __( 'Pickup point', 'skladx-woocommerce' ),
				'postOffice'    => __( 'Post office', 'skladx-woocommerce' ),
				'map'           => __( 'Map', 'skladx-woocommerce' ),
				'hours'         => __( 'Opening hours', 'skladx-woocommerce' ),
				/* translators: %s: date */
				'closing'       => __( 'Closes on %s', 'skladx-woocommerce' ),
				'missing'       => __( 'Please choose a pickup point for the selected shipping method.', 'skladx-woocommerce' ),
				'onlyCodPoints' => __( 'Showing only points that accept cash on delivery.', 'skladx-woocommerce' ),
			),
		);
	}

	/**
	 * Day labels and words for PickupPoint::opening_hours_lines().
	 *
	 * @return array<string, string>
	 */
	public static function day_labels(): array {
		return array(
			'mon'     => _x( 'Mon', 'day', 'skladx-woocommerce' ),
			'tue'     => _x( 'Tue', 'day', 'skladx-woocommerce' ),
			'wed'     => _x( 'Wed', 'day', 'skladx-woocommerce' ),
			'thu'     => _x( 'Thu', 'day', 'skladx-woocommerce' ),
			'fri'     => _x( 'Fri', 'day', 'skladx-woocommerce' ),
			'sat'     => _x( 'Sat', 'day', 'skladx-woocommerce' ),
			'sun'     => _x( 'Sun', 'day', 'skladx-woocommerce' ),
			'closed'  => __( 'closed', 'skladx-woocommerce' ),
			'nonstop' => __( 'non-stop', 'skladx-woocommerce' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Session
	 * ------------------------------------------------------------------- */

	/**
	 * @return array<string, array<string, mixed>> carrier => point.
	 */
	public static function session_points(): array {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return array();
		}

		$points = WC()->session->get( self::SESSION_KEY );

		return is_array( $points ) ? $points : array();
	}

	/**
	 * Verifies a point with SkladX and keeps it in the session.
	 *
	 * @param array<string, mixed> $widget Data from the official Packeta widget (fallback when SkladX is unreachable).
	 * @return array<string, mixed> The point.
	 *
	 * @throws PickupException When the point cannot be used.
	 */
	public static function select( string $carrier, string $id, array $widget = array() ): array {
		$carrier = sanitize_key( $carrier );
		$id      = sanitize_text_field( $id );

		if ( ! in_array( $carrier, Settings::pickup_carriers(), true ) ) {
			throw new PickupException( __( 'This carrier does not deliver to pickup points.', 'skladx-woocommerce' ), 'invalid_carrier' );
		}

		$points = self::session_points();

		if ( '' === $id ) {
			unset( $points[ $carrier ] );
			WC()->session->set( self::SESSION_KEY, $points );

			return array();
		}

		$point = self::lookup( $carrier, $id, $widget );

		if ( null === $point ) {
			throw new PickupException( self::message( 'not_found' ), 'not_found' );
		}

		$problem = PickupPoint::problem( $point, $carrier, false );

		if ( '' !== $problem ) {
			throw new PickupException( self::message( $problem, $point ), $problem );
		}

		$points[ $carrier ] = $point;
		WC()->session->set( self::SESSION_KEY, $points );

		return $point;
	}

	/**
	 * @param array<string, mixed> $widget Widget fallback data.
	 * @return array<string, mixed>|null
	 *
	 * @throws PickupException When SkladX is unreachable and there is no fallback.
	 */
	private static function lookup( string $carrier, string $id, array $widget = array() ): ?array {
		try {
			return RestController::service()->find( $carrier, $id );
		} catch ( ApiException $exception ) {
			Logger::warning( sprintf( 'Pickup point %s:%s could not be verified: %s', $carrier, $id, $exception->summary() ) );

			$fallback = array() !== $widget ? PickupPoint::from_widget( $carrier, $id, array_map( 'sanitize_text_field', array_filter( $widget, 'is_scalar' ) ) ) : null;

			if ( null !== $fallback && $exception->is_retryable() ) {
				return $fallback;
			}

			throw new PickupException( __( 'The pickup point cannot be verified right now. Please try again in a moment.', 'skladx-woocommerce' ), 'unavailable' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Validation and order meta (shared)
	 * ------------------------------------------------------------------- */

	/**
	 * Carriers of chosen pickup point methods, from rate ids or order shipping lines.
	 *
	 * @param list<array{rate_id: string, method_id: string, instance_id: string}> $rates Chosen rates.
	 * @return list<string>
	 */
	public static function pickup_carriers_for( array $rates ): array {
		$methods  = Settings::pickup_methods();
		$carriers = array();

		foreach ( $rates as $rate ) {
			$carrier = PickupPoint::carrier_for_rate( $rate['rate_id'], $rate['method_id'], $rate['instance_id'], $methods );

			if ( '' !== $carrier ) {
				$carriers[] = $carrier;
			}
		}

		return array_values( array_unique( $carriers ) );
	}

	/**
	 * Checks the chosen points for an order.
	 *
	 * @param list<string> $carriers       Carriers needing a point.
	 * @param string       $payment_method Payment gateway id.
	 * @return array<string, array<string, mixed>> carrier => verified point.
	 *
	 * @throws PickupException When a point is missing or cannot be used.
	 */
	public static function validate( array $carriers, string $payment_method ): array {
		$points   = self::session_points();
		$cod      = in_array( $payment_method, Settings::cod_gateways(), true );
		$verified = array();

		foreach ( $carriers as $carrier ) {
			$point = $points[ $carrier ] ?? null;

			if ( is_array( $point ) && empty( $point['unverified'] ) ) {
				try {
					// Cached for a few hours; picks up a point that was closed meanwhile.
					$fresh = RestController::service()->find( $carrier, (string) $point['id'] );
					$point = $fresh ?? array_merge( $point, array( 'active' => false ) );
				} catch ( ApiException $exception ) {
					Logger::warning( 'Pickup point check at checkout skipped, SkladX unreachable: ' . $exception->summary() );
				}
			}

			$problem = PickupPoint::problem( $point, $carrier, $cod );

			if ( '' !== $problem ) {
				throw new PickupException( self::message( $problem, $point ), $problem );
			}

			$verified[ $carrier ] = $point;
		}

		return $verified;
	}

	/**
	 * Writes the point onto the order (does not save).
	 *
	 * @param array<string, array<string, mixed>> $points carrier => point.
	 */
	public static function apply_to_order( \WC_Order $order, array $points ): void {
		$point = reset( $points );

		if ( ! is_array( $point ) ) {
			return;
		}

		$key = Settings::pickup_point_meta_key();

		if ( '' !== $key ) {
			$order->update_meta_data( $key, (string) $point['id'] );
		}

		$order->update_meta_data( self::META_POINT, $point );
		$order->update_meta_data( self::META_CARRIER, (string) $point['carrier'] );
		$order->update_meta_data( self::META_NAME, (string) $point['name'] );
		$order->update_meta_data( self::META_ADDRESS, PickupPoint::address_line( $point ) );
	}

	/**
	 * @param array<string, mixed>|null $point Point (for its name).
	 */
	public static function message( string $reason, ?array $point = null ): string {
		$name = is_array( $point ) ? (string) ( $point['name'] ?? '' ) : '';

		switch ( $reason ) {
			case 'missing':
			case 'wrong_carrier':
				return __( 'Please choose a pickup point for the selected shipping method.', 'skladx-woocommerce' );
			case 'inactive':
				/* translators: %s: pickup point name */
				return sprintf( __( 'The pickup point %s is out of service. Please choose another one.', 'skladx-woocommerce' ), $name );
			case 'no_cod':
				/* translators: %s: pickup point name */
				return sprintf( __( 'The pickup point %s does not accept cash on delivery. Choose another point or another payment method.', 'skladx-woocommerce' ), $name );
			case 'not_found':
				return __( 'The chosen pickup point does not exist. Please choose another one.', 'skladx-woocommerce' );
			default:
				return __( 'The pickup point cannot be used. Please choose another one.', 'skladx-woocommerce' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Classic checkout
	 * ------------------------------------------------------------------- */

	/**
	 * Button and chosen point under the chosen pickup shipping method.
	 *
	 * @param \WC_Shipping_Rate $rate  Rate.
	 * @param int|string        $index Package index.
	 */
	public static function render_classic( $rate, $index ): void {
		if ( ! $rate instanceof \WC_Shipping_Rate || ! is_checkout() ) {
			return;
		}

		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();

		if ( ( $chosen[ $index ] ?? '' ) !== $rate->get_id() ) {
			return;
		}

		$carrier = PickupPoint::carrier_for_rate( $rate->get_id(), (string) $rate->get_method_id(), (string) $rate->get_instance_id(), Settings::pickup_methods() );

		if ( '' === $carrier ) {
			return;
		}

		$point = self::session_points()[ $carrier ] ?? null;

		echo '<div class="skladx-pickup" data-carrier="' . esc_attr( $carrier ) . '">';

		if ( is_array( $point ) ) {
			echo '<div class="skladx-pickup__chosen"><span class="skladx-pickup__label">' . esc_html__( 'Pickup point:', 'skladx-woocommerce' ) . '</span> <strong>' . esc_html( (string) $point['name'] ) . '</strong><br /><span class="skladx-pickup__address">' . esc_html( PickupPoint::address_line( $point ) ) . '</span></div>';
		}

		printf(
			'<button type="button" class="button skladx-pickup__button" data-skladx-pickup-open="%1$s">%2$s</button>',
			esc_attr( $carrier ),
			is_array( $point ) ? esc_html__( 'Change pickup point', 'skladx-woocommerce' ) : esc_html__( 'Choose pickup point', 'skladx-woocommerce' )
		);
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $data   Posted checkout data.
	 * @param \WP_Error            $errors Errors.
	 */
	public static function validate_classic( $data, $errors ): void {
		$rates = array();

		foreach ( (array) ( $data['shipping_method'] ?? array() ) as $index => $rate_id ) {
			$rates[] = self::rate_info( (string) $rate_id, $index );
		}

		try {
			self::validate( self::pickup_carriers_for( $rates ), (string) ( $data['payment_method'] ?? '' ) );
		} catch ( PickupException $exception ) {
			$errors->add( 'skladx_pickup_point', $exception->getMessage() );
		}
	}

	/**
	 * @param \WC_Order            $order Order being created.
	 * @param array<string, mixed> $data  Posted checkout data.
	 */
	public static function save_classic( $order, $data ): void {
		$rates = array();

		foreach ( (array) ( $data['shipping_method'] ?? array() ) as $index => $rate_id ) {
			$rates[] = self::rate_info( (string) $rate_id, $index );
		}

		$carriers = self::pickup_carriers_for( $rates );

		if ( array() !== $carriers ) {
			self::apply_to_order( $order, array_intersect_key( self::session_points(), array_flip( $carriers ) ) );
		}
	}

	/**
	 * Method and instance id of a chosen rate (from the calculated packages).
	 *
	 * @param string     $rate_id Rate id.
	 * @param int|string $index   Package index.
	 * @return array{rate_id: string, method_id: string, instance_id: string}
	 */
	private static function rate_info( string $rate_id, $index ): array {
		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : array();
		$rate     = $packages[ $index ]['rates'][ $rate_id ] ?? null;

		return array(
			'rate_id'     => $rate_id,
			'method_id'   => $rate instanceof \WC_Shipping_Rate ? (string) $rate->get_method_id() : '',
			'instance_id' => $rate instanceof \WC_Shipping_Rate ? (string) $rate->get_instance_id() : '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Checkout block (Store API)
	 * ------------------------------------------------------------------- */

	public static function register_store_api(): void {
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE,
					'callback'  => array( self::class, 'store_api_update' ),
				)
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) && class_exists( CartSchema::class ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => CartSchema::IDENTIFIER,
					'namespace'       => self::NAMESPACE,
					'data_callback'   => array( self::class, 'store_api_data' ),
					'schema_callback' => array( self::class, 'store_api_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}
	}

	/**
	 * `POST /wc/store/v1/cart/extensions` with `{namespace: "skladx-pickup", data: {carrier, point_id, widget?}}`.
	 * Used by both checkouts.
	 *
	 * @param array<string, mixed> $data Data.
	 *
	 * @throws RouteException When the point cannot be used.
	 */
	public static function store_api_update( $data ): void {
		$data = (array) $data;

		try {
			self::select( (string) ( $data['carrier'] ?? '' ), (string) ( $data['point_id'] ?? '' ), is_array( $data['widget'] ?? null ) ? $data['widget'] : array() );
		} catch ( PickupException $exception ) {
			throw new RouteException( 'skladx_pickup_' . esc_attr( $exception->reason ), esc_html( $exception->getMessage() ), 400 );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function store_api_data(): array {
		$labels = self::day_labels();

		return array(
			'points' => (object) array_map( static fn ( array $point ): array => PickupPoint::for_browser( $point, $labels ), self::session_points() ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function store_api_schema(): array {
		return array(
			'points' => array(
				'description' => __( 'Chosen pickup points by carrier.', 'skladx-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * @param mixed $integration_registry IntegrationRegistry.
	 */
	public static function register_block_integration( $integration_registry ): void {
		if ( is_object( $integration_registry ) && method_exists( $integration_registry, 'register' ) && interface_exists( \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface::class ) ) {
			$integration_registry->register( new BlocksIntegration() );
		}
	}

	/**
	 * Validates and stores the point when the Checkout block places the order.
	 *
	 * @param \WC_Order        $order   Order.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @throws RouteException When a pickup point is missing or cannot be used.
	 */
	public static function save_blocks( $order, $request ): void {
		if ( ! $order instanceof \WC_Order || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return;
		}

		$rates = array();

		foreach ( $order->get_items( 'shipping' ) as $line ) {
			if ( $line instanceof \WC_Order_Item_Shipping ) {
				$rates[] = array(
					'rate_id'     => (string) $line->get_method_id() . ':' . (string) $line->get_instance_id(),
					'method_id'   => (string) $line->get_method_id(),
					'instance_id' => (string) $line->get_instance_id(),
				);
			}
		}

		$carriers = self::pickup_carriers_for( $rates );

		if ( array() === $carriers ) {
			return;
		}

		try {
			$points = self::validate( $carriers, (string) $order->get_payment_method() );
		} catch ( PickupException $exception ) {
			throw new RouteException( 'skladx_pickup_' . esc_attr( $exception->reason ), esc_html( $exception->getMessage() ), 400 );
		}

		self::apply_to_order( $order, $points );
	}

	/* ---------------------------------------------------------------------
	 * Display
	 * ------------------------------------------------------------------- */

	/**
	 * @return array{name: string, address: string, id: string}|null
	 */
	public static function for_order( \WC_Order $order ): ?array {
		$name = (string) $order->get_meta( self::META_NAME );

		if ( '' === $name ) {
			return null;
		}

		$point = $order->get_meta( self::META_POINT );

		return array(
			'name'    => $name,
			'address' => (string) $order->get_meta( self::META_ADDRESS ),
			'id'      => is_array( $point ) ? (string) ( $point['id'] ?? '' ) : '',
		);
	}

	/**
	 * @param \WC_Order $order Order.
	 */
	public static function render_admin( $order ): void {
		$point = $order instanceof \WC_Order ? self::for_order( $order ) : null;

		if ( null === $point ) {
			return;
		}

		printf(
			'<p class="skladx-pickup-admin"><strong>%1$s</strong><br />%2$s<br />%3$s%4$s</p>',
			esc_html__( 'Pickup point:', 'skladx-woocommerce' ),
			esc_html( $point['name'] ),
			esc_html( $point['address'] ),
			'' !== $point['id'] ? '<br /><code>' . esc_html( $point['id'] ) . '</code>' : ''
		);
	}

	/**
	 * @param \WC_Order $order Order.
	 */
	public static function render_order_details( $order ): void {
		$point = $order instanceof \WC_Order ? self::for_order( $order ) : null;

		if ( null === $point ) {
			return;
		}

		echo '<section class="skladx-pickup-point"><h2>' . esc_html__( 'Pickup point', 'skladx-woocommerce' ) . '</h2><p><strong>' . esc_html( $point['name'] ) . '</strong><br />' . esc_html( $point['address'] ) . '</p></section>';
	}

	/**
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param bool      $plain_text    Plain text email.
	 */
	public static function render_email( $order, $sent_to_admin = false, $plain_text = false ): void {
		$point = $order instanceof \WC_Order ? self::for_order( $order ) : null;

		if ( null === $point ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Pickup point:', 'skladx-woocommerce' ) . ' ' . esc_html( $point['name'] ) . ', ' . esc_html( $point['address'] ) . "\n";

			return;
		}

		echo '<h2>' . esc_html__( 'Pickup point', 'skladx-woocommerce' ) . '</h2><p><strong>' . esc_html( $point['name'] ) . '</strong><br />' . esc_html( $point['address'] ) . '</p>';
	}
}
