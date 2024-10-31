<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
* Lusopay - Payshop
*
* @since 2.0.0
*/

if ( ! class_exists( 'WC_Lusopay_PS' ) ) {

	/**
	 * Class WC_Lusopay_PS
	 */
	class WC_Lusopay_PS extends WC_Payment_Gateway {

		/**
		 * Lusopay Database Version
		 *
		 * @var string
		 */
		public $database_version = '1.0';

		/**
		 * Lusopay Secret Key
		 *
		 * @var string
		 */
		public $secret_key = '';

		/**
		 * IPN Url for Reference Payment
		 *
		 * @var string
		 */
		public $notify_url = '';

		/**
		 * Lusopay Debugger State
		 *
		 * @var bool
		 */
		public $debug = false;

		/**
		 * Lusopay Debugger Object
		 *
		 * @var bool|WC_Logger
		 */
		public $log = false;

		/**
		 * Variable to store if user has WPML module installed
		 *
		 * @var bool
		 */
		public $has_wpml = false;

		/**
		 * User ClientGuid
		 *
		 * @var string
		 */
		public $chave = '';

		/**
		 * User vatNumber
		 *
		 * @var string
		 */
		public $nif = '';

		/**
		 * Only for portuguese Clients?
		 *
		 * @var string
		 */
		public $only_portugal = 'no';

		/**
		 * Only Above a certain order value
		 *
		 * @var float
		 */
		public $only_above = 0;

		/**
		 * Only bellow a certain order value
		 *
		 * @var float
		 */
		public $only_bellow = 0;

		/**
		 * When to change the products stocks
		 *
		 * @var string
		 */
		public $stock_when = '';

		/**
		 * Send throw email generate reference
		 * 
		 * @var bool
		 */
		public $send_email = false;

		/**
		 * WC_Lusopay_PS constructor.
		 */
		public function __construct() {

			$this->integration = new WC_Lusopay_Integration;

			
			
			$this->set_plugin_id( 'lusopay_payshop' );
			$this->set_plugin_icon( plugins_url( '../imagens/payshop_icon.png', __FILE__ ) );
			$this->set_plugin_has_fields( false );
			$this->set_plugin_method_title( __( 'Payshop (BY LUSOPAY)', 'lusopaygateway' ) );
			$this->secret_key = $this->get_chave_anti_phishing();

			$this->debug = ( 'yes' === $this->get_debug() ? true : false );
			if ( $this->debug ) {
				$this->log = version_compare( WC_VERSION, '3.0', '>=' ) ? wc_get_logger() : new WC_Logger();
			}

			$this->upgrade();

			$this->notify_url = ( '' === get_option( 'permalink_structure' ) ) ? home_url( '/?wc-api=WC_Lusopay_PS&entidade=«entidade»&referencia=«referencia»&valor=«valor»&chave=' . $this->secret_key ) : home_url( '/wc-api/WC_Lusopay_PS/?entidade=«entidade»&referencia=«referencia»&valor=«valor»&chave=' . $this->secret_key );

			//$this->notify_wallet_url = ( '' === get_option( 'permalink_structure' ) ) ? home_url( '/?wc-api=WC_Lusopaygateway&descricao=«description»&valor=«amount»&utilizador=«from_user»&chave=' . $this->secret_key ) : home_url( '/wc-api/WC_Lusopaygateway/?descricao=«description»&valor=«amount»&utilizador=«from_user»&chave=' . $this->secret_key );

			// WPML?
			$this->has_wpml = function_exists( 'icl_object_id' ) && function_exists( 'icl_register_string' );

			// Plugin options and settings.
			$this->init_form_fields();
			$this->init_settings();

			// User settings.
			$this->set_plugin_title( $this->get_option( 'title' ) );
			$this->set_plugin_description( $this->get_option( 'description' ) );
			$this->chave         = $this->get_chave();
			$this->nif           = $this->get_nif();
			$this->only_portugal = $this->get_option( 'only_portugal' );
			$this->only_above    = $this->get_option( 'only_above' );
			$this->only_bellow   = $this->get_option( 'only_bellow' );
			$this->stock_when    = $this->get_option( 'stock_when' );
			$this->send_email    = $this->get_option( 'send_email' );

			if ( get_site_option( 'mag_db_version' ) !== $this->database_version ) {
				//Já é criado no ficheiro class-wc-lusopaygateway.php
				//$this->handle_database();
			}

			// Actions and filters.
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
				$this,
				'process_admin_options',
			) );

			if ( $this->has_wpml ) {
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
					$this,
					'register_wpml_strings',
				) );
			}

			add_action( 'woocommerce_thankyou_lusopay_payshop', array( &$this, 'thankyou' ) );
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'disable_unless_portugal' ) );
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'disable_only_above_or_below' ) );
      		add_action('woocommerce_order_details_after_order_table', array( $this, 'order_details_after_order_table' ), 20 );
			// APG SMS Notifications Integration.
			add_filter( 'apg_sms_message', array( $this, 'sms_instructions_apg' ), 10, 2 );

			// Customer Emails.
			add_action( 'woocommerce_email_before_order_table', array(
				$this,
				'email_instructions_lusopaygateway',
			), 10, 2 );

			// Payment listener/API hook.
			add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( &$this, 'callback' ) );
		}

		/**
		 * Get clientGUID
		 */
		public function get_chave() {
			return $this->integration->get_clientGuid();
		}
		
		/**
		 * Get nif
		 */
		public function get_nif() {
			return $this->integration->get_vat_number();
		}

		/**
		 * Get debug option
		 */
		public function get_debug() {
			return $this->integration->get_debug_option();
		}

		/**
		 * Get chave anti-phishing
		 */
		public function get_chave_anti_phishing() {
			return $this->integration->get_chave_anti_phishing();
		}

		/**
		 * Set Plugin Id
		 *
		 * @param string $id ID of the class extending the settings API. Used in option names.
		 */
		public function set_plugin_id( $id ) {
			$this->id = $id;
		}

		/**
		 * Set Plugin Icon
		 *
		 * @param string $icon Icon for the gateway.
		 */
		public function set_plugin_icon( $icon ) {
			$this->icon = $icon;
		}

		/**
		 * Set Plugin has fields
		 *
		 * @param boolean $has_fields if the gateway shows fields on the checkout.
		 */
		public function set_plugin_has_fields( $has_fields ) {
			$this->has_fields = $has_fields;
		}

		/**
		 * Set Plugin Title
		 *
		 * @param string $method_title Gateway title.
		 */
		public function set_plugin_method_title( $method_title ) {
			$this->method_title = $method_title;
		}

		/**
		 * Set Plugin Payment method title for the frontend.
		 *
		 * @param string $title Payment method title for the frontend.
		 */
		public function set_plugin_title( $title ) {
			$this->title = $title;
		}

		/**
		 * Set Plugin Payment method description for the frontend.
		 *
		 * @param string $description Payment method description for the frontend.
		 */
		public function set_plugin_description( $description ) {
			$this->description = $description;
		}

		/**
		 * Icon HTML
		 */
		public function get_icon() {
			$icon_html = '<img src="' . esc_attr( $this->icon ) . '" alt="' . esc_attr( $this->title ) . '" />';

			return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
		}

		/**
		 * Get Customer Billing Country
		 *
		 * @return string
		 */
		function get_customer_billing_country() {
			if ( version_compare( WC_VERSION, '3.0', '>=' ) ) {
				return trim( WC()->customer->get_billing_country() );
			} else {
				return trim( WC()->customer->get_country() );
			}
		}

		/**
		 * Upgrades (if needed)
		 */
		function upgrade() {
    }
    
    /**
    *
    * View Order detail payment reference.
    */
    function order_details_after_order_table( $order ) {
      if ( is_wc_endpoint_url( 'view-order' ) ) {
        $this->thankyou( $order->get_id() );
      }
    }

		/**
		 * Create or Update Needed Tables
		 */
		function handle_database() {
			global $wpdb;
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			//update_option( 'mag_db_version', $this->database_version );

			$charset_collate = $wpdb->get_charset_collate();
			$table_name      = $wpdb->prefix . 'magnimeiosreferences';

			$check_table           = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );// db call ok; no-cache ok.
			$check_status_colunm   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s', DB_NAME, $table_name, 'status' ) );// db call ok; no-cache ok.
			$check_entidade_colunm = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s', DB_NAME, $table_name, 'entidade' ) );// db call ok; no-cache ok.
			$check_expiration_date_colunm = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s', DB_NAME, $table_name, 'expiration_date' ) );// db call ok; no-cache ok.
			
			if ( ! empty( $check_table ) && empty( $check_status_colunm ) && empty( $check_entidade_colunm ) && empty($check_expiration_date_colunm)) {
				dbDelta( "ALTER TABLE $table_name ADD COLUMN (status VARCHAR(10), entidade VARCHAR(10), expiration_date DATETIME);" );
			} elseif ( ! empty( $check_table ) && ! empty( $check_status_colunm ) && empty( $check_entidade_colunm ) && empty($check_expiration_date_colunm)) {
				dbDelta( "ALTER TABLE $table_name ADD COLUMN (entidade VARCHAR(10), expiration_date DATETIME);" );
			} elseif (! empty( $check_table ) && ! empty( $check_status_colunm ) && ! empty( $check_entidade_colunm ) && empty($check_expiration_date_colunm)) {
				dbDelta( "ALTER TABLE $table_name ADD COLUMN (expiration_date DATETIME);" );
			} else {
				dbDelta( "CREATE TABLE IF NOT EXISTS $table_name (
                        id_order VARCHAR(10) PRIMARY KEY,
                        refMB VARCHAR(9),
                        refPS VARCHAR(13),
                        value VARCHAR(10),
                        status VARCHAR(10),
                        entidade VARCHAR(10),
						expiration_date DATETIME
                ) $charset_collate;" );
			}

			/*$entidade_null = $wpdb->get_results( "SELECT * FROM {$table_name} WHERE entidade IS NULL" );// db call ok; no-cache ok.
			$rows          = count( $entidade_null );
			if ( $rows > 0 ) {
				dbDelta( "UPDATE $table_name SET entidade='11024'" );
			}*/

			update_option( 'mag_db_version', $this->database_version );
		}


		/**
		 * WPML compatibility
		 */
		function register_wpml_strings() {
			$to_register = array(
				'title',
				'description',
			);
			foreach ( $to_register as $string ) {
				icl_register_string( $this->id, $this->id . '_' . $string, $this->settings[ $string ] );
			}
		}

		/**
		 * Initialise Gateway Settings Form Fields
		 * 'setting-name' => array(
		 *        'title' => __( 'Title for setting', 'woothemes' ),
		 *        'type' => 'checkbox|text|textarea',
		 *        'label' => __( 'Label for checkbox setting', 'woothemes' ),
		 *        'description' => __( 'Description for setting' ),
		 *        'default' => 'default value'
		 *    ),
		 */
		function init_form_fields() {

			$this->form_fields = array(
				'enabled'       => array(
					'title'   => __( 'Enable/Disable', 'lusopaygateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Payshop (By LUSOPAY)', 'lusopaygateway' ),
					'default' => 'no',
				),
				'only_portugal' => array(
					'title'   => __( 'Only for Portugal clients?', 'lusopaygateway' ),
					'type'    => 'checkbox',
					'label'   => __( 'Activate only for customers that have a Portuguese address?', 'lusopaygateway' ),
					'default' => 'no',
				),
				'only_above'    => array(
					'title'       => __( 'Only for orders more than', 'lusopaygateway' ),
					'type'        => 'number',
					'description' => __( 'Activate only for orders above € x (exclusive). To use Payshop payment the minimum order is of € 1.20.',
							'lusopaygateway' ) . ' <br/> ' . __( 'Payshop only accepts payments below € 4.000,00; (inclusive). You can use this option to limit more than the standard values.', 'lusopaygateway' ),
					'default'     => '',
				),
				'only_bellow'   => array(
					'title'       => __( 'Only for orders below', 'lusopaygateway' ),
					'type'        => 'number',
					'description' => __( 'Activate only for orders below € x (exclusive). Leave blank (or zero) to activate for any order value.', 'lusopaygateway' ) . ' <br/> ' . __( 'Payshop only accepts payments below € 4.000,00 (inclusive). You can use thes option to limit more than the standard values.', 'lusopaygateway' ),
					'default'     => '',
				),
				'stock_when'    => array(
					'title'       => __( 'Change stock', 'lusopaygateway' ),
					'type'        => 'select',
					'description' => __( 'Choose when to change stock.', 'lusopaygateway' ),
					'default'     => '',
					'options'     => array(
						''      => __( 'when the order is paid (demands callback feature to be active)', 'lusopaygateway' ),
						'order' => __( 'when the order is done (before payment)', 'lusopaygateway' ),
					),
				),
				'title'         => array(
					'title'       => __( 'Title', 'lusopaygateway' ),
					'type'        => 'text',
					'description' => __( 'This controls the title that customer sees when doing checkout.', 'lusopaygateway' ),
					'default'     => __( 'Payshop (By LUSOPAY)', 'lusopaygateway' ),
				),
				'description'   => array(
					'title'       => __( 'Description', 'lusopaygateway' ),
					'type'        => 'textarea',
					'description' => __( 'This controls the description that customer sees when doing checkout.', 'lusopaygateway' ),
					'default'     => __( 'Pagamento um agente PAYSHOP (apenas disponível num agente Payshop em Portugal).', 'lusopaygateway' ),
				),
				'send_email'          => array(
					'title'       => __( 'Send email of the generate reference', 'lusopaygateway' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable send email', 'lusopaygateway' ),
					'description' => __( 'If you want receive the email of the generate references, check, please.', 'lusopaygateway' ),
					'default'     => 'no',
				),
			);

		}

		/**
		 * Admin Plugin Configurations Form
		 */
		public function admin_options() {
			include 'views/html-admin-page-ps.php';
		}

		/**
		 * Thank you page
		 *
		 * @param int $order_id Order Id.
		 */
		function thankyou( $order_id ) {
			//echo $this->getRef( $order_id );
			echo $this->get_template_frontEnd_order_detais($order_id);
		}

		/**
		 * Email instructions
		 *
		 * @param mixed $order Order Object.
		 */
		function email_instructions_lusopaygateway( $order ) {
			global $wpdb;
			$order_id = version_compare( WC_VERSION, '3.0', '>=' ) ? $order->get_id() : $order->id;
			$order    = new WC_Order_Lusopay( $order_id );

			if ( $order->lp_order_get_payment_method() !== $this->id ) {
				return;
			}
			switch ( $order->lp_order_get_status() ) {
				case 'on-hold':
				case 'pending':
					echo $this->get_template_email_ps_order_detais($order->lp_order_get_id());
					break;
				case 'processing':
					?>
					<p>
						<?php $order->has_downloadable_item() ? esc_html_e( 'Payment received.', 'lusopaygateway' ) : esc_html_e( 'Payment received.', 'lusopaygateway' ). esc_html_e( 'We will process your order now.', 'lusopaygateway' )?> 
					</p>
					<?php
					break;
			}
		}

		/**
		 * Process it
		 *
		 * @param int $order_id Order Id.
		 *
		 * @return array
		 */
		function process_payment( $order_id ) {
			$sendEmail = 'false';
			$order = new WC_Order_Lusopay( $order_id );

			//$currency = trim( get_woocommerce_currency() );

			if ($this->send_email === 'yes') {
				$sendEmail = 'true';
			}

			$response=$this->GenerateRef( $this->chave, $this->nif, $order->lp_order_get_id(), $order->lp_order_get_total(), $sendEmail );
			$ref=$response[1];
			$mess=$response[2];
			if($ref!=null){
			// Mark as on-hold.
			$order->update_status( 'on-hold', __( 'Waiting for payment by Payshop reference.', 'lusopaygateway' ) );

			// Reduce stock levels.
			if ( 'order' === $this->stock_when ) {
				$order->lp_order_reduce_order_stock();
			}
			// Remove cart.
			WC()->cart->empty_cart();

			// Return thankyou redirect.
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
			
		}else{

			/*$order = wc_get_order($order_id);
			$order->delete(true);

			// Optional: You can also restore stock for the deleted order's products
			$order_items = $order->get_items();
			foreach ($order_items as $item) {
				$product = $item->get_product();
				if ($product) {
					$product->increase_stock($item->get_quantity());
				}
			}
		
			// Optional: You can also delete the associated order notes
			$order_notes = wc_get_order_notes(array(
				'order_id' => $order_id,
			));
			foreach ($order_notes as $note) {
				$note->delete(true);
			}*/
		
				throw new Exception( __( $mess, 'woo' ) );
		}
		}

		/**
		 * Just for Portugal
		 *
		 * @param array $available_gateways Woocommerce Available Gateways.
		 *
		 * @return mixed
		 */
		function disable_unless_portugal( $available_gateways ) {
			if ( isset( $available_gateways[ $this->id ] ) ) {
				if ( $available_gateways[ $this->id ]->only_portugal == 'yes' && WC()->customer && $this->get_customer_billing_country() !== 'PT' ) {
					unset( $available_gateways[ $this->id ] );
				}
			}

			return $available_gateways;
		}

		/**
		 * Just above/bellow certain amounts
		 *
		 * @param array $available_gateways Woocommerce Available Gateways.
		 *
		 * @return mixed
		 */
		function disable_only_above_or_below( $available_gateways ) {
			global $woocommerce;
      		if (isset($available_gateways[$this->id])) {
        		if (@floatval($available_gateways[$this->id]->only_above) > 0) {
					if ($woocommerce->cart) {
						if ($woocommerce->cart->total > 0) {
							if ( $woocommerce->cart->total < @floatval($available_gateways[$this->id]->only_above) ) {
								unset($available_gateways[$this->id]);
				  			}
						} 
					}
        		} 
        		if (@floatval($available_gateways[$this->id]->only_bellow) > 0) {
					if ($woocommerce->cart) {
						if ( $woocommerce->cart->total > @floatval($available_gateways[$this->id]->only_bellow) ) {
							unset($available_gateways[$this->id]);
						  }
					}
        		}
      		}
      		return $available_gateways;
		}

		/**
		 * Get order id by Lusopay PS Reference
		 *
		 * @param mixed $ref Reference.
		 * @param mixed $valor Value.
		 *
		 * @return int
		 */
		function get_order_id_by_lusopay_ps_reference( $ref, $valor ) {
			global $wpdb;

			$table_name = $wpdb->prefix . 'magnimeiosreferences';

			$order_id = 0;
			$result   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE refPS LIKE %s AND value = %s AND status IS NULL", '%' . $ref . '%', $valor ) );// db call ok; no-cache ok.
			foreach ( $result as $row ) {
				$order_id = $row->id_order;
			}

			return $order_id;
		}

		/**
		 * Update Order Lusopay Status
		 *
		 * @param int $order_id Order ID.
		 */
		function updateStatus( $order_id ) {
			global $wpdb;

			$table_name = $wpdb->prefix . 'magnimeiosreferences';

			$data  = array(
				'status' => 'PAGO',
			);
			$where = array(
				'id_order' => $order_id,
			);

			$wpdb->update( $table_name, $data, $where );// db call ok; no-cache ok.
		}

		/**
		 * Format Number
		 *
		 * @param mixed $number Value.
		 *
		 * @return string
		 */
		function format_number( $number ) {
			$verify_sep_decimal = number_format( 99, 2 );

			$valor_tmp = $number;

			$sep_decimal = substr( $verify_sep_decimal, 2, 1 );

			$has_sep_decimal = true;

			$val = ( strlen( $valor_tmp ) - 1 );

			for ( $i = $val; 0 !== $i; $i -- ) {
				if ( substr( $valor_tmp, $i, 1 ) === '.' || substr( $valor_tmp, $i, 1 ) === ',' ) {
					$has_sep_decimal = true;
					$valor_tmp       = trim( substr( $valor_tmp, 0, $i ) ) . '@' . trim( substr( $valor_tmp, 1 + $i ) );
					break;
				}
			}

			if ( true !== $has_sep_decimal ) {
				$valor_tmp = number_format( $valor_tmp, 2 );

				$val = ( strlen( $valor_tmp ) - 1 );

				for ( $i = $val; 0 !== $i; $i -- ) {
					if ( substr( $valor_tmp, $i, 1 ) === '.' || substr( $valor_tmp, $i, 1 ) === ',' ) {
						$valor_tmp = trim( substr( $valor_tmp, 0, $i ) ) . '@' . trim( substr( $valor_tmp, 1 + $i ) );
						break;
					}
				}
			}

			$tam = strlen( $valor_tmp ) - 1;
			for ( $i = 1; $i !== $tam; $i ++ ) {
				if ( '.' === substr( $valor_tmp, $i, 1 ) || ',' === substr( $valor_tmp, $i, 1 ) || ' ' === substr( $valor_tmp, $i, 1 ) ) {
					$valor_tmp = trim( substr( $valor_tmp, 0, $i ) ) . trim( substr( $valor_tmp, 1 + $i ) );
					break;
				}
			}

			if ( strlen( strstr( $valor_tmp, '@' ) ) > 0 ) {
				$valor_tmp = trim( substr( $valor_tmp, 0, strpos( $valor_tmp, '@' ) ) ) . trim( $sep_decimal ) . trim( substr( $valor_tmp, strpos( $valor_tmp, '@' ) + 1 ) );
			}

			return $valor_tmp;
		}

		/**
		 * Lusopay Call to generate reference
		 *
		 * @param string $ent_chave Lusopay GUID.
		 * @param string $ent_nif User Vat Number.
		 * @param int    $order_id Order ID.
		 * @param float  $order_value Order Value.
		 *
		 * @return bool
		 */
		function GenerateRef( $ent_chave, $ent_nif, $order_id, $order_value, $send_email ) {

			$order_value = sprintf( '%01.2f', $order_value );

			$order_value = $this->format_number( $order_value );
			$lusopay_soap_url = '';
			if ($ent_nif == '999999999') {
				$lusopay_soap_url = 'https://services.lusopay.com/PaymentServices_test/PaymentServices.svc?wsdl';
			} else {
				$lusopay_soap_url = 'https://services.lusopay.com/PaymentServices/PaymentServices.svc?wsdl';
			}

			

			$xml_post_string = '<?xml version="1.0" encoding="utf-8"?>
			<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/" xmlns:pay="http://schemas.datacontract.org/2004/07/PaymentServices">
			<soapenv:Body>
			<tem:getNewDynamicReference>
			<!--Optional:-->
			<tem:clientGuid>' . $ent_chave . '</tem:clientGuid>
			<!--Optional:-->
			<tem:vatNumber>' . $ent_nif . '</tem:vatNumber>
			<!--Optional:-->
			<tem:valueList>
			<!--Zero or more repetitions:-->
			<pay:References>
			<!--Optional:-->
			<pay:amount>' . $order_value . '</pay:amount>
			<!--Optional:-->
			<pay:description>' . $order_id . '</pay:description>
			<!--Optional:-->
			<pay:serviceType>PS</pay:serviceType>
			</pay:References>
			</tem:valueList>
			<!--Optional:-->
			<tem:sendEmail>'. $send_email .'</tem:sendEmail>
			</tem:getNewDynamicReference>
			</soapenv:Body>
			</soapenv:Envelope>';

			$headers = array(
				'Host'           => 'services.lusopay.com',
				'Content-type'   => 'text/xml;charset="utf-8"',
				'Accept'         => 'text/xml',
				'Cache-Control'  => 'no-cache',
				'Pragma'         => 'no-cache',
				'SOAPAction'     => 'http://tempuri.org/IPaymentServices/getNewDynamicReference',
				'Content-length' => strlen( $xml_post_string ),
			);

			$args     = array(
				'headers' => $headers,
				'body'    => $xml_post_string,
				'timeout' => 30,
			);
			$response = wp_remote_post( $lusopay_soap_url, $args );
			$refs   = array();
			$refs[2]='Erro ao gerar pagamento';
			if ( ! is_wp_error( $response ) ) {
				$response = $response['body'];
				if ( preg_match( '/<a:referencePS>(.*?)<\/a:referencePS>/s', $response, $reference_ps_value ) ) {
				
					$refs[1] = $reference_ps_value[1];
						$ref= $reference_ps_value[1];
					global $wpdb;
					$table_name = $wpdb->prefix . 'magnimeiosreferences';

					$wpdb->insert( $table_name, array(
						'id_order' => $order_id,
						'refPS'    => $ref,
						'value'    => $order_value,
					) );// db call ok; no-cache ok.


				} else {
					if ( preg_match( '/<a:message>(.*?)<\/a:message>/s', $response, $message_value ) ) {
						echo esc_html( $message_value[1] );
						$refs[2]=$message_value[1];
					}
				}
			} else {
				return false;
			}

			return $refs;
		}

		/**
		 * Get Payshop Reference
		 * 
		 * @param int $order_id Order Id.
		 * 
		 * @return array
		 */
		function getRef($order_id) {
			global $wpdb;

			$table_name = $wpdb->prefix . 'magnimeiosreferences';

			$result = $wpdb->get_results( $wpdb->prepare( "SELECT refMB AS mb_reference, refPS AS ps_reference, value, entidade FROM $table_name WHERE id_order = %d", $order_id ) );// db call ok; no-cache ok.
			foreach ( $result as $row ) {
				$ref    = $row->ps_reference;
				$order_value = $row->value;
			}
			if($result != null) {
				return array('ref' => $ref, 'order_value' => $order_value);
			} else {
				return array('ref' => null, 'order_value' => 0);
			}
			

		}

		/**
		 * Get Template Payshop to FrontEnd
		 *
		 * @param int $order_id Order Id.
		 *
		 * @return string
		 */
		function get_template_frontEnd_order_detais( $order_id ) {
			
			$res = $this->getRef($order_id);
			$ref = $res['ref'];
			$order_value = $res['order_value'];
			
			$html_lusopay = '';

			

				if ( '-1' !== $ref && null !== $ref ) {

					$ref = substr( $ref, 0, 3 ) . ' ' . substr( $ref, 3, 3 ) . ' ' . substr( $ref, 6, 3 ) . ' ' . substr( $ref, 9, 3 ) . ' ' . substr( $ref, 12, 1 );

					$html_lusopay .= '<div align="center">';
					$html_lusopay .= '<table class="lusopay_table2" cellpadding="3" width="350px" cellspacing="0" style="margin-top: 10px;border: 1px solid #dcdcdc;width: 50%; background-color: white; color: black;" align="center">';
					$html_lusopay .= "<tr class='lusopay_thead'>";
					$html_lusopay .= '		<td align="center" style="font-size: small; border-top: 0; border-left: 0; border-right: 0; border-bottom: 1px solid #dcdcdc; background-color: #dcdcdc; color: black; text-align:center;" colspan="3"><span style="text-align:center">Pagamento por Payshop (by LUSOPAY)</span></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td rowspan="4"><div align="center"><img class="lusopay_logo" src="https://www.lusopay.com/App_Files/cms/documents/images/payshop.jpg" alt=""/></div></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td class="lusopay_fields" style="font-size: small; font-weight:bold; text-align:left">Refer&ecirc;ncia:</td>';
					$html_lusopay .= '		<td class="lusopay_values" style="font-size: small; text-align:left">' . $ref . '</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td class="lusopay_fields" style="font-size: small; font-weight:bold; text-align:left">Valor:</td>';
					$html_lusopay .= '		<td class="lusopay_values" style="font-size: small; text-align:left">' . number_format( $order_value, 2, ',', ' ' ) . '&nbsp;&euro;</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td style="font-size: x-small; font-weight:bold; text-align:left">&nbsp;</td>';
					$html_lusopay .= '		<td style="font-size: x-small; text-align:left">&nbsp;</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td align="center" class="lusopay_tfooter" style="font-size: x-small;border-top: 1px solid #dcdcdc; border-left: 0; border-right: 0; border-bottom: 0; background-color: #dcdcdc; color: black; text-align:center;" colspan="3"><span style="text-align:center">O tal&atilde;o emitido faz prova de pagamento. Conserve-o.</span></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '</table>';
					$html_lusopay .= '</div>';
				}
			return $html_lusopay;
		}

		/**
		 * Get Template Payshop admin order
		 * 
		 * @param int $order_id Order Id.
		 * 
		 * @return string
		 */
		function get_lp_template_ps_order_details($order_id) {

			$res = $this->getRef($order_id);
			$ref = $res['ref'];
			$order_value = $res['order_value'];
			
			$html_lusopay = '';

			

				if ( '-1' !== $ref && null !== $ref ) {

					$ref = substr( $ref, 0, 3 ) . ' ' . substr( $ref, 3, 3 ) . ' ' . substr( $ref, 6, 3 ) . ' ' . substr( $ref, 9, 3 ) . ' ' . substr( $ref, 12, 1 );

					$html_lusopay .= '<table class="lusopay_table2" cellpadding="3" width="250px" cellspacing="0" style="margin-top: 10px;border: 1px solid #dcdcdc; background-color: white; color: black;" align="center">';
					$html_lusopay .= "<tr class='lusopay_thead'>";
					$html_lusopay .= '		<td align="center" style="font-size: x-small; border-top: 0; border-left: 0; border-right: 0; border-bottom: 1px solid #dcdcdc; background-color: #dcdcdc; color: black" colspan="3"><span style="text-align:center">Pagamento por Payshop (by LUSOPAY)</span></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td rowspan="4"><div align="center"><img class="lusopay_logo" src="https://www.lusopay.com/App_Files/cms/documents/images/payshop_80x80.jpg" alt=""/></div></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td class="lusopay_fields" style="font-size: x-small; font-weight:bold; text-align:left">Refer&ecirc;ncia:</td>';
					$html_lusopay .= '		<td class="lusopay_values" style="font-size: x-small; text-align:left">' . $ref . '</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td class="lusopay_fields" style="font-size: x-small; font-weight:bold; text-align:left">Valor:</td>';
					$html_lusopay .= '		<td class="lusopay_values" style="font-size: x-small; text-align:left">' . number_format( $order_value, 2, ',', ' ' ) . '&nbsp;&euro;</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td style="font-size: x-small; font-weight:bold; text-align:left">&nbsp;</td>';
					$html_lusopay .= '		<td style="font-size: x-small; text-align:left">&nbsp;</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td align="center" class="lusopay_tfooter" style="font-size: xx-small;border-top: 1px solid #dcdcdc; border-left: 0; border-right: 0; border-bottom: 0; background-color: #dcdcdc; color: black" colspan="3"><span style="text-align:center">O tal&atilde;o emitido faz prova de pagamento. Conserve-o.</span></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '</table>';
				}
			return $html_lusopay;

		}

		/**
		 * Get Template Payshop to email
		 *
		 * @param int $order_id Order Id.
		 *
		 * @return string
		 */
		function get_template_email_ps_order_detais( $order_id ) {
			
			$res = $this->getRef($order_id);
			$ref = $res['ref'];
			$order_value = $res['order_value'];
			
			$html_lusopay = '';

			

				if ( '-1' !== $ref && null !== $ref ) {

					$ref = substr( $ref, 0, 3 ) . ' ' . substr( $ref, 3, 3 ) . ' ' . substr( $ref, 6, 3 ) . ' ' . substr( $ref, 9, 3 ) . ' ' . substr( $ref, 12, 1 );

					
					$html_lusopay .= '<table class="lusopay_table2" cellpadding="3" width="100%" cellspacing="0" style="margin-top: 10px;border: 1px solid #dcdcdc; background-color: white; color: black;" align="center">';
					$html_lusopay .= "<tr class='lusopay_thead'>";
					$html_lusopay .= '		<td align="center" style="font-size: small; border-top: 0; border-left: 0; border-right: 0; border-bottom: 1px solid #dcdcdc; background-color: #dcdcdc; color: black; text-align:center;" colspan="3"><span style="text-align:center">Pagamento por Payshop (by LUSOPAY)</span></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td rowspan="4"><div align="center"><img class="lusopay_logo" src="https://www.lusopay.com/App_Files/cms/documents/images/payshop.jpg" alt=""/></div></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td class="lusopay_fields" style="font-size: small; font-weight:bold; text-align:left">Refer&ecirc;ncia:</td>';
					$html_lusopay .= '		<td class="lusopay_values" style="font-size: small; text-align:left">' . $ref . '</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td class="lusopay_fields" style="font-size: small; font-weight:bold; text-align:left">Valor:</td>';
					$html_lusopay .= '		<td class="lusopay_values" style="font-size: small; text-align:left">' . number_format( $order_value, 2, ',', ' ' ) . '&nbsp;&euro;</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td style="font-size: x-small; font-weight:bold; text-align:left">&nbsp;</td>';
					$html_lusopay .= '		<td style="font-size: x-small; text-align:left">&nbsp;</td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '	<tr>';
					$html_lusopay .= '		<td align="center" class="lusopay_tfooter" style="font-size: x-small;border-top: 1px solid #dcdcdc; border-left: 0; border-right: 0; border-bottom: 0; background-color: #dcdcdc; color: black; text-align:center;" colspan="3"><span style="text-align:center">O tal&atilde;o emitido faz prova de pagamento. Conserve-o.</span></td>';
					$html_lusopay .= '	</tr>';
					$html_lusopay .= '</table>';
					
				}
			return $html_lusopay;
		}

		/**
		 * SMS instructions for APG SMS Notifications
		 *
		 * @param string $message Message.
		 * @param mixed  $order_id Order Id.
		 *
		 * @return string
		 */
		function sms_instructions_apg( $message, $order_id ) {
			$replace = $this->sms_instructions( $order_id );

			return trim( str_replace( '%lusopay_gateway_sms%', $replace, $message ) );
		}

		/**
		 * SMS instructions - General. Can be used to feed any SMS gateway/plugin
		 *
		 * @param mixed $order_id Order Id.
		 *
		 * @return string
		 */
		function sms_instructions( $order_id ) {
			$order        = new WC_Order_Lusopay( $order_id );
			$instructions = '';
			if ( $order->lp_order_get_payment_method() === $this->id ) {
				switch ( $order->lp_order_get_status() ) {
					case 'on-hold':
					case 'pending':
						global $wpdb;
						$table_name = $wpdb->prefix . 'magnimeiosreferences';

						$result = $wpdb->get_results( $wpdb->prepare( "SELECT refMB AS mb_reference, refPS AS ps_reference, entidade FROM $table_name WHERE id_order = %d", $order_id ) );// db call ok; no-cache ok.
						foreach ( $result as $row ) {
							$ref  = $row->ps_reference;

							$ps_string = '';

							if ( - 1 !== $ref || null !== $ref ) {
								$ps_string = substr( $ref, 0, 3 ) . ' ' . substr( $ref, 3, 3 ) . ' ' . substr( $ref, 6, 3 ) . ' ' . substr( $ref, 9, 3 ) . ' ' . substr( $ref, 12, 1 );
							}

							if ( '' !== $ps_string ) {

                				$instructions .= __( 'PS Ref', 'lusopaygateway' ) . ' ' . $ref . ' ';
								$instructions .= __( 'Value', 'lusopaygateway' ) . ' ' . $order->lp_order_get_total();
							}
						}
						break;
					case 'processing':
						// No instructions.
						break;
				}
			}

			return $instructions;
		}


		/**
		 * Callback
		 */
		function callback() {
			// We must 1st check the situation and then process it and send email to the store owner in case of error.
			if ( ! is_null( filter_input( INPUT_GET, 'chave' ) ) && ! is_null( filter_input( INPUT_GET, 'valor' ) ) && ( ( ! is_null( filter_input( INPUT_GET, 'entidade' ) ) && ! is_null( filter_input( INPUT_GET, 'referencia' ) ) ) ) ) {
				if ( $this->debug ) {
					$uri = filter_input( INPUT_SERVER, 'REQUEST_URI' );
					$this->log->add( $this->id, '- Callback (' . $uri . ') with all arguments from ' . $uri );
				}
				$ref         = trim( str_replace( ' ', '', filter_input( INPUT_GET, 'referencia' ) ) );
				$ent         = trim( filter_input( INPUT_GET, 'entidade' ) );// Input var okay.
				$valor       = filter_input( INPUT_GET, 'valor' );
				$val         = str_replace( ',', '.', $valor );
				$valor_final = number_format( $val, 2, '.', '' );
				$chave       = trim( filter_input( INPUT_GET, 'chave' ) );

				if ( trim( $this->secret_key ) === $chave && $val >= 1.2 ) {

					$ref_ps = '';
					$descricao = 0;
					if ( '10120' === $ent || '10087' === $ent) {
						$ref_ps    = $ent . $ref;
						$descricao = $this->get_order_id_by_lusopay_ps_reference( $ref_ps, $valor_final );

						global $wpdb;
						$table_name = $wpdb->prefix . 'magnimeiosreferences';

						$result = $wpdb->get_results( $wpdb->prepare( "SELECT refPS AS ps_reference FROM $table_name WHERE id_order = %d", $descricao ) );// db call ok; no-cache ok.
						foreach ( $result as $row ) {
							$ref_ps = $row->ps_reference;
						}

					} 

					if ( 0 !== $descricao ) {

						$order = new WC_Order_Lusopay( $descricao );

						include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
						if ( ! is_plugin_active( 'order-status-emails-for-woocommerce/order-status-emails-for-woocommerce.php' ) ) {
							// Only if this plugin is not active.
							if ( 'pending' !== $order->lp_order_get_status() ) {
								$order->update_status( 'pending', __( 'Temporary status. Used to force an email on the next order status change.', 'lusopaygateway' ) );
							}
						}
						
						$order->update_status( $order->has_downloadable_item() ? 'completed' : 'processing', __( 'Payment received by Payshop using ref: ', 'lusopaygateway' ) . $ref_ps );
					
						$this->updateStatus( $descricao );

						echo 'true';
					} else {
						echo 'false';
					}
				} else {
					echo 'false';
				}
			} else {
				echo 'false';
			}
			exit;
		}
	}
}
