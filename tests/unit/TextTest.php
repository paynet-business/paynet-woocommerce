<?php
/**
 * Text cleaning (ТЗ разд. 8, scenario 18).
 *
 * @package Paynet_WC
 */

use PHPUnit\Framework\TestCase;

/**
 * Text tests.
 */
final class TextTest extends TestCase {

	public function test_marys_entity_and_slash() {
		$this->assertSame( "Mary's Cake & Co", Paynet_WC_Text::clean( 'Mary&#039;s Cake &amp; Co' ) );
		$this->assertSame( "Mary's", Paynet_WC_Text::clean( "Mary\\'s" ) );
	}

	public function test_tags_and_angle_brackets_removed() {
		$this->assertSame( 'Bold &', Paynet_WC_Text::clean( '<b>Bold</b> & <Co>' ) );
		$this->assertSame( 'a b', Paynet_WC_Text::clean( 'a > b' ) );
		$this->assertSame( 'x', Paynet_WC_Text::clean( '<script>alert(1)</script>x' ) );
		$this->assertSame( 'a b b', Paynet_WC_Text::clean( 'a &lt;b&gt; b' ) );
	}

	public function test_line_breaks_and_controls() {
		$this->assertSame( 'line 1 line 2 tab', Paynet_WC_Text::clean( "line 1\r\nline 2\ttab\x00" ) );
	}

	public function test_cyrillic_romanian_kept_and_cut_by_characters() {
		$this->assertSame( 'Мария Țurcan', Paynet_WC_Text::clean( 'Мария Țurcan' ) );
		$this->assertSame( 'Жжж', Paynet_WC_Text::clean( 'Жжжжж', 3 ) );
	}

	public function test_invalid_utf8_does_not_break() {
		$this->assertSame( 1, preg_match( '//u', Paynet_WC_Text::clean( "a\xC3b" ) ) );
		$this->assertNotFalse( json_encode( Paynet_WC_Text::clean( "a\xC3b" ) ) );
	}

	public function test_digits() {
		$this->assertSame( '37360000000', Paynet_WC_Text::digits( '+373 (60) 000-000' ) );
	}

	public function test_non_scalar() {
		$this->assertSame( '', Paynet_WC_Text::clean( array( 'x' ) ) );
	}
}
