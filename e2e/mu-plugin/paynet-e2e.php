<?php
/**
 * E2E helpers — loaded ONLY in the wp-env test site (.wp-env.json mapping), never shipped with the plugin.
 *
 * - REST /wp-json/paynet-e2e/v1/*: setup, order inspection, running background checks now, mocks.
 * - pre_http_request: mocks of the Paynet test API (decline, network failure, amounts) — ТЗ разд. 13
 *   allows mocks only where the test API cannot produce the case.
 * - wp_mail is captured instead of sent.
 *
 * @package Paynet_WC_E2E
 */

defined( 'ABSPATH' ) || exit;

const PAYNET_E2E_MOCKS = 'paynet_e2e_mocks';
const PAYNET_E2E_MAILS = 'paynet_e2e_mails';
const PAYNET_E2E_LOG   = 'paynet_e2e_requests';

// Mocks: [{method, path, status, body, network_error, times}] — first match wins, `times` decrements.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'paynet.md' ) ) {
			return $pre;
		}
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';

		$log   = get_option( PAYNET_E2E_LOG, array() );
		$log[] = array(
			'method' => $method,
			'path'   => $path,
			'body'   => isset( $args['body'] ) ? json_decode( (string) $args['body'], true, 512, JSON_BIGINT_AS_STRING ) : null,
			'time'   => time(),
		);
		foreach ( $log as &$entry ) {
			if ( isset( $entry['body']['password'] ) ) {
				$entry['body']['password'] = '***';
			}
		}
		update_option( PAYNET_E2E_LOG, array_slice( $log, -50 ), false );

		$mocks = get_option( PAYNET_E2E_MOCKS, array() );
		foreach ( $mocks as $i => $mock ) {
			if ( $mock['method'] !== $method || false === strpos( $path, $mock['path'] ) ) {
				continue;
			}
			if ( isset( $mock['times'] ) ) {
				--$mocks[ $i ]['times'];
				if ( $mocks[ $i ]['times'] <= 0 ) {
					unset( $mocks[ $i ] );
				}
				update_option( PAYNET_E2E_MOCKS, array_values( $mocks ), false );
			}
			if ( ! empty( $mock['network_error'] ) ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out (E2E mock)' );
			}
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $mock['body'] ),
				'response' => array(
					'code'    => (int) $mock['status'],
					'message' => 'Mock',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return $pre;
	},
	10,
	3
);

// Response codes of real (not mocked) API calls, for the failure diagnostics.
add_action(
	'http_api_debug',
	static function ( $response, $context, $class, $args, $url ) {
		if ( false === strpos( $url, 'paynet.md' ) ) {
			return;
		}
		$log = get_option( PAYNET_E2E_LOG, array() );
		if ( $log ) {
			$code = is_wp_error( $response ) ? 'WP_Error' : wp_remote_retrieve_response_code( $response );
			$body = is_wp_error( $response ) ? $response->get_error_message() : substr( (string) wp_remote_retrieve_body( $response ), 0, 300 );
			$log[ count( $log ) - 1 ]['response'] = $code . ' ' . $body;
			update_option( PAYNET_E2E_LOG, $log, false );
		}
	},
	10,
	5
);

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$mails   = get_option( PAYNET_E2E_MAILS, array() );
		$mails[] = array(
			'to'      => $atts['to'],
			'subject' => $atts['subject'],
			'message' => $atts['message'],
		);
		update_option( PAYNET_E2E_MAILS, array_slice( $mails, -50 ), false );
		return true;
	},
	10,
	2
);

