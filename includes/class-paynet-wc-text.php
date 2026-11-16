<?php
/**
 * Text cleaning for API string fields (ТЗ разд. 8, the "Mary's" case). Pure functions.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Text helper.
 */
final class Paynet_WC_Text {

	/**
	 * Clean a value for Paynet: tags removed, entities decoded, slashes removed,
	 * line breaks and control characters → space, `<` `>` removed, spaces collapsed, cut to length.
	 *
	 * @param mixed $value      Value.
	 * @param int   $max_length Max length in characters (0 = no limit).
	 * @return string
	 */
	public static function clean( $value, $max_length = 0 ) {
		$s = is_scalar( $value ) ? (string) $value : '';

		// Same as wp_strip_all_tags(): drop script/style with content, then tags.
		$s = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $s );
		$s = strip_tags( $s );
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$s = stripslashes( $s );

		// Invalid UTF-8 would break json_encode: drop invalid bytes.
		if ( function_exists( 'mb_convert_encoding' ) && ! self::is_utf8( $s ) ) {
			$s = (string) mb_convert_encoding( $s, 'UTF-8', 'UTF-8' );
		}

		$s = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}\x{0080}-\x{009F}\x{2028}\x{2029}]+/u', ' ', $s );
		$s = str_replace( array( '<', '>' ), '', $s );
		$s = (string) preg_replace( '/\s{2,}/u', ' ', $s );
		$s = trim( $s );

		if ( $max_length > 0 && self::length( $s ) > $max_length ) {
			$s = rtrim( self::cut( $s, $max_length ) );
		}
		return $s;
	}

	/**
	 * Only digits (phone numbers).
	 *
	 * @param mixed $value      Value.
	 * @param int   $max_length Max length.
	 * @return string
	 */
	public static function digits( $value, $max_length = 0 ) {
		$s = (string) preg_replace( '/\D+/', '', is_scalar( $value ) ? (string) $value : '' );
		return $max_length > 0 ? substr( $s, 0, $max_length ) : $s;
	}

	/**
	 * Character length.
	 *
	 * @param string $s String.
	 * @return int
	 */
	public static function length( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : (int) preg_match_all( '/./us', $s );
	}

	/**
	 * Cut to N characters without breaking a multibyte character.
	 *
	 * @param string $s   String.
	 * @param int    $len Length.
	 * @return string
	 */
	private static function cut( $s, $len ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, 0, $len, 'UTF-8' );
		}
		preg_match( '/^.{0,' . (int) $len . '}/us', $s, $m );
		return isset( $m[0] ) ? $m[0] : '';
	}

	/**
	 * Valid UTF-8?
	 *
	 * @param string $s String.
	 * @return bool
	 */
	private static function is_utf8( $s ) {
		return 1 === preg_match( '//u', $s );
	}
}
