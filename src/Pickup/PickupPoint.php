<?php
/**
 * Pickup point data: normalisation, query sanitising and validation rules.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Pickup;

/**
 * Pure PHP (no WordPress) — unit tested.
 *
 * The normalised point is what the plugin keeps in the session, sends to the
 * browser and stores on the order:
 *
 * @phpstan-type Point array{id: string, carrier: string, ref: string, name: string, type: string, street: string, city: string, postal_code: string, country: string, lat: float|null, lng: float|null, distance_km: float|null, cod: bool|null, card: bool|null, opening_hours: array<string, mixed>|null, photo_url: string, active: bool, closing_on: string}
 */
final class PickupPoint {

	/** Results per page of the picker. */
	public const PAGE_SIZE = 20;

	/** Search radius (km) around the customer's location. */
	public const NEAR_RADIUS_KM = 10;

	public const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Normalises one point of `GET /pickup-points` (or `/pickup-points/{carrier}/{id}`).
	 *
	 * Defensive about the shape: `address.postal_code` or `address.zip`, flat
	 * address fields, `location.{lat,lng}` or flat `lat`/`lng`, composite
	 * `carrier:id` ids.
	 *
	 * @param array<string, mixed> $raw              Point from the API.
	 * @param string               $fallback_carrier Carrier the point was asked for.
	 * @return array<string, mixed>|null Point, or null when it has no id.
	 */
	public static function normalize( array $raw, string $fallback_carrier = '' ): ?array {
		$carrier = self::text( $raw['carrier'] ?? '' );
		$carrier = '' !== $carrier ? $carrier : $fallback_carrier;
		$id      = self::text( $raw['external_id'] ?? '' );

		if ( '' === $id ) {
			$id = self::text( $raw['id'] ?? '' );
		}

		if ( '' !== $carrier && str_starts_with( $id, $carrier . ':' ) ) {
			$id = substr( $id, strlen( $carrier ) + 1 );
		} elseif ( '' === $carrier && str_contains( $id, ':' ) ) {
			[ $carrier, $id ] = explode( ':', $id, 2 );
		}

		if ( '' === $id || '' === $carrier ) {
			return null;
		}

		$address  = is_array( $raw['address'] ?? null ) ? $raw['address'] : $raw;
		$location = is_array( $raw['location'] ?? null ) ? $raw['location'] : $raw;
		$supports = is_array( $raw['supports'] ?? null ) ? $raw['supports'] : array();

		return array(
			'id'            => $id,
			'carrier'       => $carrier,
			'ref'           => $carrier . ':' . $id,
			'name'          => self::text( $raw['name'] ?? '' ),
			'type'          => self::text( $raw['type'] ?? 'point' ),
			'street'        => self::text( $address['street'] ?? '' ),
			'city'          => self::text( $address['city'] ?? '' ),
			'postal_code'   => self::text( $address['postal_code'] ?? ( $address['zip'] ?? '' ) ),
			'country'       => strtoupper( self::text( $address['country'] ?? ( $address['country_code'] ?? '' ) ) ),
			'lat'           => is_numeric( $location['lat'] ?? null ) ? (float) $location['lat'] : null,
			'lng'           => is_numeric( $location['lng'] ?? null ) ? (float) $location['lng'] : null,
			'distance_km'   => is_numeric( $raw['distance_km'] ?? null ) ? round( (float) $raw['distance_km'], 2 ) : null,
			'cod'           => self::flag( $supports['cod'] ?? ( $raw['supports_cod'] ?? null ) ),
			'card'          => self::flag( $supports['card'] ?? ( $raw['supports_card'] ?? null ) ),
			'opening_hours' => is_array( $raw['opening_hours'] ?? null ) ? $raw['opening_hours'] : null,
			'photo_url'     => self::text( $raw['photo_url'] ?? '' ),
			'active'        => array_key_exists( 'is_active', $raw ) ? (bool) $raw['is_active'] : true,
			'closing_on'    => self::text( $raw['closing_on'] ?? '' ),
		);
	}

	/**
	 * Point built from what the official Packeta widget returned. Used only
	 * when SkladX cannot be reached to verify the point; SkladX validates it
	 * again when the order is sent.
	 *
	 * @param array<string, mixed> $data Widget data (name, street, city, postal_code/zip, country).
	 * @return array<string, mixed>|null
	 */
	public static function from_widget( string $carrier, string $id, array $data ): ?array {
		$point = self::normalize(
			array(
				'carrier'     => $carrier,
				'external_id' => $id,
				'name'        => $data['name'] ?? '',
				'address'     => array(
					'street'      => $data['street'] ?? '',
					'city'        => $data['city'] ?? '',
					'postal_code' => $data['postal_code'] ?? ( $data['zip'] ?? '' ),
					'country'     => $data['country'] ?? '',
				),
			)
		);

		if ( null === $point || '' === $point['name'] ) {
			return null;
		}

		$point['unverified'] = true;

		return $point;
	}