add_action(
	'rest_api_init',
	static function () {
		$ns   = 'paynet-e2e/v1';
		$open = '__return_true';

		register_rest_route(
			$ns,
			'/setup',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => 'paynet_e2e_setup',
			)
		);
		register_rest_route(
			$ns,
			'/order/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => 'paynet_e2e_order',
			)
		);
		register_rest_route(
			$ns,
			'/order/(?P<id>\d+)/meta',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( $req ) {
					$order = wc_get_order( (int) $req['id'] );
					foreach ( (array) $req->get_json_params() as $k => $v ) {
						$order->update_meta_data( $k, $v );
					}
					$order->save();
					return paynet_e2e_order( $req );
				},
			)
		);
		register_rest_route(
			$ns,
			'/create-order',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( $req ) {
					$p     = $req->get_json_params();
					$order = wc_create_order( array( 'customer_id' => isset( $p['customer_id'] ) ? (int) $p['customer_id'] : 0 ) );
					$order->add_product( wc_get_product( (int) $p['product_id'] ), isset( $p['qty'] ) ? (int) $p['qty'] : 1 );
					$order->set_address(
						array(
							'first_name' => 'E2E',
							'last_name'  => 'Customer',
							'email'      => 'e2e-customer@example.com',
							'phone'      => '37360000000',
							'country'    => 'MD',
							'city'       => 'Chisinau',
							'address_1'  => 'Str. Test 1',
						),
						'billing'
					);
					$order->set_payment_method( 'paynet_wc' );
					$order->set_created_via( 'checkout' );
					$order->calculate_totals();
					$order->save();
					return array(
						'id'      => $order->get_id(),
						'pay_url' => $order->get_checkout_payment_url(),
						'key'     => $order->get_order_key(),
					);
				},
			)
		);
		register_rest_route(
			$ns,
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static function () {
					return array(
						'salt'     => (int) get_option( 'paynet_wc_test_salt' ),
						'hpos'     => class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
						'currency' => get_woocommerce_currency(),
						'coming_soon' => get_option( 'woocommerce_coming_soon' ),
						'myaccount' => wc_get_page_permalink( 'myaccount' ),
						'payment_methods' => wc_get_account_endpoint_url( 'payment-methods' ),
						'gateway'   => paynet_e2e_gateway_state(),
					);
				},
			)
		);
		register_rest_route(
			$ns,
			'/latest-order',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static function () {
					$orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order', 'return' => 'ids' ) );
					return array( 'id' => $orders ? (int) $orders[0] : 0 );
				},
			)
		);
		register_rest_route(
			$ns,
			'/run-checks/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => 'paynet_e2e_run_checks',
			)
		);
		register_rest_route(
			$ns,
			'/mocks',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( $req ) {
					update_option( PAYNET_E2E_MOCKS, (array) $req->get_json_params(), false );
					return array( 'ok' => true );
				},
			)
		);
		register_rest_route(
			$ns,
			'/log',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static function () {
					return array(
						'requests' => get_option( PAYNET_E2E_LOG, array() ),
						'mails'    => get_option( PAYNET_E2E_MAILS, array() ),
					);
				},
			)
		);
		// Stage 4: saved cards and refunds.
		register_rest_route(
			$ns,
			'/cards/(?P<user>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $open,
					'callback'            => 'paynet_e2e_cards',
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $open,
					'callback'            => static function ( $req ) {
						foreach ( WC_Payment_Tokens::get_customer_tokens( (int) $req['user'], 'paynet_wc' ) as $t ) {
							WC_Payment_Tokens::delete( $t->get_id() ); // Local only, no API call.
						}
						return paynet_e2e_cards( $req );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $open,
					'callback'            => static function ( $req ) {
						$p = $req->get_json_params();
						$t = new WC_Payment_Token_CC();
						$t->set_gateway_id( 'paynet_wc' );
						$t->set_user_id( (int) $req['user'] );
						$t->set_token( (string) $p['hash'] );
						$t->set_last4( (string) $p['last4'] );
						$t->set_card_type( isset( $p['type'] ) ? (string) $p['type'] : 'visa' );
						$t->set_expiry_month( isset( $p['month'] ) ? (string) $p['month'] : '12' );
						$t->set_expiry_year( isset( $p['year'] ) ? (string) $p['year'] : '2030' );
						$t->add_meta_data( 'paynet_mode', isset( $p['mode'] ) ? (string) $p['mode'] : 'test', true );
						$t->add_meta_data( 'paynet_sale_area', 'onlidocumentation_md', true );
						$t->add_meta_data( 'paynet_customer', (string) (int) $req['user'], true );
						$t->save();
						return array( 'id' => $t->get_id() ) + paynet_e2e_cards( $req );
					},
				),
			)
		);
		register_rest_route(
			$ns,
			'/user',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( $req ) {
					$p    = $req->get_json_params();
					$user = get_user_by( 'login', $p['login'] );
					$id   = $user ? $user->ID : wp_create_user( $p['login'], $p['pass'], $p['login'] . '@example.com' );
					( new WP_User( $id ) )->set_role( 'customer' );
					foreach ( array( 'billing_first_name' => 'Other', 'billing_last_name' => 'Customer', 'billing_phone' => '37360000001', 'billing_country' => 'MD', 'billing_city' => 'Chisinau', 'billing_address_1' => 'Str. Test 2', 'billing_postcode' => '2001', 'billing_email' => $p['login'] . '@example.com' ) as $k => $v ) {
						update_user_meta( $id, $k, $v );
					}
					return array( 'id' => $id );
				},
			)
		);
		// Mark an order as paid through Paynet without a real payment (rules that the test API cannot produce).
		register_rest_route(
			$ns,
			'/order/(?P<id>\d+)/paid',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( $req ) {
					$p     = $req->get_json_params();
					$order = wc_get_order( (int) $req['id'] );
					$order->update_meta_data( '_paynet_order', (string) $p['paynet_order'] );
					$order->update_meta_data( '_paynet_mode', 'test' );
					$order->update_meta_data( '_paynet_payment_id', (string) $p['payment_id'] );
					$order->update_meta_data( '_paynet_amount', (int) round( $order->get_total() * 100 ) );
					$order->update_meta_data( '_paynet_paid_amount', (int) $p['paid'] );
					$order->update_meta_data( '_paynet_final', 'paid' );
					if ( ! empty( $p['money_type'] ) ) {
						$order->update_meta_data( '_paynet_money_type', (string) $p['money_type'] );
					}
					$order->set_transaction_id( (string) $p['payment_id'] );
					$order->set_date_paid( time() - DAY_IN_SECONDS * ( isset( $p['days_ago'] ) ? (int) $p['days_ago'] : 0 ) );
					$order->set_status( 'processing' );
					$order->save();
					return paynet_e2e_order( $req );
				},
			)
		);
		// Paynet payment as the API returns it now (real call through the plugin's client).
		register_rest_route(
			$ns,
			'/paynet/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $open,
				'callback'            => static function ( $req ) {
					$order = wc_get_order( (int) $req['id'] );
					$res   = Paynet_WC_Plugin::gateway()->api( 'test' )->get_by_order( (string) $order->get_meta( '_paynet_order' ) );
					return array( 'code' => $res['code'], 'data' => $res['data'] );
				},
			)
		);
		register_rest_route(
			$ns,
			'/options',
			array(
				'methods'             => 'POST',
				'permission_callback' => $open,
				'callback'            => static function ( $req ) {
					foreach ( (array) $req->get_json_params() as $k => $v ) {
						if ( 'paynet_wc_settings' === $k ) {
							$s = array_merge( (array) get_option( 'woocommerce_paynet_wc_settings', array() ), (array) $v );
							update_option( 'woocommerce_paynet_wc_settings', $s );
						} else {
							update_option( $k, $v );
						}
					}
					return array( 'ok' => true );
				},
			)
		);
	}
);

