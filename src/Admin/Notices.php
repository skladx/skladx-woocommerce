<?php
/**
 * Admin notices that survive a redirect or come from background jobs.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs (order export, product sync) cannot show a notice directly,
 * so they queue it here; it is shown once to the next shop manager in wp-admin.
 */
final class Notices {

	private const OPTION = 'skladx_notices';
	private const LIMIT  = 20;

	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * @param string $message Plain text message.
	 * @param string $type    error|warning|success|info.
	 */
	public static function add( string $message, string $type = 'error' ): void {
		$notices   = (array) get_option( self::OPTION, array() );
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);

		update_option( self::OPTION, array_slice( $notices, -self::LIMIT ), false );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notices = (array) get_option( self::OPTION, array() );

		if ( array() === $notices ) {
			return;
		}

		delete_option( self::OPTION );

		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p><strong>SkladX:</strong> %2$s</p></div>',
				esc_attr( (string) ( $notice['type'] ?? 'info' ) ),
				wp_kses( (string) ( $notice['message'] ?? '' ), array( 'a' => array( 'href' => array() ), 'br' => array(), 'code' => array() ) )
			);
		}
	}
}
