<?php
/*
 * Plugin Name:       Paynet Moldova Gateway 
 * Plugin URI:        https://info.paynet.md/plugin/cms-wordpress.zip
 * Description:       Платежный шлюз системы Paynet для Woocommerce
 * Last Update:       04.11.2021
 * Version:           1.1.5
 * Author:            Paynet Moldova
 * Author URI:        https://paynet.md
 * Domain Path:       /languages
*/

if (!defined('ABSPATH')) exit;

add_action( 'plugins_loaded', 'wc_paynet_gateway_init', 0 );
register_activation_hook( __FILE__,"paynet_activate");

$plugin_dir = basename(dirname(__FILE__));

$load_text= load_plugin_textdomain( 'paynet', '/wp-content/plugins/'. $plugin_dir . '/languages' , $plugin_dir  . '/languages' );

function wc_paynet_gateway_init() {
	/* Check if woocommerce installed */
	if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_Paynet extends WC_Payment_Gateway 
	{		
		private $_paynet_name = "Woocommerce Paynet Gateway";
		private $_url_client_test = "https://test.paynet.md/Acquiring/SetEcom";
		private $_url_client = "https://paynet.md/Acquiring/SetEcom";		
		
		private $_url_merchant_test = "https://api-merchant.test.paynet.md/";
		private $_url_merchant = "https://api-merchant.paynet.md/";
		protected $logger;
		#region Constants
		const LOGO_TYPE_BANK       = 'bank';
        #endregion


        public function __construct()
        {

            global $woocommerce;
            global $paynet;

            $this->id = 'paynet';
            $this->has_fields = false;
            $this->method_title = $this->_paynet_name;
            $this->method_description = $this->_paynet_name;
            $this->init_form_fields();
            $this->init_settings();
            


            $this->title = $this->get_option('title');
			$this->description = $this->get_option('description');
			$this->merchant_id = $this->get_option('merchant_id');
			$this->sale_area = $this->get_option('sale_area_code');
			$this->secret_key = $this->get_option('secret_key');
            //$this->customer_code = $this->get_option('customer_code');
            $this->user_login = $this->get_option('user_login');        
            $this->user_password = $this->get_option('user_password');
			$this->mode = $this->get_option('mode');
			$this->available_methods = $this->get_option('available_methods');
           
			
			


			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_receipt_paynet', array($this, 'receipt_page'));

			add_action('wp_enqueue_scripts','paynet_available_methods');

			add_action('woocommerce_api_wc_gateway_paynet', array($this, 'response_check'));
			

        }	
		
		public function admin_options()
        {
            global $woocommerce;
            global $paynet;		
           ?>
            <h3><?php echo "Admin panel for $this->_paynet_name <hr>"; ?></h3>

            <?php if ($this->is_valid_for_use()) { ?>

           <table class="form-table">
                <?php
					$this->generate_settings_html();
                ?>
           </table>

            <?php } else {
						
			?>
            <div class="inline error"><p>
                    <strong>Message: </strong><?php echo __('Мы не поддерживаем валюту Вашего магазина.', 'paynet'); ?>
                </p></div>
            <?php
            }
        }

		function is_valid_for_use(){
            if (!in_array(get_option('woocommerce_currency'), array('MDL', 'USD', 'EUR'))) {
                return false;
            }

            return true;
        }

        function get_default_money_type(){
            switch(get_woocommerce_currency())
			{
				case 'USD': return 'VISA_AND_MASTER_USD';
				case 'EUR': return 'VISA_AND_MASTER_EUR';
				case 'MDL': return 'VISA_AND_MASTER_MDL';
			}
			
            return false;
        }

		function convert_currency_to_code(){
            switch(get_woocommerce_currency())
			{
				case 'USD': return 840;
				case 'EUR': return 978;
				case 'MDL': return 498;
			}
			
            return false;
        }

		public function init_form_fields(){
			$this->form_fields = apply_filters( 'wc_offline_form_fields', array(
				'enabled' => array(
                    'title' => __('Вкл. / Выкл.', 'paynet'),
                    'type' => 'checkbox',
                    'label' => __('Включить', 'paynet'),
                    'default' => 'yes'
                ),
		            'mode' => array(
					'title'   => __( 'Рабочая среда', 'paynet' ),
					'type'    => 'checkbox',
					'label'   => __( 'Установите для приема реальных платежей', 'paynet' ),
					'default' => 'no'
				),				
                'available_methods' => array(
					'title'   => __( 'Методы оплаты', 'paynet' ),
					'type'    => 'multiselect',
					'label'   => __( 'Отметте методы чтобы сделать их доступными', 'paynet' ),
					'options' => array(
				        'PAYNET' => 'Paynet',
				        'VISA_AND_MASTER_MDL' => 'Visa/Mastercard (MDL)',
				        'VISA_AND_MASTER_EUR' => 'Visa/Mastercard (EUR)',
				        'VISA_AND_MASTER_USD' => 'Visa/Mastercard (USD)',
				        'QIWI' => 'Qiwi',
				        'YANDEX' => 'Yandex',
				        'BITCOIN' => 'Bitcoin',
                        
				    )
				),
				'title' => array(
					'title'       => __( 'Имя платежной системы', 'paynet' ),
					'type'        => 'text',
					'description' => __( 'Данное название отображается в списке доступных платежных систем', 'paynet' ),
					'default'     => __( 'Оплата онлайн', 'paynet' ),
					//'desc_tip'    => true,
				),
				'description' => array(
					'title'       => __( 'Описание платежной системы', 'paynet' ),
					'type'        => 'textarea',
					'description' => __( 'Данное описание предоставляет информацию о платежной системе', 'paynet' ),
					'default'     => __( 'Мы принимаем карты Visa и Mastercard. А так же работаем с Paynet, Bitcoin, Qiwi, Yandex', 'paynet' ),
					//'desc_tip'    => true,
				),
				'sale_area_code' => array(
					'title'       => __( 'Код продавца (Sale Area Code)', 'paynet' ),
					'type'        => 'text',
					'placeholder' => 'Ex.: mysitecom',
					'description' => __( 'Нужен если Вы собираетесь принимать платежи с нескольких проектов в одном аккаунте', 'paynet' )		
				),
				'merchant_id' => array(
					'title'       => __( 'Мерчант ид.', 'paynet' ),
					'type'        => 'text',
					'placeholder' => 'Ex.: 345678',
					'description' => __( 'Уникальный номер в системе paynet.md', 'paynet' )				
				),
				'secret_key' => array(
					'title'       => __( 'Секретный ключ', 'paynet' ),
					'type'        => 'text',
					'placeholder' => 'Ex.: 394CAT81-6WDB-43B2-952D-33F25C92A1EG',
					'description' => __( 'Секретный ключ, предоставлен платежной системой', 'paynet' )	
				),
				'user_login' => array(
					'title'       => __( 'Имя пользователя API', 'paynet' ),
					'type'        => 'text',
					'placeholder' => 'Ex.: 234567',
					'description' => __( 'Имя пользователя API. Необходимо для проверки платежа. Обратите внимание, что <b>Мерчант ид.</b> и <b>Имя пользователя API</b> это <b>не одно и то же</b>', 'paynet' )
				),
				'user_password' => array(
					'title'       => __( 'Пароль пользователя API', 'paynet' ),
					'type'        => 'text',
					'placeholder' => 'Ex.: U1r3DfJ6',
					'description' => __( 'Пароль API. Необходим для проверки платежа', 'paynet' )
				),
			) );
		}

	//-------------------------------------
        public function process_payment( $order_id ) 
		{			
			$order = wc_get_order( $order_id );
			return array(
				'result'    => 'success',
				'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
				
			);
			
		}
		//---------------------------------------------
		 public function receipt_page($order)
        {
			global $wpdb;
			global $woocommerce;

			$table_name = $wpdb->prefix."paynet_log";
			
			$pp_order = $wpdb->get_row( "SELECT * FROM $table_name WHERE action='response_check_order' and order_id = ".strval(intval($order))); 
			$image_path = plugin_dir_url('paynet-gateway').'paynet-gateway/resources';
			echo "<img src='$image_path/all_long.png' />";

			if($this->mode !== "yes")
			{		
				echo  "<h4 style='color:red;margin-top:10px; padding: 7px; border: 2px solid red;font-weight:bold;' >".__( 'Test mode !!!', 'paynet' )."</h4>";
			}

			if($pp_order == null)
			{
				echo '<p>'.__('Спасибо за Ваш выбор, пожалуйста, нажмите кнопку ниже, чтобы перейти к оплате.', 'paynet' ).'</p>';
				echo $this->generate_form($order);
				/* Redirecting user to paynet  */
        echo   '<div style="position:fixed;left:0;top:0;width:100%;height:100%;background:#fff;z-index:999">
                <img src="'.$image_path.'/paynet.png" 
                      style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);-webkit-transform:translate(-50%,-50%);-moz-transform:translate(-50%,-50%);width:100%;max-width:200px" 
                 />
              </div>'; // overlay for receipt page
        echo   '<script>document.getElementById("submitToPaynet").submit();</script>'; // Add js to autosubmit payment form
        /* // */
			}else
			{
				$order_obj = new WC_Order($order_id);
				echo "<p>".__('Данный платеж уже был переправлен на сторону платежного шлюза.', 'paynet' )."</p>";
				echo "<p>".__('Если платеж не прошел успешно повторите занова или дождитесь его обработки или свяжитесть со службой поддержки.', 'paynet' )."</p>";
				echo '<form method="POST" action="'.wc_get_cart_url().'">'.
						'<input type="submit" class="button alt" id="submit_paynet_button" value="'.__('Вернуться в корзину', 'paynet' ).'" />'.
						'<a class="button cancel" href="' . $order_obj->get_cancel_order_url() . '">'.__('Отказаться от оплаты и вернуться в корзину', 'paynet' ).'</a>' . "\n" .
					 '</form>';
			}
        }
		//------------------------------------------------
		public function generate_form($order_id)
        {
            global $woocommerce;
            global $paynet;
			global $wpdb;
			global $wp;

			$order = new WC_Order($order_id);
			$rand_order_id = $order_id . $this->generateOrderIdPostfix();
			$merchant_id = $this->merchant_id;
			$sale_area = !empty($this->sale_area) ? $this->sale_area : '';
			$secret_key = $this->secret_key;
			$_service_name = get_bloginfo();
			$_version = 'v05';
			// Leave $_money_type empty to allow choosing currency on paynet side 
			// selected currency or use get_default_money_type() to set default according to
			$_money_type	= isset($_COOKIE['PAYNET_MONEY_TYPE']) ? $_COOKIE['PAYNET_MONEY_TYPE'] : ""; //$this->get_default_money_type();
			$redirect_url = "";
			if($this->mode == "yes")
				$redirect_url = $this->_url_client;
			else
				$redirect_url = $this->_url_client_test;
 			
 			date_default_timezone_set('Europe/Chisinau');
			$date = strtotime("+2 hour");
			$_date_expire = date('Y-m-d', $date).'T'.date('H:i:s', $date);
			$_lang = substr(get_locale(),0,2);
			$_amount = $order->order_total * 100;
		
			//-----------------------------------------------------------------
			$_customer = array(
					'Code' 			=> $order->get_user_id(),
					'NameFirst' 	=> $order->get_billing_first_name(),
					'NameLast'      => $order->get_billing_last_name(),
					'PhoneNumber'   => $order->get_billing_phone(),
					'email'         => $order->get_billing_email(),
					'Country'       => WC()->countries->countries[$order->get_billing_country()],
					'City'          => $order->get_billing_city(),
					'Address'       => $order->billing_address_1
				);

			//----------------- preparing a service  ----------------------------
			$items = $order->get_items();
			$product_line = 0;
			$_service_item = "";
			$_sing_raw_product = "";

			$_services	= array( 
				array('Name' =>$_service_name, 'Description' =>$_service_name, 'Amount' => $_amount)							
			);

			$_service_item .='<input type="hidden" name="Services[0][Name]" value="'.str_replace('"','',$_services[0]["Name"]).'"/>';
			$_service_item .='<input type="hidden" name="Services[0][Description]" value="'.str_replace('"','',$_services[0]["Description"]).'"/>';
			$_service_item .='<input type="hidden" name="Services[0][Amount]" value="'.$_services[0]['Amount'].'"/>';

			foreach ( $items as $item ) {
					/* Get item categories */
					$categories = get_the_terms( $item['product_id'], 'product_cat' );

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Amount]" value="'.(round($item['total'], 2) * 100).'"/>';
					$_sing_raw_product .= round($item['total'], 2) * 100;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Barcode]" value="'.$item['product_id'].'"/>';
					$_sing_raw_product .= $item['product_id'];

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Code]" value="'.$item['product_id'].'"/>';
					$_sing_raw_product .= $item['product_id'];

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Description]" value="'.str_replace('"','',$item['name']).'"/>';
					$_sing_raw_product .= str_replace('"','',$item['name']);

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][GroupId]" value="'.$categories[0]->term_id.'"/>';
					$_sing_raw_product .= $categories[0]->term_id;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][GroupName]" value="'.str_replace('"','',$categories[0]->name).'"/>';
					$_sing_raw_product .= str_replace('"','',$categories[0]->name);

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][LineNo]" value="'.$product_line.'"/>';
					$_sing_raw_product .= $product_line;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Name]" value="'.str_replace('"','',$item['name']).'"/>';
					$_sing_raw_product .= str_replace('"','',$item['name']);

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Quantity]" value="'.$item['quantity'].'"/>';

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][UnitPrice]" value="'.($item['total'] * 100)/$item['quantity'].'"/>';
					$_sing_raw_product .= ($item['total'] * 100)/$item['quantity'];

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][UnitProduct]" value="pcs"/>';
					$_sing_raw_product .= 'pcs';

					//if($product_line == 0) $_service_name = $item['name'];
					
					$product_line++;					
			}

			$shipping = $order->get_items( 'shipping' );

			if(!empty($shipping)){
				foreach ($shipping as $id => $item) {
					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Amount]" value="'.(round($item->get_total(), 2) * 100).'"/>';
					$_sing_raw_product .= round($item->get_total(), 2) * 100;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Barcode]" value="'.$id.'"/>';
					$_sing_raw_product .= $id;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Code]" value="'.$id.'"/>';
					$_sing_raw_product .= $id;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Description]" value="'.str_replace('"','',$item->get_method_title()).'"/>';
					$_sing_raw_product .= str_replace('"','',$item->get_method_title());

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][GroupId]" value="'.$id.'"/>';
					$_sing_raw_product .= $id;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][GroupName]" value="'.$item->get_type().'"/>';
					$_sing_raw_product .= $item->get_type();

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][LineNo]" value="'.$product_line.'"/>';
					$_sing_raw_product .= $product_line;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Name]" value="'.str_replace('"','',$item->get_method_title()).'"/>';
					$_sing_raw_product .= str_replace('"','',$item->get_method_title());

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][Quantity]" value="1"/>';

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][UnitPrice]" value="'.(round($item->get_total(), 2) * 100).'"/>';
					$_sing_raw_product .= round($item->get_total(), 2) * 100;

					$_service_item .='<input type="hidden" name="Services[0][Products]['.$product_line.'][UnitProduct]" value="pcs"/>';
					$_sing_raw_product .= 'pcs';
					
					$product_line++;
				}
			}

			//------------------------------- creating a Signature --------------------------------------				
			$current_url = home_url($wp->request);
			$_linkUrlSuccess = add_query_arg(array('wc-api' => 'WC_Gateway_Paynet', "id" => $rand_order_id, 'result' => 'ok'), home_url('/')); 
            $_linkUrlCancel = add_query_arg(array('wc-api' => 'WC_Gateway_Paynet', "id" => $rand_order_id, 'result' => 'error'), home_url('/'));
			
			$_sing_raw  = $this->convert_currency_to_code();
			$_sing_raw .= $_customer['Address'].$_customer['City'].$_customer['Code'].$_customer['Country'].$_customer['email'];
			$_sing_raw .= $_customer['NameFirst'].$_customer['NameLast'].$_customer['PhoneNumber'];
			$_sing_raw .= $_date_expire.strval($rand_order_id).$merchant_id.$_money_type;
			$_sing_raw .= $_services[0]['Amount'].$_services[0]['Description'].$_services[0]['Name'];
			$_sing_raw .= $_sing_raw_product;
			$_sing_raw .= $secret_key;
			$signature = base64_encode(md5($_sing_raw, true));	
						
			$pp_form =  '<form id="submitToPaynet" method="POST" action="'.$redirect_url.'">'.								
						'<input type="hidden" name="ExternalID" value="'.strval($rand_order_id).'"/>'.
						'<input type="hidden" name="Merchant" value="'.$merchant_id.'"/>'.
						'<input type="hidden" name="SaleAreaCode" value="'.$sale_area.'"/>'.
						'<input type="hidden" name="Currency" value="'.$this->convert_currency_to_code().'"/>'.

						$_service_item.

						'<input type="hidden" name="Customer.Code"   value="'.$_customer['Code'].'"/>'.
						'<input type="hidden" name="Customer.NameFirst"   value="'.str_replace('"','',$_customer['NameFirst']).'"/>'.
						'<input type="hidden" name="Customer.NameLast"   value="'.str_replace('"','',$_customer['NameLast']).'"/>'.
						'<input type="hidden" name="Customer.PhoneNumber"   value="'.str_replace('"','',$_customer['PhoneNumber']).'"/>'.
						'<input type="hidden" name="Customer.email"   value="'.str_replace('"','',$_customer['email']).'"/>'.
						'<input type="hidden" name="Customer.Country"   value="'.str_replace('"','',$_customer['Country']).'"/>'.
						'<input type="hidden" name="Customer.City"   value="'.str_replace('"','',$_customer['City']).'"/>'.
						'<input type="hidden" name="Customer.Address"   value="'.str_replace('"','',$_customer['Address']).'"/>'.

						'<input type="hidden" name="ExternalDate" value="'.date('Y-m-d', time()).'T'.date('H:i:s', time()).'"/>'.	
						'<input type="hidden" name="ExpiryDate"   value="'.$_date_expire.'"/>'.

						'<input type="hidden" name="LinkUrlSuccess" value="'.$_linkUrlSuccess.'"/>'.
						'<input type="hidden" name="LinkUrlCancel" value="'.$_linkUrlCancel.'"/>'.

						'<input type="hidden" name="Lang" value="'.$_lang.'"/>'.
						'<input type="hidden" name="SignVersion" value="'.$_version.'"/>'.
						'<input type="hidden" name="MoneyType.Code" value="'.$_money_type.'"/>'.
						'<input type="hidden" name="Signature" value="'.$signature.'"/>'.

						'<input type="submit" class="button alt" id="submit_paynet_button" value="'.__('Оплатить', 'paynet' ).'" />'.
						'<a class="button cancel" href="' . $order->get_cancel_order_url() . '">'.__('Отказаться от оплаты и вернуться в корзину', 'paynet' ).'</a>' . "\n" .
					 '</form>';

			$this->pp_add_to_log('generate_form',$pp_form,$order_id);
			return $pp_form;
        }

        public function generateOrderIdPostfix()
	    {
	        return substr(number_format(microtime(true), 4, '', ''), -10); // last 10 digits
	    }

		//---------------------- response from a ecom server
		public function response_check()
        {
            global $woocommerce;
            global $paynet;
			global $wpdb;
			global $wp;

			$paymentNotification = file_get_contents('php://input');
			if(!empty($paymentNotification)){
				$notification = json_decode($paymentNotification, true);
			    $external_order_id = $notification['Payment']['ExternalId']; //order id
			    $order_id = substr($external_order_id, 0, -10); //remove last 10 digits of postfix
			    $transaction_id = $notification['Payment']['Id']; //payment id on paynet
				$this->pp_add_to_log('payment_notification',print_r($paymentNotification,true),$order_id,$transaction_id);

				if($order_id !== null)
				{
					$order = new WC_Order($order_id);

					if ($order != null) {
						//------------ request to the ecom server
						$auth = $this->pp_auth();

						if($auth["result"] == "ok")
						{	
							$_orderCheck = $this->pp_order_check($external_order_id,$auth["token"]);
							$this->pp_add_to_log('response_check_order',print_r($_orderCheck,true),$order_id);

							if($_orderCheck["result"] == "ok")
							{
								$ecom_order = $_orderCheck["object"];
								if(intval($ecom_order->Status) === 4 && intval($ecom_order->Invoice) == $external_order_id)
								{				
									$order->payment_complete($ecom_order->OperationId);		
									$woocommerce->cart->empty_cart();
								}
							}
						}		
					}
				}
				exit;
			}
			
			
			$external_order_id = isset($_GET['id']) ? $_GET['id'] : null; //order id
			$result_page = isset($_GET['result']) ? $_GET['result'] : null;
			if($external_order_id !== null)
			{
				$order_id = substr($external_order_id, 0, -10); //remove last 10 digits of postfix
				$order = new WC_Order($order_id);
			
                if ($order != null && $result_page == "ok") { 

                	if($order->get_status() == "processing"){
                		wp_redirect($this->get_return_url( $order ));
						exit();
                	}

					//------------ request to the ecom server
					$auth = $this->pp_auth();					

					if($auth["result"] == "ok")
					{					  
						$_orderCheck = $this->pp_order_check($external_order_id,$auth["token"]);
						$this->pp_add_to_log('response_check_order',print_r($_orderCheck,true),$order_id);

						if($_orderCheck["result"] == "ok")
						{						   
							$ecom_order = $_orderCheck["object"];
							if(intval($ecom_order->Status) === 4 && intval($ecom_order->Invoice) == $external_order_id)
							{				
								$order->payment_complete($ecom_order->OperationId);	
								$woocommerce->cart->empty_cart();
								wp_redirect($this->get_return_url( $order ));
								exit();
							}else
							{
								// Add order note
	  							$order->add_order_note( 'The order Id: '.$order_id.'  processing. Status: '.$ecom_order->Status.' EcomID:'.$ecom_order->OperationId );
	  
							}
						}else
						{
							$order->update_status('cancelled', __('Платеж был отменен. Не найдено или исключение в методе получения статуса.') . ' Response: '.print_r($_orderCheck,true));
						}
					}else
					{
						$this->pp_add_to_log('response_check_auth',print_r($auth,true),$order_id);
						$order->update_status('cancelled', __('Платеж был отменен, так как произошла ошибка при соединении с платежным шлюзом.') . ' Response: '.print_r($auth,true));
					}

                    wp_redirect($order->get_cancel_order_url());
					exit();
					
				}else
				{
					$order->update_status('cancelled', __('Платеж был отменен клиентом на сайте платежного шлюза.'));
					wp_redirect($order->get_cancel_order_url());
					exit();				
				}
				exit();
					
			}else {                
                wp_redirect(home_url());
                exit;
            }			
        }

		//--------- get an object of ecom transaction
		function pp_order_check($order_id, $token)
		{

			$_url_merchant_api = "";
			if($this->mode == "yes"){
				$_url_merchant_api = $this->_url_merchant;
			} else {
				$_url_merchant_api = $this->_url_merchant_test;
			}

			$_url_merchant_api .= "/api/Payments?ExternalID=".$order_id;

			$curl = curl_init();

			curl_setopt_array($curl, array(				
				CURLOPT_URL =>$_url_merchant_api , 
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_HTTPHEADER => array(
						"cache-control: no-cache",
						"authorization: bearer ".$token		
				),
			));

			$response = curl_exec($curl);
			$err = curl_error($curl);
			$request_info = curl_getinfo($curl);
			curl_close($curl);

			if ($err) {
				return array("result" => "error", "msg" => $err);
			} 
			else 
			{
				if($request_info["http_code"] == "200")
				{
					$order = json_decode($response);
					return array("result" => "ok", "object" => $order[0]);
				}else
				{
					$errObj = json_decode($response);
					return array("result" => "error", "msg" => $errObj->Message);
				}
			}
			//------------------------
			return array("result" => "error", "msg" => $response);	
		}		
		//--------- get a permission token 	
		function pp_auth()
		{
			global $wpdb;
			
			$_merchant_user = $this->user_login;
			$_merchant_user_pass = htmlspecialchars_decode($this->user_password);
			$_cred = "grant_type=password&username=".$_merchant_user."&password=".urlencode($_merchant_user_pass);

			$_url_merchant_api = "";
			
			if($this->mode == "yes")
			{
				$_url_merchant_api = $this->_url_merchant."/auth";
			}
			else
			{
				$_url_merchant_api = $this->_url_merchant_test."/auth";
			}

			$curl = curl_init();

			curl_setopt_array($curl, array(
				CURLOPT_URL =>$_url_merchant_api,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_POSTFIELDS => $_cred,
				CURLOPT_HTTPHEADER => array("cache-control: no-cache")
			));

			$response = curl_exec($curl);
			$err = curl_error($curl);
			$request_info = curl_getinfo($curl);
			curl_close($curl);

			if ($err) {
				return array("result" => "error", "msg" => $err);
			} 
			else 
			{
				if($request_info["http_code"] == "200")
				{
					$auth = json_decode($response);
					return array("result" => "ok", "token" => $auth->access_token, "msg" => "");
				}else
				{
					$auth = json_decode($response);
					return array("result" => "error", "msg" => $auth->error);
				}
			}
			//------------------------
			return array("result" => "error", "msg" => $response);
		}

		function pp_add_to_log($action, $data, $order_id = 0, $transaction_id = 0)
		{
			global $wpdb;
			$table_name = $wpdb->prefix . "paynet_log"; 
			$wpdb->insert( $table_name, array('action' => $action,'data' => $data, 'order_id' => $order_id, 'transaction_id' => $transaction_id ), array( '%s','%s', '%d' ) );
		}				
    } 

	//---------------------------------------------------

	function wc_offline_add_paynet_gateways( $gateways ) {
		$gateways[] = 'WC_Gateway_Paynet';
		return $gateways;
	}

	add_filter( 'woocommerce_payment_gateways', 'wc_offline_add_paynet_gateways' );
	add_filter( 'woocommerce_gateway_icon', 'paynet_payment_gateway_icons', 10, 2 );