/**
 * Store setup: products, coupon, shipping, customer, checkout pages, gateway.
 *
 * @return array
 */
function paynet_e2e_setup( $req = null ) {
	// HPOS on/off for this run. Set here, after WooCommerce has installed itself on the first web request
	// (a new store turns HPOS on during installation, which would override a CLI setting made earlier).
	$params = $req ? (array) $req->get_json_params() : array();
	if ( isset( $params['hpos'] ) && ! get_option( 'paynet_e2e_ids' ) ) {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'off' === $params['hpos'] ? 'no' : 'yes' );
	}
	$ids = get_option( 'paynet_e2e_ids' );
	if ( is_array( $ids ) ) {
		return $ids;
	}
	$product = static function ( $name, $price, $virtual = false ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( $price );
		$p->set_virtual( $virtual );
		$p->set_sku( sanitize_title( $name ) . '-' . wp_rand( 100, 999 ) );
		$p->set_status( 'publish' );
		return $p->save();
	};
	$ids = array(
		'product'  => $product( 'E2E product', '19.99' ),
		'big'      => $product( 'E2E product 3300', '3300.00', true ),
		'special'  => $product( "Mary's & <Co> Торт \"Наполеон\" Țară", '10.00' ),
		'virtual'  => $product( 'E2E virtual product', '15.00', true ),
	);

	$coupon = new WC_Coupon();
	$coupon->set_code( 'e2e10' );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( 10 );
	$coupon->save();

	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'Moldova' );
	$zone->add_location( 'MD', 'country' );
	$zone->save();
	$instance = $zone->add_shipping_method( 'flat_rate' );
	update_option( 'woocommerce_flat_rate_' . $instance . '_settings', array( 'title' => 'Courier', 'cost' => '50', 'tax_status' => 'none' ) );

	$user = wp_create_user( 'e2e-customer', 'e2e-customer-pass', 'e2e-customer@example.com' );
	( new WP_User( $user ) )->set_role( 'customer' );
	foreach ( array( 'billing_first_name' => 'Ion', 'billing_last_name' => 'Popescu', 'billing_phone' => '37360000000', 'billing_country' => 'MD', 'billing_city' => 'Chisinau', 'billing_address_1' => 'Str. Test 1', 'billing_postcode' => '2001', 'billing_email' => 'e2e-customer@example.com' ) as $k => $v ) {
		update_user_meta( $user, $k, $v );
	}
	$ids['customer'] = $user;

	$ids['blocks_checkout']  = (int) get_option( 'woocommerce_checkout_page_id' );
	$ids['classic_checkout'] = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Classic checkout',
			'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
		)
	);
	update_option( 'woocommerce_enable_guest_checkout', 'yes' );
	update_option( 'woocommerce_coming_soon', 'no' );
	update_option( 'woocommerce_default_customer_address', 'base' ); // Guests get the store country (MD). // New stores start in "Coming soon" mode: hidden from guests.
	update_option( 'woocommerce_currency', 'MDL' );
	update_option( 'woocommerce_default_country', 'MD' );
	update_option( 'paynet_e2e_ids', $ids, false );
	return $ids;
}

