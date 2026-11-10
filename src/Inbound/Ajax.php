<?php
/**
 * admin-ajax proxy for the receipt editor (the API key never reaches the browser).
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Api\Client;
use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-ajax.php?action=skladx_inbound_*` (POST). Every handler checks the
 * `skladx_inbound` nonce and the `manage_woocommerce` capability. The label
 * download is an `admin-post.php` GET with its own nonce.
 */
final class Ajax {

	public const NONCE = 'skladx_inbound';

	/** Attachments: maximum size in bytes. */
	public const MAX_UPLOAD = 10485760;

	/** Attachments: allowed extensions => MIME types (delivery notes, photos). */
	public const UPLOAD_MIMES = array(
		'pdf'      => 'application/pdf',
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);

	private const ACTIONS = array(
		'skladx_inbound_products'      => 'products',
		'skladx_inbound_resolve'       => 'resolve',
		'skladx_inbound_parse'         => 'parse',
		'skladx_inbound_get'           => 'get',
		'skladx_inbound_save'          => 'save',
		'skladx_inbound_announce'      => 'announce',
		'skladx_inbound_cancel'        => 'cancel',
		'skladx_inbound_attach'        => 'attach',
		'skladx_inbound_sync_products' => 'sync_products',
	);

	public static function register(): void {
		foreach ( self::ACTIONS as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( self::class, $method ) );
		}

