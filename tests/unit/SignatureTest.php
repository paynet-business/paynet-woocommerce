<?php
/**
 * Notification signature — test vectors from docs.paynet.md (Signatures, vector 2; mocks/notification-paid.json).
 *
 * @package Paynet_WC
 */

use PHPUnit\Framework\TestCase;

/**
 * Signature tests.
 */
final class SignatureTest extends TestCase {

	const KEY = 'D9873689-E5F8-4272-A67F-0D8BA3F9BAA7';

	private function vector2() {
		return '{"Eventid":1000001,"EventType":"PAID","EventDate":"2026-11-09T12:00:00","Payment":{"Id":1234567,"ExternalId":1762444800000,"Merchant":"265505","Customer":"37369XXX111","StatusDate":"2026-11-09T12:00:00","Amount":1235}}';
	}

	public function test_prepared_string_vector_2() {
		$this->assertSame( '2026-11-09T12:00:001000001PAID123537369XXX111176244480000012345672655052026-11-09T12:00:00', Paynet_WC_Signature::prepared_string( $this->vector2() ) );
	}

	public function test_hash_vector_2() {
		$this->assertSame( 'lq3+OCn0J/zSyNF5iAoShg==', Paynet_WC_Signature::notification_hash( $this->vector2(), self::KEY ) );
		$this->assertTrue( Paynet_WC_Signature::verify( $this->vector2(), 'lq3+OCn0J/zSyNF5iAoShg==', self::KEY ) );
	}

	public function test_portal_mock_paid_notification() {
		$body = '{"Eventid":8482869,"EventType":"PAID","Message":"Request processed successfully","EventDate":"2026-11-09T18:17:10","Payment":{"Id":328731,"ExternalId":1762444800000,"SaleAreaCode":"onlidocumentation_md","Customer":"4645XXX61","StatusDate":"2026-11-09T18:17:10","Amount":300,"PaidAmount":300,"Merchant":"265505","CardMask":"411111******1111","CardExpireMonth":"12","CardExpireYear":"27","CardIssuer":"TEST BANK","CardLinkHash":null,"MoneyType":"CARD","PayerName":"Docs","PayerSurname":"Check","PayerIDNP":null,"RRN":"000559440827"}}';
		$this->assertTrue( Paynet_WC_Signature::verify( $body, 'MWjLwGTctYgsNssU7lXsBA==', self::KEY ) );
	}

	public function test_unsigned_paid_amount_does_not_change_hash() {
		$a = '{"Eventid":1,"EventType":"PAID","EventDate":"x","Payment":{"Id":2,"ExternalId":3,"Amount":300,"PaidAmount":300}}';
		$b = '{"Eventid":1,"EventType":"PAID","EventDate":"x","Payment":{"Id":2,"ExternalId":3,"Amount":300,"PaidAmount":1}}';
		$this->assertSame( Paynet_WC_Signature::notification_hash( $a, self::KEY ), Paynet_WC_Signature::notification_hash( $b, self::KEY ) );
	}

	public function test_signed_amount_changes_hash() {
		$a = '{"Eventid":1,"EventType":"PAID","Payment":{"Amount":300}}';
		$b = '{"Eventid":1,"EventType":"PAID","Payment":{"Amount":301}}';
		$this->assertNotSame( Paynet_WC_Signature::notification_hash( $a, self::KEY ), Paynet_WC_Signature::notification_hash( $b, self::KEY ) );
	}

	public function test_wrong_key_wrong_hash_empty_values_rejected() {
		$this->assertFalse( Paynet_WC_Signature::verify( $this->vector2(), 'lq3+OCn0J/zSyNF5iAoShg==', 'F90D6485-9CCC-458B-96A3-8958AF9B4458' ) );
		$this->assertFalse( Paynet_WC_Signature::verify( $this->vector2(), 'AAAAAAAAAAAAAAAAAAAAAA==', self::KEY ) );
		$this->assertFalse( Paynet_WC_Signature::verify( $this->vector2(), '', self::KEY ) );
		$this->assertFalse( Paynet_WC_Signature::verify( $this->vector2(), 'lq3+OCn0J/zSyNF5iAoShg==', '' ) );
		$this->assertFalse( Paynet_WC_Signature::verify( 'not json', 'x', self::KEY ) );
	}

	public function test_cyrillic_is_hashed_as_utf8() {
		$body = '{"Eventid":1,"EventType":"PAID","Payment":{"Customer":"Мария Țurcan"}}';
		$this->assertSame( base64_encode( md5( '1PAIDМария Țurcan' . self::KEY, true ) ), Paynet_WC_Signature::notification_hash( $body, self::KEY ) );
	}
}
