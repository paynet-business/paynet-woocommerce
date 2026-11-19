<?php
/**
 * Uninstall (ТЗ разд. 12; решение этапа 6 — [Пр6-4]).
 *
 * Always: pending background payment checks are unscheduled (without the plugin they only fail).
 * Only with "Delete plugin data" on: settings, the test salt, cached API access tokens and the copies of saved
 * cards in WordPress. Cards stay saved in Paynet (no API call here). Order data (notes, meta) is never deleted —
 * it is needed for accounting and refunds in the Paynet merchant cabinet.
 *
 * @package Paynet_WC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'paynet_wc_check_payment' ); // By hook only: with a group and empty args nothing matches.
}

$paynet_wc_settings = get_option( 'woocommerce_paynet_wc_settings', array() );

if ( is_array( $paynet_wc_settings ) && isset( $paynet_wc_settings['delete_on_uninstall'] ) && 'yes' === $paynet_wc_settings['delete_on_uninstall'] ) {
	delete_option( 'woocommerce_paynet_wc_settings' );
	delete_option( 'paynet_wc_test_salt' );

	global $wpdb;

	// Cached access tokens: transients paynet_wc_token_<md5>.
	$paynet_wc_transients = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup on uninstall.
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_paynet_wc_token_' ) . '%'
		)
	);
	foreach ( $paynet_wc_transients as $paynet_wc_name ) {
		delete_transient( substr( $paynet_wc_name, strlen( '_transient_' ) ) );
	}

	// Saved cards (WooCommerce payment tokens of this gateway).
	if ( class_exists( 'WC_Payment_Tokens' ) ) {
		for ( $paynet_wc_i = 0; $paynet_wc_i < 1000; $paynet_wc_i++ ) { // Batches, whatever the default page size.
			$paynet_wc_tokens = WC_Payment_Tokens::get_tokens( array( 'gateway_id' => 'paynet_wc' ) );
			if ( empty( $paynet_wc_tokens ) ) {
				break;
			}
			foreach ( $paynet_wc_tokens as $paynet_wc_token ) {
				WC_Payment_Tokens::delete( $paynet_wc_token->get_id() );
			}
		}
	}
}
