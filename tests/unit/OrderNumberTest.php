<?php
/**
 * ТЗ разд. 6 — Paynet order number.
 *
 * @package Paynet_WC
 */

use PHPUnit\Framework\TestCase;

/**
 * Order number tests.
 */
final class OrderNumberTest extends TestCase {

	public function test_live_formula() {
		$this->assertSame( 123001, Paynet_WC_Order_Number::build( 123, 1 ) );
		$this->assertSame( 123999, Paynet_WC_Order_Number::build( 123, 999 ) );
	}

	public function test_test_formula_with_salt() {
		$this->assertSame( 482913000000123002, Paynet_WC_Order_Number::build( 123, 2, 482913 ) );
	}

	public function test_max_values_fit_int64() {
		$n = Paynet_WC_Order_Number::build( 999999999, 999, 999999 );
		$this->assertSame( 999999999999999999, $n );
		$this->assertLessThan( PHP_INT_MAX, $n );
	}

	public function test_same_order_different_salt_differs() {
		$this->assertNotSame(
			Paynet_WC_Order_Number::build( 1, 1, 100000 ),
			Paynet_WC_Order_Number::build( 1, 1, 100001 )
		);
	}

	/**
	 * @dataProvider invalid_input
	 */
	public function test_invalid_input_rejected( $order_id, $attempt, $salt ) {
		$this->expectException( InvalidArgumentException::class );
		Paynet_WC_Order_Number::build( $order_id, $attempt, $salt );
	}

	public function invalid_input() {
		return array(
			'order 0'        => array( 0, 1, null ),
			'order too big'  => array( 1000000000, 1, null ),
			'attempt 0'      => array( 1, 0, null ),
			'attempt 1000'   => array( 1, 1000, null ),
			'salt 5 digits'  => array( 1, 1, 99999 ),
			'salt 7 digits'  => array( 1, 1, 1000000 ),
		);
	}

	public function test_new_salt_is_six_digits() {
		for ( $i = 0; $i < 100; $i++ ) {
			$salt = Paynet_WC_Order_Number::new_salt();
			$this->assertGreaterThanOrEqual( 100000, $salt );
			$this->assertLessThanOrEqual( 999999, $salt );
		}
	}
}
