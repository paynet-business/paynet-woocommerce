<?php
/**
 * Refund rules checked before calling Paynet (ТЗ разд. 9). Pure logic, no WordPress — unit-tested.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Refund rules.
 */
class Paynet_WC_Refund_Rules {

	/**
	 * Paynet accepts refunds within this many days of payment.
	 */
	const MAX_DAYS = 180;

	/**
	 * Refund is not possible for this payment method.
	 */
	const NO_REFUND_METHOD = 'CASH_INVOICE';

	/**
	 * First rule the refund breaks, or '' when it may be sent.
	 *
	 * @param array $c {
	 *     @type int    $amount      Refund amount, minor units.
	 *     @type int    $paid        Amount paid through Paynet, minor units.
	 *     @type bool   $has_payment The order has a confirmed Paynet payment.
	 *     @type bool   $already     A refund through Paynet was already made.
	 *     @type int    $paid_at     Payment time (Unix), 0 if unknown.
	 *     @type int    $now         Current time (Unix).
	 *     @type string $money_type  Payment method from the notification, '' if unknown.
	 *     @type int    $status      Paynet status from the API, 0 if unknown.
	 * }
	 * @return string Rule code: no_payment, already, status_refunded, amount, above_paid, too_old, method.
	 */
	public static function violation( array $c ) {
		$c = array_merge(
			array(
				'amount'      => 0,
				'paid'        => 0,
				'has_payment' => false,
				'already'     => false,
				'paid_at'     => 0,
				'now'         => time(),
				'money_type'  => '',
				'status'      => 0,
			),
			$c
		);
		if ( ! $c['has_payment'] ) {
			return 'no_payment';
		}
		if ( $c['already'] ) {
			return 'already';
		}
		if ( 5 === (int) $c['status'] || 6 === (int) $c['status'] ) {
			return 'status_refunded';
		}
		if ( (int) $c['amount'] <= 0 ) {
			return 'amount';
		}
		if ( (int) $c['amount'] > (int) $c['paid'] ) {
			return 'above_paid';
		}
		if ( (int) $c['paid_at'] > 0 && (int) $c['now'] - (int) $c['paid_at'] > self::MAX_DAYS * 86400 ) {
			return 'too_old';
		}
		if ( self::NO_REFUND_METHOD === strtoupper( (string) $c['money_type'] ) ) {
			return 'method';
		}
		return '';
	}

	/**
	 * `RefundType`: full when the whole paid amount is returned (ТЗ разд. 9).
	 *
	 * @param int $amount Refund amount, minor units.
	 * @param int $paid   Paid amount, minor units.
	 * @return string full|partial
	 */
	public static function type( $amount, $paid ) {
		return (int) $amount === (int) $paid ? 'full' : 'partial';
	}

	/**
	 * Decimal amount from WooCommerce → integer minor units, without float drift.
	 *
	 * @param string|float $amount   Amount, e.g. "29.70".
	 * @param int          $decimals Currency decimals.
	 * @return int
	 */
	public static function to_minor( $amount, $decimals = 2 ) {
		return (int) round( (float) $amount * pow( 10, (int) $decimals ) );
	}
}