function paynet_payment_gateway_icons( $icon, $gateway_id ){

    foreach( WC()->payment_gateways->get_available_payment_gateways() as $gateway )
    // Setting (or not) a custom icon to the payment IDs
    if($gateway_id == 'paynet'){
        $icon = '<img src="' . plugin_dir_url('paynet-gateway').'paynet-gateway/resources/all_long.png' . '" alt="' . esc_attr( $gateway->get_title() ) . '" />';
        //$icon.= '<img src="' . plugin_dir_url('paynet-gateway').'paynet-gateway/resources/Bitcoin.png' . '" alt="' . esc_attr( $gateway->get_title() ) . '" />';
    }
    
    return $icon;
}
}

function paynet_activate() 
		{
			global $wpdb;
		
			$table_name = $wpdb->prefix . "paynet_log"; 
			if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) 
			{
				$wpdb->insert( $table_name,array('action' => "paynet_install",'data' => "Was activated the plugin.Version: 1.0.0, table with name ".$table_name."  already exist !", 'order_id' => -2 ), array( '%s','%s', '%d' ) );	
			}
			else
			{
				$charset_collate = $wpdb->get_charset_collate();			

				$sql = "CREATE TABLE $table_name (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `reg_date` timestamp DEFAULT CURRENT_TIMESTAMP,
                          `order_id` int(11) DEFAULT NULL,	
                          `transaction_id` int(11) DEFAULT NULL,			 
                          `action` varchar(256) DEFAULT '' NOT NULL,
                          `data` text NOT NULL,
                          PRIMARY KEY  (id)) $charset_collate;";

				require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
				$r = dbDelta( $sql );	
				$wpdb->insert( $table_name,array('action' => "paynet_install",'data' => print_r($r,true), 'order_id' => -1 ), array( '%s','%s', '%d' ) );	
			}
				
		}

function paynet_available_methods(){
	wp_enqueue_script( 'paynet', '/wp-content/plugins/paynet-gateway/js/paynet.js');
}

?>