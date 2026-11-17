<?php
/**
 * Payment flow: create attempts, apply Paynet status to the order, background checks
 * (ТЗ разд. 6, 7.1, 7.3–7.6).
 *
 * The order status is changed only from an authenticated `GET /api/order/merchant/{Order}` response —
 * never from return-URL parameters or unsigned notification fields.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payment processor.
 */
class Paynet_WC_Payment {

	const HOOK_CHECK = 'paynet_wc_check_payment';
	const AS_GROUP   = 'paynet-for-woocommerce';

	/**
	 * Delays between background checks, minutes: 2, 5, 15, 30 after creation, then hourly (ТЗ разд. 7.4).
	 */
	const CHECK_DELAYS = array( 2, 3, 10, 15 );

	// Order meta (ТЗ разд. 6).
	const META_ORDER        = '_paynet_order';
	const META_ATTEMPT      = '_paynet_attempt';
	const META_PAYMENT_ID   = '_paynet_payment_id';
	const META_PAYMENT_LINK = '_paynet_payment_link';
	const META_EXPIRY       = '_paynet_expiry';
	const META_EXPIRY_TS    = '_paynet_expiry_ts';
	const META_REQUEST_HASH = '_paynet_request_hash';
	const META_AMOUNT       = '_paynet_amount';
	const META_MODE         = '_paynet_mode';
	const META_PAID_AMOUNT  = '_paynet_paid_amount';
	const META_MONEY_TYPE   = '_paynet_money_type';
	const META_MISMATCH     = '_paynet_amount_mismatch';
	const META_FINAL        = '_paynet_final';
	const META_NOTICE       = '_paynet_notification';

	// Paynet statuses (ТЗ разд. 7.5).
	const STATUS_CREATED    = 1;
	const STATUS_PROCESSING = 3;
	const STATUS_PAID       = 4;
	const STATUS_CANCELLED  = 5;
	const STATUS_PARTIAL    = 6;

