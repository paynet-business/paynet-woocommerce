<?php
/**
 * Uninstall: delete settings only if the merchant opted in (ТЗ разд. 12). Order meta is never deleted.
 *
 * @package Paynet_WC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$paynet_wc_settings = get_option( 'woocommerce_paynet_wc_settings', array() );

if ( is_array( $paynet_wc_settings ) && isset( $paynet_wc_settings['delete_on_uninstall'] ) && 'yes' === $paynet_wc_settings['delete_on_uninstall'] ) {
	delete_option( 'woocommerce_paynet_wc_settings' );
	delete_option( 'paynet_wc_test_salt' );
}
