<?php
/**
 * Plugin bootstrap: platform checks, compatibility declarations, gateway registration.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class Paynet_WC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Paynet_WC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Paynet_WC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 11 );
	}

	/**
	 * Activation: require 64-bit PHP (order numbers are int64, ТЗ разд. 6).
	 */
	public static function activate() {
		if ( PHP_INT_SIZE < 8 ) {
			deactivate_plugins( plugin_basename( PAYNET_WC_FILE ) );
			wp_die(
				esc_html__( 'Paynet for WooCommerce requires 64-bit PHP.', 'paynet-for-woocommerce' ),
				esc_html__( 'Plugin activation error', 'paynet-for-woocommerce' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Declare HPOS and Cart/Checkout Blocks compatibility.
	 */
	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PAYNET_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PAYNET_WC_FILE, true );
		}
	}

	/**
	 * Initialise after WooCommerce is loaded.
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_no_woocommerce' ) );
			return;
		}
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, PAYNET_WC_MIN_WC, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_old_woocommerce' ) );
			return;
		}

		require_once PAYNET_WC_DIR . 'includes/class-paynet-wc-gateway.php';

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks' ) );
		add_action( 'admin_notices', array( $this, 'notice_old_plugin' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PAYNET_WC_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Register gateway.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'Paynet_WC_Gateway';
		return $gateways;
	}

	/**
	 * Register Blocks integration.
	 */
	public function register_blocks() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once PAYNET_WC_DIR . 'includes/class-paynet-wc-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new Paynet_WC_Blocks() );
			}
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Paynet_WC_Gateway::ID );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'paynet-for-woocommerce' ) . '</a>' );
		return $links;
	}

	/**
	 * Notice: WooCommerce missing.
	 */
	public function notice_no_woocommerce() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Paynet for WooCommerce requires WooCommerce to be installed and active.', 'paynet-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Notice: WooCommerce too old.
	 */
	public function notice_old_woocommerce() {
		/* translators: %s: minimum WooCommerce version */
		$text = sprintf( __( 'Paynet for WooCommerce requires WooCommerce %s or later.', 'paynet-for-woocommerce' ), PAYNET_WC_MIN_WC );
		echo '<div class="notice notice-error"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Notice: the old "Paynet Moldova Gateway" plugin is active (ТЗ разд. 10).
	 */
	public function notice_old_plugin() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'is_plugin_active' ) ) {
			return;
		}
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( $active as $plugin ) {
			if ( 0 === strpos( $plugin, 'paynet-gateway/' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'The old "Paynet Moldova Gateway" plugin is active. Deactivate it: both plugins receive payment notifications at the same address.', 'paynet-for-woocommerce' ) . '</p></div>';
				return;
			}
		}
	}
}
