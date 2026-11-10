<?php
/**
 * Stable SkladX `external_id` of shop products.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

/**
 * `wc:{site}:{id}` — `{id}` is the product id (simple products) or the
 * variation id (variations), `{site}` is the site key stored once in the
 * `skladx_site_key` option (see Settings::site_key()), so the id survives a
 * change of the shop domain. Pure PHP — unit tested.
 */
final class ExternalId {

	public const PREFIX = 'wc';

	/** SkladX limit of `external_id`. */
	public const MAX_LENGTH = 191;

	/** Longest site key, leaves room for the prefix and a 20-digit id. */
	public const MAX_SITE_KEY_LENGTH = 160;

	/**
	 * Site key for a URL: its host, lowercased ('' when the URL has no host).
	 *
	 * @param string $url Usually home_url().
	 */
	public static function site_key_from_url( string $url ): string {
		$host = parse_url( trim( $url ), PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress in unit tests.

		if ( ! is_string( $host ) ) {
			return '';
		}

		$host = trim( strtolower( $host ), " \t\n\r\0\x0B[]" );
		$host = str_replace( ':', '-', $host );

		return substr( $host, 0, self::MAX_SITE_KEY_LENGTH );
	}

	/**
	 * External id of a product or variation, '' when it cannot be built.
	 *
	 * @param string $site_key   Site key (Settings::site_key()).
	 * @param int    $product_id Product id, or the variation id for variations.
	 */
	public static function format( string $site_key, int $product_id ): string {
		$site_key = trim( $site_key );

		if ( '' === $site_key || $product_id <= 0 ) {
			return '';
		}

		return substr( self::PREFIX . ':' . $site_key . ':' . $product_id, 0, self::MAX_LENGTH );
	}

	/**
	 * Splits an external id built by format().
	 *
	 * @param string $external_id External id.
	 * @return array{site: string, id: int}|null Null for ids not made by this plugin.
	 */
	public static function parse( string $external_id ): ?array {
		if ( ! preg_match( '/^' . self::PREFIX . ':(.+):(\d+)$/', $external_id, $matches ) ) {
			return null;
		}

		return array(
			'site' => $matches[1],
			'id'   => (int) $matches[2],
		);
	}
}
