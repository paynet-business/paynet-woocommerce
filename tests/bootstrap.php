<?php
/**
 * Unit-test bootstrap: pure classes only, no WordPress.
 *
 * @package Paynet_WC
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
foreach ( array( 'order-number', 'signature', 'text', 'order-body', 'refund-rules' ) as $paynet_wc_class ) {
	$paynet_wc_file = dirname( __DIR__ ) . '/includes/class-paynet-wc-' . $paynet_wc_class . '.php';
	if ( file_exists( $paynet_wc_file ) ) {
		require $paynet_wc_file;
	}
}