	/**
	 * Sanitises the query of the plugin's REST proxy into SkladX query args.
	 *
	 * @param array<string, mixed> $params           Request params (carrier, q, near, cod, cursor, country).
	 * @param list<string>         $allowed_carriers Carriers with a pickup point shipping method.
	 * @param string               $default_country  Shop base country.
	 * @return array<string, scalar> Query for `GET /pickup-points`.
	 *
	 * @throws \InvalidArgumentException With code `invalid_carrier`, `invalid_near` or `invalid_country`.
	 */
	public static function sanitize_query( array $params, array $allowed_carriers, string $default_country ): array {
		$carrier = strtolower( self::text( $params['carrier'] ?? '' ) );

		if ( '' === $carrier || ! in_array( $carrier, $allowed_carriers, true ) ) {
			throw new \InvalidArgumentException( 'invalid_carrier' );
		}

		$country = strtoupper( self::text( $params['country'] ?? '' ) );
		$country = '' !== $country ? $country : strtoupper( $default_country );

		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			throw new \InvalidArgumentException( 'invalid_country' );
		}

		$query = array(
			'carrier' => $carrier,
			'country' => $country,
			'limit'   => self::PAGE_SIZE,
		);

		$search = self::text( $params['q'] ?? '' );
		$search = trim( (string) preg_replace( '/\s+/u', ' ', $search ) );

		if ( '' !== $search ) {
			$query['q'] = mb_substr( $search, 0, 100 );
		}

		$near = self::text( $params['near'] ?? '' );

		if ( '' !== $near ) {
			if ( 1 !== preg_match( '/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $near, $match ) || abs( (float) $match[1] ) > 90 || abs( (float) $match[2] ) > 180 ) {
				throw new \InvalidArgumentException( 'invalid_near' );
			}

			// ~100 m precision: enough for "near me" and makes cached answers reusable.
			$query['near']      = sprintf( '%.3F,%.3F', (float) $match[1], (float) $match[2] );
			$query['radius_km'] = self::NEAR_RADIUS_KM;
		}

		if ( ! empty( $params['cod'] ) && 'false' !== $params['cod'] && '0' !== (string) $params['cod'] ) {
			$query['cod'] = 1;
		}

		$cursor = self::text( $params['cursor'] ?? '' );

		if ( '' !== $cursor && 1 === preg_match( '/^[A-Za-z0-9_\-=]{1,200}$/', $cursor ) ) {
			$query['cursor'] = $cursor;
		}

		return $query;
	}

