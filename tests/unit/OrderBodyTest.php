<?php
/**
 * Order body, amounts and rounding (ТЗ разд. 7.6, 8; scenarios 18, 19, 22).
 *
 * @package Paynet_WC
 */

use PHPUnit\Framework\TestCase;

/**
 * Order body tests.
 */
final class OrderBodyTest extends TestCase {

	private function data( array $over = array() ) {
		return array_merge(
			array(
				'order'       => 482913000000123001,
				'sale_area'   => 'onlidocumentation_md',
				'success_url' => 'https://shop.example/?wc-api=WC_Gateway_Paynet&paynet_return=success&order=123&key=wc_order_x',
				'cancel_url'  => 'https://shop.example/?wc-api=WC_Gateway_Paynet&paynet_return=cancel&order=123&key=wc_order_x',
				'currency'    => 498,
				'expiry'      => '2026-11-16T21:00:00',
				'lang'        => 'RO',
				'customer'    => array(
					'code'  => '7',
					'first' => 'Ion',
					'last'  => 'Popescu',
					'email' => 'ion@example.com',
					'phone' => '+373 60 000 000',
				),
				'shop_name'   => 'Demo shop',
				'description' => 'Order #123',
				'single_line' => 'Order #123',
				'total'       => '30.00',
				'lines'       => array(
					array( 'code' => 'SKU-1', 'name' => 'Product', 'qty' => 3, 'total' => '30.00' ),
				),
			),
			$over
		);
	}

	private static function sum( array $products ) {
		return array_sum( array_column( $products, 'Amount' ) );
	}

	public function test_basic_body_matches_openapi_shape() {
		$b = Paynet_WC_Order_Body::build( $this->data() );
		$this->assertSame( 482913000000123001, $b['Order'] );
		$this->assertSame( 'All', $b['MoneyType'] );
		$this->assertSame( 498, $b['Currency'] );
		$this->assertSame( 3000, $b['Services'][0]['Amount'] );
		$this->assertSame( '37360000000', $b['Customer']['PhoneNumber'] );
		$this->assertSame( 'ion@example.com', $b['Customer']['email'] );
		$p = $b['Services'][0]['Products'][0];
		$this->assertSame( array( 'LineNo' => 1, 'Name' => 'Product', 'UnitPrice' => 1000, 'Quantity' => 300, 'Amount' => 3000, 'TotalAmount' => 3000, 'Code' => 'SKU-1' ), $p );
		$this->assertArrayNotHasKey( 'CardLinkHash', $b );
	}

	public function test_scenario_19_three_items_coupon_shipping() {
		// 3 × 19.99 = 59.97, coupon 10 % → 53.97 (WooCommerce splits 5.997 → 6.00, line 53.97), shipping 50.00.
		$b = Paynet_WC_Order_Body::build(
			$this->data(
				array(
					'total' => '103.97',
					'lines' => array(
						array( 'code' => 'A', 'name' => 'Item', 'qty' => 3, 'total' => '53.97' ),
						array( 'code' => '', 'name' => 'Shipping', 'qty' => 1, 'total' => '50.00' ),
					),
				)
			)
		);
		$products = $b['Services'][0]['Products'];
		$this->assertSame( 10397, $b['Services'][0]['Amount'] );
		$this->assertSame( 10397, self::sum( $products ) );
		$this->assertSame( 1799, $products[0]['UnitPrice'] );
		$this->assertSame( 300, $products[0]['Quantity'] );
	}

	public function test_rounding_difference_goes_to_last_line() {
		$b = Paynet_WC_Order_Body::build(
			$this->data(
				array(
					'total' => '10.01',
					'lines' => array(
						array( 'name' => 'A', 'qty' => 1, 'total' => '3.333' ),
						array( 'name' => 'B', 'qty' => 1, 'total' => '6.666' ),
					),
				)
			)
		);
		$this->assertSame( 1001, self::sum( $b['Services'][0]['Products'] ) );
		$this->assertSame( 668, $b['Services'][0]['Products'][1]['Amount'] );
	}

