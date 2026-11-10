<?php
/**
 * Pushes WooCommerce products to SkladX and applies SkladX stock to WooCommerce.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

use SkladX\WooCommerce\Admin\Notices;
use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Api\Client;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Product save → Action Scheduler → `POST /products` (upsert by SKU).
 * "Sync all products" walks the catalogue in batches of BATCH_SIZE.
 */
final class ProductSync {

	public const HOOK       = 'skladx_sync_product';
	public const BATCH_HOOK = 'skladx_sync_products_batch';
	public const STOCK_HOOK = 'skladx_pull_stock';
	public const GROUP      = 'skladx';
	public const BATCH_SIZE = 25;

	public const LAST_SYNC_META = '_skladx_product_synced_at';
	public const ERROR_META     = '_skladx_product_error';
	public const HASH_META      = '_skladx_product_hash';
	public const STOCK_EVENT    = '_skladx_stock_event_id';

	/** True while SkladX data is being written into WooCommerce (prevents echo syncs). */
	private static bool $applying_remote = false;

	/** @var array<int, int>|null Product id => units held by orders not in SkladX yet (per request). */
	private static ?array $held = null;

	public static function register(): void {
		add_action( 'woocommerce_new_product', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( self::HOOK, array( self::class, 'sync' ), 10, 1 );
		add_action( self::BATCH_HOOK, array( self::class, 'sync_batch' ), 10, 1 );
		add_action( self::STOCK_HOOK, array( self::class, 'pull_stock' ), 10, 1 );
		add_action( 'add_option_skladx_stock_sync', array( self::class, 'stock_sync_enabled' ), 10, 2 );
		add_action( 'update_option_skladx_stock_sync', array( self::class, 'stock_sync_toggled' ), 10, 2 );
	}

	/**
	 * Turning stock sync on takes over all SkladX stock levels once.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public static function stock_sync_toggled( $old_value, $value ): void {
		if ( 'yes' === $value && 'yes' !== $old_value ) {
			self::queue_stock_pull();
		}
	}

	/**
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	public static function stock_sync_enabled( $option, $value ): void {
		self::stock_sync_toggled( 'no', $value );
	}

	/**
	 * Queues a full stock pull from SkladX (GET /stock, all pages).
	 */
	public static function queue_stock_pull(): bool {
		if ( ! Settings::is_configured() || ! function_exists( 'as_enqueue_async_action' ) || as_has_scheduled_action( self::STOCK_HOOK, null, self::GROUP ) ) {
			return false;
		}

		as_enqueue_async_action( self::STOCK_HOOK, array( '' ), self::GROUP );

		return true;
	}

	/**
	 * Action Scheduler callback: one page of GET /stock.
	 *
	 * @param string $cursor Page cursor ('' for the first page).
	 */
	public static function pull_stock( $cursor = '' ): void {
		if ( ! Settings::stock_sync_enabled() ) {
			return;
		}

		try {
			$page = Client::from_settings()->stock( '' !== (string) $cursor ? (string) $cursor : null );
		} catch ( ApiException $exception ) {
			Logger::error( 'Stock pull from SkladX failed: ' . $exception->summary() );

			if ( $exception->is_retryable() ) {
				as_schedule_single_action( time() + 300, self::STOCK_HOOK, array( (string) $cursor ), self::GROUP );
			}

			return;
		}

		$changed = 0;

		foreach ( $page['data'] as $row ) {
			if ( is_array( $row ) && is_scalar( $row['sku'] ?? null ) && '' !== (string) $row['sku'] && is_numeric( $row['available'] ?? null ) ) {
				$changed += self::apply_stock( (string) $row['sku'], (int) $row['available'] ) ? 1 : 0;
			}
		}

		$next = (string) ( $page['meta']['next_cursor'] ?? '' );

		if ( ! empty( $page['meta']['has_more'] ) && '' !== $next ) {
			as_enqueue_async_action( self::STOCK_HOOK, array( $next ), self::GROUP );
		}

		Logger::info( sprintf( 'Stock pull from SkladX: %d rows, %d products changed.', count( $page['data'] ), $changed ) );
	}

	/**
	 * Units of a product that WooCommerce already took off stock for orders
	 * SkladX does not have yet (e.g. bank transfer orders on hold, or orders
	 * still in the export queue). SkladX `available` does not include them, so
	 * they are subtracted — otherwise taking over the SkladX level would put
	 * them back on sale.
	 */
	public static function held_quantity( int $product_id ): int {
		if ( null === self::$held ) {
			self::$held = array();

			$orders = wc_get_orders(
				array(
					'limit'        => -1,
					'type'         => 'shop_order',
					'status'       => array( 'pending', 'on-hold', 'processing' ),
					'date_created' => '>' . ( time() - 90 * DAY_IN_SECONDS ),
				)
			);

			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order || OrderMeta::is_exported( $order ) ) {
					continue;
				}

				foreach ( $order->get_items( 'line_item' ) as $item ) {
					$reduced = $item instanceof \WC_Order_Item_Product ? (int) $item->get_meta( '_reduced_stock' ) : 0;

					if ( $reduced > 0 ) {
						$id                = (int) $item->get_variation_id() ?: (int) $item->get_product_id();
						self::$held[ $id ] = ( self::$held[ $id ] ?? 0 ) + $reduced;
					}
				}
			}
		}

