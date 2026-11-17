<?php
/**
 * Orders of the old "Paynet Moldova Gateway" plugin (protocol v0.5) after the switch (ТЗ разд. 11).
 *
 * The old plugin keeps nothing in order meta: its Paynet `ExternalId` is the order ID followed by 10 digits
 * (end of microtime). The new API finds such orders by `GET /api/order/merchant/{ExternalId}` (В-3, confirmed
 * on the test API 17.11.2026). The status is never taken from the notification body or the URL — only from
 * that GET. Pure checks are static and unit-tested.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Old plugin orders.
 */
class Paynet_WC_Legacy {

	/**
	 * Gateway ID of the old plugin.
	 */
	const GATEWAY_ID = 'paynet';

	/**
	 * Digits the old plugin appends to the order ID.
	 */
	const POSTFIX_DIGITS = 10;

	/**
	 * Paynet `ExternalId` of a confirmed old order.
	 */
	const META_ORDER = '_paynet_legacy_order';

	/**
	 * `ExternalId`s already reported for a manual check (no repeated notes).
	 */
	const META_MANUAL = '_paynet_legacy_manual';

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
	 * Shop order ID from an old `ExternalId`: everything except the last 10 digits.
	 *
	 * @param string|int $external ExternalId.
	 * @return int 0 when it cannot be an old plugin number.
	 */
	public static function order_id( $external ) {
		$external = (string) $external;
		if ( ! ctype_digit( $external ) || strlen( $external ) <= self::POSTFIX_DIGITS || strlen( $external ) > 19 ) {
			return 0;
		}
		$id = substr( $external, 0, -self::POSTFIX_DIGITS );
		return '0' === $id[0] ? 0 : (int) $id;
	}

	/**
	 * Result of the API answer for an old order.
	 *
	 * @param array    $payment  `GET /api/order/merchant/{ExternalId}` response.
	 * @param int      $expected Order total, minor units.
	 * @param int|null $currency ISO 4217 numeric code of the order currency.
	 * @return string paid | less | review | not_paid
	 */
	public static function verdict( array $payment, $expected, $currency ) {
		if ( ! isset( $payment['status'] ) || 4 !== (int) $payment['status'] ) {
			return 'not_paid';
		}
		$amount = isset( $payment['amount']['amount'] ) ? (int) $payment['amount']['amount'] : null;
		$paid   = isset( $payment['amount']['paidAmount'] ) ? (int) $payment['amount']['paidAmount'] : $amount;
		$cur    = isset( $payment['amount']['requestCurrency'] ) ? (int) $payment['amount']['requestCurrency'] : null;
		if ( null === $amount || (int) $expected !== $amount || $paid > $amount || ( null !== $cur && null !== $currency && (int) $currency !== $cur ) ) {
			return 'review';
		}
		return $paid < $amount ? 'less' : 'paid';
	}

	/**
	 * Old plugin order for an `ExternalId`, or null.
	 *
	 * @param string|int $external ExternalId.
	 * @return WC_Order|null
	 */
	public static function find_order( $external ) {
		$id = self::order_id( $external );
		if ( ! $id ) {
			return null;
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order || self::GATEWAY_ID !== $order->get_payment_method() ) {
			return null;
		}
		return $order;
	}

	/**
	 * Confirm an old order through the new API and apply the result.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $external ExternalId.
	 * @return string paid | less | review | not_paid | not_found
	 */
	public function confirm( WC_Order $order, $external ) {
		$external = (string) $external;
		if ( $order->is_paid() ) {
			return 'paid';
		}
		// Old orders do not record the mode: the credentials of the current mode are used.
		$res = $this->gateway->api()->get_by_order( $external, 10 );
		if ( 200 !== $res['code'] || ! isset( $res['data']['status'] ) ) {
			$this->manual( $order, $external, (int) $res['code'] );
			return 'not_found';
		}
		$payments = $this->gateway->payments();
		$order_id = $order->get_id();
		if ( ! $payments->lock( $order_id ) ) {
			return 'not_paid'; // Another request is applying the same payment.
		}
		try {
			$order   = wc_get_order( $order_id );
			$verdict = self::verdict(
				$res['data'],
				Paynet_WC_Order_Body::to_minor( $order->get_total() ),
				Paynet_WC_Order_Body::currency_code( $order->get_currency() )
			);
			if ( 'not_paid' !== $verdict && ! $order->is_paid() && ! $order->get_meta( self::META_ORDER ) ) {
				$this->apply( $order, $external, $res['data'], $verdict );
			}
		} finally {
			$payments->unlock( $order_id );
		}
		return $verdict;
	}

