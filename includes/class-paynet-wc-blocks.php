<?php
/**
 * Cart/Checkout Blocks integration.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Blocks payment method type.
 */
final class Paynet_WC_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name (must match the gateway ID).
	 *
	 * @var string
	 */
	protected $name = Paynet_WC_Gateway::ID;

	/**
	 * Gateway instance.
	 *
	 * @var Paynet_WC_Gateway|null
	 */
	private $gateway = null;

	/**
	 * Initialise.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Paynet_WC_Gateway::ID . '_settings', array() );
		$gateways       = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$this->gateway  = isset( $gateways[ Paynet_WC_Gateway::ID ] ) ? $gateways[ Paynet_WC_Gateway::ID ] : null;
	}

	/**
	 * Active in Blocks only when the gateway is available.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway && $this->gateway->is_ready();
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script( 'paynet-wc-cards', PAYNET_WC_URL . 'assets/js/cards.js', array(), PAYNET_WC_VERSION, true );
		wp_localize_script( 'paynet-wc-cards', 'paynetWcCards', Paynet_WC_Cards::script_data() );
		wp_register_style( 'paynet-wc-cards', PAYNET_WC_URL . 'assets/css/cards.css', array(), PAYNET_WC_VERSION );
		wp_enqueue_style( 'paynet-wc-cards' );
		wp_register_script(
			'paynet-wc-blocks',
			PAYNET_WC_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'paynet-wc-cards' ),
			PAYNET_WC_VERSION,
			true
		);
		return array( 'paynet-wc-blocks' );
	}

	/**
	 * Data for the script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->gateway ? $this->gateway->description : $this->get_setting( 'description' ),
			'icons'       => $this->gateway ? $this->gateway->icons() : array(),
			'supports'    => $this->gateway ? array_values( $this->gateway->supports ) : array( 'products' ),
			// Saved cards of the logged-in customer (no CardLinkHash in the browser; ТЗ разд. 9б).
			'cards'       => $this->gateway && is_user_logged_in() ? $this->gateway->cards()->list_for_checkout( get_current_user_id() ) : array(),
			'cardsText'   => Paynet_WC_Cards::script_data(),
		);
	}
}
