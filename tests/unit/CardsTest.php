<?php
/**
 * Saved cards: brand, expiry from the API (ТЗ разд. 9б; OPEN_QUESTIONS #28).
 *
 * @package Paynet_WC
 */

use PHPUnit\Framework\TestCase;

/**
 * Saved card helper tests.
 */
final class CardsTest extends TestCase {

	const NOW = 1794787200; // 2026-11-16 (UTC).

	public function test_card_type_from_mask() {
		$this->assertSame( 'visa', Paynet_WC_Cards::card_type( '411111******1111' ) );
		$this->assertSame( 'mastercard', Paynet_WC_Cards::card_type( '555555******4444' ) );
		$this->assertSame( 'mastercard', Paynet_WC_Cards::card_type( '222100******0000' ) );
		$this->assertSame( 'card', Paynet_WC_Cards::card_type( '9112******0000' ) );
		$this->assertSame( 'card', Paynet_WC_Cards::card_type( '' ) );
	}

	public function test_expiry_two_and_four_digit_year() {
		$this->assertSame( array( '12', '2027' ), Paynet_WC_Cards::expiry( '12', '27', self::NOW ) );
		$this->assertSame( array( '03', '2028' ), Paynet_WC_Cards::expiry( '3', '2028', self::NOW ) );
	}

	public function test_expiry_in_the_past_is_unknown() {
		// The test API returns cardExpireYear "20" for a card valid until 12/27.
		$this->assertSame( array( null, null ), Paynet_WC_Cards::expiry( '12', '20', self::NOW ) );
		$this->assertSame( array( null, null ), Paynet_WC_Cards::expiry( '', '', self::NOW ) );
		$this->assertSame( array( null, null ), Paynet_WC_Cards::expiry( '13', '27', self::NOW ) );
	}

	public function test_valid_through_end_of_month() {
		$this->assertFalse( Paynet_WC_Cards::is_expired( 11, 2026, self::NOW ) );
		$this->assertTrue( Paynet_WC_Cards::is_expired( 10, 2026, self::NOW ) );
		$this->assertTrue( Paynet_WC_Cards::is_expired( 12, 2025, self::NOW ) );
	}

	public function test_customer_code_live_and_test() {
		$this->assertSame( '7', Paynet_WC_Cards::customer_code( 7 ) );
		$this->assertSame( '482913-7', Paynet_WC_Cards::customer_code( 7, 482913 ) );
		$this->assertLessThanOrEqual( 64, strlen( Paynet_WC_Cards::customer_code( PHP_INT_MAX, 999999 ) ), 'fits Customer.Code (64)' );
	}
}