	/**
	 * Status 4 for an old order.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $external ExternalId.
	 * @param array    $payment  API payment.
	 * @param string   $verdict  paid | less | review.
	 */
	private function apply( WC_Order $order, $external, array $payment, $verdict ) {
		$payment_id = isset( $payment['paymentId'] ) ? (string) $payment['paymentId'] : '';
		$amount     = isset( $payment['amount']['amount'] ) ? (int) $payment['amount']['amount'] : 0;
		$paid       = isset( $payment['amount']['paidAmount'] ) ? (int) $payment['amount']['paidAmount'] : $amount;
		$currency   = $order->get_currency();
		$cancelled  = $order->has_status( 'cancelled' );
		$payments   = $this->gateway->payments();

		$order->update_meta_data( self::META_ORDER, $external );
		$order->update_meta_data( Paynet_WC_Payment::META_PAYMENT_ID, $payment_id );
		$order->update_meta_data( Paynet_WC_Payment::META_PAID_AMOUNT, $paid );
		if ( ! empty( $payment['payer']['moneyType'] ) ) {
			$order->update_meta_data( Paynet_WC_Payment::META_MONEY_TYPE, Paynet_WC_Text::clean( $payment['payer']['moneyType'], 32 ) );
		}

		if ( 'review' === $verdict ) {
			$order->update_meta_data( Paynet_WC_Payment::META_MISMATCH, 'review' );
			$order->set_transaction_id( $payment_id );
			$note = sprintf(
				/* translators: 1: Paynet ExternalId, 2: amount in Paynet, 3: order total */
				__( 'Paynet: order of the old plugin (Order %1$s) is paid, but the amount in Paynet %2$s differs from the order total %3$s. Check manually.', 'paynet-for-woocommerce' ),
				$external,
				Paynet_WC_Payment::money( $amount, $currency ),
				Paynet_WC_Payment::money( Paynet_WC_Order_Body::to_minor( $order->get_total() ), $currency )
			);
			$order->update_status( 'on-hold', $note );
			$payments->mail_admin( $order, $note );
			$this->gateway->log( sprintf( 'Old plugin order %d: amount mismatch, on-hold.', $order->get_id() ) );
			return;
		}

		$order->save();
		$order->payment_complete( $payment_id );

		$details = array( Paynet_WC_Payment::money( $paid, $currency ) );
		foreach ( array( 'moneyType', 'cardMask' ) as $field ) {
			if ( ! empty( $payment['payer'][ $field ] ) ) {
				$details[] = Paynet_WC_Text::clean( $payment['payer'][ $field ], 32 );
			}
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: Paynet payment ID, 2: Paynet ExternalId, 3: amount, method and card mask */
				__( 'Paynet: payment #%1$s of the old plugin order (Order %2$s) confirmed by the new plugin (%3$s).', 'paynet-for-woocommerce' ),
				$payment_id,
				$external,
				implode( ', ', $details )
			)
		);
		$this->gateway->log( sprintf( 'Old plugin order %d: payment confirmed.', $order->get_id() ) );

		if ( 'less' === $verdict ) {
			$order->update_meta_data( Paynet_WC_Payment::META_MISMATCH, 'less' );
			$order->save();
			$note = sprintf(
				/* translators: 1: paid amount, 2: order amount */
				__( 'Attention: the paid amount (%1$s) is less than the order amount (%2$s). Check whether there was a discount.', 'paynet-for-woocommerce' ),
				Paynet_WC_Payment::money( $paid, $currency ),
				Paynet_WC_Payment::money( $amount, $currency )
			);
			$order->add_order_note( $note );
			$payments->mail_admin( $order, $note );
		}
		if ( $cancelled ) {
			$note = __( 'Paynet: paid after the order was cancelled — check that the goods are in stock.', 'paynet-for-woocommerce' );
			$order->add_order_note( $note );
			$payments->mail_admin( $order, $note );
		}
	}

	/**
	 * Note «check manually» once per ExternalId (not found in the new API or the API did not answer).
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $external ExternalId.
	 * @param int      $code     HTTP code (0 = no answer).
	 */
	private function manual( WC_Order $order, $external, $code ) {
		$done = (array) $order->get_meta( self::META_MANUAL );
		if ( in_array( $external, $done, true ) ) {
			return;
		}
		$done[] = $external;
		$order->update_meta_data( self::META_MANUAL, array_slice( $done, -10 ) );
		$order->add_order_note(
			sprintf(
				/* translators: %s: Paynet ExternalId */
				__( 'Paynet: notification or return for the old plugin order (Order %s) — the payment could not be checked through the new API. Check the payment manually in Paynet.', 'paynet-for-woocommerce' ),
				$external
			)
		);
		$order->save();
		$this->gateway->log( sprintf( 'Old plugin order %d: status check failed (HTTP %d), manual check noted.', $order->get_id(), $code ) );
	}
}
