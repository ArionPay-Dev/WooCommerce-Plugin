<?php
/**
 * Signed client for the ArionPay merchant server API.
 *
 * @package ArionPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Error from the ArionPay API. The message is for logs and the shop owner; customers get a generic one.
 */
class ArionPay_API_Exception extends Exception {

	/**
	 * HTTP status, 0 when the server could not be reached.
	 *
	 * @var int
	 */
	public $http_status;

	/**
	 * Machine-readable code from the API, when it sent one (for example idempotency_key_reused).
	 *
	 * @var string
	 */
	public $api_code;

	/**
	 * Constructor.
	 *
	 * @param string $message     Message (never contains a secret).
	 * @param int    $http_status HTTP status.
	 * @param string $api_code    API error code.
	 */
	public function __construct( $message, $http_status = 0, $api_code = '' ) {
		parent::__construct( $message );
		$this->http_status = (int) $http_status;
		$this->api_code    = (string) $api_code;
	}
}

/**
 * HTTP client that signs every request the way the backend expects.
 */
class ArionPay_API {

	/**
	 * API base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Store public key.
	 *
	 * @var string
	 */
	private $public_key;

	/**
	 * Store secret key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Constructor.
	 *
	 * @param string $base_url   API base URL.
	 * @param string $public_key Store public key.
	 * @param string $secret_key Store secret key.
	 */
	public function __construct( $base_url, $public_key, $secret_key ) {
		$this->base_url   = untrailingslashit( (string) $base_url );
		$this->public_key = (string) $public_key;
		$this->secret_key = (string) $secret_key;
	}

	/**
	 * Create an invoice: POST /api/v1/invoices.
	 *
	 * @param array $fields Request fields.
	 * @return array Decoded response.
	 * @throws ArionPay_API_Exception On any failure.
	 */
	public function create_invoice( array $fields ) {
		$body = wp_json_encode( $fields );
		if ( false === $body ) {
			throw new ArionPay_API_Exception( 'Could not encode the invoice request.' );
		}
		return $this->request( 'POST', '/api/v1/invoices', $body );
	}

	/**
	 * Read an invoice: GET /api/v1/invoices/:id.
	 *
	 * @param string $invoice_id Invoice id.
	 * @return array Decoded response.
	 * @throws ArionPay_API_Exception On any failure.
	 */
	public function get_invoice( $invoice_id ) {
		return $this->request( 'GET', '/api/v1/invoices/' . rawurlencode( (string) $invoice_id ) );
	}

	/**
	 * Send one signed request. The backend accepts each signature once, so a retry is signed again.
	 *
	 * @param string      $method Method.
	 * @param string      $path   Path with leading slash.
	 * @param string|null $body   JSON body for POST.
	 * @return array
	 * @throws ArionPay_API_Exception On any failure.
	 */
	private function request( $method, $path, $body = null ) {
		$attempts = 0;
		do {
			++$attempts;
			$retry = false;
			try {
				return $this->send( $method, $path, $body );
			} catch ( ArionPay_API_Exception $e ) {
				// Two identical signed requests in the same second have the same signature; only the first is accepted.
				if ( 401 === $e->http_status && 1 === $attempts && false !== stripos( $e->getMessage(), 'already used' ) ) {
					$retry = true;
					sleep( 1 );
				} else {
					throw $e;
				}
			}
		} while ( $retry );
		throw new ArionPay_API_Exception( 'ArionPay API request failed.' );
	}

	/**
	 * Perform the HTTP call.
	 *
	 * @param string      $method Method.
	 * @param string      $path   Path.
	 * @param string|null $body   Body.
	 * @return array
	 * @throws ArionPay_API_Exception On any failure.
	 */
	private function send( $method, $path, $body ) {
		$timestamp = time();
		$headers   = array(
			'Accept'      => 'application/json',
			'x-api-key'   => $this->public_key,
			'x-timestamp' => (string) $timestamp,
			'x-signature' => ArionPay_Signature::sign_api_request( $this->secret_key, $timestamp, $method, $path, (string) $body ),
		);
		$args      = array(
			'method'      => $method,
			'timeout'     => 20,
			'redirection' => 0,
			'headers'     => $headers,
			'user-agent'  => 'ArionPay-for-WooCommerce/' . ARIONPAY_WC_VERSION,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $body;
		}

		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new ArionPay_API_Exception( 'Could not reach ArionPay: ' . $response->get_error_message() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $decoded['error'] ) && is_scalar( $decoded['error'] ) ? (string) $decoded['error'] : 'HTTP ' . $status;
			$code    = isset( $decoded['code'] ) && is_scalar( $decoded['code'] ) ? (string) $decoded['code'] : '';
			throw new ArionPay_API_Exception( 'ArionPay API error: ' . $message, $status, $code );
		}
		return $decoded;
	}
}
