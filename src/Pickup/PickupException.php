<?php
/**
 * A pickup point cannot be chosen or used.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Pickup;

/**
 * `reason` is one of: missing, wrong_carrier, inactive, no_cod, not_found,
 * unavailable, invalid_carrier. The message is already translated.
 */
class PickupException extends \RuntimeException {

	public function __construct( string $message, public readonly string $reason ) {
		parent::__construct( $message );
	}
}
