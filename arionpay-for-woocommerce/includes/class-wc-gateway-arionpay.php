<?php
/**
 * The WooCommerce payment gateway.
 *
 * @package ArionPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * ArionPay gateway: creates a hosted invoice and sends the customer to it.
 */
class WC_Gateway_ArionPay extends WC_Payment_Gateway {

	const ID               = 'arionpay';
	const DEFAULT_API_BASE = 'https://merchant.arionpay.com';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'ArionPay (crypto)', 'arionpay-for-woocommerce' );
		$this->method_description = __( 'Take cryptocurrency payments through ArionPay. Customers pay on a hosted ArionPay page and the money goes straight to your own wallets.', 'arionpay-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = self::display_description( $this->settings );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	/**
	 * Checkout description, with a warning while test mode is on.
	 *
	 * @param array $settings Gateway settings.
	 * @return string
	 */
	public static function display_description( array $settings ) {
		$description = isset( $settings['description'] ) ? (string) $settings['description'] : '';
		if ( isset( $settings['testmode'] ) && 'yes' === $settings['testmode'] ) {
			$description = trim( __( 'TEST MODE is on: no real money is taken.', 'arionpay-for-woocommerce' ) . ' ' . $description );
		}
		return $description;
	}

	/**
	 * Are the API keys and webhook secret all set?
	 *
	 * @param array $settings Gateway settings.
	 * @return bool
	 */
	public static function credentials_present( array $settings ) {
		foreach ( array( 'api_base_url', 'public_key', 'secret_key', 'webhook_secret' ) as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The method is only offered when it is fully configured.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && self::credentials_present( $this->settings );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$webhook_url = WC()->api_request_url( 'arionpay' );

		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable/Disable', 'arionpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable ArionPay at checkout', 'arionpay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'arionpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What customers see at checkout.', 'arionpay-for-woocommerce' ),
				'default'     => __( 'Cryptocurrency (ArionPay)', 'arionpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'arionpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown to the customer at checkout.', 'arionpay-for-woocommerce' ),
				'default'     => __( 'You will be sent to an ArionPay page to choose a coin and pay the exact amount shown.', 'arionpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'api_base_url'   => array(
				'title'       => __( 'API base URL', 'arionpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Leave this as it is unless ArionPay tells you to change it. It must be https.', 'arionpay-for-woocommerce' ),
				'default'     => self::DEFAULT_API_BASE,
			),
			'public_key'     => array(
				'title'       => __( 'Public key', 'arionpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'From your store in the ArionPay dashboard. It starts with pk_.', 'arionpay-for-woocommerce' ),
				'default'     => '',
			),
			'secret_key'     => array(
				'title'       => __( 'Secret key', 'arionpay-for-woocommerce' ),
				'type'        => 'arionpay_secret',
				'description' => __( 'From your store in the ArionPay dashboard. It is stored here and never shown again. Leave empty to keep the saved key.', 'arionpay-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_secret' => array(
				'title'       => __( 'Webhook secret', 'arionpay-for-woocommerce' ),
				'type'        => 'arionpay_secret',
				'description' => __( 'From the Webhooks page in the ArionPay dashboard. It starts with whsec_. Leave empty to keep the saved secret.', 'arionpay-for-woocommerce' ),
				'default'     => '',
			),
			'testmode'       => array(
				'title'       => __( 'Test mode', 'arionpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Create test invoices (no real money)', 'arionpay-for-woocommerce' ),
				'description' => __( 'While this is on, invoices are test invoices and payments are simulated. Orders still change status. Turn it off before you take real orders.', 'arionpay-for-woocommerce' ),
				'default'     => 'no',
			),
			'webhook_info'   => array(
				'title'       => __( 'Webhook URL', 'arionpay-for-woocommerce' ),
				'type'        => 'title',
				'description' => '<p>' . esc_html__( 'Paste this address into your store\'s Webhooks page in the ArionPay dashboard. Without it, orders are not updated when customers pay.', 'arionpay-for-woocommerce' ) . '</p><p><code style="user-select:all">' . esc_html( $webhook_url ) . '</code></p>',
			),
		);
	}

	/**
	 * Notices above the settings form.
	 */
	public function admin_options() {
		if ( 'yes' === $this->enabled && ! self::credentials_present( $this->settings ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'ArionPay is enabled but will not show at checkout until the public key, secret key and webhook secret are saved.', 'arionpay-for-woocommerce' ) . '</p></div>';
		}
		if ( 'yes' === $this->get_option( 'testmode' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Test mode is on: invoices are test invoices and no real money is taken.', 'arionpay-for-woocommerce' ) . '</p></div>';
		}
		parent::admin_options();
	}

	/**
	 * Render a secret field. The saved value is never printed into the page.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition.
	 * @return string
	 */
	public function generate_arionpay_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'description' => '',
				'desc_tip'    => false,
			)
		);
		$saved     = '' !== (string) $this->get_option( $key );
		$hint      = $saved ? __( 'Saved. Leave empty to keep it.', 'arionpay-for-woocommerce' ) : __( 'Not set', 'arionpay-for-woocommerce' );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<input class="input-text regular-input" type="password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo esc_attr( $hint ); ?>" autocomplete="new-password" />
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by WooCommerce. ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Save a secret field. An empty value keeps the saved secret.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_arionpay_secret_field( $key, $value ) {
		$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
		if ( '' === $value ) {
			return (string) $this->get_option( $key );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{8,200}$/', $value ) ) {
			$this->settings_error( __( 'A secret key was not saved because it contains characters that a real ArionPay key does not have.', 'arionpay-for-woocommerce' ) );
			return (string) $this->get_option( $key );
		}
		return $value;
	}

	/**
	 * Save the public key.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_public_key_field( $key, $value ) {
		$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
		if ( '' !== $value && ! preg_match( '/^pk_[A-Za-z0-9_-]{8,64}$/', $value ) ) {
			$this->settings_error( __( 'The public key was not saved: it should start with pk_. Copy it from your ArionPay store.', 'arionpay-for-woocommerce' ) );
			return (string) $this->get_option( $key );
		}
		return $value;
	}

	/**
	 * Save the API base URL: https, host only (the local host may use http for development).
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string
	 */
	public function validate_api_base_url_field( $key, $value ) {
		$value = is_string( $value ) ? untrailingslashit( trim( wp_unslash( $value ) ) ) : '';
		if ( '' === $value ) {
			return self::DEFAULT_API_BASE;
		}
		$parts  = wp_parse_url( $value );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$local  = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true );
		$ok     = '' !== $host && ( 'https' === $scheme || ( 'http' === $scheme && $local ) ) && empty( $parts['path'] ) && empty( $parts['query'] ) && empty( $parts['fragment'] );
		if ( ! $ok ) {
			$this->settings_error( __( 'The API base URL was not saved: it must be a plain https address such as https://merchant.arionpay.com.', 'arionpay-for-woocommerce' ) );
			return (string) $this->get_option( $key, self::DEFAULT_API_BASE );
		}
		return esc_url_raw( $value );
	}

	/**
	 * Show a settings error.
	 *
	 * @param string $message Message.
	 */
	private function settings_error( $message ) {
		if ( class_exists( 'WC_Admin_Settings' ) ) {
			WC_Admin_Settings::add_error( $message );
		}
	}

	/**
	 * Start the payment: reuse an open invoice or create one, then send the customer to it.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'We could not find your order. Please try again.', 'arionpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			$redirect = $this->payment_redirect( $order );
		} catch ( ArionPay_API_Exception $e ) {
			self::log( 'error', 'Order ' . $order->get_id() . ': ' . $e->getMessage() );
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message from ArionPay */
					__( 'The ArionPay invoice could not be created: %s', 'arionpay-for-woocommerce' ),
					sanitize_text_field( $e->getMessage() )
				)
			);
			wc_add_notice( __( 'We could not start the crypto payment. Please try again in a moment or choose another payment method.', 'arionpay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Find where to send the customer.
	 *
	 * @param WC_Order $order Order.
	 * @return string URL.
	 * @throws ArionPay_API_Exception On API failure.
	 */
	private function payment_redirect( WC_Order $order ) {
		$current = (string) $order->get_meta( ArionPay_Order_Sync::META_INVOICE_ID );
		$mode    = $this->mode();

		if ( '' !== $current ) {
			$same_terms = $mode === (string) $order->get_meta( ArionPay_Order_Sync::META_MODE )
				&& strtoupper( $order->get_currency() ) === strtoupper( (string) $order->get_meta( ArionPay_Order_Sync::META_INVOICE_CURRENCY ) )
				&& ArionPay_Order_Sync::same_amount( $order->get_meta( ArionPay_Order_Sync::META_INVOICE_AMOUNT ), $order->get_total() );

			if ( $same_terms ) {
				$reuse = $this->check_existing_invoice( $order, $current );
				if ( null !== $reuse ) {
					return $reuse;
				}
			}
			// The old invoice cannot be used any more. Keep its id: a payment can still arrive on it.
			$past   = ArionPay_Order_Sync::json_meta( $order, ArionPay_Order_Sync::META_PAST_INVOICES );
			$past[] = $current;
			$order->update_meta_data( ArionPay_Order_Sync::META_PAST_INVOICES, wp_json_encode( array_slice( array_unique( $past ), -20 ) ) );
			$order->update_meta_data( ArionPay_Order_Sync::META_ATTEMPT, (int) $order->get_meta( ArionPay_Order_Sync::META_ATTEMPT ) + 1 );
			$order->save();
		}

		return $this->create_invoice( $order );
	}

	/**
	 * Decide from a signed read whether an existing invoice can be reused.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $invoice_id Invoice id.
	 * @return string|null URL to redirect to, or null when a new invoice is needed.
	 * @throws ArionPay_API_Exception On API failure.
	 */
	private function check_existing_invoice( WC_Order $order, $invoice_id ) {
		try {
			$read = $this->api()->get_invoice( $invoice_id );
		} catch ( ArionPay_API_Exception $e ) {
			if ( 404 === $e->http_status ) {
				return null;
			}
			throw $e;
		}
		$status = isset( $read['status'] ) ? strtoupper( (string) $read['status'] ) : '';

		if ( 'PAID' === $status ) {
			$this->apply_read( $order, $read );
			return $this->get_return_url( $order );
		}
		if ( in_array( $status, array( 'NEW', 'PENDING', 'PAID_PARTIAL', 'CLAIM_PENDING' ), true ) ) {
			$url = (string) $order->get_meta( ArionPay_Order_Sync::META_INVOICE_URL );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return null;
	}

	/**
	 * Create a new invoice through the signed API and store it on the order.
	 *
	 * @param WC_Order $order Order.
	 * @return string Hosted checkout URL.
	 * @throws ArionPay_API_Exception On API failure.
	 */
	private function create_invoice( WC_Order $order ) {
		$mode     = $this->mode();
		$amount   = wc_format_decimal( $order->get_total(), 2 );
		$currency = $order->get_currency();
		$attempt  = max( 1, (int) $order->get_meta( ArionPay_Order_Sync::META_ATTEMPT ) );
		$key      = (string) $order->get_order_key();

		/* translators: 1: order number, 2: site name */
		$description = sprintf( __( 'Order %1$s from %2$s', 'arionpay-for-woocommerce' ), $order->get_order_number(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$reference   = (string) $order->get_order_number();

		$fields = array(
			'amount'         => $amount,
			'currency'       => $currency,
			'orderId'        => mb_substr( $reference, 0, 120 ),
			'description'    => mb_substr( $description, 0, 500 ),
			'successUrl'     => $this->get_return_url( $order ),
			'cancelUrl'      => $order->get_checkout_payment_url(),
			// Same order and attempt with the same amount gets the same invoice, also after a network timeout.
			'idempotencyKey' => substr( sprintf( 'wc-%d-%s-%s-%s%s-%d', $order->get_id(), $key, $mode, str_replace( '.', '', (string) $amount ), $currency, $attempt ), 0, 100 ),
			'metadata'       => array(
				'wc_order_id'    => $order->get_id(),
				'wc_order_key'   => $key,
				'plugin'         => 'arionpay-for-woocommerce',
				'plugin_version' => ARIONPAY_WC_VERSION,
			),
		);
		if ( is_email( $order->get_billing_email() ) ) {
			$fields['customerEmail'] = $order->get_billing_email();
		}
		if ( 'test' === $mode ) {
			$fields['test'] = true;
		}

		$response = $this->api()->create_invoice( $fields );

		$data       = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$invoice_id = isset( $data['id'] ) && is_string( $data['id'] ) ? $data['id'] : '';
		$url        = isset( $data['invoice_url'] ) && is_string( $data['invoice_url'] ) ? esc_url_raw( $data['invoice_url'] ) : '';
		$scheme     = '' !== $url ? wp_parse_url( $url, PHP_URL_SCHEME ) : '';
		if ( ! preg_match( '/^[0-9a-fA-F]{24}$/', $invoice_id ) || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			throw new ArionPay_API_Exception( 'ArionPay API returned an unexpected response.' );
		}

		$order->update_meta_data( ArionPay_Order_Sync::META_INVOICE_ID, $invoice_id );
		$order->update_meta_data( ArionPay_Order_Sync::META_INVOICE_URL, $url );
		$order->update_meta_data( ArionPay_Order_Sync::META_INVOICE_AMOUNT, $amount );
		$order->update_meta_data( ArionPay_Order_Sync::META_INVOICE_CURRENCY, $currency );
		$order->update_meta_data( ArionPay_Order_Sync::META_ORDER_REF, $fields['orderId'] );
		$order->update_meta_data( ArionPay_Order_Sync::META_MODE, $mode );
		$order->update_meta_data( ArionPay_Order_Sync::META_ATTEMPT, $attempt );
		$order->add_order_note(
			sprintf(
				/* translators: 1: ArionPay invoice id, 2: test or live */
				__( 'ArionPay invoice %1$s created (%2$s).', 'arionpay-for-woocommerce' ),
				$invoice_id,
				'test' === $mode ? __( 'test mode, no real money', 'arionpay-for-woocommerce' ) : __( 'live', 'arionpay-for-woocommerce' )
			)
		);
		$order->save();

		return $url;
	}

	/**
	 * Apply the result of a signed invoice read to the order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $read  Decoded GET /api/v1/invoices/:id response.
	 * @return string apply result, or the invoice status when no event applies.
	 */
	private function apply_read( WC_Order $order, array $read ) {
		$invoice = ArionPay_Order_Sync::normalize_read( $read );
		if ( ! ArionPay_Order_Sync::invoice_belongs_to_order( $order, $invoice ) ) {
			self::log( 'warning', 'Signed read refused: invoice does not match order ' . $order->get_id() . '.' );
			return 'mismatch';
		}
		$event = ArionPay_Order_Sync::event_for_status( $invoice['status'] );
		if ( null === $event ) {
			return $invoice['status'];
		}
		return ArionPay_Order_Sync::apply_locked( $order->get_id(), $event, $invoice, 'read:' . $invoice['id'] . ':' . $invoice['status'] );
	}

	/**
	 * Check an order's invoice with a signed read (order action and thank-you page).
	 *
	 * @param WC_Order $order Order.
	 * @return string|null Invoice status from ArionPay, or null when the order has no invoice.
	 * @throws ArionPay_API_Exception On API failure.
	 */
	public function sync_order_from_api( WC_Order $order ) {
		$invoice_id = (string) $order->get_meta( ArionPay_Order_Sync::META_INVOICE_ID );
		if ( '' === $invoice_id ) {
			return null;
		}
		$read = $this->api()->get_invoice( $invoice_id );
		$this->apply_read( $order, $read );
		return isset( $read['status'] ) ? (string) $read['status'] : '';
	}

	/**
	 * Message on the order-received page. The URL proves nothing: the order only changes through a signed read here.
	 *
	 * @param int $order_id Order id.
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || self::ID !== $order->get_payment_method() ) {
			return;
		}

		if ( ! $order->is_paid() ) {
			$throttle = 'arionpay_read_' . $order->get_id();
			if ( false === get_transient( $throttle ) ) {
				set_transient( $throttle, 1, 20 );
				try {
					$this->sync_order_from_api( $order );
				} catch ( ArionPay_API_Exception $e ) {
					self::log( 'warning', 'Order ' . $order->get_id() . ': ' . $e->getMessage() );
				}
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order ) {
					return;
				}
			}
		}

		if ( $order->is_paid() ) {
			echo '<p>' . esc_html__( 'Your crypto payment is confirmed. Thank you!', 'arionpay-for-woocommerce' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'We are waiting for your crypto payment to be confirmed on the network. Your order will update automatically; you do not need to do anything. If you sent a different amount than shown, the shop has to accept it before your order can continue.', 'arionpay-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Add "Check ArionPay payment status" to the order actions dropdown.
	 *
	 * @param array         $actions Actions.
	 * @param WC_Order|null $order   Order (passed by recent WooCommerce versions).
	 * @return array
	 */
	public static function add_order_action( $actions, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			global $theorder;
			$order = $theorder;
		}
		if ( $order instanceof WC_Order && self::ID === $order->get_payment_method() && ! $order->is_paid() && '' !== (string) $order->get_meta( ArionPay_Order_Sync::META_INVOICE_ID ) ) {
			$actions['arionpay_check_status'] = __( 'Check ArionPay payment status', 'arionpay-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * Run the "Check ArionPay payment status" action. WooCommerce has already checked the save nonce and capability.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function run_order_action( $order ) {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( ! $order instanceof WC_Order || empty( $gateways[ self::ID ] ) ) {
			return;
		}
		try {
			$status = $gateways[ self::ID ]->sync_order_from_api( $order );
			$note   = sprintf(
				/* translators: %s: invoice status from ArionPay */
				__( 'Checked with ArionPay: the invoice status is %s.', 'arionpay-for-woocommerce' ),
				sanitize_text_field( (string) $status )
			);
		} catch ( ArionPay_API_Exception $e ) {
			self::log( 'warning', 'Order ' . $order->get_id() . ': ' . $e->getMessage() );
			$note = __( 'Could not check the invoice with ArionPay. See the arionpay log under WooCommerce > Status > Logs.', 'arionpay-for-woocommerce' );
		}
		$order = wc_get_order( $order->get_id() );
		if ( $order instanceof WC_Order ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Current mode.
	 *
	 * @return string test or live.
	 */
	private function mode() {
		return 'yes' === $this->get_option( 'testmode' ) ? 'test' : 'live';
	}

	/**
	 * API client built from the saved settings.
	 *
	 * @return ArionPay_API
	 */
	private function api() {
		return new ArionPay_API( (string) $this->get_option( 'api_base_url', self::DEFAULT_API_BASE ), (string) $this->get_option( 'public_key' ), (string) $this->get_option( 'secret_key' ) );
	}

	/**
	 * Write to the WooCommerce log.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message; never contains secrets.
	 */
	private static function log( $level, $message ) {
		wc_get_logger()->log( $level, $message, array( 'source' => 'arionpay' ) );
	}
}
