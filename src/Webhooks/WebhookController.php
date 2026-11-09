<?php
/**
 * REST endpoint receiving SkladX webhooks.
 *
 * @package SkladX\WooCommerce
 */

namespace SkladX\WooCommerce\Webhooks;

use SkladX\WooCommerce\Settings;
use SkladX\WooCommerce\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /wp-json/skladx/v1/webhook`
 *
 * Verifies the HMAC signature over the raw body, stores the event once
 * (dedupe by X-SkladX-Event-Id) and answers 200 immediately; the actual work
 * runs in an Action Scheduler job so SkladX never waits on WooCommerce.
 */
final class WebhookController {

	public const NAMESPACE = 'skladx/v1';
	public const ROUTE     = '/webhook';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				// Authentication is the HMAC signature, checked in handle().
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = Settings::webhook_secret();

		if ( '' === $secret ) {
			return self::error( 'not_configured', 'Webhook secret is not configured.', 503 );
		}

		$body      = (string) $request->get_body();
		$signature = (string) $request->get_header( 'x_skladx_signature' );

		if ( ! SignatureVerifier::verify( $secret, $body, $signature ) ) {
			Logger::warning( 'Webhook rejected: invalid or expired signature.', array( 'event_id' => (string) $request->get_header( 'x_skladx_event_id' ) ) );

			return self::error( 'invalid_signature', 'Invalid or expired signature.', 401 );
		}

		$envelope = json_decode( $body, true );

		if ( ! is_array( $envelope ) ) {
			return self::error( 'invalid_payload', 'Body is not valid JSON.', 400 );
		}

		$event_id = (string) ( $request->get_header( 'x_skladx_event_id' ) ?: ( $envelope['id'] ?? '' ) );
		$type     = (string) ( $request->get_header( 'x_skladx_event' ) ?: ( $envelope['type'] ?? '' ) );

		if ( '' === $event_id || '' === $type ) {
			return self::error( 'invalid_payload', 'Missing event id or type.', 400 );
		}

		if ( ! EventStore::insert( $event_id, $type, $envelope, 'webhook' ) ) {
			return new \WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		Logger::debug( sprintf( 'Webhook %s (%s) received, delivery %s.', $event_id, $type, (string) $request->get_header( 'x_skladx_delivery' ) ) );

		if ( 'ping' === $type ) {
			EventStore::mark( $event_id, 'processed' );
		} else {
			EventProcessor::queue( $event_id );
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}
}
