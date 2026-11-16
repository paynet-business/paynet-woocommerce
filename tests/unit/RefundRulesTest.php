<?php
/**
 * Refund rules (ТЗ разд. 9; scenarios 11–13, 24).
 *
 * @package Paynet_WC
 */

use PHPUnit\Framework\TestCase;

/**
 * Refund rule tests.
 */
final class RefundRulesTest extends TestCase {

	const NOW = 1794787200; // 2026-11-16.

	private function check( array $over ) {
		return Paynet_WC_Refund_Rules::violation(
			array_merge(
				array(
					'amount'      => 330000,
					'paid'        => 330000,
					'has_payment' => true,
					'already'     => false,
					'paid_at'     => self::NOW - 86400,
					'now'         => self::NOW,
					'money_type'  => 'CARD',
					'status'      => 4,
				),
				$over
			)
		);
	}

	public function test_full_refund_allowed() {
		$this->assertSame( '', $this->check( array() ) );
		$this->assertSame( 'full', Paynet_WC_Refund_Rules::type( 330000, 330000 ) );
	}

	public function test_partial_refund_allowed() {
		$this->assertSame( '', $this->check( array( 'amount' => 1000 ) ) );
		$this->assertSame( 'partial', Paynet_WC_Refund_Rules::type( 1000, 330000 ) );
	}

	public function test_second_refund_blocked() {
		$this->assertSame( 'already', $this->check( array( 'already' => true, 'amount' => 1000 ) ) );
	}

	public function test_refund_in_paynet_cabinet_blocks() {
		$this->assertSame( 'status_refunded', $this->check( array( 'status' => 6, 'amount' => 1000 ) ) );
		$this->assertSame( 'status_refunded', $this->check( array( 'status' => 5 ) ) );
	}

	public function test_paid_less_than_order_blocks_above_paid() {
		// Scenario 24: order 3300 MDL, paid 2970 MDL.
		$this->assertSame( 'above_paid', $this->check( array( 'paid' => 297000, 'amount' => 297001 ) ) );
		$this->assertSame( '', $this->check( array( 'paid' => 297000, 'amount' => 297000 ) ) );
		$this->assertSame( 'full', Paynet_WC_Refund_Rules::type( 297000, 297000 ) );
	}

	public function test_180_days() {
		$this->assertSame( '', $this->check( array( 'paid_at' => self::NOW - 180 * 86400 ) ) );
		$this->assertSame( 'too_old', $this->check( array( 'paid_at' => self::NOW - 180 * 86400 - 1 ) ) );
		$this->assertSame( '', $this->check( array( 'paid_at' => 0 ) ), 'unknown date is not blocked locally' );
	}

	public function test_cash_invoice_blocked() {
		$this->assertSame( 'method', $this->check( array( 'money_type' => 'CASH_INVOICE' ) ) );
		$this->assertSame( 'method', $this->check( array( 'money_type' => 'cash_invoice' ) ) );
		$this->assertSame( '', $this->check( array( 'money_type' => '' ) ) );
	}

	public function test_no_payment_and_zero_amount() {
		$this->assertSame( 'no_payment', $this->check( array( 'has_payment' => false ) ) );
		$this->assertSame( 'amount', $this->check( array( 'amount' => 0 ) ) );
	}

	public function test_to_minor_without_float_drift() {
		$this->assertSame( 2970, Paynet_WC_Refund_Rules::to_minor( '29.70' ) );
		$this->assertSame( 5997, Paynet_WC_Refund_Rules::to_minor( 59.97 ) );
		$this->assertSame( 1, Paynet_WC_Refund_Rules::to_minor( '0.01' ) );
		$this->assertSame( 1000, Paynet_WC_Refund_Rules::to_minor( '10', 2 ) );
	}
}
