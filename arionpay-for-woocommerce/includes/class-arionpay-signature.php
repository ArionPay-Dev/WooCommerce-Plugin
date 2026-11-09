<?php
/**
 * Request and webhook signatures, matching the ArionPay backend.
 *
 * Signed API requests (backend: http/routes/apiV1.ts requireHmac):
 *   POST: x-signature = hex HMAC-SHA256(secret key, "<timestamp>.<raw body>")
 *   GET:  x-signature = hex HMAC-SHA256(secret key, "<timestamp>.GET <path and query>")
 *
 * Webhooks (backend: services/webhooks.ts signPayload):
 *   ArionPay-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(webhook secret, "<t>.<raw body>")>
 *
 * @package ArionPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signature helpers. No WordPress or WooCommerce calls here, so this can be tested on its own.
 */
class ArionPay_Signature {

	/**
	 * Webhook timestamps older (or newer) than this are refused. The backend signs at delivery time, also on retries.
	 */
	const TOLERANCE_SECONDS = 300;

	/**
	 * Signature for a signed API request.
	 *
	 * @param string $secret         Store secret key.
	 * @param int    $timestamp      Unix seconds (same value as the x-timestamp header).
	 * @param string $method         HTTP method.
	 * @param string $path_and_query Request path and query, for example /api/v1/invoices/abc.
	 * @param string $raw_body       Exact request body (POST).
	 * @return string Lowercase hex.
	 */
	public static function sign_api_request( $secret, $timestamp, $method, $path_and_query, $raw_body ) {
		$data = 'GET' === strtoupper( (string) $method )
			? $timestamp . '.GET ' . $path_and_query
			: $timestamp . '.' . $raw_body;
		return hash_hmac( 'sha256', $data, (string) $secret );
	}

	/**
	 * Read the ArionPay-Signature header value.
	 *
	 * @param string $header Header value.
	 * @return array|null Array with int t and lowercase hex v1, or null when malformed.
	 */
	public static function parse_webhook_header( $header ) {
		$values = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 === count( $pair ) ) {
				$values[ $pair[0] ] = $pair[1];
			}
		}
		if ( ! isset( $values['t'], $values['v1'] ) || ! ctype_digit( $values['t'] ) || ! preg_match( '/^[0-9a-fA-F]{64}$/', $values['v1'] ) ) {
			return null;
		}
		return array(
			't'  => (int) $values['t'],
			'v1' => strtolower( $values['v1'] ),
		);
	}

	/**
	 * Check a webhook: timing-safe signature comparison and a fresh timestamp. Fails closed.
	 *
	 * @param string   $secret   Store webhook secret.
	 * @param string   $raw_body Exact raw request body.
	 * @param string   $header   ArionPay-Signature header value.
	 * @param int|null $now      Current Unix seconds (for tests).
	 * @return bool
	 */
	public static function verify_webhook( $secret, $raw_body, $header, $now = null ) {
		if ( '' === (string) $secret ) {
			return false;
		}
		$parsed = self::parse_webhook_header( $header );
		if ( null === $parsed ) {
			return false;
		}
		$now = null === $now ? time() : (int) $now;
		if ( abs( $now - $parsed['t'] ) > self::TOLERANCE_SECONDS ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $parsed['t'] . '.' . $raw_body, (string) $secret );
		return hash_equals( $expected, $parsed['v1'] );
	}
}
