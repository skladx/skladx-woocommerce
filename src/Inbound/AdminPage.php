<?php
/**
 * WooCommerce → SkladX receipts: list and editor screens.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

use SkladX\WooCommerce\Api\ApiException;
use SkladX\WooCommerce\Api\Client;
use SkladX\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The list is rendered on the server (one `GET /inbound-receipts` page,
 * cursor pagination); the editor is plain JavaScript (assets/js/inbound.js)
 * talking to the admin-ajax proxy in Ajax.
 */
final class AdminPage {

	public const SLUG = 'skladx-receipts';

	/** Receipts per list page. */
	public const PAGE_SIZE = 25;

	private static string $hook = '';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function menu(): void {
		self::$hook = (string) add_submenu_page(
			'woocommerce',
			__( 'SkladX receipts', 'skladx-woocommerce' ),
			__( 'SkladX receipts', 'skladx-woocommerce' ),
			Receipts::CAPABILITY,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * @param array<string, scalar> $args Query args.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * @param string $hook_suffix Current admin page.
	 */
	public static function assets( $hook_suffix ): void {
		if ( '' === self::$hook || self::$hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'skladx-inbound', plugins_url( 'assets/css/inbound.css', SKLADX_WC_FILE ), array(), SKLADX_WC_VERSION );

		if ( self::is_editor() ) {
			wp_enqueue_script( 'skladx-inbound', plugins_url( 'assets/js/inbound.js', SKLADX_WC_FILE ), array(), SKLADX_WC_VERSION, true );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( Receipts::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'skladx-woocommerce' ), 403 );
		}

		echo '<div class="wrap skladx-inbound">';

		if ( ! Settings::is_configured() ) {
			printf( '<h1>%s</h1>', esc_html__( 'SkladX receipts', 'skladx-woocommerce' ) );
			self::notice(
				sprintf(
					/* translators: %s: settings page link */
					esc_html__( 'SkladX is not configured. Fill in the API URL and key in %s.', 'skladx-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=skladx' ) ) . '">' . esc_html__( 'WooCommerce → Settings → SkladX', 'skladx-woocommerce' ) . '</a>'
				),
				'warning',
				true
			);
			echo '</div>';

			return;
		}

		if ( self::is_editor() ) {
			self::render_editor();
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function is_editor(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
		return isset( $_GET['receipt'] ) || isset( $_GET['new'] );
	}

	private static function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$cursor = isset( $_GET['cursor'] ) ? sanitize_text_field( wp_unslash( $_GET['cursor'] ) ) : '';
		// phpcs:enable

		$status = in_array( $status, Receipt::STATUSES, true ) ? $status : '';

		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'SkladX receipts', 'skladx-woocommerce' ) );

		$receipts = array();
		$meta     = array();
		$error    = null;

		try {
			$page = Client::from_settings()->inbound_receipts(
				array(
					'status' => $status,
					'cursor' => $cursor,
					'limit'  => self::PAGE_SIZE,
				)
			);

			foreach ( $page['data'] as $raw ) {
				$receipt    = Receipt::normalize( $raw );
				$receipts[] = $receipt;
				Receipts::remember( $receipt );
			}

			$meta = $page['meta'];
		} catch ( ApiException $exception ) {
			$error = $exception;
		}

		if ( null !== $error && Receipts::is_unsupported( $error ) ) {
			echo '<hr class="wp-header-end">';
			self::notice( esc_html( Receipts::unsupported_message() ) . ' ' . esc_html__( 'Receipts will appear here once your SkladX warehouse turns on inbound receipts in the API.', 'skladx-woocommerce' ), 'info', true );

			return;
		}

		printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( self::url( array( 'new' => 1 ) ) ), esc_html__( 'New receipt', 'skladx-woocommerce' ) );
		echo '<hr class="wp-header-end">';

		if ( null !== $error ) {
			self::notice( esc_html( Receipts::error_message( $error ) ), 'error', true );
			$receipts = array_values( ( new OptionReceiptStore() )->all() );

			if ( array() === $receipts ) {
				return;
			}

			self::notice( esc_html__( 'Showing the last known state of your receipts.', 'skladx-woocommerce' ), 'info', true );
		}

		?>
		<form method="get" class="skladx-inbound-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<label for="skladx-inbound-status" class="screen-reader-text"><?php esc_html_e( 'Status', 'skladx-woocommerce' ); ?></label>
			<select name="status" id="skladx-inbound-status">
				<option value=""><?php esc_html_e( 'All statuses', 'skladx-woocommerce' ); ?></option>
				<?php foreach ( Receipts::status_labels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'skladx-woocommerce' ), '', '', false ); ?>
		</form>

		<table class="widefat striped skladx-inbound-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Number', 'skladx-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'skladx-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'skladx-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expected delivery', 'skladx-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Carrier / tracking', 'skladx-woocommerce' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Pieces', 'skladx-woocommerce' ); ?></th>
					<th scope="col" class="num"><?php echo esc_html_x( 'Received', 'pieces', 'skladx-woocommerce' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'skladx-woocommerce' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $receipts ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No receipts yet. Create one to announce goods you are sending to the warehouse.', 'skladx-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $receipts as $receipt ) : ?>
					<?php self::render_row( $receipt ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

		$next = (string) ( $meta['next_cursor'] ?? '' );

		if ( '' !== $cursor || ( ! empty( $meta['has_more'] ) && '' !== $next ) ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages skladx-inbound-pages">';

			if ( '' !== $cursor ) {
				printf( '<a class="button" href="%s">%s</a> ', esc_url( self::url( array_filter( array( 'status' => $status ) ) ) ), esc_html__( '« First page', 'skladx-woocommerce' ) );
			}

			if ( ! empty( $meta['has_more'] ) && '' !== $next ) {
				printf(
					'<a class="button" href="%s">%s</a>',
					esc_url(
						self::url(
							array_filter(
								array(
									'status' => $status,
									'cursor' => $next,
								)
							)
						)
					),
					esc_html__( 'Next page »', 'skladx-woocommerce' )
				);
			}

			echo '</div></div>';
		}
	}

	/**
	 * One list row. Accepts a normalised receipt or a cached summary.
	 *
	 * @param array<string, mixed> $receipt Receipt.
	 */
	private static function render_row( array $receipt ): void {
		$id       = (string) ( $receipt['id'] ?? '' );
		$status   = (string) ( $receipt['status'] ?? '' );
		$edit_url = self::url( array( 'receipt' => $id ) );
		$tracking = (string) ( $receipt['tracking_number'] ?? '' );
		$url      = '' !== $tracking ? Receipts::tracking_url( $receipt ) : '';
		$totals   = (array) ( $receipt['totals'] ?? array() );
		$expected = (int) ( $totals['expected'] ?? ( $receipt['expected'] ?? 0 ) );
		$received = $totals['received'] ?? ( $receipt['received'] ?? null );
		?>
		<tr>
			<td><a class="row-title" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( '' !== (string) ( $receipt['number'] ?? '' ) ? (string) $receipt['number'] : $id ); ?></a></td>
			<td><?php echo esc_html( (string) ( $receipt['name'] ?? '' ) ); ?></td>
			<td><?php self::badge( $status, (string) ( $receipt['status_label'] ?? '' ) ); ?></td>
			<td><?php echo esc_html( self::date( (string) ( $receipt['expected_at'] ?? '' ) ) ); ?></td>
			<td>
				<?php echo esc_html( Receipts::carrier_label( (string) ( $receipt['carrier'] ?? '' ) ) ); ?>
				<?php if ( '' !== $tracking ) : ?>
					<br>
					<?php if ( '' !== $url ) : ?>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $tracking ); ?></a>
					<?php else : ?>
						<code><?php echo esc_html( $tracking ); ?></code>
					<?php endif; ?>
				<?php endif; ?>
			</td>
			<td class="num"><?php echo esc_html( number_format_i18n( $expected ) ); ?></td>
			<td class="num">
				<?php if ( null !== $received ) : ?>
					<?php $difference = (int) $received - $expected; ?>
					<?php echo esc_html( number_format_i18n( (int) $received ) ); ?>
					<?php if ( 0 !== $difference ) : ?>
						<span class="skladx-diff <?php echo esc_attr( $difference < 0 ? 'is-minus' : 'is-plus' ); ?>">(<?php echo esc_html( ( $difference > 0 ? '+' : '' ) . number_format_i18n( $difference ) ); ?>)</span>
					<?php endif; ?>
				<?php else : ?>
					—
				<?php endif; ?>
			</td>
			<td class="skladx-inbound-actions">
				<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( Receipt::is_editable( $status ) ? __( 'Edit', 'skladx-woocommerce' ) : __( 'Open', 'skladx-woocommerce' ) ); ?></a>
				<?php if ( Receipt::DRAFT !== $status && Receipt::CANCELLED !== $status ) : ?>
					| <a href="<?php echo esc_url( Ajax::label_url( $id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Label', 'skladx-woocommerce' ); ?></a>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function render_editor(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen.
		$id      = isset( $_GET['receipt'] ) ? sanitize_text_field( wp_unslash( $_GET['receipt'] ) ) : '';
		$initial = null;
		$client  = Client::from_settings();

		printf(
			'<p class="skladx-inbound-back"><a href="%s">%s</a></p>',
			esc_url( self::url() ),
			esc_html__( '← All receipts', 'skladx-woocommerce' )
		);

		try {
			if ( '' !== $id ) {
				if ( ! Ajax::is_receipt_id( $id ) ) {
					throw new ApiException( __( 'Receipt not found.', 'skladx-woocommerce' ), 404, 'not_found' );
				}

				$receipt = Receipt::normalize( $client->inbound_receipt( $id ) );
				Receipts::remember( $receipt );
				$initial = self::receipt_for_browser( $receipt );
			} else {
				// Feature detection before offering the form.
				$client->inbound_receipts( array( 'limit' => 1 ) );
			}
		} catch ( ApiException $exception ) {
			printf( '<h1>%s</h1>', esc_html__( 'SkladX receipts', 'skladx-woocommerce' ) );

			if ( '' === $id && Receipts::is_unsupported( $exception ) ) {
				self::notice( esc_html( Receipts::unsupported_message() ), 'info', true );
			} elseif ( 404 === $exception->status ) {
				self::notice( esc_html__( 'Receipt not found in SkladX.', 'skladx-woocommerce' ), 'error', true );
			} else {
				self::notice( esc_html( Receipts::error_message( $exception ) ), 'error', true );
			}

			return;
		}

		wp_add_inline_script( 'skladx-inbound', 'window.skladxInbound = ' . wp_json_encode( self::config( $initial ) ) . ';', 'before' );

		printf(
			'<div id="skladx-inbound-app" class="skladx-inbound-app"><p class="skladx-inbound-loading">%s</p><noscript>%s</noscript></div>',
			esc_html__( 'Loading…', 'skladx-woocommerce' ),
			esc_html__( 'The receipt editor needs JavaScript.', 'skladx-woocommerce' )
		);
	}

	/**
	 * Receipt with display fields and the WooCommerce products of its lines.
	 *
	 * @param array<string, mixed> $receipt Normalised receipt.
	 * @return array{receipt: array<string, mixed>, products: object}
	 */
	public static function receipt_for_browser( array $receipt ): array {
		$receipt['status_text']   = Receipts::status_label( (string) $receipt['status'], (string) $receipt['status_label'] );
		$receipt['tone']          = Receipt::tone( (string) $receipt['status'] );
		$receipt['tracking_link'] = '' !== (string) $receipt['tracking_number'] ? Receipts::tracking_url( $receipt ) : '';
		$receipt['carrier_text']  = Receipts::carrier_label( (string) $receipt['carrier'] );
		$receipt['label_url']     = '' !== (string) $receipt['id'] ? Ajax::label_url( (string) $receipt['id'] ) : '';
		$receipt['label_zpl_url'] = '' !== (string) $receipt['id'] ? Ajax::label_url( (string) $receipt['id'], 'zpl' ) : '';
		$receipt['edit_url']      = self::url( array( 'receipt' => (string) $receipt['id'] ) );

		return array(
			'receipt'  => $receipt,
			'products' => (object) ProductLookup::by_skus( array_column( (array) $receipt['items'], 'sku' ) ),
		);
	}

	/**
	 * Browser configuration (no secrets).
	 *
	 * @param array<string, mixed>|null $initial receipt_for_browser() of the opened receipt.
	 * @return array<string, mixed>
	 */
	private static function config( ?array $initial ): array {
		$carriers = array();

		foreach ( Receipts::carrier_options() as $value => $label ) {
			$carriers[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( Ajax::NONCE ),
			'listUrl'   => self::url(),
			'newUrl'    => self::url( array( 'new' => 1 ) ),
			'locale'    => str_replace( '_', '-', get_user_locale() ),
			'carriers'  => $carriers,
			'personal'  => ReceiptPayload::CARRIER_PERSONAL,
			'other'     => ReceiptPayload::CARRIER_OTHER,
			'maxUpload' => Ajax::MAX_UPLOAD,
			'initial'   => $initial,
			'i18n'      => self::i18n(),
		);
	}

	/**
	 * Texts of the editor script.
	 *
	 * @return array<string, string>
	 */
	private static function i18n(): array {
		return array(
			'newReceipt'       => __( 'New receipt', 'skladx-woocommerce' ),
			/* translators: %s: receipt number */
			'receiptTitle'     => __( 'Receipt %s', 'skladx-woocommerce' ),
			'tabDetail'        => __( 'Detail', 'skladx-woocommerce' ),
			'tabEdit'          => __( 'Edit receipt', 'skladx-woocommerce' ),
			'tabBulk'          => __( 'Bulk add', 'skladx-woocommerce' ),
			'tabScan'          => __( 'Scan EAN', 'skladx-woocommerce' ),
			'tabAttachments'   => __( 'Attachments', 'skladx-woocommerce' ),
			'name'             => __( 'Name', 'skladx-woocommerce' ),
			'namePlaceholder'  => __( 'e.g. Autumn collection – delivery 1', 'skladx-woocommerce' ),
			'expectedAt'       => __( 'Expected delivery date', 'skladx-woocommerce' ),
			'carrier'          => __( 'Via carrier', 'skladx-woocommerce' ),
			'chooseCarrier'    => __( '— Choose —', 'skladx-woocommerce' ),
			'carrierOther'     => __( 'Carrier name', 'skladx-woocommerce' ),
			'tracking'         => __( 'Tracking number', 'skladx-woocommerce' ),
			'reference'        => __( 'Reference (optional)', 'skladx-woocommerce' ),
			'referenceLabel'   => __( 'Reference', 'skladx-woocommerce' ),
			'referenceHelp'    => __( 'e.g. your purchase order or supplier invoice number.', 'skladx-woocommerce' ),
			'blind'            => __( 'Blind receipt', 'skladx-woocommerce' ),
			'blindHelp'        => __( 'The warehouse counts whatever arrives; the items below are only a guide.', 'skladx-woocommerce' ),
			'note'             => __( 'Note for the warehouse', 'skladx-woocommerce' ),
			'items'            => __( 'Items', 'skladx-woocommerce' ),
			'product'          => __( 'Product', 'skladx-woocommerce' ),
			'productSearch'    => __( 'Search by name, SKU or EAN…', 'skladx-woocommerce' ),
			'sku'              => __( 'SKU', 'skladx-woocommerce' ),
			'ean'              => __( 'EAN', 'skladx-woocommerce' ),
			'quantity'         => __( 'Quantity', 'skladx-woocommerce' ),
			'pieces'           => __( 'pcs', 'skladx-woocommerce' ),
			'batch'            => __( 'Batch', 'skladx-woocommerce' ),
			'expiry'           => __( 'Expiry', 'skladx-woocommerce' ),
			'addItem'          => __( 'Add item', 'skladx-woocommerce' ),
			'remove'           => __( 'Remove', 'skladx-woocommerce' ),
			'noItems'          => __( 'No items yet. Search for a product above, paste lines in Bulk add, or scan EANs.', 'skladx-woocommerce' ),
			'noResults'        => __( 'No product with a SKU matches.', 'skladx-woocommerce' ),
			'searching'        => __( 'Searching…', 'skladx-woocommerce' ),
			'chooseProduct'    => __( 'Choose a product from the list first.', 'skladx-woocommerce' ),
			'notInSkladx'      => __( 'Not in SkladX yet', 'skladx-woocommerce' ),
			'notInSkladxHelp'  => __( 'SkladX does not know this product yet. Sync it first, otherwise the receipt will be rejected.', 'skladx-woocommerce' ),
			'notInShop'        => __( 'Not found in the shop', 'skladx-woocommerce' ),
			'syncError'        => __( 'Last sync failed:', 'skladx-woocommerce' ),
			'sync'             => __( 'Sync to SkladX', 'skladx-woocommerce' ),
			/* translators: %d: number of products */
			'syncAll'          => __( 'Sync to SkladX (%d)', 'skladx-woocommerce' ),
			'synced'           => __( 'Products synced to SkladX.', 'skladx-woocommerce' ),
			'syncing'          => __( 'Syncing…', 'skladx-woocommerce' ),
			/* translators: 1: number of lines, 2: number of pieces */
			'totals'           => __( '%1$d lines, %2$d pieces', 'skladx-woocommerce' ),
			'saveDraft'        => __( 'Save draft', 'skladx-woocommerce' ),
			'saving'           => __( 'Saving…', 'skladx-woocommerce' ),
			'saved'            => __( 'Draft saved in SkladX.', 'skladx-woocommerce' ),
			'announce'         => __( 'Send to warehouse', 'skladx-woocommerce' ),
			'announceConfirm'  => __( 'Send the receipt to the warehouse? It can no longer be edited afterwards.', 'skladx-woocommerce' ),
			'announced'        => __( 'The receipt was sent to the warehouse. Print the label and stick it on the parcel.', 'skladx-woocommerce' ),
			'cancel'           => __( 'Cancel receipt', 'skladx-woocommerce' ),
			'cancelConfirm'    => __( 'Cancel this receipt in SkladX?', 'skladx-woocommerce' ),
			'cancelled'        => __( 'The receipt was cancelled.', 'skladx-woocommerce' ),
			'printLabel'       => __( 'Print label', 'skladx-woocommerce' ),
			'labelZpl'         => __( 'Label (ZPL)', 'skladx-woocommerce' ),
			'labelHelp'        => __( 'Stick the label with the receipt code on the parcel so the warehouse can match it.', 'skladx-woocommerce' ),
			'refresh'          => __( 'Refresh', 'skladx-woocommerce' ),
			'unsaved'          => __( 'You have unsaved changes.', 'skladx-woocommerce' ),
			'errorGeneric'     => __( 'Something went wrong. Please try again.', 'skladx-woocommerce' ),
			'number'           => __( 'Number', 'skladx-woocommerce' ),
			'status'           => __( 'Status', 'skladx-woocommerce' ),
			'warehouse'        => __( 'Warehouse', 'skladx-woocommerce' ),
			'created'          => __( 'Created', 'skladx-woocommerce' ),
			'announcedAt'      => __( 'Sent to warehouse', 'skladx-woocommerce' ),
			'receivedAt'       => __( 'Received on', 'skladx-woocommerce' ),
			'yes'              => __( 'Yes', 'skladx-woocommerce' ),
			'no'               => __( 'No', 'skladx-woocommerce' ),
			'expected'         => __( 'Expected', 'skladx-woocommerce' ),
			'received'         => _x( 'Received', 'pieces', 'skladx-woocommerce' ),
			'difference'       => __( 'Difference', 'skladx-woocommerce' ),
			'total'            => __( 'Total', 'skladx-woocommerce' ),
			'differencesFound' => __( 'The warehouse received a different quantity than announced. See the differences below.', 'skladx-woocommerce' ),
			'receivedOk'       => __( 'Everything was received as announced.', 'skladx-woocommerce' ),
			'bulkHelp'         => __( 'One item per line: SKU or EAN; quantity; batch (optional); expiry (optional). Columns can be separated by semicolon, tab (paste from a spreadsheet) or comma. Expiry: YYYY-MM-DD, DD.MM.YYYY or MM/YYYY.', 'skladx-woocommerce' ),
			'bulkPlaceholder'  => "RF-TRIKO-M;24\n8588000000062;10;L2026-11;31.12.2027\nRF-FLASA;5;B7;2028-03",
			'bulkAdd'          => __( 'Add lines', 'skladx-woocommerce' ),
			/* translators: %d: number of lines */
			'bulkAdded'        => __( '%d lines added to the receipt.', 'skladx-woocommerce' ),
			'bulkErrors'       => __( 'These lines were not added:', 'skladx-woocommerce' ),
			'bulkEmpty'        => __( 'Paste at least one line.', 'skladx-woocommerce' ),
			'scanHelp'         => __( 'Click into the field and scan EAN codes with a USB scanner (or type a SKU/EAN and press Enter). Every scan adds the quantity to the line with the same product, batch and expiry.', 'skladx-woocommerce' ),
			'scanPlaceholder'  => __( 'Scan EAN or SKU', 'skladx-woocommerce' ),
			'scanQuantity'     => __( 'Pieces per scan', 'skladx-woocommerce' ),
			'scanBatch'        => __( 'Batch for scanned items', 'skladx-woocommerce' ),
			'scanExpiry'       => __( 'Expiry for scanned items', 'skladx-woocommerce' ),
			/* translators: 1: product name, 2: quantity on the line */
			'scanAdded'        => __( '%1$s – now %2$d pcs', 'skladx-woocommerce' ),
			'scanSaveHint'     => __( 'Scanned items are added to the receipt. Save the draft when you are done.', 'skladx-woocommerce' ),
			'attachmentsHelp'  => __( 'Attach the delivery note or photos of the shipment (PDF, JPG, PNG, WebP, up to 10 MB each).', 'skladx-woocommerce' ),
			'attachmentsFirst' => __( 'Save the draft first, then you can attach files.', 'skladx-woocommerce' ),
			'upload'           => __( 'Upload', 'skladx-woocommerce' ),
			'uploading'        => __( 'Uploading…', 'skladx-woocommerce' ),
			/* translators: %s: file name */
			'uploaded'         => __( '%s uploaded to SkladX.', 'skladx-woocommerce' ),
			'tooLarge'         => __( 'The file is larger than 10 MB.', 'skladx-woocommerce' ),
			'noAttachments'    => __( 'No attachments yet.', 'skladx-woocommerce' ),
			'readOnly'         => __( 'The receipt has been sent to the warehouse and can no longer be edited.', 'skladx-woocommerce' ),
		);
	}

	/**
	 * @param string $status   Status.
	 * @param string $fallback SkladX label for unknown statuses.
	 */
	public static function badge( string $status, string $fallback = '' ): void {
		printf(
			'<span class="skladx-badge skladx-badge--%1$s">%2$s</span>',
			esc_attr( Receipt::tone( $status ) ),
			esc_html( Receipts::status_label( $status, $fallback ) )
		);
	}

	private static function date( string $date ): string {
		$time = '' !== $date ? strtotime( $date ) : false;

		return false === $time ? '—' : (string) date_i18n( get_option( 'date_format' ), $time );
	}

	/**
	 * @param string $html      Already escaped message HTML.
	 * @param string $type      error|warning|success|info.
	 * @param bool   $inline    Keep it in place (not moved under the heading by WP).
	 */
	private static function notice( string $html, string $type, bool $inline = false ): void {
		printf(
			'<div class="notice notice-%1$s%2$s"><p>%3$s</p></div>',
			esc_attr( $type ),
			$inline ? ' inline' : '',
			wp_kses( $html, array( 'a' => array( 'href' => array() ) ) )
		);
	}
}