	/**
	 * Cache key of a search (independent of the argument order).
	 *
	 * @param array<string, scalar> $query Sanitised query.
	 */
	public static function cache_key( array $query ): string {
		ksort( $query );

		return 'skladx_pp_' . md5( (string) json_encode( $query ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure helper.
	}

	public static function point_cache_key( string $carrier, string $id ): string {
		return 'skladx_ppt_' . md5( $carrier . ':' . $id );
	}

	/**
	 * Why a chosen point cannot be used for the order, '' when it can.
	 *
	 * @param array<string, mixed>|null $point   Normalised point (null = none chosen).
	 * @param string                    $carrier Carrier of the chosen shipping method.
	 * @param bool                      $cod     Cash on delivery is the payment method.
	 * @return string ''|missing|wrong_carrier|inactive|no_cod
	 */
	public static function problem( ?array $point, string $carrier, bool $cod ): string {
		if ( null === $point || '' === (string) ( $point['id'] ?? '' ) ) {
			return 'missing';
		}

		if ( (string) ( $point['carrier'] ?? '' ) !== $carrier ) {
			return 'wrong_carrier';
		}

		if ( isset( $point['active'] ) && false === $point['active'] ) {
			return 'inactive';
		}

		if ( $cod && false === ( $point['cod'] ?? null ) ) {
			return 'no_cod';
		}

		return '';
	}

	/**
	 * Carrier of a chosen shipping rate when the method is a pickup point method.
	 *
	 * @param string                $rate_id        Rate id, e.g. `flat_rate:1`.
	 * @param string                $method_id      Method id ('' when unknown).
	 * @param string                $instance_id    Instance id ('' when unknown).
	 * @param array<string, string> $pickup_methods `method_id:instance_id` or `method_id` => carrier.
	 */
	public static function carrier_for_rate( string $rate_id, string $method_id, string $instance_id, array $pickup_methods ): string {
		if ( '' === $method_id ) {
			$parts       = explode( ':', $rate_id );
			$method_id   = $parts[0];
			$instance_id = $parts[1] ?? '';
		}

		return (string) ( $pickup_methods[ $method_id . ':' . $instance_id ] ?? $pickup_methods[ $method_id ] ?? '' );
	}

	/**
	 * One-line address: "Street, 811 06 City".
	 *
	 * @param array<string, mixed> $point Normalised point.
	 */
	public static function address_line( array $point ): string {
		$city = trim( self::text( $point['postal_code'] ?? '' ) . ' ' . self::text( $point['city'] ?? '' ) );

		return implode( ', ', array_filter( array( self::text( $point['street'] ?? '' ), $city ), static fn ( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Opening hours as short lines, consecutive days with equal hours grouped:
	 * `["Po–Pi 09:00–17:00", "So 09:00–12:00", "Ne zatvorené"]`.
	 *
	 * @param array<string, mixed>|null $hours  `{"mon": [["09:00","17:00"]], …}`.
	 * @param array<string, string>     $labels Day labels (mon…sun) plus `closed` and `nonstop`.
	 * @return list<string>
	 */
	public static function opening_hours_lines( ?array $hours, array $labels ): array {
		if ( null === $hours ) {
			return array();
		}

		$texts = array();

		foreach ( self::DAYS as $day ) {
			$intervals = is_array( $hours[ $day ] ?? null ) ? $hours[ $day ] : null;

			if ( null === $intervals ) {
				$texts[ $day ] = null;
				continue;
			}

			$parts = array();

			foreach ( $intervals as $interval ) {
				if ( is_array( $interval ) && isset( $interval[0], $interval[1] ) ) {
					$parts[] = ( '00:00' === $interval[0] && in_array( $interval[1], array( '24:00', '23:59' ), true ) ) ? ( $labels['nonstop'] ?? '0–24' ) : $interval[0] . '–' . $interval[1];
				}
			}

			$texts[ $day ] = array() === $parts ? ( $labels['closed'] ?? '–' ) : implode( ', ', $parts );
		}

		if ( array() === array_filter( $texts, static fn ( $text ): bool => null !== $text ) ) {
			return array();
		}

		$lines = array();
		$start = null;
		$prev  = null;

		foreach ( array_merge( self::DAYS, array( '' ) ) as $day ) {
			$text = '' === $day ? false : $texts[ $day ];

			if ( null !== $start && $text !== $texts[ $prev ] ) {
				if ( null !== $texts[ $prev ] ) {
					$range   = $start === $prev ? ( $labels[ $start ] ?? $start ) : ( $labels[ $start ] ?? $start ) . '–' . ( $labels[ $prev ] ?? $prev );
					$lines[] = $range . ' ' . $texts[ $prev ];
				}

				$start = null;
			}

			if ( '' !== $day && null === $start ) {
				$start = $day;
			}

			$prev = $day;
		}

		return $lines;
	}

	/**
	 * What the browser needs of a point (no raw data, no internal flags).
	 *
	 * @param array<string, mixed>  $point  Normalised point.
	 * @param array<string, string> $labels Day labels for opening_hours_lines().
	 * @return array<string, mixed>
	 */
	public static function for_browser( array $point, array $labels ): array {
		return array(
			'id'          => (string) $point['id'],
			'carrier'     => (string) $point['carrier'],
			'name'        => (string) $point['name'],
			'type'        => (string) $point['type'],
			'address'     => self::address_line( $point ),
			'street'      => (string) $point['street'],
			'city'        => (string) $point['city'],
			'postal_code' => (string) $point['postal_code'],
			'lat'         => $point['lat'],
			'lng'         => $point['lng'],
			'distance_km' => $point['distance_km'],
			'cod'         => $point['cod'],
			'card'        => $point['card'],
			'hours'       => self::opening_hours_lines( $point['opening_hours'] ?? null, $labels ),
			'closing_on'  => (string) $point['closing_on'],
		);
	}

	/**
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * @param mixed $value Value.
	 */
	private static function flag( $value ): ?bool {
		return null === $value ? null : (bool) $value;
	}
}
