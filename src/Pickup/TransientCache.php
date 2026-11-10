<?php
/**
 * Cache backed by WordPress transients.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Pickup;

defined( 'ABSPATH' ) || exit;

final class TransientCache implements Cache {

	/**
	 * @return mixed|null
	 */
	public function get( string $key ) {
		$value = get_transient( $key );

		return false === $value ? null : $value;
	}

	/**
	 * @param mixed $value Value.
	 */
	public function set( string $key, $value, int $ttl ): void {
		set_transient( $key, $value, $ttl );
	}
}
