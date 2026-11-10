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
 * Product save → `POST /products` (upsert by `external_id`, then by SKU).
 *
 * Saves (wp-admin, variations, REST API, CSV import, trash) collect product
 * ids; at `shutdown` each changed product is pushed right away with a short
 * timeout (at most IMMEDIATE_LIMIT per request, the rest is queued). Network
 * errors, 429 and 5xx are retried in Action Scheduler with backoff. Final
 * errors (409 SKU conflict, 422 validation) are stored on the product and not
 * resent until its payload changes or the admin clicks "Retry sync".
 * "Sync all products" walks the catalogue in batches of BATCH_SIZE.
 */
final class ProductSync {

	public const HOOK       = 'skladx_sync_product';
	public const BATCH_HOOK = 'skladx_sync_products_batch';
	public const STOCK_HOOK = 'skladx_pull_stock';
	public const GROUP      = 'skladx';
	public const BATCH_SIZE = 25;

	/** Products pushed synchronously per request (the rest goes to the queue). */
	public const IMMEDIATE_LIMIT = 10;

	/** HTTP timeout (seconds) of a synchronous push. */
	public const IMMEDIATE_TIMEOUT = 5;

	/** Seconds before retry n+1 of a retryable failure. */
	public const BACKOFF = array( 60, 300, 900, 3600, 10800, 21600 );

	/** API error codes that fail again with the same payload (not resent until it changes). */
	public const FINAL_ERROR_CODES = array( 'sku_conflict', 'validation_failed' );

	public const LAST_SYNC_META   = '_skladx_product_synced_at';
	public const ERROR_META       = '_skladx_product_error';
	public const ERROR_CODE_META  = '_skladx_product_error_code';
	public const FAILED_HASH_META = '_skladx_product_failed_hash';
	public const HASH_META        = '_skladx_product_hash';
	public const SKU_META         = '_skladx_product_sku';
	public const EXTERNAL_ID_META = '_skladx_external_id';
	public const REMOTE_ID_META   = '_skladx_product_id';
	public const STOCK_EVENT      = '_skladx_stock_event_id';

	/** True while SkladX data is being written into WooCommerce (prevents echo syncs). */
	private static bool $applying_remote = false;

	/** @var array<int, int>|null Product id => units held by orders not in SkladX yet (per request). */
	private static ?array $held = null;

	/** @var array<int, true> Products saved in this request, pushed at shutdown. */
	private static array $pending = array();

	/** Whether the shutdown push already ran (later saves go to the queue). */
	private static bool $flushed = false;

