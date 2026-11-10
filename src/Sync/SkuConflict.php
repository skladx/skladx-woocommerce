<?php
/**
 * Readable message for a SkladX `409 sku_conflict` answer to a product upsert.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Sync;

use SkladX\WooCommerce\Api\ApiException;

/**
 * The SKU the shop sends already belongs to another SkladX product. The
 * error details say which one:
 *
 *     {"sku": "NEW", "external_id": "wc:…", "conflicting_product": {"id", "sku", "name", "external_id"},
 *      "product": {"id", "sku", "name"}|null}
 *
 * Such an answer is final — sending the same payload again fails again, so
 * it is never retried automatically. Pure PHP — unit tested (texts are
 * translated when WordPress is loaded).
 */
final class SkuConflict {

	public const CODE = 'sku_conflict';

	/**
	 * Whether the API error is a SKU conflict.
	 */
	public static function matches( ApiException $exception ): bool {
		return $exception->is_sku_conflict();
	}

	/**
	 * Normalised details.
	 *
	 * @param array<string, mixed> $details Error details from the API.
	 * @return array{sku: string, external_id: string, conflicting: array{id: string, sku: string, name: string, external_id: string}|null, product: array{id: string, sku: string, name: string}|null}
	 */
	public static function details( array $details ): array {
		$text = static fn ( $value ): string => is_scalar( $value ) ? trim( (string) $value ) : '';

		$conflicting = is_array( $details['conflicting_product'] ?? null ) ? $details['conflicting_product'] : null;
		$product     = is_array( $details['product'] ?? null ) ? $details['product'] : null;

		return array(
			'sku'         => $text( $details['sku'] ?? '' ),
			'external_id' => $text( $details['external_id'] ?? '' ),
			'conflicting' => null === $conflicting ? null : array(
				'id'          => $text( $conflicting['id'] ?? '' ),
				'sku'         => $text( $conflicting['sku'] ?? '' ),
				'name'        => $text( $conflicting['name'] ?? '' ),
				'external_id' => $text( $conflicting['external_id'] ?? '' ),
			),
			'product'     => null === $product ? null : array(
				'id'   => $text( $product['id'] ?? '' ),
				'sku'  => $text( $product['sku'] ?? '' ),
				'name' => $text( $product['name'] ?? '' ),
			),
		);
	}

	/**
	 * One paragraph for the product error and admin notices.
	 *
	 * @param array<string, mixed> $details        Error details from the API.
	 * @param string               $server_message Message from the API, used when the details are missing.
	 */
	public static function message( array $details, string $server_message = '' ): string {
		$data        = self::details( $details );
		$conflicting = $data['conflicting'];

		if ( null === $conflicting || '' === $data['sku'] . $conflicting['sku'] ) {
			$fallback = '' !== trim( $server_message ) ? trim( $server_message ) : self::t( 'generic' );

			return $fallback . ' ' . self::t( 'hint' );
		}

		$sku   = '' !== $data['sku'] ? $data['sku'] : $conflicting['sku'];
		$owner = '' !== $conflicting['name'] ? $conflicting['name'] : $conflicting['sku'];
		$parts = array(
			sprintf(
				self::t( 'owner' ),
				$sku,
				'' !== $owner ? $owner : '?',
				'' !== $conflicting['sku'] ? $conflicting['sku'] : $sku,
				'' !== $conflicting['id'] ? $conflicting['id'] : '?'
			),
		);

		if ( '' !== $conflicting['external_id'] ) {
			$linked = ExternalId::parse( $conflicting['external_id'] );

			$parts[] = null !== $linked
				? sprintf( self::t( 'linked' ), $linked['id'], $linked['site'] )
				: sprintf( self::t( 'foreign' ), $conflicting['external_id'] );
		}

		if ( null !== $data['product'] && '' !== $data['product']['sku'] ) {
			$parts[] = sprintf(
				self::t( 'stays' ),
				$data['product']['sku'],
				'' !== $data['product']['id'] ? $data['product']['id'] : '?'
			);
		}

		$parts[] = self::t( 'hint' );

		return implode( ' ', $parts );
	}

	/**
	 * Texts of the message, translated when WordPress is loaded.
	 *
	 * @param string $key Text key.
	 */
	private static function t( string $key ): string {
		$english = array(
			'generic' => 'The SKU already belongs to another SkladX product.',
			'hint'    => 'Change the SKU here or in SkladX, then save the product again or click "Retry sync".',
			'owner'   => 'SKU %1$s already belongs to another SkladX product: %2$s (SKU %3$s, ID %4$s).',
			'linked'  => 'That product is linked to WooCommerce product #%1$d (%2$s).',
			'foreign' => 'That product is linked to %s.',
			'stays'   => 'This product stays in SkladX under SKU %1$s (ID %2$s).',
		);

		if ( ! function_exists( '__' ) ) {
			return $english[ $key ] ?? $key;
		}

		switch ( $key ) {
			case 'generic':
				return __( 'The SKU already belongs to another SkladX product.', 'skladx-woocommerce' );
			case 'hint':
				return __( 'Change the SKU here or in SkladX, then save the product again or click "Retry sync".', 'skladx-woocommerce' );
			case 'owner':
				/* translators: 1: SKU, 2: product name in SkladX, 3: its SKU, 4: its SkladX id */
				return __( 'SKU %1$s already belongs to another SkladX product: %2$s (SKU %3$s, ID %4$s).', 'skladx-woocommerce' );
			case 'linked':
				/* translators: 1: WooCommerce product id, 2: site key */
				return __( 'That product is linked to WooCommerce product #%1$d (%2$s).', 'skladx-woocommerce' );
			case 'foreign':
				/* translators: %s: external id */
				return __( 'That product is linked to %s.', 'skladx-woocommerce' );
			case 'stays':
				/* translators: 1: SKU in SkladX, 2: SkladX id */
				return __( 'This product stays in SkladX under SKU %1$s (ID %2$s).', 'skladx-woocommerce' );
			default:
				return $english[ $key ] ?? $key;
		}
	}
}
