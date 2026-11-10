<?php
/**
 * Minimal cache interface (transients in WordPress, an array in tests).
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Pickup;

interface Cache {

	/**
	 * @return mixed|null Null when missing.
	 */
	public function get( string $key );

	/**
	 * @param mixed $value Value.
	 */
	public function set( string $key, $value, int $ttl ): void;
}
