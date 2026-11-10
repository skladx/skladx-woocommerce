<?php
/**
 * Cache of the last known state of each inbound receipt.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

/**
 * Lets webhook, polling and the receipt list notice status changes (and
 * show the last known statuses while SkladX cannot be reached).
 */
interface ReceiptStore {

	/**
	 * @return array<string, mixed>|null Summary saved by put(), null when unknown.
	 */
	public function get( string $id ): ?array;

	/**
	 * @param array<string, mixed> $summary Receipt summary (InboundEvents::summary()).
	 */
	public function put( string $id, array $summary ): void;

	/**
	 * @return array<string, array<string, mixed>> All summaries, newest first.
	 */
	public function all(): array;
}
