<?php
/**
 * Old plugin orders (ТЗ разд. 11; scenario 17).
 *
 * @package Paynet_WC
 */

use PHPUnit\Framework\TestCase;

/**
 * Legacy order tests.
 */
final class LegacyTest extends TestCase {

	public function test_order_id_is_external_without_last_ten_digits() {
		$this->assertSame( 95, Paynet_WC_Legacy::order_id( '951438999501' ) );
		$this->assertSame( 12345, Paynet_WC_Legacy::order_id( '123451763371234' ) );
		$this->assertSame( 12345, Paynet_WC_Legacy::order_id( 123451763371234 ) );
	}

	public function test_order_id_rejects_non_legacy_numbers() {
		$this->assertSame( 0, Paynet_WC_Legacy::order_id( '1234567890' ) ); // No order ID left.
		$this->assertSame( 0, Paynet_WC_Legacy::order_id( '' ) );
		$this->assertSame( 0, Paynet_WC_Legacy::order_id( '12a4567890123' ) );
		$this->assertSame( 0, Paynet_WC_Legacy::order_id( '-12345678901' ) );
		$this->assertSame( 0, Paynet_WC_Legacy::order_id( '01234567890' ) ); // Leading zero.
		$this->assertSame( 0, Paynet_WC_Legacy::order_id( '12345678901234567890' ) ); // > int64.
	}

	private function payment( $status, $amount = 300, $paid = 300, $currency = 498 ) {
		return array(
			'status' => $status,
			'amount' => array(
				'requestAmount'   => $amount,
				'requestCurrency' => $currency,
				'amount'          => $amount,
				'paidAmount'      => $paid,
			),
		);
	}

	public function test_verdict_paid() {
		$this->assertSame( 'paid', Paynet_WC_Legacy::verdict( $this->payment( 4 ), 300, 498 ) );
		// paidAmount missing (old API style) → the order amount.
		$p = $this->payment( 4 );
		unset( $p['amount']['paidAmount'] );
		$this->assertSame( 'paid', Paynet_WC_Legacy::verdict( $p, 300, 498 ) );
	}

	public function test_verdict_not_paid() {
		foreach ( array( 1, 3, 5, 6 ) as $status ) {
			$this->assertSame( 'not_paid', Paynet_WC_Legacy::verdict( $this->payment( $status ), 300, 498 ) );
		}
		$this->assertSame( 'not_paid', Paynet_WC_Legacy::verdict( array(), 300, 498 ) );
	}

	public function test_verdict_less_and_review() {
		$this->assertSame( 'less', Paynet_WC_Legacy::verdict( $this->payment( 4, 300, 270 ), 300, 498 ) );
		$this->assertSame( 'review', Paynet_WC_Legacy::verdict( $this->payment( 4, 300, 330 ), 300, 498 ) );
		$this->assertSame( 'review', Paynet_WC_Legacy::verdict( $this->payment( 4, 250, 250 ), 300, 498 ) );
		$this->assertSame( 'review', Paynet_WC_Legacy::verdict( $this->payment( 4, 300, 300, 840 ), 300, 498 ) );
	}
}
