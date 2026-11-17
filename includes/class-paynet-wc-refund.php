<?php
/**
 * Refunds from the WooCommerce admin through Paynet (ТЗ разд. 9).
 *
 * One refund per order; ≤ paid amount; ≤ 180 days; not for CASH_INVOICE; the result comes in the
 * response (no notification); never retried automatically.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Refund processor.
 */
class Paynet_WC_Refund {

	/**
	 * Order meta: the refund made through Paynet (array: amount, type, date, status).
	 */
	const META_REFUND = '_paynet_refund';

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
	 * Refund an order. WooCommerce records the refund only when this returns true.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $amount Amount as entered by the admin.
	 * @param string   $reason Reason.
	 * @return true|WP_Error
	 */
	public function refund( WC_Order $order, $amount, $reason ) {
		$payments   = $this->gateway->payments();
		$mode       = $payments->order_mode( $order );
		$api        = $this->gateway->api( $mode );
		$number     = (string) $order->get_meta( Paynet_WC_Payment::META_ORDER );
		$payment_id = (string) $order->get_meta( Paynet_WC_Payment::META_PAYMENT_ID );
		$currency   = $order->get_currency();
		$minor      = Paynet_WC_Refund_Rules::to_minor( $amount, wc_get_price_decimals() );
		$paid       = (int) $order->get_meta( Paynet_WC_Payment::META_PAID_AMOUNT );
		$paid_at    = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : 0;
		$status     = 0;

		// Fresh status and paid amount from Paynet: catches a refund made in the Paynet cabinet. [Пр] Р4-1.
		if ( '' !== $number ) {
			$res = $api->get_by_order( $number, 15 );
			if ( 200 === $res['code'] && is_array( $res['data'] ) ) {
				$status = isset( $res['data']['status'] ) ? (int) $res['data']['status'] : 0;
				if ( isset( $res['data']['amount']['paidAmount'] ) ) {
					$paid = (int) $res['data']['amount']['paidAmount'];
				}
				if ( '' === $payment_id && ! empty( $res['data']['paymentId'] ) ) {
					$payment_id = (string) $res['data']['paymentId'];
				}
			}
		}

		$rule = Paynet_WC_Refund_Rules::violation(
			array(
				'amount'      => $minor,
				'paid'        => $paid,
				'has_payment' => '' !== $payment_id && $paid > 0,
				'already'     => (bool) $order->get_meta( self::META_REFUND ),
				'paid_at'     => $paid_at,
				'now'         => time(),
				'money_type'  => (string) $order->get_meta( Paynet_WC_Payment::META_MONEY_TYPE ),
				'status'      => $status,
			)
		);
		if ( '' !== $rule ) {
			return new WP_Error( 'paynet_refund_' . $rule, $this->rule_message( $rule, $minor, $paid, $currency, $paid_at, $status ) );
		}

		$type = Paynet_WC_Refund_Rules::type( $minor, $paid );
		$body = array(
			'PaymentId'    => (int) $payment_id,
			'Amount'       => $minor,
			'SaleAreaCode' => $this->gateway->credential( 'sale_area', $mode ),
			'Currency'     => Paynet_WC_Order_Body::currency_code( $currency ),
			'RefundType'   => $type,
			'Reason'       => $this->reason( $reason, $order ),
		);
		$res = $api->call( 'POST', '/api/order/refund', $body, 45 );

		if ( 200 === $res['code'] ) {
			$this->record( $order, $minor, $type, $reason, '' );
			return true;
		}

		if ( 0 === $res['code'] ) {
			// No answer: the result is unknown. Ask Paynet; 5/6 means the refund went through (ТЗ разд. 9).
			$check = $api->get_by_order( $number, 15 );
			$now   = ( 200 === $check['code'] && isset( $check['data']['status'] ) ) ? (int) $check['data']['status'] : 0;
			if ( 5 === $now || 6 === $now ) {
				$this->record( $order, $minor, $type, $reason, __( 'The refund response did not arrive; Paynet status confirms the refund.', 'paynet-for-woocommerce' ) );
				return true;
			}
			$order->add_order_note(
				__( 'Paynet: no response to the refund request and the status does not show a refund. The refund was not recorded — check the payment in the Paynet cabinet before trying again.', 'paynet-for-woocommerce' )
			);
			return new WP_Error( 'paynet_refund_unknown', __( 'Paynet did not respond, the refund result is unknown. Check the payment status in the Paynet cabinet before trying again.', 'paynet-for-woocommerce' ) );
		}

		$message = $this->api_message( $res );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: error text from Paynet */
				__( 'Paynet: refund of %1$s rejected — %2$s', 'paynet-for-woocommerce' ),
				Paynet_WC_Payment::money( $minor, $currency ),
				$message
			)
		);
		return new WP_Error( 'paynet_refund_api', $message );
	}

	/**
	 * Store the refund and add the order note.
	 *
	 * @param WC_Order $order  Order.
	 * @param int      $minor  Amount, minor units.
	 * @param string   $type   full|partial.
	 * @param string   $reason Reason.
	 * @param string   $extra  Extra note text.
	 */
	private function record( WC_Order $order, $minor, $type, $reason, $extra ) {
		$order->update_meta_data(
			self::META_REFUND,
			array(
				'amount' => $minor,
				'type'   => $type,
				'date'   => gmdate( 'c' ),
			)
		);
		$order->save();
		$note = sprintf(
			/* translators: 1: amount, 2: full or partial */
			__( 'Paynet: refund of %1$s done (%2$s).', 'paynet-for-woocommerce' ),
			Paynet_WC_Payment::money( $minor, $order->get_currency() ),
			'full' === $type ? __( 'full', 'paynet-for-woocommerce' ) : __( 'partial', 'paynet-for-woocommerce' )
		);
		if ( '' !== trim( (string) $reason ) ) {
			/* translators: %s: refund reason */
			$note .= ' ' . sprintf( __( 'Reason: %s', 'paynet-for-woocommerce' ), Paynet_WC_Text::clean( $reason, 255 ) );
		}
		if ( '' !== $extra ) {
			$note .= ' ' . $extra;
		}
		$order->add_order_note( $note );
	}

	/**
	 * Reason sent to Paynet (required by the API).
	 *
	 * @param string   $reason Reason entered by the admin.
	 * @param WC_Order $order  Order.
	 * @return string
	 */
	private function reason( $reason, WC_Order $order ) {
		$reason = Paynet_WC_Text::clean( (string) $reason, 255 );
		if ( '' === $reason ) {
			/* translators: %s: order number */
			$reason = sprintf( __( 'Refund for order %s', 'paynet-for-woocommerce' ), $order->get_order_number() );
		}
		return $reason;
	}

	/**
	 * Admin message for a broken rule.
	 *
	 * @param string $rule     Rule code.
	 * @param int    $minor    Refund amount.
	 * @param int    $paid     Paid amount.
	 * @param string $currency Currency.
	 * @param int    $paid_at  Payment time.
	 * @param int    $status   Paynet status.
	 * @return string
	 */
	private function rule_message( $rule, $minor, $paid, $currency, $paid_at, $status ) {
		switch ( $rule ) {
			case 'no_payment':
				return __( 'This order has no confirmed Paynet payment to refund.', 'paynet-for-woocommerce' );
			case 'already':
				return __( 'Paynet allows one refund per order, and this order has already been refunded through Paynet. Refund the rest manually if needed.', 'paynet-for-woocommerce' );
			case 'status_refunded':
				return sprintf(
					/* translators: %d: Paynet status */
					__( 'Paynet already shows a refund for this payment (status %d), for example made in the Paynet cabinet. Paynet allows one refund per order.', 'paynet-for-woocommerce' ),
					$status
				);
			case 'amount':
				return __( 'Enter a refund amount greater than zero.', 'paynet-for-woocommerce' );
			case 'above_paid':
				return sprintf(
					/* translators: 1: refund amount, 2: paid amount */
					__( 'The refund amount %1$s is more than the amount paid through Paynet (%2$s).', 'paynet-for-woocommerce' ),
					Paynet_WC_Payment::money( $minor, $currency ),
					Paynet_WC_Payment::money( $paid, $currency )
				);
			case 'too_old':
				return sprintf(
					/* translators: %s: payment date */
					__( 'Paynet accepts refunds within 180 days of payment. This order was paid on %s.', 'paynet-for-woocommerce' ),
					wp_date( get_option( 'date_format' ), $paid_at )
				);
			case 'method':
				return __( 'Paynet does not refund payments made by invoice (CASH_INVOICE). Return the money to the customer another way and refund manually.', 'paynet-for-woocommerce' );
		}
		return __( 'The refund cannot be made through Paynet.', 'paynet-for-woocommerce' );
	}

	/**
	 * Admin message for an API error: Paynet's own text plus code and trace ID.
	 *
	 * @param array $res API result.
	 * @return string
	 */
	private function api_message( array $res ) {
		$text = '' !== $res['error'] ? $res['error'] : sprintf( 'HTTP %d', $res['code'] );
		if ( 400 === $res['code'] && false !== stripos( $text, 'RefundType' ) ) {
			// Paynet names RefundType for both "amount above paid" and "already refunded" (OPEN_QUESTIONS #4).
			$text = __( 'Paynet rejected the refund: the amount is more than the paid amount, or the payment has already been refunded.', 'paynet-for-woocommerce' ) . ' (' . $text . ')';
		} elseif ( 404 === $res['code'] ) {
			$text = __( 'Paynet did not find a paid payment for this order.', 'paynet-for-woocommerce' ) . ' (' . $text . ')';
		}
		$suffix = trim( $res['error_code'] . ( '' !== $res['trace_id'] ? ', traceId ' . $res['trace_id'] : '' ), ', ' );
		return Paynet_WC_Text::clean( $text, 400 ) . ( '' !== $suffix ? ' [' . $suffix . ']' : '' );
	}
}
