<?php
/**
 * Paynet payment gateway: settings (ТЗ разд. 5). Payment flow is added in stage 3.
 *
 * @package Paynet_WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paynet gateway.
 */
class Paynet_WC_Gateway extends WC_Payment_Gateway {

	/**
	 * Gateway ID. Differs from the old plugin's ID so their settings never collide.
	 */
	const ID = 'paynet_wc';

	/**
	 * Option that stores the test-mode order-number salt (ТЗ разд. 6).
	 */
	const SALT_OPTION = 'paynet_wc_test_salt';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Paynet', 'paynet-for-woocommerce' );
		$this->method_description = __( 'Card, Apple Pay and Google Pay payments through the Paynet payment page.', 'paynet-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
	}

	/**
	 * Whether test mode is on.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return 'live' !== $this->get_option( 'mode', 'test' );
	}

	/**
	 * Callback URL. Same address as the old plugin, so nothing changes on Paynet's side (ТЗ разд. 11).
	 *
	 * @return string
	 */
	public static function callback_url() {
		return add_query_arg( 'wc-api', 'WC_Gateway_Paynet', home_url( '/' ) );
	}

	/**
	 * Current test salt; created on first use.
	 *
	 * @return int
	 */
	public static function get_salt() {
		$salt = (int) get_option( self::SALT_OPTION, 0 );
		if ( $salt < Paynet_WC_Order_Number::MIN_SALT ) {
			$salt = Paynet_WC_Order_Number::new_salt();
			update_option( self::SALT_OPTION, $salt, false );
		}
		return $salt;
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'   => __( 'Enable', 'paynet-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Paynet', 'paynet-for-woocommerce' ),
				'default' => 'no',
			),
			'title'               => array(
				'title'   => __( 'Title for customers', 'paynet-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Card, Apple Pay, Google Pay (Paynet)', 'paynet-for-woocommerce' ),
			),
			'description'         => array(
				'title'   => __( 'Description', 'paynet-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'You will be redirected to the secure Paynet payment page.', 'paynet-for-woocommerce' ),
			),
			'mode'                => array(
				'title'   => __( 'Mode', 'paynet-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'test' => __( 'Test', 'paynet-for-woocommerce' ),
					'live' => __( 'Live', 'paynet-for-woocommerce' ),
				),
				'default' => 'test',
			),
			'test_heading'        => array(
				'title' => __( 'Test credentials', 'paynet-for-woocommerce' ),
				'type'  => 'title',
			),
			'test_username'       => array(
				'title' => __( 'API login', 'paynet-for-woocommerce' ),
				'type'  => 'text',
			),
			'test_password'       => array(
				'title' => __( 'API password', 'paynet-for-woocommerce' ),
				'type'  => 'password',
			),
			'test_sale_area'      => array(
				'title'       => __( 'SaleArea', 'paynet-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'One sale area — one website: order numbers and the notification address are tied to the sale area.', 'paynet-for-woocommerce' ),
			),
			'test_notify_key'     => array(
				'title' => __( 'Notification key', 'paynet-for-woocommerce' ),
				'type'  => 'password',
			),
			'live_heading'        => array(
				'title' => __( 'Live credentials', 'paynet-for-woocommerce' ),
				'type'  => 'title',
			),
			'live_username'       => array(
				'title' => __( 'API login', 'paynet-for-woocommerce' ),
				'type'  => 'text',
			),
			'live_password'       => array(
				'title' => __( 'API password', 'paynet-for-woocommerce' ),
				'type'  => 'password',
			),
			'live_sale_area'      => array(
				'title'       => __( 'SaleArea', 'paynet-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'One sale area — one website: order numbers and the notification address are tied to the sale area.', 'paynet-for-woocommerce' ),
			),
			'live_notify_key'     => array(
				'title' => __( 'Notification key', 'paynet-for-woocommerce' ),
				'type'  => 'password',
			),
			'other_heading'       => array(
				'title' => __( 'Notifications and options', 'paynet-for-woocommerce' ),
				'type'  => 'title',
			),
			'callback_url'        => array(
				'title' => __( 'Callback URL', 'paynet-for-woocommerce' ),
				'type'  => 'paynet_callback',
			),
			'test_salt'           => array(
				'title' => __( 'Test order-number salt', 'paynet-for-woocommerce' ),
				'type'  => 'paynet_salt',
			),
			'payment_window'      => array(
				'title'             => __( 'Payment window (minutes)', 'paynet-for-woocommerce' ),
				'type'              => 'number',
				'default'           => '60',
				'custom_attributes' => array(
					'min'  => '5',
					'step' => '1',
				),
				'description'       => __( 'Not longer than WooCommerce "Hold stock" — the shorter value is used.', 'paynet-for-woocommerce' ),
			),
			'check_ip'            => array(
				'title'   => __( 'Notification IP check', 'paynet-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept notifications only from Paynet IP addresses (turn off behind CDN or proxy)', 'paynet-for-woocommerce' ),
				'default' => 'no',
			),
			'debug'               => array(
				'title'   => __( 'Debug log', 'paynet-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Write a debug log (WooCommerce → Status → Logs). No personal data is logged.', 'paynet-for-woocommerce' ),
				'default' => 'no',
			),
			'saved_cards'         => array(
				'title'   => __( 'Saved cards', 'paynet-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Let registered customers pay with saved cards. Enable only after Paynet turns on card saving for your sale area.', 'paynet-for-woocommerce' ),
				'default' => 'no',
			),
			'delete_on_uninstall' => array(
				'title'   => __( 'Uninstall', 'paynet-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Delete plugin settings when the plugin is deleted', 'paynet-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Read-only Callback URL with copy button and mode-specific hint.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_paynet_callback_html( $key, $data ) {
		$url  = self::callback_url();
		$hint = $this->is_test_mode()
			? __( 'In test mode you do not need to send this address and notifications do not arrive — see the test mode notice above.', 'paynet-for-woocommerce' )
			: __( 'Send this address to Paynet for your sale area — without it payment notifications are not sent.', 'paynet-for-woocommerce' );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<input type="text" class="regular-input paynet-wc-copy-source" readonly value="<?php echo esc_attr( $url ); ?>" id="paynet-wc-callback-url" />
				<button type="button" class="button paynet-wc-copy" data-target="paynet-wc-callback-url"><?php esc_html_e( 'Copy', 'paynet-for-woocommerce' ); ?></button>
				<p class="description"><?php echo esc_html( $hint ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Read-only test salt with "Regenerate" button (test mode only).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_paynet_salt_html( $key, $data ) {
		if ( ! $this->is_test_mode() ) {
			return '';
		}
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<input type="text" class="small-text" readonly value="<?php echo esc_attr( (string) self::get_salt() ); ?>" />
				<input type="hidden" name="paynet_wc_regenerate_salt" value="" class="paynet-wc-regenerate-flag" />
				<button type="button" class="button paynet-wc-regenerate-salt"><?php esc_html_e( 'Regenerate', 'paynet-for-woocommerce' ); ?></button>
				<p class="description"><?php esc_html_e( 'Added to order numbers in test mode so they do not collide with other users of the shared test account.', 'paynet-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Custom field types are display-only.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return string
	 */
	public function validate_paynet_callback_field( $key, $value ) {
		return '';
	}

	/**
	 * Custom field types are display-only.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return string
	 */
	public function validate_paynet_salt_field( $key, $value ) {
		return '';
	}

	/**
	 * Save settings; handle "Regenerate" salt.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		check_admin_referer( 'woocommerce-settings' );

		// "Regenerate" sets this flag and clicks the regular Save button (WooCommerce saves only on "save").
		if ( ! empty( $_POST['paynet_wc_regenerate_salt'] ) && current_user_can( 'manage_woocommerce' ) ) {
			update_option( self::SALT_OPTION, Paynet_WC_Order_Number::new_salt(), false );
		}

		$saved = parent::process_admin_options();

		if ( $this->is_test_mode() ) {
			self::get_salt();
		}
		return $saved;
	}

	/**
	 * Settings screen: test-mode banner and the permanent notice (ТЗ разд. 10).
	 */
	public function admin_options() {
		if ( $this->is_test_mode() ) {
			echo '<div class="notice notice-warning inline paynet-wc-test-notice"><p><strong>' . esc_html__( 'TEST MODE', 'paynet-for-woocommerce' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'The Paynet test account is shared by all developers. Therefore:', 'paynet-for-woocommerce' ) . '</p><ol>';
			echo '<li><strong>' . esc_html__( 'Payment notifications do not reach your site.', 'paynet-for-woocommerce' ) . '</strong> ' . esc_html__( 'Paynet sends notifications to one address per sale area, and the test sale area is shared. The order status is updated when the customer returns to your site after payment and by a background check (after 2, 5, 15 and 30 minutes). In live mode notifications arrive once you send the Callback URL to Paynet for your sale area.', 'paynet-for-woocommerce' ) . '</li>';
			echo '<li><strong>' . esc_html__( 'A payment creation error may be caused by an order number collision', 'paynet-for-woocommerce' ) . '</strong> ' . esc_html__( 'with another user. The plugin adds a random salt to order numbers; if errors repeat, click "Regenerate".', 'paynet-for-woocommerce' ) . '</li>';
			echo '</ol></div>';
		}
		parent::admin_options();
	}

	/**
	 * Admin script for the Copy button.
	 *
	 * @param string $hook Current admin page.
	 */
	public function admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'paynet-wc-admin', PAYNET_WC_URL . 'assets/js/admin.js', array(), PAYNET_WC_VERSION, true );
	}

	/**
	 * Not available to customers until the payment flow ships (stage 3).
	 *
	 * @return bool
	 */
	public function is_available() {
		return false;
	}
}