/**
 * Order state for assertions.
 *
 * @param WP_REST_Request $req Request.
 * @return array|WP_Error
 */
function paynet_e2e_order( $req ) {
	$order = wc_get_order( (int) $req['id'] );
	if ( ! $order ) {
		return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
	}
	$meta = array();
	foreach ( $order->get_meta_data() as $m ) {
		if ( 0 === strpos( $m->key, '_paynet' ) ) {
			$meta[ $m->key ] = $m->value;
		}
	}
	$notes = array();
	foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
		$notes[] = $note->content;
	}
	$pending = array();
	if ( function_exists( 'as_get_scheduled_actions' ) ) {
		foreach ( as_get_scheduled_actions( array( 'hook' => 'paynet_wc_check_payment', 'status' => 'pending', 'per_page' => 100 ) ) as $action ) {
			$args = $action->get_args();
			if ( isset( $args['order_id'] ) && (int) $args['order_id'] === $order->get_id() ) {
				$pending[] = array(
					'args' => $args,
					'at'   => $action->get_schedule()->get_date() ? $action->get_schedule()->get_date()->getTimestamp() : null,
				);
			}
		}
	}
	return array(
		'id'             => $order->get_id(),
		'status'         => $order->get_status(),
		'total'          => $order->get_total(),
		'transaction_id' => $order->get_transaction_id(),
		'created'        => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : null,
		'now'            => time(),
		'meta'           => $meta,
		'notes'          => $notes,
		'checks'         => $pending,
		'pay_url'        => $order->get_checkout_payment_url(),
		'received_url'   => $order->get_checkout_order_received_url(),
		'key'            => $order->get_order_key(),
	);
}

/**
 * Saved Paynet cards of a user (hash shortened — it is a secret outside tests too).
 *
 * @param WP_REST_Request $req Request.
 * @return array
 */
function paynet_e2e_cards( $req ) {
	$list = array();
	foreach ( WC_Payment_Tokens::get_customer_tokens( (int) $req['user'], 'paynet_wc' ) as $t ) {
		$list[] = array(
			'id'      => $t->get_id(),
			'hash'    => substr( $t->get_token(), 0, 12 ),
			'last4'   => $t instanceof WC_Payment_Token_CC ? $t->get_last4() : '',
			'type'    => $t instanceof WC_Payment_Token_CC ? $t->get_card_type() : '',
			'expiry'  => $t instanceof WC_Payment_Token_CC ? $t->get_expiry_month() . '/' . $t->get_expiry_year() : '',
			'default' => $t->is_default(),
			'mode'    => $t->get_meta( 'paynet_mode' ),
		);
	}
	return array( 'cards' => $list );
}

/**
 * Run the pending background checks of an order now (instead of waiting 2/5/15/30 minutes).
 *
 * @param WP_REST_Request $req Request.
 * @return array
 */
function paynet_e2e_run_checks( $req ) {
	$ran = 0;
	foreach ( as_get_scheduled_actions( array( 'hook' => 'paynet_wc_check_payment', 'status' => 'pending', 'per_page' => 100 ), 'ids' ) as $id ) {
		$action = ActionScheduler::store()->fetch_action( $id );
		$args   = $action->get_args();
		if ( isset( $args['order_id'] ) && (int) $args['order_id'] === (int) $req['id'] ) {
			ActionScheduler_QueueRunner::instance()->process_action( $id, 'e2e' );
			++$ran;
		}
	}
	return array( 'ran' => $ran ) + paynet_e2e_order( $req );
}

/**
 * Gateway readiness (diagnostics).
 *
 * @return array
 */
function paynet_e2e_gateway_state() {
	$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
	$g        = isset( $gateways['paynet_wc'] ) ? $gateways['paynet_wc'] : null;
	$settings = (array) get_option( 'woocommerce_paynet_wc_settings', array() );
	foreach ( array( 'test_password', 'live_password', 'test_notify_key', 'live_notify_key' ) as $secret ) {
		if ( isset( $settings[ $secret ] ) ) {
			$settings[ $secret ] = '' === $settings[ $secret ] ? '' : '***';
		}
	}
	return array(
		'loaded'     => (bool) $g,
		'ready'      => $g ? $g->is_ready() : null,
		'configured' => $g ? $g->is_configured() : null,
		'settings'   => $settings,
		'checkout'   => (int) get_option( 'woocommerce_checkout_page_id' ),
	);
}
