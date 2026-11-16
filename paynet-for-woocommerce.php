<?php
/**
 * Plugin Name:          Paynet for WooCommerce
 * Plugin URI:           https://docs.paynet.md/api/
 * Description:          Accept card, Apple Pay and Google Pay payments through the Paynet payment page (Paynet Ecom API).
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Paynet Services SRL
 * Author URI:           https://paynet.md
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          paynet-for-woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      9.9
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

define( 'PAYNET_WC_VERSION', '0.1.0' );
define( 'PAYNET_WC_FILE', __FILE__ );
define( 'PAYNET_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYNET_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'PAYNET_WC_MIN_WC', '8.0' );

require_once PAYNET_WC_DIR . 'includes/class-paynet-wc-order-number.php';
require_once PAYNET_WC_DIR . 'includes/class-paynet-wc-plugin.php';

register_activation_hook( __FILE__, array( 'Paynet_WC_Plugin', 'activate' ) );

Paynet_WC_Plugin::instance();