	/**
	 * Gateway.
	 *
	 * @var Paynet_WC_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param Paynet_WC_Gateway $gateway Gateway.
	 */
	public function __construct( Paynet_WC_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Find the shop order by Paynet `Order` (meta lookup, HPOS-compatible).
	 *
	 * @param string|int $paynet_order Paynet Order.
	 * @return WC_Order|null
	 */
	public static function find_order( $paynet_order ) {
		$paynet_order = (string) $paynet_order;
		if ( '' === $paynet_order || ! ctype_digit( $paynet_order ) ) {
			return null;
		}
		// `meta_key` / `meta_value`, not `meta_query`: the posts storage (HPOS off) silently drops `meta_query`
		// in wc_get_orders and would return the latest order of the shop. Both storages support these two.
		$orders = wc_get_orders(
			array(
				'limit'      => 5,
				'type'       => 'shop_order',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_key'   => self::META_ORDER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $paynet_order, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		// Never trust the query alone: the order must really carry this Paynet Order.
		foreach ( $orders as $order ) {
			if ( (string) $order->get_meta( self::META_ORDER ) === $paynet_order ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Start or resume a payment (process_payment).
	 *
	 * @param WC_Order $order Order.
	 * @return array{redirect?:string,error?:string}
	 */
	public function pay( WC_Order $order ) {
		if ( '' !== (string) $order->get_meta( self::META_ORDER ) ) {
			$resume = $this->resume( $order );
			if ( null !== $resume ) {
				return $resume;
			}
		}
		return $this->create( $order );
	}

	/**
	 * Repeat payment of an order that already has a Paynet attempt (ТЗ разд. 6 «Повторная оплата»).
	 *
	 * @param WC_Order $order Order.
	 * @return array|null Result, or null when a new attempt is needed.
	 */
	private function resume( WC_Order $order ) {
		$mode = $this->order_mode( $order );
		if ( $mode !== $this->gateway->mode() ) {
			return null; // Mode switched since the last attempt: start over in the current mode.
		}
		$body = $this->body( $order, (int) $order->get_meta( self::META_ORDER ), $mode );
		if ( Paynet_WC_Order_Body::content_hash( $body ) !== $order->get_meta( self::META_REQUEST_HASH ) ) {
			return null; // Order content changed: new attempt.
		}

		$res = $this->gateway->api( $mode )->get_by_order( $order->get_meta( self::META_ORDER ) );
		if ( 404 === $res['code'] ) {
			return null;
		}
		if ( 200 !== $res['code'] || ! isset( $res['data']['status'] ) ) {
			return array( 'error' => __( 'Could not check the payment status. Please try again in a minute.', 'paynet-for-woocommerce' ) );
		}

		$status = (int) $res['data']['status'];
		if ( self::STATUS_PAID === $status ) {
			$this->sync( $order, $res['data'] );
			return array( 'redirect' => $this->gateway->get_return_url( $order ) );
		}
		if ( self::STATUS_PROCESSING === $status ) {
			return array( 'error' => __( 'The payment is being processed. Please wait a few minutes before paying again.', 'paynet-for-woocommerce' ) );
		}
		$link = (string) $order->get_meta( self::META_PAYMENT_LINK );
		if ( self::STATUS_CREATED === $status && '' !== $link && (int) $order->get_meta( self::META_EXPIRY_TS ) > time() + 120 ) {
			return array( 'redirect' => $link );
		}
		return null; // Cancelled/declined or expired: new attempt.
	}

	/**
	 * New attempt: new `Order`, new `ExpiryDate` (ТЗ разд. 7.1).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function create( WC_Order $order ) {
		$mode    = $this->gateway->mode();
		$api     = $this->gateway->api( $mode );
		$attempt = (int) $order->get_meta( self::META_ATTEMPT );
		$tries   = 'test' === $mode ? 2 : 1;
		$res     = null;

		for ( $i = 0; $i < $tries; $i++ ) {
			++$attempt;
			if ( $attempt > Paynet_WC_Order_Number::MAX_ATTEMPT ) {
				$order->add_order_note( __( 'Paynet: the limit of 999 payment attempts for this order is reached. Create a new order.', 'paynet-for-woocommerce' ) );
				return array( 'error' => $this->customer_error() );
			}
			$number = Paynet_WC_Order_Number::build( $order->get_id(), $attempt, 'test' === $mode ? Paynet_WC_Gateway::get_salt() : null );
			$window = Paynet_WC_Order_Body::window_minutes( $this->gateway->get_option( 'payment_window', 60 ), get_option( 'woocommerce_hold_stock_minutes', 0 ) );
			$now    = time();
			$body   = $this->body( $order, $number, $mode, Paynet_WC_Order_Body::expiry_date( $window, $now ) );

			// Remember the attempt before the request: a repeated click must never reuse a number with other data.
			$order->update_meta_data( self::META_ATTEMPT, $attempt );
			$order->save();

			$res = $api->create_order( $body );
			if ( 0 === $res['code'] ) {
				$res = $api->create_order( $body ); // Network failure: one retry with the same body (ТЗ 7.1 п. 6).
			}

			if ( 200 === $res['code'] && ! empty( $res['data']['paymentLink'] ) ) {
				$this->save_attempt( $order, $mode, $number, $attempt, $body, $res['data'], $now + 60 * $window );
				return array( 'redirect' => (string) $res['data']['paymentLink'] );
			}

			$collision = 'test' === $mode && 500 === $res['code'] && 'TECHNICAL_ERROR' === $res['error_code'];
			if ( ! $collision ) {
				break;
			}
			if ( $i + 1 < $tries ) {
				$order->add_order_note(
					/* translators: %s: Paynet order number */
					sprintf( __( 'Paynet: TECHNICAL_ERROR for Order %s in test mode, retrying with a new attempt number.', 'paynet-for-woocommerce' ), $number )
				);
			} else {
				$order->add_order_note( __( 'Paynet: in the test environment this error may be caused by an order number that another user of the shared test account already used. Click "Regenerate" next to the test order-number salt in the plugin settings and try again.', 'paynet-for-woocommerce' ) );
			}
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: HTTP code, 2: Paynet error code, 3: trace ID, 4: error text */
				__( 'Paynet: payment was not created. HTTP %1$s, code %2$s, traceId %3$s. %4$s', 'paynet-for-woocommerce' ),
				$res ? $res['code'] : 0,
				$res && '' !== $res['error_code'] ? $res['error_code'] : '—',
				$res && '' !== $res['trace_id'] ? $res['trace_id'] : '—',
				$res ? Paynet_WC_Text::clean( $res['error'], 300 ) : ''
			)
		);
		return array( 'error' => $this->customer_error() );
	}

	/**
	 * Customer-facing error (ТЗ 7.1 п. 5). The order is not cancelled.
	 *
	 * @return string
	 */
	private function customer_error() {
		return __( 'Could not proceed to payment, please try again.', 'paynet-for-woocommerce' );
	}

	/**
	 * Store a created attempt.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $mode    test|live.
	 * @param int      $number  Paynet Order.
	 * @param int      $attempt Attempt.
	 * @param array    $body    Body.
	 * @param array    $data    Response.
	 * @param int      $expiry  Expiry, unix.
	 */
	private function save_attempt( WC_Order $order, $mode, $number, $attempt, array $body, array $data, $expiry ) {
		$order->update_meta_data( self::META_ORDER, (string) $number );
		$order->update_meta_data( self::META_ATTEMPT, $attempt );
		$order->update_meta_data( self::META_MODE, $mode );
		$order->update_meta_data( self::META_PAYMENT_ID, isset( $data['paymentId'] ) ? (string) $data['paymentId'] : '' );
		$order->update_meta_data( self::META_PAYMENT_LINK, (string) $data['paymentLink'] );
		$order->update_meta_data( self::META_EXPIRY, $body['ExpiryDate'] );
		$order->update_meta_data( self::META_EXPIRY_TS, $expiry );
		$order->update_meta_data( self::META_REQUEST_HASH, Paynet_WC_Order_Body::content_hash( $body ) );
		$order->update_meta_data( self::META_AMOUNT, $body['Services'][0]['Amount'] );
		$order->delete_meta_data( self::META_FINAL );
		$order->delete_meta_data( self::META_NOTICE );
		$order->delete_meta_data( self::META_MISMATCH );
		$order->add_order_note(
			sprintf(
				/* translators: 1: Paynet payment ID, 2: Paynet order number, 3: mode */
				__( 'Paynet payment #%1$s created, Order %2$s (%3$s mode).', 'paynet-for-woocommerce' ),
				isset( $data['paymentId'] ) ? $data['paymentId'] : '—',
				$number,
				'test' === $mode ? __( 'test', 'paynet-for-woocommerce' ) : __( 'live', 'paynet-for-woocommerce' )
			)
		);
		if ( ! $order->has_status( 'pending' ) ) {
			$order->set_status( 'pending' );
		}
		$order->save();
		$this->schedule( $order, 0 );
	}

	/**
	 * Build the request body from the order.
	 *
	 * @param WC_Order    $order  Order.
	 * @param int         $number Paynet Order.
	 * @param string      $mode   Mode.
	 * @param string|null $expiry ExpiryDate (null — any, for content hash).
	 * @return array
	 */
	public function body( WC_Order $order, $number, $mode, $expiry = null ) {
		$lines = array();
		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $item ) {
			$code = $item->get_type(); // shipping, fee.
			$qty  = 1;
			if ( $item instanceof WC_Order_Item_Product ) {
				$qty     = $item->get_quantity();
				$product = $item->get_product();
				$code    = $product && '' !== $product->get_sku() ? $product->get_sku() : 'product-' . $item->get_product_id();
			}
			$lines[] = array(
				'code'  => $code,
				'name'  => $item->get_name(),
				'qty'   => $qty,
				'total' => (float) $item->get_total() + (float) $item->get_total_tax(),
			);
		}

		$user_id = $order->get_customer_id();
		/* translators: %s: order number */
		$label = sprintf( __( 'Order #%s', 'paynet-for-woocommerce' ), $order->get_order_number() );

		return Paynet_WC_Order_Body::build(
			array(
				'order'       => $number,
				'sale_area'   => $this->gateway->credential( 'sale_area', $mode ),
				'success_url' => self::return_url( $order, 'success' ),
				'cancel_url'  => self::return_url( $order, 'cancel' ),
				'currency'    => (int) Paynet_WC_Order_Body::currency_code( $order->get_currency() ),
				'expiry'      => null === $expiry ? '' : $expiry,
				'lang'        => Paynet_WC_Order_Body::lang( get_locale() ),
				'customer'    => array(
					'code'  => $user_id > 0 ? Paynet_WC_Cards::code_for( $user_id, $mode ) : 'guest-' . $order->get_id(),
					'first' => $order->get_billing_first_name(),
					'last'  => $order->get_billing_last_name(),
					'email' => $order->get_billing_email(),
					'phone' => $order->get_billing_phone(),
				),
				'shop_name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'description' => $label,
				'single_line' => $label,
				'total'       => $order->get_total(),
				'lines'       => $lines,
				/**
				 * Send each order line as a separate Paynet product (off: the API currently accepts one product per service).
				 *
				 * @param bool     $multiline Per-line sending.
				 * @param WC_Order $order     Order.
				 */
				'multiline'   => (bool) apply_filters( 'paynet_wc_multiline_products', false, $order ),
				'card_link_hash' => $user_id > 0 ? $this->gateway->cards()->hash_for_order( $order, $mode ) : '',
			)
		);
	}

