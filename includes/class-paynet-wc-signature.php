<?php
/**
 * Notification signature (docs.paynet.md → Signatures, test vector 2). Pure functions.
 *
 * Hash = Base64( MD5( UTF-8( PreparedString + NotificationKey ) ) ), PreparedString =
 * EventDate + Eventid + EventType + Payment.Amount + Payment.Customer + Payment.ExternalId +
 * Payment.Id + Payment.Merchant + Payment.StatusDate (fixed order, null = empty string).
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signature helper.
 */
final class Paynet_WC_Signature {

	/**
	 * Build the string to sign from the raw notification body.
	 *
	 * Values are taken from the raw JSON text, not from decoded numbers: a long like
	 * 1762444800000 must stay exactly as Paynet wrote it.
	 *
	 * @param string $raw_body Raw JSON body.
	 * @return string|null Null when the body is not a JSON object.
	 */
	public static function prepared_string( $raw_body ) {
		$n = json_decode( (string) $raw_body, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $n ) ) {
			return null;
		}
		$p = ( isset( $n['Payment'] ) && is_array( $n['Payment'] ) ) ? $n['Payment'] : array();

		$values = array(
			self::get( $n, 'EventDate' ),
			self::get( $n, 'Eventid' ),
			self::get( $n, 'EventType' ),
			self::get( $p, 'Amount' ),
			self::get( $p, 'Customer' ),
			self::get( $p, 'ExternalId' ),
			self::get( $p, 'Id' ),
			self::get( $p, 'Merchant' ),
			self::get( $p, 'StatusDate' ),
		);
		return implode( '', $values );
	}

	/**
	 * Expected Hash for a body.
	 *
	 * @param string $raw_body Raw JSON body.
	 * @param string $key      Notification secret key.
	 * @return string|null
	 */
	public static function notification_hash( $raw_body, $key ) {
		$prepared = self::prepared_string( $raw_body );
		if ( null === $prepared ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Paynet signature format.
		return base64_encode( md5( $prepared . $key, true ) );
	}

	/**
	 * Constant-time check of the Hash header.
	 *
	 * @param string $raw_body Raw JSON body.
	 * @param string $header   Hash header value.
	 * @param string $key      Notification secret key.
	 * @return bool
	 */
	public static function verify( $raw_body, $header, $key ) {
		if ( '' === (string) $key || '' === (string) $header ) {
			return false;
		}
		$expected = self::notification_hash( $raw_body, $key );
		return null !== $expected && hash_equals( $expected, trim( (string) $header ) );
	}

	/**
	 * Value as string; null/missing → ''. Booleans and floats are not expected in signed fields.
	 *
	 * @param array  $data Array.
	 * @param string $key  Key (case-sensitive, as in the docs).
	 * @return string
	 */
	private static function get( array $data, $key ) {
		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
			return '';
		}
		$v = $data[ $key ];
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( is_array( $v ) ) {
			return '';
		}
		return (string) $v;
	}
}