	public function test_unit_price_not_whole_bani_becomes_one_unit() {
		// 3 pcs for 10.00 → unit 3.333…: sent as "Item × 3", 1 × 10.00.
		$b = Paynet_WC_Order_Body::build( $this->data( array( 'total' => '10.00', 'lines' => array( array( 'name' => 'Item', 'qty' => 3, 'total' => '10.00' ) ) ) ) );
		$p = $b['Services'][0]['Products'][0];
		$this->assertSame( 'Item × 3', $p['Name'] );
		$this->assertSame( 100, $p['Quantity'] );
		$this->assertSame( 1000, $p['UnitPrice'] );
		$this->assertSame( $p['Amount'], intdiv( $p['UnitPrice'] * $p['Quantity'], 100 ) );
	}

	public function test_zero_lines_dropped() {
		// The API rejects zero-amount lines (probe 16.11.2026).
		$b = Paynet_WC_Order_Body::build(
			$this->data(
				array(
					'lines' => array(
						array( 'name' => 'Product', 'qty' => 1, 'total' => '30.00' ),
						array( 'name' => 'Free shipping', 'qty' => 1, 'total' => '0' ),
					),
				)
			)
		);
		$this->assertCount( 1, $b['Services'][0]['Products'] );
	}

	public function test_negative_line_gives_single_line() {
		// The API rejects negative lines (probe 16.11.2026).
		$b = Paynet_WC_Order_Body::build(
			$this->data(
				array(
					'total' => '25.00',
					'lines' => array(
						array( 'name' => 'Product', 'qty' => 1, 'total' => '30.00' ),
						array( 'name' => 'Discount fee', 'qty' => 1, 'total' => '-5.00' ),
					),
				)
			)
		);
		$products = $b['Services'][0]['Products'];
		$this->assertCount( 1, $products );
		$this->assertSame( 'Order #123', $products[0]['Name'] );
		$this->assertSame( 2500, $products[0]['Amount'] );
	}

	public function test_last_line_going_negative_gives_single_line() {
		$b = Paynet_WC_Order_Body::build(
			$this->data(
				array(
					'total' => '1.00',
					'lines' => array(
						array( 'name' => 'A', 'qty' => 1, 'total' => '5.00' ),
						array( 'name' => 'B', 'qty' => 1, 'total' => '0.50' ),
					),
				)
			)
		);
		$this->assertCount( 1, $b['Services'][0]['Products'] );
		$this->assertSame( 100, $b['Services'][0]['Products'][0]['Amount'] );
	}

	public function test_scenario_18_special_characters() {
		$b = Paynet_WC_Order_Body::build(
			$this->data(
				array(
					'customer' => array( 'code' => '7', 'first' => 'Mary&#039;s', 'last' => '<Co>', 'email' => '', 'phone' => '' ),
					'shop_name' => "Mary's & <Co>",
					'lines'    => array( array( 'name' => "Торт \"Наполеон\"\nсвежий", 'qty' => 1, 'total' => '30.00' ) ),
				)
			)
		);
		$this->assertSame( "Mary's", $b['Customer']['NameFirst'] );
		$this->assertArrayNotHasKey( 'NameLast', $b['Customer'] );
		$this->assertArrayNotHasKey( 'email', $b['Customer'] );
		$this->assertArrayNotHasKey( 'PhoneNumber', $b['Customer'] );
		$this->assertSame( "Mary's &", $b['Services'][0]['Name'] );
		$this->assertSame( 'Торт "Наполеон" свежий', $b['Services'][0]['Products'][0]['Name'] );
		$this->assertNotFalse( json_encode( $b ) );
	}

