<?php
/**
 * Unit-test bootstrap: pure classes only, no WordPress.
 *
 * @package Paynet_WC
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-paynet-wc-order-number.php';
