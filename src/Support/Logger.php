<?php
/**
 * WooCommerce logger wrapper that never writes secrets.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Support;

use SkladX\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Logs to WooCommerce → Status → Logs, source `skladx`. Errors are always
 * logged, debug/info only with the "Debug log" option on. Every message and
 * context passes through the Redactor first.
 */
final class Logger {

	private const SOURCE = 'skladx';

	/**
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( Settings::debug_enabled() ) {
			self::write( 'debug', $message, $context );
		}
	}

	/**
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function info( string $message, array $context = array() ): void {
		if ( Settings::debug_enabled() ) {
			self::write( 'info', $message, $context );
		}
	}

	/**
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::write( 'warning', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::write( 'error', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context Extra data.
	 */
	private static function write( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$secrets = array_filter( array( Settings::api_key(), Settings::webhook_secret() ) );
		$message = (string) Redactor::redact( $message, $secrets );

		if ( array() !== $context ) {
			$message .= ' ' . wp_json_encode( Redactor::redact( $context, $secrets ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
