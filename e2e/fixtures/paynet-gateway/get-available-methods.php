<?php
define('WP_USE_THEMES', false);
$blog_header = $_SERVER['DOCUMENT_ROOT'].'/wp-load.php';
require($blog_header);
global $woocommerce;
$payment_gateway = WC()->payment_gateways->payment_gateways()['paynet'];
$all_methods = $payment_gateway->form_fields['available_methods']['options'];
$available_methods = [];
foreach ($payment_gateway->available_methods as $method) {
	$available_methods[$method] = $all_methods[$method];
}
echo json_encode($available_methods);