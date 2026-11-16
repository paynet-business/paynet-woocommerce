<?php
/**
 * `POST /api/order` body (ТЗ разд. 8). Pure functions: the gateway passes plain data taken from WC_Order.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order body builder.
 */
final class Paynet_WC_Order_Body {

	/**
	 * Max field lengths in characters (ТЗ В-10). Found by probes on the test API 16.11.2026
	 * (tools/probe-api.js), to be confirmed by Paynet developers:
	 * Products.Name > 256 and Products.Description > 1024 → HTTP 500; Customer.Code/NameFirst/NameLast
	 * are silently cut to 64, email/PhoneNumber to 128; Services.Name/Description and Products.Code
	 * accepted up to 2000 (the stored length cannot be read back) — 255 is used to be safe.
	 * Limits count characters, not bytes (Cyrillic checked).
	 */
	const MAX_SERVICE_NAME        = 255;
	const MAX_SERVICE_DESCRIPTION = 255;
	const MAX_PRODUCT_NAME        = 256;
	const MAX_PRODUCT_CODE        = 255;
	const MAX_CUSTOMER_CODE       = 64;
	const MAX_CUSTOMER_NAME       = 64;
	const MAX_CUSTOMER_EMAIL      = 128;
	const MAX_CUSTOMER_PHONE      = 128;

	/**
	 * Minimum payment window: the API rejects ExpiryDate closer than ~6 minutes (probe 16.11.2026).
	 */
	const MIN_WINDOW_MINUTES = 10;

	/**
	 * ISO 4217 numeric codes supported by Paynet (ТЗ разд. 3).
	 */
	const CURRENCIES = array(
		'MDL' => 498,
		'USD' => 840,
		'EUR' => 978,
	);

	/**
	 * Numeric currency code or null if not supported.
	 *
	 * @param string $currency ISO alpha code.
	 * @return int|null
	 */
	public static function currency_code( $currency ) {
		$currency = strtoupper( (string) $currency );
		return isset( self::CURRENCIES[ $currency ] ) ? self::CURRENCIES[ $currency ] : null;
	}

	/**
	 * Payment page language from a WordPress locale.
	 *
	 * @param string $locale Locale, e.g. ro_RO.
	 * @return string RO|RU|EN
	 */
	public static function lang( $locale ) {
		$prefix = strtolower( substr( (string) $locale, 0, 2 ) );
		if ( 'ro' === $prefix ) {
			return 'RO';
		}
		if ( 'ru' === $prefix ) {
			return 'RU';
		}
		return 'EN';
	}

