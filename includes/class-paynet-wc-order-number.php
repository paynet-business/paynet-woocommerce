<?php
/**
 * Paynet `Order` number (ТЗ разд. 6). Pure functions, no WordPress dependency.
 *
 * Live: order_id * 1000 + attempt.
 * Test: salt * 10^12 + order_id * 1000 + attempt (the public test SaleArea is shared).
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order number builder.
 */
final class Paynet_WC_Order_Number {

	const MAX_ATTEMPT  = 999;
	const MAX_ORDER_ID = 999999999;   // 10^9 - 1.
	const MIN_SALT     = 100000;
	const MAX_SALT     = 999999;
	const SALT_FACTOR  = 1000000000000; // 10^12.

	/**
	 * Build the Paynet order number.
	 *
	 * @param int      $order_id WooCommerce order ID.
	 * @param int      $attempt  Attempt number, 1..999.
	 * @param int|null $salt     Test-mode salt (6 digits) or null in live mode.
	 * @return int
	 * @throws InvalidArgumentException On out-of-range input.
	 */
	public static function build( $order_id, $attempt, $salt = null ) {
		$order_id = (int) $order_id;
		$attempt  = (int) $attempt;

		if ( PHP_INT_SIZE < 8 ) {
			throw new InvalidArgumentException( '64-bit PHP is required.' );
		}
		if ( $order_id < 1 || $order_id > self::MAX_ORDER_ID ) {
			throw new InvalidArgumentException( 'Order ID out of range.' );
		}
		if ( $attempt < 1 || $attempt > self::MAX_ATTEMPT ) {
			throw new InvalidArgumentException( 'Attempt out of range.' );
		}

		$number = $order_id * 1000 + $attempt;

		if ( null !== $salt ) {
			$salt = (int) $salt;
			if ( $salt < self::MIN_SALT || $salt > self::MAX_SALT ) {
				throw new InvalidArgumentException( 'Salt out of range.' );
			}
			$number += $salt * self::SALT_FACTOR;
		}

		return $number;
	}

	/**
	 * Generate a new random 6-digit salt.
	 *
	 * @return int
	 */
	public static function new_salt() {
		return random_int( self::MIN_SALT, self::MAX_SALT );
	}
}