	public function test_lengths_are_cut() {
		$b = Paynet_WC_Order_Body::build(
			$this->data(
				array(
					'customer' => array( 'code' => str_repeat( 'c', 100 ), 'first' => str_repeat( 'Ж', 100 ), 'last' => 'L', 'email' => str_repeat( 'a', 200 ) . '@example.com', 'phone' => str_repeat( '1', 130 ) ),
					'lines'    => array( array( 'name' => str_repeat( 'Ж', 400 ), 'qty' => 1, 'total' => '30.00' ) ),
				)
			)
		);
		$this->assertSame( Paynet_WC_Order_Body::MAX_CUSTOMER_CODE, mb_strlen( $b['Customer']['Code'] ) );
		$this->assertSame( Paynet_WC_Order_Body::MAX_CUSTOMER_NAME, mb_strlen( $b['Customer']['NameFirst'] ) );
		$this->assertArrayNotHasKey( 'email', $b['Customer'] );
		$this->assertArrayNotHasKey( 'PhoneNumber', $b['Customer'] );
		$this->assertSame( Paynet_WC_Order_Body::MAX_PRODUCT_NAME, mb_strlen( $b['Services'][0]['Products'][0]['Name'] ) );
	}

	public function test_guest_without_customer_code() {
		$b = Paynet_WC_Order_Body::build( $this->data( array( 'customer' => array() ) ) );
		$this->assertArrayNotHasKey( 'Customer', $b );
	}

	public function test_card_link_hash_passed() {
		$b = Paynet_WC_Order_Body::build( $this->data( array( 'card_link_hash' => 'abc' ) ) );
		$this->assertSame( 'abc', $b['CardLinkHash'] );
	}

	public function test_currency_and_lang() {
		$this->assertSame( 498, Paynet_WC_Order_Body::currency_code( 'MDL' ) );
		$this->assertSame( 840, Paynet_WC_Order_Body::currency_code( 'usd' ) );
		$this->assertSame( 978, Paynet_WC_Order_Body::currency_code( 'EUR' ) );
		$this->assertNull( Paynet_WC_Order_Body::currency_code( 'RON' ) );
		$this->assertSame( 'RO', Paynet_WC_Order_Body::lang( 'ro_RO' ) );
		$this->assertSame( 'RU', Paynet_WC_Order_Body::lang( 'ru_RU' ) );
		$this->assertSame( 'EN', Paynet_WC_Order_Body::lang( 'de_DE' ) );
		$this->assertSame( 'EN', Paynet_WC_Order_Body::lang( '' ) );
	}

	public function test_expiry_date_is_chisinau_time_without_changing_php_timezone() {
		$before = date_default_timezone_get();
		// 2026-07-01 12:00:00 UTC = 15:00 in Chișinău (summer, UTC+3); 2026-12-01 12:00 UTC = 14:00 (UTC+2).
		$this->assertSame( '2026-07-01T16:00:00', Paynet_WC_Order_Body::expiry_date( 60, gmmktime( 12, 0, 0, 7, 1, 2026 ) ) );
		$this->assertSame( '2026-12-01T14:30:00', Paynet_WC_Order_Body::expiry_date( 30, gmmktime( 12, 0, 0, 12, 1, 2026 ) ) );
		$this->assertSame( $before, date_default_timezone_get() );
	}

	public function test_scenario_22_window_is_min_of_setting_and_hold_stock() {
		$this->assertSame( 30, Paynet_WC_Order_Body::window_minutes( 60, 30 ) );
		$this->assertSame( 60, Paynet_WC_Order_Body::window_minutes( 60, 0 ) );
		$this->assertSame( 45, Paynet_WC_Order_Body::window_minutes( 45, 60 ) );
		// The API rejects ExpiryDate closer than ~6 minutes: never below 10.
		$this->assertSame( 10, Paynet_WC_Order_Body::window_minutes( 60, 3 ) );
		$this->assertSame( 10, Paynet_WC_Order_Body::window_minutes( 1, 0 ) );
	}

	public function test_content_hash_ignores_order_and_expiry() {
		$a = Paynet_WC_Order_Body::build( $this->data() );
		$b = Paynet_WC_Order_Body::build( $this->data( array( 'order' => 1, 'expiry' => '2030-01-01T00:00:00' ) ) );
		$c = Paynet_WC_Order_Body::build( $this->data( array( 'total' => '31.00', 'lines' => array() ) ) );
		$this->assertSame( Paynet_WC_Order_Body::content_hash( $a ), Paynet_WC_Order_Body::content_hash( $b ) );
		$this->assertNotSame( Paynet_WC_Order_Body::content_hash( $a ), Paynet_WC_Order_Body::content_hash( $c ) );
	}
}