	/**
	 * Amount in minor units (bani). Paynet currencies all have 2 decimals.
	 *
	 * @param mixed $amount Decimal amount.
	 * @return int
	 */
	public static function to_minor( $amount ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * ExpiryDate in Chișinău time without changing PHP's timezone (ТЗ разд. 3, 7.6).
	 *
	 * @param int $minutes Minutes from $now.
	 * @param int $now     Unix time.
	 * @return string YYYY-MM-DDTHH:mm:ss
	 */
	public static function expiry_date( $minutes, $now ) {
		$date = new DateTimeImmutable( '@' . ( (int) $now + 60 * (int) $minutes ) );
		return $date->setTimezone( new DateTimeZone( 'Europe/Chisinau' ) )->format( 'Y-m-d\TH:i:s' );
	}

	/**
	 * Payment window: min(setting, WooCommerce "Hold stock") (ТЗ разд. 7.6).
	 *
	 * @param int $setting    Payment window setting, minutes.
	 * @param int $hold_stock WooCommerce hold stock minutes (0/empty = off).
	 * @return int
	 */
	public static function window_minutes( $setting, $hold_stock ) {
		$setting    = max( self::MIN_WINDOW_MINUTES, (int) $setting );
		$hold_stock = (int) $hold_stock;
		return $hold_stock > 0 ? max( self::MIN_WINDOW_MINUTES, min( $setting, $hold_stock ) ) : $setting;
	}

	/**
	 * Build the body.
	 *
	 * @param array $d {
	 *     @type int    $order           Paynet Order.
	 *     @type string $sale_area       SaleArea code.
	 *     @type string $success_url     LinkUrlSuccess.
	 *     @type string $cancel_url      LinkUrlCancel.
	 *     @type int    $currency        Numeric currency.
	 *     @type string $expiry          ExpiryDate.
	 *     @type string $lang            RO|RU|EN.
	 *     @type array  $customer        code, first, last, email, phone.
	 *     @type string $shop_name       Services[0].Name.
	 *     @type string $description     Services[0].Description.
	 *     @type string $single_line     Name of the one-line fallback ("Order #123").
	 *     @type mixed  $total           Order total (decimal).
	 *     @type array  $lines           Each: code, name, qty (decimal), total (decimal, incl. tax).
	 *     @type string $card_link_hash  Optional saved card token.
	 * }
	 * @return array
	 */
	public static function build( array $d ) {
		$total = self::to_minor( $d['total'] );

		$body = array(
			'Order'          => (int) $d['order'],
			'SaleAreaCode'   => (string) $d['sale_area'],
			'LinkUrlSuccess' => (string) $d['success_url'],
			'LinkUrlCancel'  => (string) $d['cancel_url'],
		);

		$customer = isset( $d['customer'] ) ? (array) $d['customer'] : array();
		if ( ! empty( $customer['code'] ) ) {
			$c = array( 'Code' => Paynet_WC_Text::clean( $customer['code'], self::MAX_CUSTOMER_CODE ) );
			$map = array(
				'NameFirst' => array( 'first', self::MAX_CUSTOMER_NAME ),
				'NameLast'  => array( 'last', self::MAX_CUSTOMER_NAME ),
			);
			foreach ( $map as $field => $src ) {
				$v = isset( $customer[ $src[0] ] ) ? Paynet_WC_Text::clean( $customer[ $src[0] ], $src[1] ) : '';
				if ( '' !== $v ) {
					$c[ $field ] = $v;
				}
			}
			$email = isset( $customer['email'] ) ? trim( (string) $customer['email'] ) : '';
			if ( '' !== $email && Paynet_WC_Text::length( $email ) <= self::MAX_CUSTOMER_EMAIL && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$c['email'] = $email;
			}
			$phone = isset( $customer['phone'] ) ? Paynet_WC_Text::digits( $customer['phone'] ) : '';
			if ( '' !== $phone && strlen( $phone ) <= self::MAX_CUSTOMER_PHONE ) {
				$c['PhoneNumber'] = $phone;
			}
			$body['Customer'] = $c;
		}

		$body['Currency']   = (int) $d['currency'];
		$body['ExpiryDate'] = (string) $d['expiry'];
		$body['Services']   = array(
			array(
				'Name'        => Paynet_WC_Text::clean( $d['shop_name'], self::MAX_SERVICE_NAME ),
				'Description' => Paynet_WC_Text::clean( $d['description'], self::MAX_SERVICE_DESCRIPTION ),
				'Amount'      => $total,
				'Products'    => self::products( isset( $d['lines'] ) ? (array) $d['lines'] : array(), $total, (string) $d['single_line'] ),
			),
		);
		if ( '' === $body['Services'][0]['Name'] ) {
			$body['Services'][0]['Name'] = Paynet_WC_Text::clean( $d['single_line'], self::MAX_SERVICE_NAME );
		}
		if ( '' === $body['Services'][0]['Description'] ) {
			$body['Services'][0]['Description'] = $body['Services'][0]['Name'];
		}
		$body['MoneyType'] = 'All';
		$body['Lang']      = (string) $d['lang'];

		if ( ! empty( $d['card_link_hash'] ) ) {
			$body['CardLinkHash'] = (string) $d['card_link_hash'];
		}
		return $body;
	}

	/**
	 * Product lines whose sum equals the total (ТЗ разд. 8 «Суммы»).
	 *
	 * Rounding differences go to the last line; a negative line or no lines → one line for the whole order.
	 * Each line satisfies UnitPrice × Quantity / 100 = Amount: if the unit price is not a whole number
	 * of bani, the line is sent as 1 × amount with the quantity in the name.
	 *
	 * @param array  $lines       Lines.
	 * @param int    $total       Total in minor units.
	 * @param string $single_name Name of the one-line fallback.
	 * @return array
	 */
	public static function products( array $lines, $total, $single_name ) {
		$items = array();
		foreach ( $lines as $line ) {
			$amount = self::to_minor( isset( $line['total'] ) ? $line['total'] : 0 );
			if ( 0 === $amount ) {
				continue; // Free shipping, zero fees.
			}
			$qty     = isset( $line['qty'] ) ? (float) $line['qty'] : 1.0;
			$items[] = array(
				'code'   => isset( $line['code'] ) ? (string) $line['code'] : '',
				'name'   => isset( $line['name'] ) ? (string) $line['name'] : '',
				'qty'    => $qty > 0 ? (int) round( $qty * 100 ) : 100,
				'amount' => $amount,
			);
		}

		$sum = 0;
		foreach ( $items as $item ) {
			$sum += $item['amount'];
		}
		$negative = false;
		foreach ( $items as $item ) {
			if ( $item['amount'] < 0 ) {
				$negative = true;
			}
		}
		if ( $items && $sum !== $total ) {
			$last                      = count( $items ) - 1;
			$items[ $last ]['amount'] += $total - $sum;
			$negative                  = $negative || $items[ $last ]['amount'] <= 0;
		}
		if ( ! $items || $negative || $total <= 0 ) {
			$items = array(
				array(
					'code'   => '',
					'name'   => $single_name,
					'qty'    => 100,
					'amount' => $total,
				),
			);
		}

		$products = array();
		$line_no  = 0;
		foreach ( $items as $item ) {
			++$line_no;
			$qty  = $item['qty'];
			$name = Paynet_WC_Text::clean( $item['name'], self::MAX_PRODUCT_NAME );
			if ( 0 !== ( $item['amount'] * 100 ) % $qty ) {
				// Unit price is not a whole number of bani: send "name × qty" as one unit.
				$suffix = ' × ' . rtrim( rtrim( number_format( $qty / 100, 2, '.', '' ), '0' ), '.' );
				$name   = Paynet_WC_Text::clean( $item['name'], self::MAX_PRODUCT_NAME - Paynet_WC_Text::length( $suffix ) ) . $suffix;
				$qty    = 100;
			}
			if ( '' === trim( $name ) ) {
				$name = Paynet_WC_Text::clean( $single_name, self::MAX_PRODUCT_NAME );
			}
			$product = array(
				'LineNo'      => $line_no,
				'Name'        => $name,
				'UnitPrice'   => (int) ( $item['amount'] * 100 / $qty ),
				'Quantity'    => $qty,
				'Amount'      => $item['amount'],
				'TotalAmount' => $item['amount'],
			);
			$code = Paynet_WC_Text::clean( $item['code'], self::MAX_PRODUCT_CODE );
			if ( '' !== $code ) {
				$product['Code'] = $code;
			}
			$products[] = $product;
		}
		return $products;
	}

	/**
	 * Hash of the body without the fields that change between identical attempts (Order, ExpiryDate).
	 * Used to decide whether a saved payment link still matches the order (ТЗ разд. 6).
	 *
	 * @param array $body Body.
	 * @return string
	 */
	public static function content_hash( array $body ) {
		unset( $body['Order'], $body['ExpiryDate'] );
		return md5( (string) self::json( $body ) );
	}

	/**
	 * JSON encode with or without WordPress (unit tests).
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	public static function json( $data ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