		return self::$held[ $product_id ] ?? 0;
	}

	/**
	 * @param int $product_id Product id.
	 */
	public static function on_product_saved( $product_id ): void {
		if ( self::$applying_remote || ! Settings::product_sync_enabled() || ! Settings::is_configured() ) {
			return;
		}

		self::queue( (int) $product_id );
	}

	public static function queue( int $product_id ): void {
		$args = array( $product_id );

		if ( ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			as_enqueue_async_action( self::HOOK, $args, self::GROUP );
		}
	}

	/**
	 * Queues the whole catalogue.
	 */
	public static function queue_all(): void {
		if ( ! as_has_scheduled_action( self::BATCH_HOOK, array( 1 ), self::GROUP ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( 1 ), self::GROUP );
		}
	}

	/**
	 * Action Scheduler callback for one page of the catalogue.
	 *
	 * @param int $page 1-based page.
	 */
	public static function sync_batch( $page ): void {
		$ids = wc_get_products(
			array(
				'type'    => array( 'simple', 'variation' ),
				'status'  => array( 'publish', 'private' ),
				'limit'   => self::BATCH_SIZE,
				'page'    => max( 1, (int) $page ),
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			self::sync( (int) $id, false, true );
		}

		if ( count( $ids ) === self::BATCH_SIZE ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( (int) $page + 1 ), self::GROUP );
		} else {
			Logger::info( 'Full product sync finished.' );
		}
	}

	/**
	 * Action Scheduler callback: push one product.
	 *
	 * Unchanged payloads (e.g. a save that only changed stock) are skipped
	 * unless $force is set.
	 *
	 * @param int  $product_id Product id.
	 * @param bool $notify     Queue an admin notice on failure.
	 * @param bool $force      Send even when nothing relevant changed.
	 */
	public static function sync( $product_id, $notify = true, $force = false ): void {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product instanceof \WC_Product || $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			return;
		}

		if ( '' === (string) $product->get_sku() ) {
			Logger::debug( sprintf( 'Product %d skipped: no SKU.', $product->get_id() ) );

			return;
		}

		try {
			$payload = self::payload( $product );
			$hash    = md5( (string) wp_json_encode( $payload ) );

			if ( ! $force && $hash === (string) $product->get_meta( self::HASH_META ) && '' === (string) $product->get_meta( self::ERROR_META ) ) {
				return;
			}

			$remote = Client::from_settings()->upsert_product( $payload );

			self::write_meta(
				$product,
				array(
					self::LAST_SYNC_META => (string) time(),
					self::HASH_META      => $hash,
				),
				array( self::ERROR_META )
			);
			Logger::info( sprintf( 'Product %s synced (%s).', $payload['sku'], (string) ( $remote['id'] ?? '?' ) ) );
		} catch ( MappingException | ApiException $exception ) {
			$message = $exception instanceof ApiException ? $exception->summary() : $exception->localized_message();

			self::write_meta( $product, array( self::ERROR_META => $message ) );
			Logger::error( sprintf( 'Product %s sync failed: %s', $product->get_sku(), $message ) );

			if ( $exception instanceof ApiException && $exception->is_retryable() ) {
				as_schedule_single_action( time() + 300, self::HOOK, array( (int) $product->get_id() ), self::GROUP );
			} elseif ( $notify ) {
				Notices::add(
					sprintf(
						/* translators: 1: SKU, 2: error */
						__( 'Product %1$s could not be synced to SkladX: %2$s', 'skladx-woocommerce' ),
						esc_html( (string) $product->get_sku() ),
						esc_html( $message )
					)
				);
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function payload( \WC_Product $product ): array {
		$payload = ProductPayloadBuilder::build(
			ProductPayloadBuilder::snapshot( $product ),
			(string) get_option( 'woocommerce_weight_unit', 'kg' ),
			(string) get_option( 'woocommerce_dimension_unit', 'cm' ),
			get_woocommerce_currency()
		);

		/**
		 * Filters the SkladX product payload before it is sent.
		 *
		 * @param array<string, mixed> $payload Payload for POST /products.
		 * @param \WC_Product          $product Product.
		 */
		return (array) apply_filters( 'skladx_product_payload', $payload, $product );
	}

	/**
	 * Sets WooCommerce stock from SkladX (when stock sync is on).
	 *
	 * Event ids are time-ordered (ULID): an event older than the last one
	 * applied to the product (webhook and polling may deliver out of order)
	 * is skipped, so an old level never overwrites a newer one.
	 *
	 * @param string $sku       SKU.
	 * @param int    $available SkladX `available` (on hand − reserved).
	 * @param string $event_id  `evt_…` id of the event, '' when unknown.
	 * @return bool Whether the stock changed.
	 */
	public static function apply_stock( string $sku, int $available, string $event_id = '' ): bool {
		$product_id = (int) wc_get_product_id_by_sku( $sku );
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product || ! $product->managing_stock() ) {
			return false;
		}

		$last_event = (string) $product->get_meta( self::STOCK_EVENT );

		if ( '' !== $event_id && '' !== $last_event && strcmp( $event_id, $last_event ) < 0 ) {
			Logger::debug( sprintf( 'Stale stock event %s for %s ignored (last applied %s).', $event_id, $sku, $last_event ) );

			return false;
		}

		if ( '' !== $event_id ) {
			self::write_meta( $product, array( self::STOCK_EVENT => $event_id ) );
		}

		$held      = self::held_quantity( (int) $product->get_id() );
		$available = max( 0, $available - $held );

		if ( (int) $product->get_stock_quantity() === $available ) {
			return false;
		}

		self::$applying_remote = true;

		try {
			wc_update_product_stock( $product, $available, 'set' );
		} finally {
			self::$applying_remote = false;
		}

		Logger::info( sprintf( 'Stock of %s set to %d from SkladX%s.', $sku, $available, $held > 0 ? sprintf( ' (%d held by orders not in SkladX yet)', $held ) : '' ) );

		return true;
	}

	/**
	 * Writes product meta without triggering another sync.
	 *
	 * @param array<string, string> $set    Meta to set.
	 * @param list<string>          $delete Meta keys to delete.
	 */
	private static function write_meta( \WC_Product $product, array $set, array $delete = array() ): void {
		self::$applying_remote = true;

		try {
			foreach ( $set as $key => $value ) {
				$product->update_meta_data( $key, $value );
			}

			foreach ( $delete as $key ) {
				$product->delete_meta_data( $key );
			}

			$product->save_meta_data();
		} finally {
			self::$applying_remote = false;
		}
	}
}
