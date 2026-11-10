<?php
/**
 * ReceiptStore kept in one WordPress option.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Inbound;

defined( 'ABSPATH' ) || exit;

/**
 * Option `skladx_inbound_receipts` (not autoloaded), at most LIMIT receipts.
 */
final class OptionReceiptStore implements ReceiptStore {

	public const OPTION = 'skladx_inbound_receipts';
	private const LIMIT = 200;

	public function get( string $id ): ?array {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	public function put( string $id, array $summary ): void {
		$all        = $this->all();
		$all[ $id ] = $summary;

		uasort( $all, static fn ( array $a, array $b ): int => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );

		update_option( self::OPTION, array_slice( $all, 0, self::LIMIT, true ), false );
	}

	public function all(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? array_filter( $all, 'is_array' ) : array();
	}
}
