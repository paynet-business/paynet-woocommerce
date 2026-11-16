<?php
/**
 * Saved cards (ТЗ разд. 9б): WooCommerce tokens holding Paynet `CardLinkHash`, the card list at checkout
 * (classic and Blocks), deletion through `POST /api/linked-cards/unlink`, "My account → Payment methods".
 *
 * Facts checked on the test API (OPEN_QUESTIONS #28–#30):
 * - the card is shown on the Paynet page only when `CardLinkHash` is sent with the same `Customer.Code`;
 *   other `Customer` fields (name, email, phone) do not matter (В-11);
 * - the same card saved again gives the same `CardLinkHash` (В-12);
 * - unlink needs exactly three fields; `404 NOT_FOUND_RECORD` when the record is gone or the code differs (В-13).
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saved cards.
 */
class Paynet_WC_Cards {

	/**
	 * Order meta: ID of the saved card chosen at checkout ('' = new card).
	 */
	const META_CARD = '_paynet_card_token';

	/**
	 * Token meta keys.
	 */
	const T_MODE      = 'paynet_mode';
	const T_SALE_AREA = 'paynet_sale_area';
	const T_CUSTOMER  = 'paynet_customer';
	const T_ISSUER    = 'paynet_issuer';

	/**
	 * Form / payment-data field with the chosen card.
	 */
	const FIELD = 'paynet_wc_card';

	/**
	 * Nonce action for card deletion.
	 */
	const NONCE = 'paynet_wc_cards';

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
	 * Hooks (registered once from the plugin bootstrap).
	 */
	public static function hooks() {
		add_action( 'wp_ajax_paynet_wc_delete_card', array( __CLASS__, 'ajax_delete' ) );
		add_filter( 'woocommerce_payment_methods_list_item', array( __CLASS__, 'account_list_item' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'account_delete_fallback' ) );
		add_action( 'wp_login', array( __CLASS__, 'remove_expired_on_login' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'scripts' ) );
	}

	/**
	 * Saved cards are switched on in the settings.
	 *
	 * @return bool
	 */
	public function enabled() {
		return 'yes' === $this->gateway->get_option( 'saved_cards', 'no' );
	}

