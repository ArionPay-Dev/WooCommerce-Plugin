<?php
/**
 * Webhook endpoint for ArionPay events.
 *
 * Backend reference: services/webhooks.ts (signature header, payload, events). Non-2xx answers are retried for about 3 days.
 *
 * @package ArionPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies and routes incoming webhooks.
 */
class ArionPay_Webhook {

	const MAX_BODY_BYTES = 262144;

	/**
	 * Event types the backend sends (services/webhooks.ts WEBHOOK_TYPES).
	 *
	 * @var string[]
	 */
	private static $events = array( 'invoice.pending', 'invoice.partial', 'invoice.paid', 'invoice.expired', 'invoice.needs_review' );

	/**
	 * Handle one request to /?wc-api=arionpay. Never changes an order unless the signature is valid.
	 */
	public static function handle() {
		nocache_headers();

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			self::respond( 405, array( 'error' => 'method_not_allowed' ) );
		}

		$settings = get_option( 'woocommerce_arionpay_settings', array() );
		$secret   = is_array( $settings ) && isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
		if ( '' === $secret ) {
			self::log( 'warning', 'Webhook refused: the webhook secret is not set in the plugin settings.' );
			self::respond( 503, array( 'error' => 'not_configured' ) );
		}

		$raw = file_get_contents( 'php://input' );
		if ( false === $raw || strlen( $raw ) > self::MAX_BODY_BYTES ) {
			self::respond( 413, array( 'error' => 'payload_too_large' ) );
		}

		// The body is signed byte for byte, so it must not be sanitized before this check.
		$header = isset( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) ) : '';
		if ( ! ArionPay_Signature::verify_webhook( $secret, $raw, $header ) ) {
			if ( '' === $header && isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
				self::log( 'warning', 'Webhook refused: it has the old format. Turn off old API (v1) compatibility for this store in ArionPay.' );
			} else {
				self::log( 'warning', 'Webhook refused: bad signature or stale timestamp. Check the webhook secret and this server\'s clock.' );
			}
			self::respond( 401, array( 'error' => 'invalid_signature' ) );
		}

		$event = json_decode( $raw, true );
		if ( ! is_array( $event ) || ! isset( $event['id'], $event['type'] ) || ! is_string( $event['id'] ) || ! is_string( $event['type'] ) ) {
			self::respond( 400, array( 'error' => 'malformed_event' ) );
		}
		$invoice = isset( $event['data']['invoice'] ) && is_array( $event['data']['invoice'] ) ? $event['data']['invoice'] : null;
		if ( null === $invoice ) {
			self::respond( 400, array( 'error' => 'malformed_event' ) );
		}

		$invoice_id = isset( $invoice['id'] ) && is_string( $invoice['id'] ) ? $invoice['id'] : '';

		// "Send test webhook" events describe a made-up invoice (id inv_test_..., orderId TEST) and never touch an order.
		if ( ! empty( $event['test'] ) || 0 === strpos( $event['id'], 'evt_test_' ) || 0 === strpos( $invoice_id, 'inv_test_' ) ) {
			self::log( 'info', 'Verified ArionPay test webhook received.' );
			self::respond(
				200,
				array(
					'received'   => true,
					'test_event' => true,
				)
			);
		}

		if ( ! in_array( $event['type'], self::$events, true ) ) {
			self::log( 'info', 'Webhook ignored: unknown event type ' . sanitize_text_field( $event['type'] ) );
			self::respond(
				200,
				array(
					'received' => true,
					'ignored'  => 'unknown_event',
				)
			);
		}

		if ( ! preg_match( '/^[0-9a-fA-F]{24}$/', $invoice_id ) ) {
			self::respond( 400, array( 'error' => 'malformed_event' ) );
		}

		$metadata = isset( $invoice['metadata'] ) && is_array( $invoice['metadata'] ) ? $invoice['metadata'] : array();
		if ( ! isset( $metadata['wc_order_id'] ) ) {
			// An invoice from the dashboard or a payment link on the same store: not ours, nothing to do.
			self::respond(
				200,
				array(
					'received' => true,
					'ignored'  => 'not_a_woocommerce_invoice',
				)
			);
		}

		$order_id = absint( $metadata['wc_order_id'] );
		if ( ! $order_id ) {
			self::respond( 400, array( 'error' => 'malformed_event' ) );
		}

		// The order is loaded inside the per-order lock, so it always reflects concurrent deliveries.
		$result = ArionPay_Order_Sync::apply_locked( $order_id, $event['type'], $invoice, $event['id'] );
		switch ( $result ) {
			case 'busy':
				header( 'Retry-After: 30' );
				self::respond( 503, array( 'error' => 'busy' ) );
				break;
			case 'mismatch':
				self::log( 'warning', 'Webhook refused: invoice ' . $invoice_id . ' does not belong to order ' . $order_id . '.' );
				self::respond( 409, array( 'error' => 'invoice_order_mismatch' ) );
				break;
			case 'not_found':
				self::log( 'warning', 'Webhook refused: no order ' . $order_id . ' for invoice ' . $invoice_id . '.' );
				self::respond( 404, array( 'error' => 'order_not_found' ) );
				break;
			case 'duplicate':
				self::respond(
					200,
					array(
						'received'  => true,
						'duplicate' => true,
					)
				);
				break;
			default:
				self::respond( 200, array( 'received' => true ) );
		}
	}

	/**
	 * Send a JSON answer and stop. Uses plain JSON with no secrets or internal details.
	 *
	 * @param int   $status HTTP status.
	 * @param array $body   Response body.
	 */
	private static function respond( $status, array $body ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
		exit;
	}

	/**
	 * Write to the WooCommerce log (WooCommerce > Status > Logs, source arionpay).
	 *
	 * @param string $level   Log level.
	 * @param string $message Message; never contains secrets or customer data.
	 */
	private static function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'arionpay' ) );
		}
	}
}
