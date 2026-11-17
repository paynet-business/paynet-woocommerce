<?php
/**
 * `?wc-api=WC_Gateway_Paynet` — the old plugin's address, kept so nothing changes on Paynet's side (ТЗ разд. 11).
 *
 * POST with JSON → notification (ТЗ 7.2). GET with paynet_return → customer return (ТЗ 7.4).
 * GET with `id` + `result` → return link of the old plugin: status check only (ТЗ разд. 11).
 * Anything else → home page, no changes.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhook and return handler.
 */
class Paynet_WC_Webhook {

	/**
	 * Notification source IPs (ТЗ разд. 3).
	 */
	const IPS = array(
		'live' => '93.113.159.22',
		'test' => '93.113.159.23',
	);

	/**
	 * Gateway.
	 *
	 * @var Paynet_WC_Gateway
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param Paynet_WC_Gateway $gateway Gateway.
	 */
	public function __construct( Paynet_WC_Gateway $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Entry point (hooked to woocommerce_api_wc_gateway_paynet with an early priority).
	 */
	public function handle() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public endpoint; authenticity: order key / Hash.
		$return = isset( $_GET['paynet_return'] ) ? sanitize_key( wp_unslash( $_GET['paynet_return'] ) ) : '';

		if ( 'POST' === $method && '' === $return ) {
			$this->notification();
		} elseif ( in_array( $return, array( 'success', 'cancel' ), true ) ) {
			$this->customer_return( $return );
		} elseif ( isset( $_GET['id'], $_GET['result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Old return link; status only from the API.
			$this->legacy_return( sanitize_text_field( wp_unslash( $_GET['id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Notification (ТЗ разд. 7.2).
	 */
	private function notification() {
		$raw = (string) file_get_contents( 'php://input' );

		if ( 'yes' === $this->gateway->get_option( 'check_ip', 'no' ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( ! in_array( $ip, self::IPS, true ) ) {
				$this->gateway->log( 'Notification rejected: IP not allowed.' );
				$this->respond( 403, array( 'ResultCode' => 'FORBIDDEN' ) );
			}
		}

		$hash = isset( $_SERVER['HTTP_HASH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HASH'] ) ) : '';
		$n    = json_decode( $raw, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $n ) || ! isset( $n['Payment'] ) || ! is_array( $n['Payment'] ) ) {
			$this->gateway->log( 'Notification rejected: body is not a Paynet notification.' );
			$this->respond( 400, array( 'ResultCode' => 'BAD_REQUEST' ) );
		}
		$p        = $n['Payment'];
		$external = isset( $p['ExternalId'] ) ? (string) $p['ExternalId'] : '';
		$order    = Paynet_WC_Payment::find_order( $external );

		// Key of the mode the order was created in; for unknown orders — the current mode, then the other one.
		$modes = array( $this->gateway->mode(), 'test' === $this->gateway->mode() ? 'live' : 'test' );
		if ( $order ) {
			$modes = array( $this->gateway->payments()->order_mode( $order ) );
		}
		$valid = false;
		foreach ( $modes as $mode ) {
			if ( Paynet_WC_Signature::verify( $raw, $hash, $this->gateway->credential( 'notify_key', $mode ) ) ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			$this->gateway->log( sprintf( 'Notification for Order %s rejected: invalid Hash.', $external ) );
			$this->respond( 400, array( 'ResultCode' => 'INVALID_HASH' ) );
		}

		if ( ! $order ) {
			// Order of the old plugin (ТЗ разд. 11): status only from GET by its ExternalId; never 4xx.
			$legacy = Paynet_WC_Legacy::find_order( $external );
			if ( $legacy ) {
				$result = $this->gateway->legacy()->confirm( $legacy, $external );
				$this->gateway->log( sprintf( 'Notification for old plugin order %d: %s.', $legacy->get_id(), $result ) );
			} else {
				$this->gateway->log( sprintf( 'Notification for unknown Order %s: answered 200.', $external ) );
			}
			$this->respond( 200, array( 'ResultCode' => 'SUCCESS' ) );
		}

		$final = (string) $order->get_meta( Paynet_WC_Payment::META_FINAL );
		if ( $order->is_paid() || 'paid' === $final || 'review' === $final ) {
			$this->gateway->log( sprintf( 'Repeated notification for order %d: no changes.', $order->get_id() ) );
			$this->respond( 200, array( 'ResultCode' => 'SUCCESS' ) );
		}

		// Unsigned fields: kept only to compare with the API response (ТЗ 7.2 п. 5–6).
		$notice = array();
		foreach ( array( 'Id', 'Amount', 'PaidAmount', 'MoneyType', 'CardMask', 'CardLinkHash' ) as $field ) {
			$notice[ $field ] = isset( $p[ $field ] ) && ! is_array( $p[ $field ] ) ? (string) $p[ $field ] : null;
		}
		$order->update_meta_data( Paynet_WC_Payment::META_NOTICE, $notice );
		$order->save();

		$payments = $this->gateway->payments();
		$res      = $this->gateway->api( $payments->order_mode( $order ) )->get_by_order( $external, 10 );
		if ( 200 !== $res['code'] || ! isset( $res['data']['status'] ) ) {
			$this->gateway->log( sprintf( 'Notification for order %d: status check failed (HTTP %d), background check scheduled.', $order->get_id(), $res['code'] ) );
			$payments->schedule( $order, 0, 1 );
			$this->respond( 200, array( 'ResultCode' => 'SUCCESS' ) );
		}
		$payments->sync( $order, $res['data'], $notice );
		$this->respond( 200, array( 'ResultCode' => 'SUCCESS' ) );
	}

	/**
	 * Customer return (ТЗ разд. 7.4). Never changes the status from URL parameters.
	 *
	 * @param string $type success|cancel.
	 */
	private function customer_return( $type ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The order key authenticates the customer.
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) || Paynet_WC_Gateway::ID !== $order->get_payment_method() ) {
			return; // → home page.
		}

		$payments = $this->gateway->payments();
		$status   = $order->is_paid() ? Paynet_WC_Payment::STATUS_PAID : $payments->refresh( $order );
		$order    = wc_get_order( $order_id );

		if ( Paynet_WC_Payment::STATUS_PAID === $status || $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			$this->redirect( $this->gateway->get_return_url( $order ) );
		}
		if ( Paynet_WC_Payment::STATUS_CANCELLED === $status ) {
			$this->notice( __( 'The payment was not completed. You can try again.', 'paynet-for-woocommerce' ) );
			$this->redirect( $order->get_checkout_payment_url() );
		}
		if ( 'cancel' === $type && Paynet_WC_Payment::STATUS_CREATED === $status ) {
			// The customer left the payment page without paying: let them pay again (same link while it is valid).
			$this->notice( __( 'The payment was not completed. You can pay again.', 'paynet-for-woocommerce' ) );
			$this->redirect( $order->get_checkout_payment_url() );
		}
		// 1/3 after success, or the API did not answer: "being processed", the background check continues.
		$this->redirect( add_query_arg( 'paynet_pending', '1', $this->gateway->get_return_url( $order ) ) );
	}

	/**
	 * Return link of the old plugin `?wc-api=WC_Gateway_Paynet&id=<ExternalId>&result=ok|error` (ТЗ разд. 11).
	 * Only a status check: paid → "Thank you" page; anything else → home page, the order is not changed
	 * (the old plugin cancelled orders here — not repeated). `result` is ignored.
	 *
	 * @param string $external ExternalId.
	 */
	private function legacy_return( $external ) {
		$order = Paynet_WC_Legacy::find_order( $external );
		if ( ! $order ) {
			return; // → home page.
		}
		$result = $this->gateway->legacy()->confirm( $order, $external );
		$this->gateway->log( sprintf( 'Old plugin return link for order %d: %s.', $order->get_id(), $result ) );
		$order = wc_get_order( $order->get_id() );
		if ( $order && ( $order->is_paid() || 'review' === $result ) ) {
			$this->redirect( $order->get_checkout_order_received_url() );
		}
	}

	/**
	 * Notice for the next page.
	 *
	 * @param string $text Text.
	 */
	private function notice( $text ) {
		if ( function_exists( 'wc_add_notice' ) && WC()->session ) {
			wc_add_notice( $text, 'error' );
		}
	}

	/**
	 * Redirect inside the site and stop.
	 *
	 * @param string $url URL.
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * JSON answer and stop.
	 *
	 * @param int   $code HTTP code.
	 * @param array $data Body.
	 */
	private function respond( $code, array $data ) {
		wp_send_json( $data, $code );
	}
}
