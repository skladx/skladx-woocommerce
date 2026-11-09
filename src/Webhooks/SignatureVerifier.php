<?php
/**
 * Verifies the `X-SkladX-Signature` header.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Webhooks;

/**
 * `X-SkladX-Signature: t=<unix>,v1=<hex hmac_sha256(secret, "<t>.<raw body>")>`.
 *
 * The timestamp is part of the signed string; requests older (or newer) than
 * TOLERANCE seconds are rejected so a captured request cannot be replayed.
 * Pure PHP, no WordPress calls.
 */
final class SignatureVerifier {

	public const TOLERANCE = 300;

	/**
	 * @param string   $secret Webhook signing secret (`whsec_…`).
	 * @param string   $body   Raw request body, exactly as received.
	 * @param string   $header Value of X-SkladX-Signature.
	 * @param int|null $now    Current unix time (for tests).
	 */
	public static function verify( string $secret, string $body, string $header, ?int $now = null ): bool {
		if ( '' === $secret || '' === $header ) {
			return false;
		}

		$timestamp  = null;
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( null === $timestamp || ! ctype_digit( $timestamp ) || array() === $signatures ) {
			return false;
		}

		if ( abs( ( $now ?? time() ) - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$expected = self::sign( $secret, $body, (int) $timestamp );

		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, strtolower( $signature ) ) ) {
				return true;
			}
		}

		return false;
	}

	public static function sign( string $secret, string $body, int $timestamp ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	public static function header( string $secret, string $body, int $timestamp ): string {
		return 't=' . $timestamp . ',v1=' . self::sign( $secret, $body, $timestamp );
	}
}