	/**
	 * `Customer.Code` of a registered customer (ТЗ разд. 9б).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function customer_code( $user_id ) {
		return (string) (int) $user_id;
	}

	/**
	 * Cards of a user usable in the current mode and sale area, newest default first, expired hidden.
	 *
	 * @param int $user_id User ID.
	 * @return WC_Payment_Token_CC[]
	 */
	public function cards( $user_id ) {
		if ( $user_id <= 0 || ! $this->enabled() ) {
			return array();
		}
		$mode = $this->gateway->mode();
		$area = $this->gateway->credential( 'sale_area', $mode );
		$out  = array();
		foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id, Paynet_WC_Gateway::ID ) as $token ) {
			if ( ! $token instanceof WC_Payment_Token_CC ) {
				continue;
			}
			if ( $token->get_meta( self::T_MODE ) !== $mode || $token->get_meta( self::T_SALE_AREA ) !== $area ) {
				continue; // Test cards never show in live mode and the reverse (ТЗ разд. 9б).
			}
			if ( self::is_expired( $token->get_expiry_month(), $token->get_expiry_year() ) ) {
				continue;
			}
			$out[] = $token;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a->is_default() !== $b->is_default() ) {
					return $a->is_default() ? -1 : 1;
				}
				return $b->get_id() - $a->get_id();
			}
		);
		return $out;
	}

	/**
	 * Card data for the checkout list (no CardLinkHash — it never reaches the browser).
	 *
	 * @param int $user_id User ID.
	 * @return array[]
	 */
	public function list_for_checkout( $user_id ) {
		$list = array();
		foreach ( $this->cards( $user_id ) as $i => $token ) {
			$list[] = array(
				'id'      => $token->get_id(),
				'label'   => self::label( $token ),
				'expiry'  => sprintf( '%02d/%s', (int) $token->get_expiry_month(), substr( (string) $token->get_expiry_year(), -2 ) ),
				'default' => 0 === $i,
			);
		}
		return $list;
	}

	/**
	 * "Visa •••• 4242".
	 *
	 * @param WC_Payment_Token_CC $token Token.
	 * @return string
	 */
	public static function label( WC_Payment_Token_CC $token ) {
		$type = $token->get_card_type();
		$name = 'card' === $type || '' === $type ? __( 'Card', 'paynet-for-woocommerce' ) : wc_get_credit_card_type_label( $type );
		return $name . ' •••• ' . $token->get_last4();
	}

	/**
	 * Remember the card chosen at checkout (classic form or Blocks payment data, both arrive in $_POST).
	 *
	 * @param WC_Order $order Order.
	 */
	public function remember_choice( WC_Order $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		$raw    = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
		$chosen = '';
		if ( '' !== $raw && 'new' !== $raw && $this->enabled() ) {
			$token = WC_Payment_Tokens::get( absint( $raw ) );
			if ( $token && $this->owns( $token, $order->get_customer_id() ) ) {
				$chosen = (string) $token->get_id();
			}
		}
		$order->update_meta_data( self::META_CARD, $chosen );
		$order->save();
	}

	/**
	 * `CardLinkHash` to send for an order, or ''.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $mode  Mode.
	 * @return string
	 */
	public function hash_for_order( WC_Order $order, $mode ) {
		$id = (int) $order->get_meta( self::META_CARD );
		if ( $id <= 0 || ! $this->enabled() ) {
			return '';
		}
		$token = WC_Payment_Tokens::get( $id );
		if ( ! $token || ! $this->owns( $token, $order->get_customer_id() ) || $token->get_meta( self::T_MODE ) !== $mode ) {
			return '';
		}
		return (string) $token->get_token();
	}

	/**
	 * Save the card from a confirmed payment (`GET` response, not the unsigned notification; ТЗ разд. 9б).
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payment API payment object.
	 * @param string   $mode    Mode of the order.
	 */
	public function save_from_payment( WC_Order $order, array $payment, $mode ) {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 || ! $this->enabled() ) {
			return; // Guests: the code changes every order, a token would be useless (scenario 27).
		}
		$payer = isset( $payment['payer'] ) && is_array( $payment['payer'] ) ? $payment['payer'] : array();
		$hash  = '';
		foreach ( array( $payment['cardLinkHash'] ?? '', $payment['customer']['cardLinkHash'] ?? '', $payer['cardLinkHash'] ?? '' ) as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				$hash = $candidate;
				break;
			}
		}
		if ( '' === $hash ) {
			return; // Customer unticked "save card" on the Paynet page.
		}
		$code = self::customer_code( $user_id );
		if ( isset( $payment['customer']['code'] ) && (string) $payment['customer']['code'] !== $code ) {
			$this->gateway->log( 'Saved card skipped: customer code in the payment differs from the order customer.' );
			return;
		}
		$hash  = substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $hash ), 0, 128 );
		$mask  = isset( $payer['cardMask'] ) ? (string) $payer['cardMask'] : '';
		$last4 = substr( preg_replace( '/\D/', '', $mask ), -4 );
		if ( 4 !== strlen( $last4 ) ) {
			$last4 = '0000';
		}
		list( $month, $year ) = self::expiry( $payer['cardExpireMonth'] ?? '', $payer['cardExpireYear'] ?? '' );
		$area = $this->gateway->credential( 'sale_area', $mode );

		$token = null;
		foreach ( WC_Payment_Tokens::get_customer_tokens( $user_id, Paynet_WC_Gateway::ID ) as $existing ) {
			if ( ! $existing instanceof WC_Payment_Token_CC || $existing->get_meta( self::T_MODE ) !== $mode || $existing->get_meta( self::T_SALE_AREA ) !== $area ) {
				continue;
			}
			if ( $existing->get_token() === $hash ) {
				$token = $existing; // Same card again — no duplicate (ТЗ разд. 9б).
			} elseif ( $existing->get_last4() === $last4 && self::card_type( $mask ) === $existing->get_card_type() && null !== $month
				&& (int) $existing->get_expiry_month() === (int) $month && (string) $existing->get_expiry_year() === (string) $year ) {
				WC_Payment_Tokens::delete( $existing->get_id() ); // Re-issued token for the same card replaces the old one.
			}
		}
		if ( ! $token ) {
			$token = new WC_Payment_Token_CC();
			$token->set_gateway_id( Paynet_WC_Gateway::ID );
			$token->set_user_id( $user_id );
			$token->set_token( $hash );
			$token->add_meta_data( self::T_MODE, $mode, true );
			$token->add_meta_data( self::T_SALE_AREA, $area, true );
			$token->add_meta_data( self::T_CUSTOMER, $code, true );
		}
		$token->set_last4( $last4 );
		$token->set_card_type( self::card_type( $mask ) );
		if ( null !== $month ) {
			$token->set_expiry_month( $month );
			$token->set_expiry_year( $year );
		} elseif ( ! $token->get_expiry_year() ) {
			// Expiry from the API is not usable (OPEN_QUESTIONS #28): keep the card, expiry unknown. [Пр] Р4-3.
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( '2099' );
		}
		if ( ! empty( $payer['cardIssuer'] ) ) {
			$token->update_meta_data( self::T_ISSUER, Paynet_WC_Text::clean( $payer['cardIssuer'], 64 ) );
		}
		if ( ! $token->save() ) {
			$this->gateway->log( 'Saved card: token not saved (validation failed).' );
			return;
		}
		WC_Payment_Tokens::set_users_default( $user_id, $token->get_id() ); // Last used card is preselected.
		$order->add_order_note(
			/* translators: %s: card label */
			sprintf( __( 'Paynet: card %s saved for the customer.', 'paynet-for-woocommerce' ), self::label( $token ) )
		);
	}

	/**
	 * Delete a saved card in Paynet and WordPress. `200` and `404` both remove it here (ТЗ разд. 9б «Удаление»).
	 *
	 * @param WC_Payment_Token $token Token.
	 * @return bool Deleted.
	 */
	public function unlink( WC_Payment_Token $token ) {
		$mode = 'live' === $token->get_meta( self::T_MODE ) ? 'live' : 'test';
		$code = (string) $token->get_meta( self::T_CUSTOMER );
		$res  = $this->gateway->api( $mode )->call(
			'POST',
			'/api/linked-cards/unlink',
			array(
				'CardLinkHash' => $token->get_token(),
				'SaleAreaCode' => (string) $token->get_meta( self::T_SALE_AREA ),
				'CustomerCode' => '' !== $code ? $code : self::customer_code( $token->get_user_id() ),
			),
			20
		);
		if ( 200 === $res['code'] || 404 === $res['code'] ) {
			WC_Payment_Tokens::delete( $token->get_id() );
			return true;
		}
		$this->gateway->log( sprintf( 'Card unlink failed: HTTP %d %s %s', $res['code'], $res['error_code'], $res['trace_id'] ) );
		return false;
	}

	/**
	 * The token is a Paynet card of this user.
	 *
	 * @param WC_Payment_Token $token   Token.
	 * @param int              $user_id User ID.
	 * @return bool
	 */
	private function owns( WC_Payment_Token $token, $user_id ) {
		return (int) $user_id > 0 && (int) $token->get_user_id() === (int) $user_id && Paynet_WC_Gateway::ID === $token->get_gateway_id();
	}

	/**
	 * AJAX: delete a card of the current user (checkout and "My account").
	 */
	public static function ajax_delete() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$cards = new self( Paynet_WC_Plugin::gateway() );
		$id    = isset( $_POST['token'] ) ? absint( $_POST['token'] ) : 0;
		$token = $id ? WC_Payment_Tokens::get( $id ) : null;
		if ( ! $token || ! $cards->owns( $token, get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => __( 'This card cannot be deleted.', 'paynet-for-woocommerce' ) ), 403 );
		}
		if ( ! $cards->unlink( $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not delete the card, please try again later.', 'paynet-for-woocommerce' ) ), 502 );
		}
		wp_send_json_success();
	}

	/**
	 * "My account → Payment methods": our delete link goes through Paynet, with confirmation.
	 *
	 * @param array            $item  List item.
	 * @param WC_Payment_Token $token Token.
	 * @return array
	 */
	public static function account_list_item( $item, $token ) {
		if ( ! $token instanceof WC_Payment_Token || Paynet_WC_Gateway::ID !== $token->get_gateway_id() ) {
			return $item;
		}
		if ( $token instanceof WC_Payment_Token_CC ) {
			$item['method']['brand'] = self::label( $token );
			$item['method']['last4'] = ''; // Already in the label ("Visa •••• 4242").
			if ( '2099' === (string) $token->get_expiry_year() ) {
				$item['expires'] = '—';
			}
		}
		if ( isset( $item['actions']['delete'] ) ) {
			$item['actions']['delete']['url'] = add_query_arg(
				array(
					'paynet-wc-delete-card' => $token->get_id(),
					'_wpnonce'              => wp_create_nonce( self::NONCE ),
				),
				wc_get_account_endpoint_url( 'payment-methods' )
			);
		}
		return $item;
	}

	/**
	 * Delete link without JavaScript (the script normally intercepts it and asks for confirmation first).
	 */
	public static function account_delete_fallback() {
		if ( ! isset( $_GET['paynet-wc-delete-card'], $_GET['_wpnonce'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE ) ) {
			return;
		}
		$cards = new self( Paynet_WC_Plugin::gateway() );
		$token = WC_Payment_Tokens::get( absint( $_GET['paynet-wc-delete-card'] ) );
		if ( $token && $cards->owns( $token, get_current_user_id() ) ) {
			if ( $cards->unlink( $token ) ) {
				wc_add_notice( __( 'The card was deleted.', 'paynet-for-woocommerce' ) );
			} else {
				wc_add_notice( __( 'Could not delete the card, please try again later.', 'paynet-for-woocommerce' ), 'error' );
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
		exit;
	}

	/**
	 * Expired cards are removed from WordPress at the next login, without calling the API (ТЗ разд. 9б).
	 *
	 * @param string  $login Login.
	 * @param WP_User $user  User.
	 */
	public static function remove_expired_on_login( $login, $user ) {
		if ( ! class_exists( 'WC_Payment_Tokens' ) || ! $user instanceof WP_User ) {
			return;
		}
		foreach ( WC_Payment_Tokens::get_customer_tokens( $user->ID, Paynet_WC_Gateway::ID ) as $token ) {
			if ( $token instanceof WC_Payment_Token_CC && self::is_expired( $token->get_expiry_month(), $token->get_expiry_year() ) ) {
				WC_Payment_Tokens::delete( $token->get_id() );
			}
		}
	}

	/**
	 * Card list script for the classic checkout and "My account".
	 */
	public static function scripts() {
		if ( ! is_user_logged_in() || ! ( is_checkout() || is_account_page() ) ) {
			return;
		}
		wp_enqueue_script( 'paynet-wc-cards', PAYNET_WC_URL . 'assets/js/cards.js', array(), PAYNET_WC_VERSION, true );
		wp_localize_script( 'paynet-wc-cards', 'paynetWcCards', self::script_data() );
		wp_enqueue_style( 'paynet-wc-cards', PAYNET_WC_URL . 'assets/css/cards.css', array(), PAYNET_WC_VERSION );
	}

	/**
	 * Data for the card scripts.
	 *
	 * @return array
	 */
	public static function script_data() {
		return array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE ),
			/* translators: %s: card label, e.g. "Visa •••• 4242" */
			'confirm' => __( 'Do you really want to delete the card %s?', 'paynet-for-woocommerce' ),
			'yes'     => __( 'Yes', 'paynet-for-woocommerce' ),
			'no'      => __( 'Cancel', 'paynet-for-woocommerce' ),
			'failed'  => __( 'Could not delete the card, please try again later.', 'paynet-for-woocommerce' ),
			'newCard' => __( 'New card', 'paynet-for-woocommerce' ),
			'delete'  => __( 'Delete', 'paynet-for-woocommerce' ),
		);
	}

	/**
	 * Classic checkout: card list under the payment method (nothing when there are no cards).
	 *
	 * @param int $user_id User ID.
	 */
	public function render_list( $user_id ) {
		$list = $this->list_for_checkout( $user_id );
		if ( ! $list ) {
			return;
		}
		echo '<ul class="paynet-wc-cards">';
		foreach ( $list as $card ) {
			printf(
				'<li class="paynet-wc-card"><label><input type="radio" name="%1$s" value="%2$d"%3$s> <span class="paynet-wc-card-label">%4$s</span> <span class="paynet-wc-card-exp">%5$s</span></label> <button type="button" class="paynet-wc-card-delete" data-token="%2$d" data-label="%4$s">%6$s</button></li>',
				esc_attr( self::FIELD ),
				(int) $card['id'],
				$card['default'] ? ' checked' : '',
				esc_html( $card['label'] ),
				'99' === substr( $card['expiry'], -2 ) ? '' : esc_html( $card['expiry'] ),
				esc_html__( 'Delete', 'paynet-for-woocommerce' )
			);
		}
		printf(
			'<li class="paynet-wc-card paynet-wc-card-new"><label><input type="radio" name="%1$s" value="new"> <span class="paynet-wc-card-label">%2$s</span></label></li></ul>',
			esc_attr( self::FIELD ),
			esc_html__( 'New card', 'paynet-for-woocommerce' )
		);
	}

	/**
	 * Card brand from the mask (`411111******1111`).
	 *
	 * @param string $mask Mask.
	 * @return string visa|mastercard|card
	 */
	public static function card_type( $mask ) {
		$digits = preg_replace( '/\D/', '', (string) $mask );
		if ( '4' === substr( $digits, 0, 1 ) ) {
			return 'visa';
		}
		$two  = (int) substr( $digits, 0, 2 );
		$four = (int) substr( $digits, 0, 4 );
		if ( ( $two >= 51 && $two <= 55 ) || ( $four >= 2221 && $four <= 2720 ) ) {
			return 'mastercard';
		}
		return 'card';
	}

	/**
	 * Usable expiry from the API, or [null, null] when missing or already in the past
	 * (a card that has just paid cannot be expired — the value is wrong; OPEN_QUESTIONS #28).
	 *
	 * @param string   $month Month, "1"–"12".
	 * @param string   $year  Year, "27" or "2027".
	 * @param int|null $now   Now (Unix), for tests.
	 * @return array{0:?string,1:?string} Month "MM", year "YYYY".
	 */
	public static function expiry( $month, $year, $now = null ) {
		$m = (int) $month;
		$y = (int) $year;
		if ( $m < 1 || $m > 12 || $y <= 0 ) {
			return array( null, null );
		}
		if ( $y < 100 ) {
			$y += 2000;
		}
		if ( self::is_expired( $m, $y, $now ) ) {
			return array( null, null );
		}
		return array( sprintf( '%02d', $m ), (string) $y );
	}

	/**
	 * The card expired (valid through the end of its expiry month).
	 *
	 * @param string|int $month Month.
	 * @param string|int $year  Year (4 digits).
	 * @param int|null   $now   Now (Unix).
	 * @return bool
	 */
	public static function is_expired( $month, $year, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$y   = (int) gmdate( 'Y', $now );
		$m   = (int) gmdate( 'n', $now );
		return (int) $year < $y || ( (int) $year === $y && (int) $month < $m );
	}
}