	/**
	 * Customer return URL (ТЗ разд. 7.4).
	 *
	 * @param WC_Order $order Order.
	 * @param string   $type  success|cancel.
	 * @return string
	 */
	public static function return_url( WC_Order $order, $type ) {
		return add_query_arg(
			array(
				'wc-api'        => 'WC_Gateway_Paynet',
				'paynet_return' => $type,
				'order'         => $order->get_id(),
				'key'           => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Check the status with the API and apply it.
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $timeout Timeout.
	 * @return int|null Paynet status, or null when the API did not answer.
	 */
	public function refresh( WC_Order $order, $timeout = 15 ) {
		$number = (string) $order->get_meta( self::META_ORDER );
		if ( '' === $number ) {
			return null;
		}
		$res = $this->gateway->api( $this->order_mode( $order ) )->get_by_order( $number, $timeout );
		if ( 200 !== $res['code'] || ! isset( $res['data']['status'] ) ) {
			return null;
		}
		return $this->sync( $order, $res['data'] );
	}

	/**
	 * Apply the API payment object to the order (ТЗ разд. 7.2 п. 4–6, 7.3, 7.5, 7.6).
	 *
	 * @param WC_Order   $order   Order.
	 * @param array      $payment `GET /api/order/merchant/{Order}` response.
	 * @param array|null $notice  Unsigned notification fields to compare, if this comes from a notification.
	 * @return int Paynet status.
	 */
	public function sync( WC_Order $order, array $payment, $notice = null ) {
		$status   = isset( $payment['status'] ) ? (int) $payment['status'] : 0;
		$order_id = $order->get_id();

		if ( ! $this->lock( $order_id ) ) {
			return $status; // Another request is applying the same status right now.
		}
		try {
			$order = wc_get_order( $order_id ); // Fresh copy after the lock.
			if ( ! $order ) {
				return $status;
			}
			$final = (string) $order->get_meta( self::META_FINAL );

			if ( self::STATUS_PAID === $status ) {
				if ( 'paid' !== $final && 'review' !== $final ) {
					$this->apply_paid( $order, $payment, $notice );
				}
			} elseif ( self::STATUS_CANCELLED === $status ) {
				if ( '' === $final && ! $order->is_paid() ) {
					$order->update_meta_data( self::META_FINAL, 'failed' );
					$order->update_status( 'failed', __( 'Paynet: the payment was declined or cancelled (status 5). The customer can try again.', 'paynet-for-woocommerce' ) );
				}
			} elseif ( self::STATUS_CREATED === $status ) {
				$expiry = (int) $order->get_meta( self::META_EXPIRY_TS );
				if ( '' === $final && $expiry > 0 && time() > $expiry ) {
					$order->update_meta_data( self::META_FINAL, 'expired' );
					$order->add_order_note( __( 'Paynet: the payment window has expired, the order was not paid.', 'paynet-for-woocommerce' ) );
					$order->save();
				}
			}
			// 3 — still processing; 6 / 5 after payment — refunds, handled by the refund flow (stage 4).
		} finally {
			$this->unlock( $order_id );
		}
		return $status;
	}

	/**
	 * Status 4: complete, or send to manual review on amount mismatch (ТЗ 7.3, 7.6).
	 *
	 * @param WC_Order   $order   Order.
	 * @param array      $payment API payment.
	 * @param array|null $notice  Notification fields.
	 */
	private function apply_paid( WC_Order $order, array $payment, $notice ) {
		$payment_id = isset( $payment['paymentId'] ) ? (string) $payment['paymentId'] : '';
		$amount     = isset( $payment['amount']['amount'] ) ? (int) $payment['amount']['amount'] : null;
		$paid       = isset( $payment['amount']['paidAmount'] ) ? (int) $payment['amount']['paidAmount'] : $amount;
		$expected   = (int) $order->get_meta( self::META_AMOUNT );
		$currency   = $order->get_currency();
		$stored_id  = (string) $order->get_meta( self::META_PAYMENT_ID );
		$cancelled  = $order->has_status( 'cancelled' );

		$problems = array();
		if ( null === $amount || $amount !== $expected ) {
			$problems[] = sprintf(
				/* translators: 1: amount from Paynet, 2: order amount */
				__( 'order amount in Paynet %1$s ≠ order amount %2$s', 'paynet-for-woocommerce' ),
				self::money( (int) $amount, $currency ),
				self::money( $expected, $currency )
			);
		}
		if ( $paid > (int) $amount ) {
			$problems[] = sprintf(
				/* translators: 1: paid amount, 2: order amount */
				__( 'paid %1$s is more than the order amount %2$s', 'paynet-for-woocommerce' ),
				self::money( $paid, $currency ),
				self::money( (int) $amount, $currency )
			);
		}
		if ( '' !== $stored_id && '' !== $payment_id && $stored_id !== $payment_id ) {
			$problems[] = __( 'payment ID differs from the created payment', 'paynet-for-woocommerce' );
		}
		if ( is_array( $notice ) ) {
			if ( isset( $notice['Id'] ) && (string) $notice['Id'] !== $payment_id ) {
				$problems[] = __( 'notification payment ID differs from the API', 'paynet-for-woocommerce' );
			}
			if ( array_key_exists( 'PaidAmount', $notice ) && null !== $notice['PaidAmount'] && (int) $notice['PaidAmount'] !== $paid ) {
				$problems[] = sprintf(
					/* translators: 1: PaidAmount in the notification, 2: paid amount in the API */
					__( 'PaidAmount in the notification %1$s ≠ API %2$s', 'paynet-for-woocommerce' ),
					self::money( (int) $notice['PaidAmount'], $currency ),
					self::money( $paid, $currency )
				);
			}
		}

		$order->update_meta_data( self::META_PAID_AMOUNT, $paid );
		if ( '' === $stored_id && '' !== $payment_id ) {
			$order->update_meta_data( self::META_PAYMENT_ID, $payment_id );
		}
		// Actual payment method: `payer.moneyType` of the API response (also in test mode, where no notification
		// arrives); the unsigned notification value only as a fallback. Needed for the CASH_INVOICE refund rule.
		if ( ! empty( $payment['payer']['moneyType'] ) ) {
			$order->update_meta_data( self::META_MONEY_TYPE, Paynet_WC_Text::clean( $payment['payer']['moneyType'], 32 ) );
		} elseif ( is_array( $notice ) && ! empty( $notice['MoneyType'] ) && empty( $problems ) ) {
			$order->update_meta_data( self::META_MONEY_TYPE, Paynet_WC_Text::clean( $notice['MoneyType'], 32 ) );
		}

		if ( $problems ) {
			$order->update_meta_data( self::META_MISMATCH, 'review' );
			$order->update_meta_data( self::META_FINAL, 'review' );
			$order->set_transaction_id( $payment_id );
			$note = __( 'Paynet: amount mismatch, check manually.', 'paynet-for-woocommerce' ) . ' ' . implode( '; ', $problems ) . '.';
			$order->update_status( 'on-hold', $note );
			$this->mail_admin( $order, $note );
			return;
		}

		$order->update_meta_data( self::META_FINAL, 'paid' );
		$order->save();
		$order->payment_complete( $payment_id );
		$this->gateway->cards()->save_from_payment( $order, $payment, $this->order_mode( $order ) );

		$details = array( self::money( $paid, $currency ) );
		if ( ! empty( $payment['payer']['moneyType'] ) ) {
			$details[] = Paynet_WC_Text::clean( $payment['payer']['moneyType'], 32 );
		} elseif ( is_array( $notice ) && ! empty( $notice['MoneyType'] ) ) {
			$details[] = Paynet_WC_Text::clean( $notice['MoneyType'], 32 );
		}
		if ( is_array( $notice ) && ! empty( $notice['CardMask'] ) ) {
			$details[] = Paynet_WC_Text::clean( $notice['CardMask'], 32 );
		} elseif ( ! empty( $payment['payer']['cardMask'] ) ) {
			$details[] = Paynet_WC_Text::clean( $payment['payer']['cardMask'], 32 );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: Paynet payment ID, 2: amount, method and card mask */
				__( 'Paynet: payment #%1$s confirmed (%2$s).', 'paynet-for-woocommerce' ),
				$payment_id,
				implode( ', ', $details )
			)
		);

		if ( $paid < (int) $amount ) {
			$order->update_meta_data( self::META_MISMATCH, 'less' );
			$order->save();
			$note = sprintf(
				/* translators: 1: paid amount, 2: order amount */
				__( 'Attention: the paid amount (%1$s) is less than the order amount (%2$s). Check whether there was a discount.', 'paynet-for-woocommerce' ),
				self::money( $paid, $currency ),
				self::money( (int) $amount, $currency )
			);
			$order->add_order_note( $note );
			$this->mail_admin( $order, $note );
		}
		if ( $cancelled ) {
			$note = __( 'Paynet: paid after the order was cancelled — check that the goods are in stock.', 'paynet-for-woocommerce' );
			$order->add_order_note( $note );
			$this->mail_admin( $order, $note );
		}
	}

	/**
	 * Amount in minor units → "12.34 MDL".
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency Currency.
	 * @return string
	 */
	public static function money( $minor, $currency ) {
		return number_format( $minor / 100, 2, '.', '' ) . ' ' . $currency;
	}

	/**
	 * Email the shop administrator.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Text.
	 */
	public function mail_admin( WC_Order $order, $text ) {
		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		/* translators: %s: order number */
		$subject = sprintf( __( 'Paynet: check order #%s', 'paynet-for-woocommerce' ), $order->get_order_number() );
		$body    = $text . "\n\n" . $order->get_edit_order_url();
		wp_mail( $to, $subject, $body );
	}

	/**
	 * Mode the order was created in (credentials for status checks).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public function order_mode( WC_Order $order ) {
		$mode = (string) $order->get_meta( self::META_MODE );
		return in_array( $mode, array( 'test', 'live' ), true ) ? $mode : $this->gateway->mode();
	}

	/**
	 * Schedule the next background check.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $step  Step (0 = first check).
	 * @param int|null $delay Delay in minutes; null = by step.
	 */
	public function schedule( WC_Order $order, $step, $delay = null ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( null === $delay ) {
			$delay = isset( self::CHECK_DELAYS[ $step ] ) ? self::CHECK_DELAYS[ $step ] : 60;
		}
		as_schedule_single_action(
			time() + 60 * (int) $delay,
			self::HOOK_CHECK,
			array(
				'order_id'     => $order->get_id(),
				'paynet_order' => (string) $order->get_meta( self::META_ORDER ),
				'step'         => (int) $step,
			),
			self::AS_GROUP
		);
	}

	/**
	 * Background check (Action Scheduler callback).
	 *
	 * @param int    $order_id     Order ID.
	 * @param string $paynet_order Paynet Order the check was scheduled for.
	 * @param int    $step         Step.
	 */
	public function run_check( $order_id, $paynet_order, $step ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || (string) $order->get_meta( self::META_ORDER ) !== (string) $paynet_order ) {
			return; // A newer attempt has its own checks.
		}
		if ( '' !== (string) $order->get_meta( self::META_FINAL ) || $order->is_paid() ) {
			return;
		}
		$status = $this->refresh( $order );
		$order  = wc_get_order( $order_id );
		if ( ! $order || '' !== (string) $order->get_meta( self::META_FINAL ) ) {
			return;
		}
		if ( in_array( $status, array( null, self::STATUS_CREATED, self::STATUS_PROCESSING ), true )
			&& time() < (int) $order->get_meta( self::META_EXPIRY_TS ) + HOUR_IN_SECONDS ) {
			$this->schedule( $order, (int) $step + 1 );
		}
	}

	/**
	 * Per-order lock so a notification and a return do not complete the order twice.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	public function lock( $order_id ) {
		$key = 'paynet_wc_lock_' . (int) $order_id;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( $key );
		if ( $since && time() - $since > 60 ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}
		// Wait briefly for the other request.
		for ( $i = 0; $i < 10; $i++ ) {
			usleep( 300000 );
			if ( add_option( $key, time(), '', false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $order_id Order ID.
	 */
	public function unlock( $order_id ) {
		delete_option( 'paynet_wc_lock_' . (int) $order_id );
	}
}
