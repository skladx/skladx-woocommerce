<?php
/**
 * PSR-4 autoloader for the SkladX\WooCommerce namespace (no Composer needed at runtime).
 *
 * @package SkladX\WooCommerce
 */

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'SkladX\\WooCommerce\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
