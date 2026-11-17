<?php
/**
 * Paynet payment gateway: settings (ТЗ разд. 5), availability and process_payment (ТЗ разд. 7.1).
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
	 * Public test credentials of the shared test account (docs.paynet.md → Quick start).
	 */
	const TEST_CREDENTIALS = array(
		'username'   => '771064',
		'password'   => 'sLgMnGWJ',
		'sale_area'  => 'onlidocumentation_md',
		'notify_key' => 'D9873689-E5F8-4272-A67F-0D8BA3F9BAA7',
	);

	/**
	 * Payment processor.
	 *
	 * @var Paynet_WC_Payment|null
	 */
	private $payments = null;

	/**
	 * Logger.
	 *
	 * @var WC_Logger_Interface|null
	 */
	private $logger = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Paynet', 'paynet-for-woocommerce' );
		$this->method_description = __( 'Card, Apple Pay and Google Pay payments through the Paynet payment page.', 'paynet-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		if ( 'yes' === $this->get_option( 'saved_cards', 'no' ) ) {
			$this->supports[] = 'tokenization'; // Shows "My account → Payment methods" (ТЗ разд. 9б).
		}

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		if ( $this->is_test_mode() ) {
			$this->description = trim( __( 'TEST MODE: no real money is charged.', 'paynet-for-woocommerce' ) . ' ' . $this->description );
		}

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
	 * Current mode.
	 *
	 * @return string test|live
	 */
	public function mode() {
		return $this->is_test_mode() ? 'test' : 'live';
	}

	/**
	 * Credential of a mode: username, password, sale_area, notify_key.
	 *
	 * @param string      $name Name.
	 * @param string|null $mode Mode (null = current).
	 * @return string
	 */
	public function credential( $name, $mode = null ) {
		$mode = null === $mode ? $this->mode() : $mode;
		return trim( (string) $this->get_option( $mode . '_' . $name, '' ) );
	}

	/**
	 * Login, password and SaleArea of the current mode are filled.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->credential( 'username' ) && '' !== $this->credential( 'password' ) && '' !== $this->credential( 'sale_area' );
	}

	/**
	 * API client for a mode.
	 *
	 * @param string|null $mode Mode (null = current).
	 * @return Paynet_WC_Api
	 */
	public function api( $mode = null ) {
		$mode = null === $mode ? $this->mode() : $mode;
		return new Paynet_WC_Api( 'test' === $mode, $this->credential( 'username', $mode ), $this->credential( 'password', $mode ), array( $this, 'log' ) );
	}

	/**
	 * Payment processor.
	 *
	 * @return Paynet_WC_Payment
	 */
	public function payments() {
		if ( null === $this->payments ) {
			$this->payments = new Paynet_WC_Payment( $this );
		}
		return $this->payments;
	}

	/**
	 * Saved cards.
	 *
	 * @return Paynet_WC_Cards
	 */
	public function cards() {
		return new Paynet_WC_Cards( $this );
	}

	/**
	 * Description and, for a logged-in customer with saved cards, the card list (ТЗ разд. 9б).
	 */
	public function payment_fields() {
		parent::payment_fields();
		if ( is_user_logged_in() && ! is_add_payment_method_page() ) {
			$this->cards()->render_list( get_current_user_id() );
		}
	}

	/**
	 * Debug log (only when enabled; no personal data is passed here).
	 *
	 * @param string $message Message.
	 */
	public function log( $message ) {
		if ( 'yes' !== $this->get_option( 'debug', 'no' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( null === $this->logger ) {
			$this->logger = wc_get_logger();
		}
		$this->logger->info( (string) $message, array( 'source' => 'paynet-for-woocommerce' ) );
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
			'test_fill'           => array(
				'title' => '',
				'type'  => 'paynet_test_fill',
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
			'connection'          => array(
				'title' => __( 'Connection', 'paynet-for-woocommerce' ),
				'type'  => 'paynet_check',
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
					'min'  => (string) Paynet_WC_Order_Body::MIN_WINDOW_MINUTES,
					'step' => '1',
				),
				'description'       => __( 'Not longer than WooCommerce "Hold stock" — the shorter value is used. Minimum 10 minutes (Paynet does not accept a shorter payment window).', 'paynet-for-woocommerce' ),
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
	 * "Fill with test credentials" button (public test account, ТЗ разд. 5).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_paynet_test_fill_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"></th>
			<td class="forminp">
				<button type="button" class="button paynet-wc-test-fill" data-credentials="<?php echo esc_attr( (string) wp_json_encode( self::TEST_CREDENTIALS ) ); ?>"><?php esc_html_e( 'Fill with test credentials', 'paynet-for-woocommerce' ); ?></button>
				<p class="description"><?php esc_html_e( 'Public credentials of the shared Paynet test account (docs.paynet.md). Click "Save changes" afterwards.', 'paynet-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * "Check connection" button (ТЗ разд. 5): `/auth` with the values in the form.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_paynet_check_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button paynet-wc-check" data-nonce="<?php echo esc_attr( wp_create_nonce( 'paynet_wc_check' ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"><?php esc_html_e( 'Check connection', 'paynet-for-woocommerce' ); ?></button>
				<span class="paynet-wc-check-result" aria-live="polite"></span>
				<p class="description"><?php esc_html_e( 'Signs in to the Paynet API of the selected mode with the login and password entered above (saving is not needed).', 'paynet-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * AJAX: check connection.
	 */
	public static function ajax_check_connection() {
		check_ajax_referer( 'paynet_wc_check', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'paynet-for-woocommerce' ) ), 403 );
		}
		$mode     = isset( $_POST['mode'] ) && 'live' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ? 'live' : 'test';
		$username = isset( $_POST['username'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['username'] ) ) ) : '';
		$password = isset( $_POST['password'] ) ? trim( (string) wp_unslash( $_POST['password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password is sent as is.
		if ( '' === $username || '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'Enter the API login and password.', 'paynet-for-woocommerce' ) ) );
		}
		$api = new Paynet_WC_Api( 'test' === $mode, $username, $password );
		$res = $api->check_auth();
		if ( 200 === $res['code'] && ! empty( $res['data']['access_token'] ) ) {
			wp_send_json_success( array( 'message' => __( 'Connected.', 'paynet-for-woocommerce' ) ) );
		}
		if ( 0 === $res['code'] ) {
			/* translators: %s: error text */
			$message = sprintf( __( 'No answer from Paynet: %s', 'paynet-for-woocommerce' ), $res['error'] );
		} else {
			/* translators: 1: HTTP code, 2: Paynet error code, 3: error text */
			$message = sprintf( __( 'Error %1$d %2$s: %3$s', 'paynet-for-woocommerce' ), $res['code'], $res['error_code'], $res['error'] );
		}
		wp_send_json_error( array( 'message' => $message ) );
	}

	/**
	 * Custom field types are display-only.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return string
	 */
	public function validate_paynet_test_fill_field( $key, $value ) {
		return '';
	}

	/**
	 * Custom field types are display-only.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return string
	 */
	public function validate_paynet_check_field( $key, $value ) {
		return '';
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
		$currency = get_woocommerce_currency();
		if ( null === Paynet_WC_Order_Body::currency_code( $currency ) ) {
			/* translators: %s: store currency */
			echo '<div class="notice notice-error inline paynet-wc-currency-notice"><p>' . esc_html( sprintf( __( 'Paynet accepts MDL, USD and EUR. The store currency is %s, so Paynet is not shown to customers.', 'paynet-for-woocommerce' ), $currency ) ) . '</p></div>';
		} elseif ( $this->is_test_mode() && 'MDL' !== $currency ) {
			echo '<div class="notice notice-warning inline paynet-wc-currency-notice"><p>' . esc_html__( 'Only MDL is available in the Paynet test environment.', 'paynet-for-woocommerce' ) . '</p></div>';
		}
		if ( ! $this->is_configured() ) {
			echo '<div class="notice notice-info inline paynet-wc-config-notice"><p>' . esc_html__( 'Enter the API login, password and SaleArea of the selected mode: until then Paynet is not shown to customers.', 'paynet-for-woocommerce' ) . '</p></div>';
		}
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
	 * Ready to take payments: enabled, credentials filled, currency supported.
	 *
	 * @return bool
	 */
	public function is_ready() {
		return 'yes' === $this->enabled && $this->is_configured() && null !== Paynet_WC_Order_Body::currency_code( get_woocommerce_currency() );
	}

	/**
	 * Available to customers.
	 *
	 * @return bool
	 */
	public function is_available() {
		return $this->is_ready() && parent::is_available();
	}

	/**
	 * Create (or resume) the payment and send the customer to the Paynet page (ТЗ разд. 7.1).
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		$this->cards()->remember_choice( $order );
		$result = $this->payments()->pay( $order );
		if ( isset( $result['redirect'] ) ) {
			return array(
				'result'   => 'success',
				'redirect' => $result['redirect'],
			);
		}
		wc_add_notice( isset( $result['error'] ) ? $result['error'] : __( 'Could not proceed to payment, please try again.', 'paynet-for-woocommerce' ), 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Refund through Paynet (ТЗ разд. 9).
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Amount.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'paynet_refund_order', __( 'Order not found.', 'paynet-for-woocommerce' ) );
		}
		if ( null === $amount ) {
			$amount = $order->get_remaining_refund_amount();
		}
		return ( new Paynet_WC_Refund( $this ) )->refund( $order, $amount, (string) $reason );
	}

	/**
	 * Thank-you page: "payment is being processed" while the order is not paid yet (ТЗ разд. 7.4).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function thankyou_text( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->is_paid() || $order->has_status( array( 'failed', 'cancelled', 'on-hold' ) ) ) {
			return;
		}
		echo '<p class="paynet-wc-pending woocommerce-info">' . esc_html__( 'The payment is being processed. The order status will be updated automatically within a few minutes.', 'paynet-for-woocommerce' ) . '</p>';
	}
}