		add_action( 'admin_post_skladx_inbound_label', array( self::class, 'label' ) );
	}

	/**
	 * Product autocomplete (name, SKU, EAN).
	 */
	public static function products(): void {
		self::guard();

		wp_send_json_success( array( 'products' => ProductLookup::search( self::post_text( 'q' ) ) ) );
	}

	/**
	 * Exact SKU/EAN match (EAN scanner).
	 */
	public static function resolve(): void {
		self::guard();

		$code    = self::post_text( 'code' );
		$product = ProductLookup::resolve( $code );

		if ( null === $product ) {
			wp_send_json_error( array( 'message' => ValidationException::message( ValidationException::error( 'code', ValidationException::UNKNOWN_PRODUCT, null, $code ) ) ), 404 );
		}

		wp_send_json_success( array( 'product' => $product ) );
	}

	/**
	 * Bulk paste: parses the lines and finds their products.
	 */
	public static function parse(): void {
		self::guard();

		$text   = isset( $_POST['text'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['text'] ) ), 0, 200000 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$parsed = BulkParser::parse( $text );
		$errors = $parsed['errors'];
		$rows   = array();
		$cache  = array();

		foreach ( $parsed['rows'] as $row ) {
			$cache[ $row['code'] ] ??= ProductLookup::resolve( $row['code'] );

			if ( null === $cache[ $row['code'] ] ) {
				$errors[] = ValidationException::error( 'code', ValidationException::UNKNOWN_PRODUCT, $row['line'], $row['code'] );
				continue;
			}

			$rows[] = array(
				'line'       => $row['line'],
				'product'    => $cache[ $row['code'] ],
				'quantity'   => $row['quantity'],
				'batch'      => $row['batch'],
				'expires_at' => $row['expires_at'],
			);
		}

		usort( $errors, static fn ( array $a, array $b ): int => (int) $a['line'] <=> (int) $b['line'] );

		wp_send_json_success(
			array(
				'rows'   => $rows,
				'errors' => ( new ValidationException( $errors ) )->localized(),
			)
		);
	}

	public static function get(): void {
		self::guard();

		try {
			$receipt = Receipt::normalize( Client::from_settings()->inbound_receipt( self::receipt_id() ) );
		} catch ( ApiException $exception ) {
			self::api_error( $exception );
		}

		Receipts::remember( $receipt );
		self::send_receipt( $receipt );
	}

	/**
	 * Creates (POST) or updates (PUT) a draft.
	 */
	public static function save(): void {
		self::guard();

		$id    = self::receipt_id( false );
		$input = self::receipt_input();

		try {
			$payload = ReceiptPayload::build( $input, Settings::CARRIERS );
		} catch ( ValidationException $exception ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please fix the highlighted fields.', 'skladx-woocommerce' ),
					'errors'  => $exception->localized(),
				),
				422
			);
		}

		/**
		 * Filters the inbound receipt payload before it is sent to SkladX.
		 *
		 * @param array<string, mixed> $payload Body of POST/PUT /inbound-receipts.
		 * @param string               $id      Receipt id ('' when creating).
		 */
		$payload = (array) apply_filters( 'skladx_inbound_payload', $payload, $id );
		$client  = Client::from_settings();

		try {
			if ( '' === $id ) {
				$token   = preg_replace( '/[^a-z0-9-]/', '', strtolower( self::post_text( 'token' ) ) );
				$token   = strlen( (string) $token ) >= 8 ? $token : wp_generate_uuid4();
				$receipt = Receipt::normalize( $client->create_inbound_receipt( $payload, sprintf( 'wc:%s:inbound:%s', Settings::site_host(), $token ) ) );
			} else {
				$receipt = Receipt::normalize( $client->update_inbound_receipt( $id, $payload ) );
			}
		} catch ( ApiException $exception ) {
			self::api_error( $exception );
		}

		Receipts::remember( $receipt );
		self::send_receipt( $receipt, array( 'created' => '' === $id ) );
	}

	/**
	 * "Send to warehouse": draft → announced.
	 */
	public static function announce(): void {
		self::guard();

		$client = Client::from_settings();
		$id     = self::receipt_id();

		try {
			ReceiptPayload::assert_announceable( Receipt::normalize( $client->inbound_receipt( $id ) ) );
			$receipt = Receipt::normalize( $client->announce_inbound_receipt( $id ) );
		} catch ( ValidationException $exception ) {
			wp_send_json_error(
				array(
					'message' => __( 'The receipt cannot be sent yet.', 'skladx-woocommerce' ),
					'errors'  => $exception->localized(),
				),
				422
			);
		} catch ( ApiException $exception ) {
			self::api_error( $exception );
		}

		Receipts::remember( $receipt );
		self::send_receipt( $receipt );
	}

	public static function cancel(): void {
		self::guard();

		try {
			/* translators: %s: user name */
			$receipt = Receipt::normalize( Client::from_settings()->cancel_inbound_receipt( self::receipt_id(), sprintf( __( 'Cancelled in WooCommerce by %s', 'skladx-woocommerce' ), wp_get_current_user()->display_name ) ) );
		} catch ( ApiException $exception ) {
			self::api_error( $exception );
		}

		Receipts::remember( $receipt );
		self::send_receipt( $receipt );
	}

	/**
	 * Uploads a delivery note or photo to SkladX (nothing is kept in WordPress).
	 */
	public static function attach(): void {
		self::guard();

		$id = self::receipt_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); the file is validated below.
		$file = isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : null;

		if ( null === $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The file was not uploaded.', 'skladx-woocommerce' ) ), 400 );
		}

		if ( (int) $file['size'] > self::MAX_UPLOAD ) {
			/* translators: %s: maximum size, e.g. 10 MB */
			wp_send_json_error( array( 'message' => sprintf( __( 'The file is larger than %s.', 'skladx-woocommerce' ), size_format( self::MAX_UPLOAD ) ) ), 400 );
		}

		$name  = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], $name, self::UPLOAD_MIMES );

		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::UPLOAD_MIMES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Only PDF, JPG, PNG and WebP files can be attached.', 'skladx-woocommerce' ) ), 400 );
		}

		$client = Client::from_settings();

		try {
			$client->upload_inbound_attachment( $id, (string) $file['tmp_name'], ! empty( $check['proper_filename'] ) ? (string) $check['proper_filename'] : $name, (string) $check['type'] );
			$receipt = Receipt::normalize( $client->inbound_receipt( $id ) );
		} catch ( ApiException $exception ) {
			self::api_error( $exception );
		}

		self::send_receipt( $receipt, array( 'uploaded' => $name ) );
	}

	/**
	 * Sends products to SkladX right away (before they are put on a receipt).
	 */
	public static function sync_products(): void {
		self::guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$ids      = isset( $_POST['product_ids'] ) ? array_slice( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['product_ids'] ) ) ), 0, 50 ) : array();
		$products = array();

		foreach ( $ids as $product_id ) {
			ProductSync::sync( $product_id, false, true );
			$product = wc_get_product( $product_id );
			$info    = $product instanceof \WC_Product ? ProductLookup::info( $product ) : null;

			if ( null !== $info ) {
				$products[ $info['sku'] ] = $info;
			}
		}

		wp_send_json_success( array( 'products' => (object) $products ) );
	}

	/**
	 * Streams the receipt label (PDF) or offers it for download (ZPL).
	 */
	public static function label(): void {
		if ( ! current_user_can( Receipts::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'skladx-woocommerce' ), 403 );
		}

		check_admin_referer( 'skladx_inbound_label' );

		$id     = isset( $_GET['receipt'] ) ? sanitize_text_field( wp_unslash( $_GET['receipt'] ) ) : '';
		$format = isset( $_GET['format'] ) && 'zpl' === $_GET['format'] ? 'zpl' : 'pdf';

		if ( ! self::is_receipt_id( $id ) ) {
			wp_die( esc_html__( 'Receipt not found.', 'skladx-woocommerce' ), 404 );
		}

		try {
			$label = Client::from_settings()->inbound_receipt_label( $id, $format );
		} catch ( ApiException $exception ) {
			wp_die( esc_html( Receipts::error_message( $exception ) ), esc_html__( 'Receipt label', 'skladx-woocommerce' ), array( 'response' => 502, 'back_link' => true ) );
		}

		$is_pdf = str_starts_with( $label['body'], '%PDF' );
		$type   = $is_pdf ? 'application/pdf' : 'application/octet-stream';
		$file   = 'prijemka-' . sanitize_file_name( $id ) . ( $is_pdf ? '.pdf' : '.zpl' );

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: ' . ( $is_pdf ? 'inline' : 'attachment' ) . '; filename="' . $file . '"' );
		header( 'Content-Length: ' . strlen( $label['body'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $label['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary label file from SkladX served with its own content type.
		exit;
	}

	/**
	 * Nonce-protected label URL (not HTML-escaped: escape it when printing).
	 */
	public static function label_url( string $id, string $format = 'pdf' ): string {
		return add_query_arg(
			array(
				'action'   => 'skladx_inbound_label',
				'receipt'  => rawurlencode( $id ),
				'format'   => $format,
				'_wpnonce' => wp_create_nonce( 'skladx_inbound_label' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function is_receipt_id( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id );
	}

	/**
	 * Receipt plus the WooCommerce products of its lines.
	 *
	 * @param array<string, mixed> $receipt Normalised receipt.
	 * @param array<string, mixed> $extra   Extra response fields.
	 */
	private static function send_receipt( array $receipt, array $extra = array() ): void {
		wp_send_json_success( array_merge( AdminPage::receipt_for_browser( $receipt ), $extra ) );
	}

	/**
	 * Form input decoded from the `receipt` JSON field and sanitised.
	 *
	 * @return array<string, mixed>
	 */
	private static function receipt_input(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); decoded fields are sanitised below.
		$raw   = isset( $_POST['receipt'] ) ? json_decode( wp_unslash( (string) $_POST['receipt'] ), true ) : null;
		$raw   = is_array( $raw ) ? $raw : array();
		$input = array();

		foreach ( array( 'name', 'reference', 'expected_at', 'carrier', 'carrier_other', 'tracking_number', 'warehouse_code' ) as $key ) {
			$input[ $key ] = is_scalar( $raw[ $key ] ?? null ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		}

		$input['note']     = is_scalar( $raw['note'] ?? null ) ? sanitize_textarea_field( (string) $raw['note'] ) : '';
		$input['is_blind'] = ! empty( $raw['is_blind'] );
		$input['items']    = array();

		foreach ( is_array( $raw['items'] ?? null ) ? array_values( $raw['items'] ) : array() as $item ) {
			$item = is_array( $item ) ? $item : array();

			$input['items'][] = array(
				'sku'        => is_scalar( $item['sku'] ?? null ) ? sanitize_text_field( (string) $item['sku'] ) : '',
				'quantity'   => is_scalar( $item['quantity'] ?? null ) ? sanitize_text_field( (string) $item['quantity'] ) : '',
				'batch'      => is_scalar( $item['batch'] ?? null ) ? sanitize_text_field( (string) $item['batch'] ) : '',
				'expires_at' => is_scalar( $item['expires_at'] ?? null ) ? sanitize_text_field( (string) $item['expires_at'] ) : '',
			);
		}

		return $input;
	}

	private static function receipt_id( bool $required = true ): string {
		$id = self::post_text( 'id' );

		if ( '' === $id && ! $required ) {
			return '';
		}

		if ( ! self::is_receipt_id( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Receipt not found.', 'skladx-woocommerce' ) ), 400 );
		}

		return $id;
	}

	private static function post_text( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
	}

	/**
	 * Sends the API error to the browser and ends the request.
	 */
	private static function api_error( ApiException $exception ): never {
		$fields = array();

		foreach ( $exception->field_errors() as $field => $messages ) {
			$fields[] = array(
				'field'   => (string) $field,
				'line'    => preg_match( '/^items\.(\d+)\./', (string) $field, $m ) ? (int) $m[1] + 1 : null,
				'message' => implode( ' ', array_map( 'strval', $messages ) ),
			);
		}

		$status = $exception->status >= 400 && $exception->status < 600 ? $exception->status : 502;

		wp_send_json_error(
			array(
				'message' => Receipts::error_message( $exception ),
				'code'    => $exception->error_code,
				'errors'  => $fields,
			),
			$status
		);
		exit;
	}

	private static function guard(): void {
		if ( ! current_user_can( Receipts::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'skladx-woocommerce' ) ), 403 );
		}

		check_ajax_referer( self::NONCE, 'nonce' );
	}
}
