<?php
/**
 * Applies ArionPay invoice state to WooCommerce orders.
 *
 * Only verified data gets here: a webhook whose signature was checked, or a signed invoice read made by this server.
 * Whether a payment counts as paid is decided by ArionPay alone; this class never compares crypto amounts.
 *
 * @package ArionPay_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order state changes, idempotency and per-order locking.
 */
class ArionPay_Order_Sync {

	const META_INVOICE_ID       = '_arionpay_invoice_id';
	const META_INVOICE_URL      = '_arionpay_invoice_url';
	const META_INVOICE_AMOUNT   = '_arionpay_invoice_amount';
	const META_INVOICE_CURRENCY = '_arionpay_invoice_currency';
	const META_ORDER_REF        = '_arionpay_order_ref';
	const META_MODE             = '_arionpay_mode';
	const META_ATTEMPT          = '_arionpay_attempt';
	const META_PAST_INVOICES    = '_arionpay_past_invoices';
	const META_EVENTS           = '_arionpay_processed_events';
	const META_PAID_INVOICE     = '_arionpay_paid_invoice';
	const META_PAID_VIA         = '_arionpay_paid_via';

	const LOCK_TTL_SECONDS = 60;
	const MAX_EVENTS       = 40;

	/**
	 * Apply one event to an order under a per-order lock.
	 *
	 * @param int    $order_id   Order id.
	 * @param string $event_type invoice.pending, invoice.partial, invoice.paid, invoice.expired or invoice.needs_review.
	 * @param array  $invoice    Invoice in the webhook format.
	 * @param string $event_id   Unique event id, for idempotency.
	 * @return string processed, duplicate, busy, not_found or mismatch.
	 */
	public static function apply_locked( $order_id, $event_type, array $invoice, $event_id ) {
		if ( ! self::acquire_lock( $order_id ) ) {
			return 'busy';
		}
		try {
			$order = self::fresh_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return 'not_found';
			}
			if ( ! self::invoice_belongs_to_order( $order, $invoice ) ) {
				return 'mismatch';
			}
			$seen = self::json_meta( $order, self::META_EVENTS );
			if ( in_array( $event_id, $seen, true ) ) {
				return 'duplicate';
			}

			$is_current = self::text( $invoice, 'id' ) === (string) $order->get_meta( self::META_INVOICE_ID );
			switch ( $event_type ) {
				case 'invoice.paid':
					self::on_paid( $order, $invoice );
					break;
				case 'invoice.pending':
					self::on_progress( $order, $invoice, $is_current, self::pending_note( $invoice ) );
					break;
				case 'invoice.partial':
					self::on_progress( $order, $invoice, $is_current, self::partial_note( $invoice ) );
					break;
				case 'invoice.expired':
					self::on_expired( $order, $invoice, $is_current );
					break;
				case 'invoice.needs_review':
					$order->add_order_note( self::review_note( $invoice ) );
					break;
			}

			$seen[] = (string) $event_id;
			$order->update_meta_data( self::META_EVENTS, wp_json_encode( array_slice( $seen, -self::MAX_EVENTS ) ) );
			$order->save();
			return 'processed';
		} finally {
			self::release_lock( $order_id );
		}
	}

	/**
	 * The strict match: the invoice must be one this order created, and the order id, order key,
	 * order reference and test/live mode must all agree.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $invoice Invoice in the webhook format.
	 * @return bool
	 */
	public static function invoice_belongs_to_order( WC_Order $order, array $invoice ) {
		$invoice_id = self::text( $invoice, 'id' );
		if ( '' === $invoice_id ) {
			return false;
		}
		$current = (string) $order->get_meta( self::META_INVOICE_ID );
		$past    = self::json_meta( $order, self::META_PAST_INVOICES );
		if ( ! hash_equals( $current, $invoice_id ) && ! in_array( $invoice_id, $past, true ) ) {
			return false;
		}

		$metadata = isset( $invoice['metadata'] ) && is_array( $invoice['metadata'] ) ? $invoice['metadata'] : array();
		$order_id = isset( $metadata['wc_order_id'] ) ? absint( $metadata['wc_order_id'] ) : 0;
		$key      = isset( $metadata['wc_order_key'] ) && is_string( $metadata['wc_order_key'] ) ? $metadata['wc_order_key'] : '';
		if ( $order_id !== $order->get_id() || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return false;
		}

		$reference = (string) $order->get_meta( self::META_ORDER_REF );
		if ( '' !== $reference && self::text( $invoice, 'orderId' ) !== $reference ) {
			return false;
		}

		$mode = (string) $order->get_meta( self::META_MODE );
		if ( '' !== $mode && array_key_exists( 'test', $invoice ) && (bool) $invoice['test'] !== ( 'test' === $mode ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Convert the signed invoice read (GET /api/v1/invoices/:id) to the webhook field names.
	 *
	 * @param array $read Decoded API response.
	 * @return array
	 */
	public static function normalize_read( array $read ) {
		$pick = function ( $key, $default = null ) use ( $read ) {
			return array_key_exists( $key, $read ) ? $read[ $key ] : $default;
		};
		return array(
			'id'                       => $pick( 'invoiceId', '' ),
			'status'                   => strtolower( (string) $pick( 'status', '' ) ),
			'orderId'                  => $pick( 'orderId', '' ),
			'amountFiat'               => $pick( 'amountFiat' ),
			'currency'                 => $pick( 'currency', '' ),
			'asset'                    => $pick( 'settledCurrency', '' ),
			'network'                  => $pick( 'network', '' ),
			'amountCrypto'             => $pick( 'amountExpected', '' ),
			'paidCrypto'               => $pick( 'amountReceived', '' ),
			'confirmedCrypto'          => $pick( 'amountConfirmed', '' ),
			'overpaid'                 => (bool) $pick( 'overpaid', false ),
			'underpaidWithinTolerance' => (bool) $pick( 'underpaidWithinTolerance', false ),
			'late'                     => (bool) $pick( 'late', false ),
			'needsReview'              => (bool) $pick( 'needsReview', false ),
			'manual'                   => (bool) $pick( 'manual', false ),
			'test'                     => (bool) $pick( 'test', false ),
			'transactions'             => $pick( 'transactions', array() ),
			'metadata'                 => $pick( 'metadata', array() ),
		);
	}

	/**
	 * Which event type a read invoice status is equivalent to.
	 *
	 * @param string $status Lowercase status.
	 * @return string|null Null for statuses that need no order change (new, cancelled, ...).
	 */
	public static function event_for_status( $status ) {
		$map = array(
			'paid'         => 'invoice.paid',
			'pending'      => 'invoice.pending',
			'paid_partial' => 'invoice.partial',
			'expired'      => 'invoice.expired',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : null;
	}

	/**
	 * Load an order with the state other requests have saved. WooCommerce can keep order objects in a cache,
	 * and a copy loaded before the lock was taken would still show the old status and the old event list.
	 *
	 * @param int $order_id Order id.
	 * @return WC_Order|WC_Order_Refund|false
	 */
	private static function fresh_order( $order_id ) {
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
		}
		clean_post_cache( $order_id );
		return wc_get_order( $order_id );
	}

	/**
	 * Is an invoice amount the same as an amount from the order? Exact to the cent.
	 * This guards against an order whose total changed after the invoice was made; it is not a payment tolerance.
	 *
	 * @param mixed $a First amount.
	 * @param mixed $b Second amount.
	 * @return bool
	 */
	public static function same_amount( $a, $b ) {
		return is_numeric( $a ) && is_numeric( $b ) && (int) round( (float) $a * 100 ) === (int) round( (float) $b * 100 );
	}

	/**
	 * Decode a JSON list stored in order meta.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Meta key.
	 * @return string[]
	 */
	public static function json_meta( WC_Order $order, $key ) {
		$list = json_decode( (string) $order->get_meta( $key ), true );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}

	/**
	 * Handle invoice.paid.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $invoice Invoice.
	 */
	private static function on_paid( WC_Order $order, array $invoice ) {
		$invoice_id = self::text( $invoice, 'id' );

		if ( $order->is_paid() ) {
			if ( (string) $order->get_meta( self::META_PAID_INVOICE ) !== $invoice_id ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: ArionPay invoice id */
						__( 'ArionPay reports invoice %s as paid, but this order was already paid another way. This may be a double payment: check your wallet and refund the customer if needed.', 'arionpay-for-woocommerce' ),
						$invoice_id
					)
				);
			}
			return;
		}

		if ( $order->has_status( 'refunded' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: ArionPay invoice id */
					__( 'ArionPay reports invoice %s as paid, but this order is refunded. The order was not changed.', 'arionpay-for-woocommerce' ),
					$invoice_id
				)
			);
			return;
		}

		$amount   = isset( $invoice['amountFiat'] ) ? $invoice['amountFiat'] : null;
		$currency = self::text( $invoice, 'currency' );
		if ( ! self::same_amount( $amount, $order->get_total() ) || strtoupper( $currency ) !== strtoupper( $order->get_currency() ) ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: ArionPay invoice id, 2: invoice amount, 3: invoice currency */
					__( 'ArionPay invoice %1$s is paid (%2$s %3$s), but that does not match this order\'s current total, so the order was NOT marked paid. Please check the order and the invoice.', 'arionpay-for-woocommerce' ),
					$invoice_id,
					is_scalar( $amount ) ? (string) $amount : '?',
					$currency
				)
			);
			return;
		}

		$reopened = $order->has_status( 'cancelled' );
		$order->update_meta_data( self::META_PAID_INVOICE, $invoice_id );
		$order->update_meta_data( self::META_PAID_VIA, self::paid_via( $invoice ) );
		$order->add_order_note( self::paid_note( $invoice, $reopened ) );

		$transaction_id = '';
		if ( ! empty( $invoice['transactions'] ) && is_array( $invoice['transactions'] ) ) {
			$last           = end( $invoice['transactions'] );
			$transaction_id = is_array( $last ) ? self::text( $last, 'txid' ) : '';
		}
		$order->payment_complete( $transaction_id );
	}

	/**
	 * Handle invoice.pending and invoice.partial: hold the order while money is on its way, without marking it paid.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $invoice    Invoice.
	 * @param bool     $is_current Whether this is the order's current invoice.
	 * @param string   $note       Order note.
	 */
	private static function on_progress( WC_Order $order, array $invoice, $is_current, $note ) {
		if ( ! $is_current || $order->is_paid() || $order->has_status( array( 'refunded', 'cancelled' ) ) ) {
			return;
		}
		if ( $order->has_status( 'pending' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Handle invoice.expired: the order stays unpaid and gets a note.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $invoice    Invoice.
	 * @param bool     $is_current Whether this is the order's current invoice.
	 */
	private static function on_expired( WC_Order $order, array $invoice, $is_current ) {
		if ( ! $is_current || $order->is_paid() || $order->has_status( array( 'refunded', 'cancelled' ) ) ) {
			return;
		}
		$order->add_order_note(
			self::prefix( $invoice ) . sprintf(
				/* translators: %s: ArionPay invoice id */
				__( 'ArionPay invoice %s expired without full payment. The order was not marked paid and stays unpaid. A late payment can still arrive; if ArionPay accepts it, this order will be updated.', 'arionpay-for-woocommerce' ),
				self::text( $invoice, 'id' )
			)
		);
	}

	/**
	 * How the invoice was paid, as far as ArionPay tells us.
	 *
	 * @param array $invoice Invoice.
	 * @return string radar, manual or auto.
	 */
	private static function paid_via( array $invoice ) {
		// paidVia exists on the merchant invoice view but the webhook and the signed read do not carry it yet.
		if ( isset( $invoice['paidVia'] ) && 'radar' === $invoice['paidVia'] ) {
			return 'radar';
		}
		return ! empty( $invoice['manual'] ) ? 'manual' : 'auto';
	}

	/**
	 * Build the paid order note.
	 *
	 * @param array $invoice  Invoice.
	 * @param bool  $reopened Whether the order was cancelled before this payment.
	 * @return string
	 */
	private static function paid_note( array $invoice, $reopened ) {
		$parts = array(
			sprintf(
				/* translators: 1: ArionPay invoice id, 2: amount received, 3: coin code, 4: network name */
				__( 'ArionPay invoice %1$s is paid: %2$s %3$s received on %4$s.', 'arionpay-for-woocommerce' ),
				self::text( $invoice, 'id' ),
				self::text( $invoice, 'paidCrypto' ),
				self::text( $invoice, 'asset' ),
				self::text( $invoice, 'network' )
			),
		);

		if ( ! empty( $invoice['late'] ) ) {
			$parts[] = __( 'Paid late: the payment arrived after the invoice expired.', 'arionpay-for-woocommerce' );
		}
		$via = self::paid_via( $invoice );
		if ( 'radar' === $via ) {
			$parts[] = __( 'Paid via Payment Radar: the amount was not exact and the merchant accepted it in the ArionPay dashboard.', 'arionpay-for-woocommerce' );
		} elseif ( 'manual' === $via ) {
			$parts[] = __( 'Accepted by hand in the ArionPay dashboard (Payment Radar or "mark paid"). ArionPay does not say which one here; see the invoice in your dashboard.', 'arionpay-for-woocommerce' );
		}
		if ( ! empty( $invoice['overpaid'] ) ) {
			$parts[] = __( 'The customer paid more than the invoice amount.', 'arionpay-for-woocommerce' );
		}
		if ( ! empty( $invoice['underpaidWithinTolerance'] ) ) {
			$parts[] = __( 'The customer paid slightly less than the invoice amount, and ArionPay accepted it under this store\'s settings.', 'arionpay-for-woocommerce' );
		}
		if ( ! empty( $invoice['needsReview'] ) ) {
			$parts[] = __( 'ArionPay flagged this invoice for review; see your dashboard.', 'arionpay-for-woocommerce' );
		}
		if ( $reopened ) {
			$parts[] = __( 'This order had been cancelled; it was reopened because the payment arrived.', 'arionpay-for-woocommerce' );
		}
		return self::prefix( $invoice ) . implode( ' ', $parts );
	}

	/**
	 * Build the invoice.pending note.
	 *
	 * @param array $invoice Invoice.
	 * @return string
	 */
	private static function pending_note( array $invoice ) {
		return self::prefix( $invoice ) . sprintf(
			/* translators: 1: ArionPay invoice id, 2: amount seen, 3: coin code */
			__( 'ArionPay invoice %1$s: %2$s %3$s was seen on the network and is waiting for confirmations. Do not ship yet.', 'arionpay-for-woocommerce' ),
			self::text( $invoice, 'id' ),
			self::text( $invoice, 'paidCrypto' ),
			self::text( $invoice, 'asset' )
		);
	}

	/**
	 * Build the invoice.partial note.
	 *
	 * @param array $invoice Invoice.
	 * @return string
	 */
	private static function partial_note( array $invoice ) {
		return self::prefix( $invoice ) . sprintf(
			/* translators: 1: ArionPay invoice id, 2: amount received, 3: amount due, 4: coin code */
			__( 'ArionPay invoice %1$s: partial payment of %2$s of %3$s %4$s. The invoice stays open for the rest. Do not ship yet.', 'arionpay-for-woocommerce' ),
			self::text( $invoice, 'id' ),
			self::text( $invoice, 'paidCrypto' ),
			self::text( $invoice, 'amountCrypto' ),
			self::text( $invoice, 'asset' )
		);
	}

	/**
	 * Build the invoice.needs_review note.
	 *
	 * @param array $invoice Invoice.
	 * @return string
	 */
	private static function review_note( array $invoice ) {
		$reason = self::text( $invoice, 'reviewReason' );
		return self::prefix( $invoice ) . sprintf(
			/* translators: 1: ArionPay invoice id, 2: reason given by ArionPay */
			__( 'ArionPay invoice %1$s needs your review in the ArionPay dashboard: %2$s The order status was not changed.', 'arionpay-for-woocommerce' ),
			self::text( $invoice, 'id' ),
			'' !== $reason ? $reason : __( 'no reason was given.', 'arionpay-for-woocommerce' )
		);
	}

	/**
	 * Marks notes for test invoices.
	 *
	 * @param array $invoice Invoice.
	 * @return string
	 */
	private static function prefix( array $invoice ) {
		return ! empty( $invoice['test'] ) ? __( '[TEST - no real money] ', 'arionpay-for-woocommerce' ) : '';
	}

	/**
	 * Read a field as clean, length-limited text.
	 *
	 * @param array  $source Source array.
	 * @param string $key    Field name.
	 * @return string
	 */
	private static function text( array $source, $key ) {
		if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( (string) $source[ $key ] ), 0, 400 );
	}

	/**
	 * Atomic per-order lock, the same technique WordPress core uses for its upgrade lock.
	 *
	 * @param int $order_id Order id.
	 * @return bool
	 */
	private static function acquire_lock( $order_id ) {
		global $wpdb;
		$name = 'arionpay_lock_' . absint( $order_id );
		for ( $try = 0; $try < 5; $try++ ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the insert itself is the lock; caching it would defeat the purpose.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$name,
					(string) time()
				)
			);
			if ( $inserted ) {
				return true;
			}
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			// phpcs:enable
			if ( $since > 0 && $since < time() - self::LOCK_TTL_SECONDS ) {
				self::release_lock( $order_id );
				continue;
			}
			sleep( 1 );
		}
		return false;
	}

	/**
	 * Release the per-order lock.
	 *
	 * @param int $order_id Order id.
	 */
	private static function release_lock( $order_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'arionpay_lock_' . absint( $order_id ) ) );
	}
}