	public static function register(): void {
		add_action( 'woocommerce_new_product', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( self::class, 'on_product_saved' ), 20, 1 );
		// Variations saved via admin-ajax (woocommerce_save_variations), even when only meta changed.
		add_action( 'woocommerce_save_product_variation', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_rest_insert_product_object', array( self::class, 'on_product_object_saved' ), 20, 1 );
		add_action( 'woocommerce_rest_insert_product_variation_object', array( self::class, 'on_product_object_saved' ), 20, 1 );
		add_action( 'woocommerce_product_import_inserted_product_object', array( self::class, 'on_product_object_saved' ), 20, 1 );
		add_action( 'trashed_post', array( self::class, 'on_post_trashed' ), 20, 1 );
		add_action( 'untrashed_post', array( self::class, 'on_post_trashed' ), 20, 1 );
		add_action( 'shutdown', array( self::class, 'flush' ), 5 );
		add_action( self::HOOK, array( self::class, 'run_queued' ), 10, 2 );
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
	 * Product or variation saved (any hook passing the id first).
	 *
	 * @param int $product_id Product id.
	 */
	public static function on_product_saved( $product_id ): void {
		if ( self::$applying_remote || ! Settings::product_sync_enabled() || ! Settings::is_configured() ) {
			return;
		}

		self::defer( (int) $product_id );
	}

	/**
	 * REST API and CSV import hooks pass the product object.
	 *
	 * @param mixed $product Product.
	 */
	public static function on_product_object_saved( $product ): void {
		if ( $product instanceof \WC_Product ) {
			self::on_product_saved( $product->get_id() );
		}
	}

	/**
	 * Trashed products are sent as archived, restored ones as active.
	 *
	 * @param int $post_id Post id.
	 */
	public static function on_post_trashed( $post_id ): void {
		if ( in_array( get_post_type( (int) $post_id ), array( 'product', 'product_variation' ), true ) ) {
			self::on_product_saved( (int) $post_id );
		}
	}

	/**
	 * Remembers a product for the push at shutdown (once per request).
	 */
	public static function defer( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		if ( self::$flushed ) {
			self::queue( $product_id );

			return;
		}

		self::$pending[ $product_id ] = true;
	}

	/**
	 * Shutdown: pushes the products saved in this request.
	 *
	 * A variable product stands for its variations (their names and images
	 * come from the parent). Unchanged products cost no request; the first
	 * IMMEDIATE_LIMIT changed ones are sent now, the rest is queued.
	 */
	public static function flush(): void {
		if ( self::$flushed ) {
			return;
		}

		self::$flushed = true;
		$sent          = 0;
		$done          = array();
		// A classic wp-admin save redirects to the edit screen, which shows the product error itself.
		$notify = ! ( is_admin() && ! wp_doing_ajax() );

		while ( array() !== self::$pending ) {
			$product_id = (int) array_key_first( self::$pending );
			unset( self::$pending[ $product_id ] );

			if ( isset( $done[ $product_id ] ) ) {
				continue;
			}

			$done[ $product_id ] = true;
			$product             = wc_get_product( $product_id );

			if ( $product instanceof \WC_Product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					self::$pending[ (int) $child_id ] ??= true;
				}

				continue;
			}

			if ( ! self::is_syncable( $product ) || ! self::needs_send( $product ) ) {
				continue;
			}

			if ( $sent >= self::IMMEDIATE_LIMIT ) {
				self::queue( $product_id );
				continue;
			}

			++$sent;
			self::sync( $product_id, $notify, false, 0, self::IMMEDIATE_TIMEOUT );
		}
	}

	/**
	 * Action Scheduler callback of HOOK.
	 *
	 * @param int $product_id Product id.
	 * @param int $attempt    0 for a first try, n for retry n.
	 */
	public static function run_queued( $product_id, $attempt = 0 ): void {
		self::sync( (int) $product_id, true, false, (int) $attempt );
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
	 * Pushes one product to SkladX.
	 *
	 * Skipped without a request: unchanged payloads (e.g. a save that only
	 * changed stock) of a product SkladX already links to this SKU and
	 * external id, and a payload SkladX already rejected for good (409/422) —
	 * unless $force is set.
	 *
	 * @param int      $product_id Product id.
	 * @param bool     $notify     Queue an admin notice on a final failure.
	 * @param bool     $force      Send even when nothing relevant changed.
	 * @param int      $attempt    Retry number (0 = first try).
	 * @param int|null $timeout    HTTP timeout, null for the default.
	 */
	public static function sync( $product_id, $notify = true, $force = false, $attempt = 0, $timeout = null ): void {
		$product = wc_get_product( (int) $product_id );

		if ( ! self::is_syncable( $product ) ) {
			return;
		}

		if ( '' === (string) $product->get_sku() ) {
			Logger::debug( sprintf( 'Product %d skipped: no SKU.', $product->get_id() ) );

			return;
		}

		unset( self::$pending[ (int) $product->get_id() ] );

		$hash = '';

		try {
			$payload = self::payload( $product );
			$hash    = self::hash( $payload );

			if ( ! $force && ! self::needs_send( $product, $payload ) ) {
				return;
			}

			$remote = Client::from_settings( null !== $timeout ? (int) $timeout : null )->upsert_product( $payload );

			self::write_meta(
				$product,
				array(
					self::LAST_SYNC_META   => (string) time(),
					self::HASH_META        => $hash,
					self::SKU_META         => (string) $payload['sku'],
					self::EXTERNAL_ID_META => (string) ( $payload['external_id'] ?? '' ),
					self::REMOTE_ID_META   => (string) ( $remote['id'] ?? '' ),
				),
				array( self::ERROR_META, self::ERROR_CODE_META, self::FAILED_HASH_META )
			);
			if ( is_string( $remote['external_id'] ?? null ) && $remote['external_id'] !== (string) ( $payload['external_id'] ?? '' ) ) {
				Logger::warning( sprintf( 'Product %s: SkladX keeps external id %s instead of %s.', $payload['sku'], $remote['external_id'], (string) ( $payload['external_id'] ?? '' ) ) );
			}

			Logger::info( sprintf( 'Product %s (%s) synced (%s).', $payload['sku'], (string) ( $payload['external_id'] ?? '' ), (string) ( $remote['id'] ?? '?' ) ) );
		} catch ( MappingException | ApiException $exception ) {
			self::failed( $product, $exception, $hash, (bool) $notify, (int) $attempt );
		}
	}

	/**
	 * Stores the error and decides between retry, notice and silence.
	 *
	 * @param \WC_Product                       $product   Product.
	 * @param MappingException|ApiException     $exception Error.
	 * @param string                            $hash      Hash of the payload that failed ('' when not built).
	 * @param bool                              $notify    Queue an admin notice on a final failure.
	 * @param int                               $attempt   Retry number.
	 */
	private static function failed( \WC_Product $product, $exception, string $hash, bool $notify, int $attempt ): void {
		$api   = $exception instanceof ApiException ? $exception : null;
		$code  = null !== $api ? $api->error_code : 'mapping_failed';
		$final = null !== $api && in_array( $api->error_code, self::FINAL_ERROR_CODES, true );

		if ( null !== $api && SkuConflict::matches( $api ) ) {
			$message = SkuConflict::message( $api->details, $api->getMessage() );
		} elseif ( null !== $api ) {
			$message = $api->summary();
		} else {
			$message = $exception->localized_message();
		}

		$set = array(
			self::ERROR_META      => $message,
			self::ERROR_CODE_META => $code,
		);

		if ( $final && '' !== $hash ) {
			$set[ self::FAILED_HASH_META ] = $hash;
		}

		self::write_meta( $product, $set, $final ? array() : array( self::FAILED_HASH_META ) );
		Logger::error( sprintf( 'Product %s sync failed: %s', $product->get_sku(), $message ) );

		if ( null !== $api && $api->is_retryable() && $attempt < count( self::BACKOFF ) ) {
			$args = array( (int) $product->get_id(), $attempt + 1 );

			if ( ! as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				as_schedule_single_action( time() + self::BACKOFF[ $attempt ], self::HOOK, $args, self::GROUP );
			}

			return;
		}

		if ( $notify ) {
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

	/**
	 * Simple products and variations with a SKU, not auto-drafts.
	 *
	 * @param mixed $product Product.
	 * @phpstan-assert-if-true \WC_Product $product
	 */
	public static function is_syncable( $product ): bool {
		return $product instanceof \WC_Product
			&& ! $product->is_type( 'variable' )
			&& ! $product->is_type( 'grouped' )
			&& ! $product->is_type( 'external' )
			&& 'auto-draft' !== $product->get_status();
	}

	/**
	 * Whether the product has to be sent (cheap — no request).
	 *
	 * Sent when the payload changed, the SKU differs from the one SkladX knows
	 * (rename), SkladX has not confirmed the external id yet, or the last
	 * attempt failed for a temporary reason. Not sent while the same payload
	 * stays rejected for good (409/422).
	 *
	 * @param \WC_Product              $product Product.
	 * @param array<string, mixed>|null $payload Payload, built when null.
	 */
	public static function needs_send( \WC_Product $product, ?array $payload = null ): bool {
		if ( '' === (string) $product->get_sku() ) {
			return false;
		}

		try {
			$payload ??= self::payload( $product );
		} catch ( MappingException $exception ) {
			return true; // Let sync() record the error.
		}

		$hash = self::hash( $payload );

		if ( '' !== (string) $product->get_meta( self::ERROR_META ) ) {
			return $hash !== (string) $product->get_meta( self::FAILED_HASH_META );
		}

		return $hash !== (string) $product->get_meta( self::HASH_META )
			|| (string) ( $payload['sku'] ?? '' ) !== (string) $product->get_meta( self::SKU_META )
			|| (string) ( $payload['external_id'] ?? '' ) !== (string) $product->get_meta( self::EXTERNAL_ID_META );
	}

	/**
	 * Stable external id of a product or variation (`wc:{site_key}:{id}`).
	 */
	public static function external_id( \WC_Product $product ): string {
		return ExternalId::format( Settings::site_key(), (int) $product->get_id() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function payload( \WC_Product $product ): array {
		$payload = ProductPayloadBuilder::build(
			ProductPayloadBuilder::snapshot( $product, self::external_id( $product ) ),
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
		$payload = (array) apply_filters( 'skladx_product_payload', $payload, $product );

		// The external id links the product to SkladX; a filter must not drop it.
		$payload['external_id'] = (string) ( $payload['external_id'] ?? '' ) ?: self::external_id( $product );

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 */
	private static function hash( array $payload ): string {
		return md5( (string) wp_json_encode( $payload ) );
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
