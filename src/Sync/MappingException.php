<?php
/**
 * A WooCommerce object cannot be mapped to a SkladX payload.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

/**
 * Thrown before any API call, e.g. when an order line has no SKU.
 */
class MappingException extends \RuntimeException {
}
