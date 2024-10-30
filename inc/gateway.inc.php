<?php
/** Can't be called outside WP **/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Payment Gateway class for MANGOPAY
 * 
 * @author yann@wpandco.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 */
class WC_Gateway_Mangopay extends WC_Payment_Gateway {

	/** 
	 * Required class variables : standard WC Gateway
	 * 
	 *
	public $id; 					// Unique ID for your gateway. e.g. â€˜your_gatewayâ€™
	public $icon;					// If you want to show an image next to the gatewayâ€™s name on the frontend, enter a URL to an image.
	public $has_fields;				// Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration).
	public $method_title;			// Title of the payment method shown on the admin page.
	public $method_description;		// Description for the payment method shown on the admin page.
	public $title;					// Appears in the WC Admin Checkout tab >> Gateway Display
	public $form_fields;
	*/
	
	/** @var bool Whether or not logging is enabled */
	public static $log_enabled = false;
	
	/** @var WC_Logger Logger instance */
	public static $log = false;
	
	/**
	 * MANGOPAY specific class variables
	 * 
	 * @see: https://docs.mangopay.com/api-references/payins/payins-card-web/
	 * 
	 */
	private $supported_locales = array(
		'de', 'en', 'da', 'es', 'et', 'fi', 'fr', 'el', 'hu', 'it', 'nl', 'no', 'pl', 'pt', 'sk', 'sv', 'cs'
	);
	private $allowed_currencies = array(
		'EUR', 'GBP', 'USD', 'CHF', 'NOK', 'PLN', 'SEK', 'DKK', 'CAD', 'ZAR'
	);
	private $available_card_types = array(
		'CB_VISA_MASTERCARD'	=> 'CB/Visa/Mastercard',
		'MAESTRO'				=> 'Maestro', 
		'AMEX'					=> 'American Express (beta)', 
		'BCMC'					=> 'Bancontact/Mister Cash', 
		'P24'					=> 'Przelewy24', 
		'DINERS'				=> 'Diners', 
		'PAYLIB'				=> 'PayLib',
		'IDEAL'					=> 'iDeal', 
		'MASTERPASS'			=> 'MasterPass',
		'BANK_WIRE'				=> 'Bankwire Direct'	// This is not actually a card
	);
	private $available_directdebit_web_payin_types = array(
		'SOFORT'				=> 'Sofort',
		'GIROPAY'				=> 'Giropay'
	);
	
	/* 
	 * NB : added _preauth at the end for the process 
	 * in order to prevent confusion with other paying instruments with the same name, 
	 * _preauth will be suppress later 
	 *
	private $preauthorization_types = array(
		'CB_VISA_MASTERCARD_preauth' => 'CB/Visa/Mastercard'
	);
	*/

	private $default_card_types = array(
		'CB_VISA_MASTERCARD',
		'BCMC',
		'PAYLIB'
	);
		
	/**
	 * Class constructor (required)
	 *
	 */
	public function __construct() {

		/** Initialize payment gateway **/
		$this->wcGatewayInit();
		$this->init_form_fields();
		$this->init_settings();
		
		/** Admin hooks **/
		if( !is_admin() )
			return;
		
		/** Inherited class hook, mandatory **/
		add_action( 
			'woocommerce_update_options_payment_gateways_' . $this->id, 
			array( $this, 'process_admin_options' ) 
		);
	}
	
	/**
	 * Register the WC Payment gateway
	 *
	 * @param array $methods
	 * @return array $methods
	 *
	 */
	public static function add_gateway_class( $methods ) {
	
		$methods[] = 'WC_Gateway_Mangopay';
	
		return $methods;
	}

	/**
	 * Performs all initialization for a standard WooCommerce payment gateway
	 *
	 */
	private function wcGatewayInit() {
		
		$form_fields = array();
		
		/** Back-office form fields for activating MANGOPAY payments **/
		$form_fields['enabled'] = array(
			'title'		=> __( 'Enable/Disable', 'mangopay' ),
			'type'		=> 'checkbox',
			'label'		=> __( 'Enable MANGOPAY Payment', 'mangopay' ),
			'default'	=> 'yes'
		);
		
		/** Back-office form fields for activating MANGOPAY direct payout **/
		$form_fields['instapay'] = array(
			'title'		=> __( 'Direct payout', 'mangopay' ),
			'type'		=> 'checkbox',
			'label'		=> __( 'Enable direct payout of vendors', 'mangopay' ),
			'default'	=> 'no'
		);		
		
		/** Fields to choose available credit card types **/
		$first = true;
		foreach( $this->available_card_types as $type=>$label ) {
			$default = 'no';
			
			if( 'CB_VISA_MASTERCARD'==$type ){
				$default = 'yes';
			}
			
			$star = '<span class="note star" title="' . __('Needs activation','mangopay') . '">*</span>';
			
			
			if( in_array( $type, $this->default_card_types ) ){
				$star = '';
			}
			
			$title = $first?__( 'Choose available credit card types:', 'mangopay' ):'';
			
			if( 'BANK_WIRE' == $type ){
				$title = __( 'Choose available direct payment types:', 'mangopay' );
			}
			
			$form_fields['enabled_' . $type] = array(
				'title'		=> $title,
				'type'		=> 'checkbox',
				'label'		=> sprintf( __( 'Enable %s payment', 'mangopay' ), __( $label, 'mangopay' ) ) . $star,
				'default'	=> $default,
				'class'		=> 'mp_payment_method'
			);
			$first = false;
		}
		
		/** Fields to choose available DIRECT DEBIT WEB types **/
		//$first = true;	// NOT NEEDED
		foreach( $this->available_directdebit_web_payin_types as $type=>$label ) {
			$default = 'no';
			
			if( 'CB_VISA_MASTERCARD'==$type ){
				$default = 'yes';
			}
			
			$star = '<span class="note star" title="' . __('Needs activation','mangopay') . '">*</span>';
						
			if( in_array( $type, $this->default_card_types ) ){
				$star = '';
			}
			
			//$title = $first?__( 'Choose available web direct payment types:', 'mangopay' ):'';
			$title = '';
			
			$form_fields['enabled_' . $type] = array(
				'title'		=> $title,
				'type'		=> 'checkbox',
				'label'		=> sprintf( __( 'Enable %s payment', 'mangopay' ), __( $label, 'mangopay' ) ) . $star,
				'default'	=> $default,
				'class'		=> 'mp_payment_method'
			);
			$first = false;
		}
		
		/** Options user  **/
		/** REGISTERED CARDS **/
		$title = __( 'Show optional user fields at registration:', 'mangopay' );
		$form_fields['show_optional_user_fields'] = array(
			'title'		=> $title,
			'type'		=> 'checkbox',
			'label'		=> __( 'Show optional user fields at registration', 'mangopay' ),
			'default'	=> 'yes',
			'class'		=> 'mp_payment_method'
		);
		
		/** REGISTERED CARDS **/
		$title = __( 'Card registration (beta):', 'mangopay' );
		$form_fields['enabled_card_registration'] = array(
			'title'		=> $title,
			'type'		=> 'checkbox',
			'label'		=> __( 'Activate card registration (beta)', 'mangopay' ),
			'default'	=> 'no',
			'class'		=> 'mp_payment_method'
		);
		
		$args = array(
			'sort_column'      => 'menu_order',
			'sort_order'       => 'ASC',
		);
		$options = array( 0=>__( 'Use payment form of the bank (default)', 'mangopay' ));
		$pages = get_pages( $args );
		foreach( $pages as $page ) {
			$prefix = str_repeat( '&nbsp;', count( get_post_ancestors( $page ) )*3 );
			$options[$page->ID] = $prefix . $page->post_title;
		}
		
		$form_fields['custom_template_page_id'] = array(
			'title'				=> __( 'Use this page for payment template', 'mangopay' ),
			'description'		=> __( 'The page needs to be secured with https', 'mangopay' ),
			'id'				=> 'custom_template_page_id',
			'type'				=> 'select',
			'label'				=> __( 'Use this page for payment template', 'mangopay' ),
			'default'			=> 0,
			'class'				=> 'wc-enhanced-select-nostd',
			'css'				=> 'min-width:300px;',
			'desc_tip' 			=> __( 'Page contents:', 'woocommerce' ) . ' [' . apply_filters( 'mangopay_payform_shortcode_tag', 'mangopay_payform' ) . ']',
			'placeholder'		=> __( 'Select a page&hellip;', 'woocommerce' ),
			'options'			=> $options,
			'custom_attributes'	=> array( 'placeholder'	=> __( 'Select a page&hellip;', 'woocommerce' ) )
		);
		
		//test only in sandbox
		if( !mpAccess::getInstance()->is_production() ){
			$default_3ds2 = "no";
			$form_fields['enabled_3DS2'] = array(
				'title'		=> '3DS2',
				'type'		=> 'checkbox',
				'label'		=> __( 'Enforce 3DS2 on the sandbox', 'mangopay' ),
				'default'	=> $default_3ds2,
				'class'		=> 'mp_payment_method'
			);
		}
		
		$this->id					= 'mangopay';
		$this->icon					= ''; //plugins_url( '/img/card-icons.gif', dirname( __FILE__ ) );
		$this->has_fields			= true;		// Payment on third-party site with redirection
		$this->method_title			= __( 'MANGOPAY', 'mangopay' );
		$this->method_description	= __( 'MANGOPAY', 'mangopay' );
		$this->method_description	.= '<br/>' . __( 'Payment types marked with a * will need to be activated for your account. Please contact MANGOPAY.', 'mangopay' );
		$this->title				= __( 'Online payment', 'mangopay' );
		$this->form_fields			= $form_fields;
		$this->supports 			= array( 'refunds' );	// ||Â default_credit_card_form
	}
	
	/**
	 * Payform health-check
	 * 
	 */
	public function validate_custom_template_page_id_field( $key ) {
		// get the posted value
		$value = $_POST[ $this->plugin_id . $this->id . '_' . $key ];
		
		if( !$value )
			return $value;
		
		$url = get_permalink( $value );
		
		if( !preg_match( '/^https/', $url ) )
			$url = preg_replace( '/^http/', 'https', $url );
		
		$response = wp_remote_get( $url, array( 'timeout'=>2, 'sslverify'=>true ) );
		
		if( is_wp_error( $response ) ) {
			$this->error_notice_display( 'The payment template page cannot be reached with https.' );
			return '';
		}
		
		if( $page = get_post( $value ) ) {
			if( !preg_match( '/[mangopay_payform]/', $page->post_content ) ) {
				/** Add the shortcode **/
				$page->post_content = $page->post_content . '[mangopay_payform]';
				wp_update_post( $page );
			}
		}
		
		return $value;
	}
	
	/**
	 * Error notice display function
	 * 
	 */
	private function error_notice_display( $msg ) {
		$class = 'notice notice-error';
		$message = __( $msg, 'mangopay' );
		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
	}
	
	/**
	 * Logging method.
	 * @param string $message
	 */
	public static function log( $message ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
			self::$log->add( 'paypal', $message );
		}
	}

	/**
	 * Check if the gateway is available for use
	 *
	 * @return bool
	 */
	public function is_available() {

		$is_available = ( 'yes' === $this->enabled ) ? true : false;
		
		/** This payment method can't be used for unsupported curencies **/
		$currency	= get_woocommerce_currency();
		if( !in_array( $currency, $this->allowed_currencies ) )
			$is_available = false;

		/** This payment method can't be used if a Vendor does not have a MP account **/
		if( isset(WC()->cart->cart_contents) && $items = WC()->cart->cart_contents ) {
			foreach( $items as $item ) {
				$item_object	= $item['data'];
				$item_post_id	= $item_object->get_id();
				$item_post		= get_post( $item_post_id );
				
				if( 'product_variation' != $item_post->post_type ) {
					$vendor_id		= $item_post->post_author;
					
				} else {
					/** 
					 * WooCommerce stores the wrong author 
					 * for product variations when they are created in the back-office,
					 * so we have to check the parent product's author instead
					 * 
					 */
					$parent_id		= $item_post->post_parent;
					$parent_post	= get_post( $parent_id );
					$vendor_id		= $parent_post->post_author;
				}
				
				/* DEBUG *
				$post_id = $item_post->ID;
				echo 'post ID: ' . $post_id . "<br/>\r\n";
				echo 'vendor ID: ' . $vendor_id . "<br/>\r\n";
				var_dump( $item_post );
				//exit();
				/* */
				
				/** We store a different mp_user_id for production and sandbox environments **/
				$umeta_key = 'mp_user_id';
				if( !mpAccess::getInstance()->is_production() )
					$umeta_key .= '_sandbox';
				if( !get_user_meta( $vendor_id, $umeta_key, true ) ) 
					$is_available = false;
			}
		}
		return $is_available;
	}
	
	/**
	 * Injects some jQuery to improve credit card selection admin
	 *
	 */
	public function admin_options() {
		parent::admin_options();
		?>
		<script>
		(function($) {
			$(document).ready(function() {
				if( $('#woocommerce_mangopay_enabled').is(':checked') ){
					//enable checkboxes
					checkboxSwitch( true );
				} else {
					//disable checkboxes
					checkboxSwitch( false );
				}
				$('#woocommerce_mangopay_enabled').on( 'change', function( e ){
					checkboxSwitch($(this).is(':checked'));
				});
				$('.mp_payment_method.readonly').on('click', function( e ) {
					e.preventDefault();
					//console.log('clicked');
				});
			});
			function checkboxSwitch( current ) {
				//console.log( current );
				if( current ) {
					//console.log( 'yes' );
					$('.mp_payment_method').removeAttr('readonly').removeClass('readonly');
				} else {
					//console.log( 'no' );
					$('.mp_payment_method').attr('readonly', true).addClass('readonly');
				}
			}
		})( jQuery );
		</script>
		<?php
	}

	/**
	 * Display our payment-related fields
	 * 
	 */
	public function payment_fields() {
		
		/** Default selection for the "mp_card_type" drop-down menu **/
		$selected = null;
		$previous_default = false;
		$html = '';
		
		global $woocommerce;
		$only_preauth = false;
		if( 'yes' == $this->get_option( 'enabled_card_registration' ) ) {
			foreach ( $woocommerce->cart->cart_contents as $key => $values ) {
				
				$product_id = $values['product_id'];
				$preauth = get_post_meta( $product_id, '_preautorisation', true );
				if($preauth === "yes"){
					$only_preauth = true;
					break;
				}
				
			}//end foreach
		} // end if
		
		/** if there is at least one product in _preautorisation, lock all other method **/
		if(!$only_preauth){
			/** Check if this was previously selected in the POST or in the post_data **/
			if( !empty( $_POST['mp_card_type'] ) ) {

				$selected = $_POST['mp_card_type'];

			} elseif( !empty( $_POST['post_data'] ) ) {

				parse_str( $_POST['post_data'], $post_data );
				if( !empty( $post_data['mp_card_type'] ) ){
					$selected = $post_data['mp_card_type'];
				}	
			}

			/** Check if at least one credit card is activated **/
			$credit_card_activated = false;
			foreach( $this->available_card_types as $card_type => $card_name ) {
				if( 'BANK_WIRE' == $card_type ) {
					continue;
				}
				if( 'yes' == $this->get_option( 'enabled_' . $card_type ) ) {
					$credit_card_activated = true;
				}
			}

			$html .= '<div class="mp_payment_fields">';

			/** Credit cards selector **/
			if( $credit_card_activated ) {
				if( 
					'yes' == $this->get_option('enabled_BANK_WIRE') ||
					'yes' == $this->get_option('enabled_SOFORT') ||
					'yes' == $this->get_option('enabled_GIROPAY') ||
					'yes' == $this->get_option('enabled_IDEAL') ||
					'yes' == $this->get_option('enabled_PAYLIB') ||
					'yes' == $this->get_option('enabled_MASTERPASS') ||
					'yes' == $this->get_option('enabled_AMEX') ||
					'yes' == $this->get_option( 'enabled_card_registration' )
				) { 
					$previous_default = true;

					$html .= '<div class="mp_pay_method_wrap">';
					$html .= '<div class="mp_card_dropdown_wrap">';
					$html .= '<input type="radio" name="mp_payment_type" class="mp_payment_type card" value="card" checked="checked" />';
					$html .= '<label for="mp_payment_type">'.__( 'Use a credit card', 'mangopay' ).'&nbsp;</label>';
				} else {
					$html .= '<label for="mp_card_type">'.__( 'Credit card type:', 'mangopay' ).'&nbsp;</label>';
				}

				$html .= '<select name="mp_card_type" id="mp_card_type">';

				$available_card_types = apply_filters( 'mangopay_payment_available_card_types', $this->available_card_types );

				foreach( $available_card_types as $type=>$label ) { 
					if( 'yes' == $this->get_option('enabled_'.$type) ) {
						if( 'BANK_WIRE' == $type ){
							continue;
						}
						$html .= '<option value="'.$type.'" '.selected( $type, $selected,false ).'>'. __( $label, 'mangopay' ).'</option>';
					}
				}//end foreach
				$html .= '</select>';
			} // if( $credit_card_activated )

			/** Debit cards selector **/
			if(
				'yes' == $this->get_option('enabled_SOFORT') ||
				'yes' == $this->get_option('enabled_GIROPAY')
			) { 
				if (
					$credit_card_activated ||
					'yes' == $this->get_option('enabled_BANK_WIRE')
				) {

					$html .= '</div>';
					$html .= '<div class="mp_spacer">&nbsp;</div>';
					$html .= '<div class="mp_card_dropdown_wrap">';

					$checked = '';
					if(!$previous_default){
						$checked = 'checked="checked"';
					}
					$html .= '<input type="radio" name="mp_payment_type" class="mp_payment_type directdebitweb" value="directdebitweb" '.$checked.'/>';
					$html .= '<label for="mp_payment_type">'.__( 'Use a direct debit web wire', 'mangopay' ).'</label>';
				} else {
					$html .= '<label for="mp_card_type">'.__( 'Payment type:', 'mangopay' ).'&nbsp;</label>';
				}

				$html .= '<select name="mp_directdebitweb_type" id="mp_directdebitweb_type">';

				$available_directdebit_web_payin_types = apply_filters( 'mangopay_payment_available_directdebit', $this->available_directdebit_web_payin_types );
				foreach( $available_directdebit_web_payin_types as $type=>$label ) {
					if( 'yes' == $this->get_option('enabled_'.$type) ) {
						$html .= '<option value="'.$type.'" '.selected( $type, $selected ).'>'.__( $label, 'mangopay' ).'</option>';
					}
				}

				$html .= '</select>';	
			} // if( SOFORT or GIROPAY activated ) 

			/** Bank wire selector **/
			if( 'yes' == $this->get_option('enabled_BANK_WIRE') ) { 
				if( 
					$credit_card_activated ||
					'yes' == $this->get_option('enabled_SOFORT') ||
					'yes' == $this->get_option('enabled_GIROPAY')
				) {
					$html .= '</div>';
					$html .= '<div class="mp_spacer">&nbsp;</div>';
					$html .= '<div class="mp_card_dropdown_wrap">';
						$html .= '<div class="mp_direct_dropdown_wrap">';
							$html .= '<input id="mp_payment_type_bw" type="radio" name="mp_payment_type" value="bank_wire" />';
							$html .= '<label for="mp_payment_type_bw">'.__( 'Use a direct bank wire', 'mangopay' ).'</label>';
						$html .= '</div>';
					$html .= '</div>';
				} else {
					$html .= '<input type="hidden" name="mp_payment_type" value="bank_wire" />';
					$html .= '<label for="mp_payment_type">'.__( 'Use a direct bank wire', 'mangopay' ).'</label>';
				}
			} // if( BANK WIRE activated )
		}//end if preauth only
		
		
		/** Card registered **/
		if( 'yes' == $this->get_option( 'enabled_card_registration' ) ) {
		
			$preauthpass = false;
			
			foreach($this->available_card_types as $type=>$name) {
				if( 'yes' == $this->get_option( 'enabled_' . $type ) ) {
					$preauthpass = true;
				}
			}
			
			if($preauthpass) {
				
				$html .= '<div class="mp_spacer">&nbsp;</div>';
			
				/** In production info for javascript card registration **/
				$status_prod = "p";
				if( !mpAccess::getInstance()->is_production() ) {
					$status_prod = 's';
				}
				$html .= '<input type="hidden" id="status_p" value="'.$status_prod.'" >';

				/** Error messages **/
				$html .= '<div id="preauth_error_messages" class="preauth_error_messages"></div>';				

				/** Radio selector **/
				$html .= '<div>';
					$html .= '<input type="radio" id="mp_payment_type_registeredcard" name="mp_payment_type" class="mp_payment_type directdebitweb" value="registeredcard" />';
					$html .= '<label for="mp_payment_type"><label>'.__( 'Register a card or use a registered card', 'mangopay' ).'</label></label>';
				$html .= '</div>';

				if( is_user_logged_in() ) {
									
					/** Waiting **/
					$html .= '<div id="registercardlist" class="preauthusercardslist">';
					$html .= '	<span id="registercard_waiting">';
					$html .= '		<label class="preauthusercardslist_label">';
					$html .= 			__('Getting cards...','mangopay');
					$html .= '		</label>';
					$html .= '	</span>';
					$html .= '</div>';

					/** List cards usable of this user **/
					$html .= '<div id="registeredcard_waiting" class="preauthform_waiting">'.__('Processing...','mangopay').'</div>';			

					/** Form to add card **/
					$html .= '<input type="button" value="' . __( 'Add a card', 'mangopay' ) . '" id="toggle_addcard" style="display:none;">';
					$html .= '<div id="registercardform" class="preauthform">';
						
					$html .= '<div class="title_add_card">';
						$html .= '<label class="title_add_card_label">';
							$html .= __('Register a new card:','mangopay');
						$html .= '</label>';
					$html .= '</div>';

					/** CARD TYPE **/
					$html .= '<div class="registered_card_type_div">';
						$html .= '<label>'.__('Card type','mangopay').'</label>';
						$html .= '<select name="registered_card_type" id="registered_card_type" class="registered_card_type">';
						$available_card_types = apply_filters( 'mangopay_payment_available_card_types', $this->available_card_types );
						foreach( $available_card_types as $type=>$label ) {
							if( 'yes' == $this->get_option('enabled_'.$type) ) {
								if( 
									'BANK_WIRE' == $type
									|| 'IDEAL' == $type
									) {
									continue;
								}
								$html .= '<option value="'.$type.'" '.selected( $type, $selected,false ).'>'. __( $label, 'mangopay' ).'</option>';

							} // if( 'yes' == $this->get_option('enabled_'.$type) )
						}
						$html .= '</select>';
					$html .= '</div>';

					/** CREDIT CARD NUMBER **/
					$html .= '<div class="preauth_ccnumber_div">';				
						$html .= '<label>'.__('Card number','mangopay').'</label>';
						$html .= '<input type="text" id="preauth_ccnumber" name="preauth_ccnumber" class="preauth_ccnumber">';
					$html .= '</div>';

					/** CREDIT CARD DATE **/
					$html .= '<div class="preauth_ccdate_div">';
						$html .= '<label>'.__('Expiration date','mangopay').'</label>';				
						//$html .= '<input type="text" id="preauth_ccdate" name="preauth_ccdate" class="preauth_ccdate">';
						//select month
						$html .= '<select name="preauth_ccdate_month" id="preauth_ccdate_month" class="preauth_ccdate_month">';
							for($alpha=1;$alpha<=12;$alpha++){
								$value = $alpha;
								if($alpha<10){
									$value = '0'.$alpha;
								}
								$html .= '<option value="'.$value.'">'. $value .'</option>';
							}
						$html .= '<select>';
						
						//separator
						$html .= '&nbsp;/&nbsp;';
						
						/** select year **/
						$html .= '<select name="preauth_ccdate_year" id="preauth_ccdate_year" class="preauth_ccdate_year">';						
							$this_year = date('Y');							
							$this_year_limit = intval($this_year)+10;							
							for($alpha=$this_year;$alpha<=$this_year_limit;$alpha++){								
								$html .= '<option value="'.substr($alpha,2,2).'">'. $alpha .'</option>';								
							}
						$html .= '<select>';

					$html .= '</div>';

					/** CREDIT CARD CRYPTO **/
					$html .= '<div class="preauth_cccrypto_div">';
						$html .= '<label>'.__('CVV','mangopay').'</label>';
						$html .= '<input type="text" id="preauth_cccrypto" name="preauth_cccrypto" class="preauth_cccrypto">';
					$html .= '</div>';

					$html .= '<div class="clear:both;"></div>';

					/** Other data **/
					$html .= '<input type="hidden" id="user_id" value="'.get_current_user_id().'">';
					$html .= '<input type="hidden" id="order_currency" value="'.get_woocommerce_currency().'">';

					/** Button **/
					$html .= '<div>';
					$html .= '	<button id="save_preauth_card_button" class="button alt" type="button" value="Save card">';
					$html .= 		__('Register card','mangopay');
					$html .= '	</button>';
					$html .= '</div>';
					
				} else { 
					/** Is not logged_in -> ask **/
				
					$html .= '<div id="askforconnect" class="preauthform" style="display:none;width: 100%;text-align: center;">';
					//$url_login = apply_filters('mangopay_url_redirect_pre_register_cards','/wp-login.php?redirect_to='.urlencode(site_url().'/checkout/'));
					$html .= '	<label class="entry-content" style="cursor:pointer;">';
					$html .= 		__('To use this option you need to be connected.','mangopay');
					$html .= '	</label>';
					$html .= '</div>';
				
				} // if(is_user_logged_in())
				
			} // if($preauthpass)
				
		} // if( 'yes' == $this->get_option( 'enabled_card_registration' ) )
		
		$html .= "
			<script>
			(function($) {
				$(document).ready(function() {
					$('#mp_card_type').on('change click', function( e ){
						$('.mp_payment_type.card').attr('checked','checked');
					});
					$('#mp_directdebitweb_type').on('change click', function( e ){
						$('.mp_payment_type.directdebitweb').attr('checked','checked');
					});				
				});
			})( jQuery );
			</script>";

		if(!$only_preauth){
			$html .= "
			<script>
			(function($) {
				$(document).ready(function() {
					$('#mp_card_type').on('change click', function( e ){
						$('.mp_payment_type.card').attr('checked','checked');
					});
					$('#mp_directdebitweb_type').on('change click', function( e ){
						$('.mp_payment_type.directdebitweb').attr('checked','checked');
					});				
				});
			})( jQuery );
			</script>";
		}else{
			$html .= "
			<script>
			(function($) {
				$(document).ready(function() {
					$('#mp_payment_type_registeredcard').attr('checked','checked');
				});
			})( jQuery );
			</script>";
		}
		$html .= '</div><!-- /class="mp_payment_fields" -->';
		
		
		/** Security fields 3DS2 **/
		/* JAVA ENABLED */
		$html .= '<input type="hidden" id="JavaEnabled3ds2" name="JavaEnabled3ds2" >';
		$html .= "<script>
		(function($) {
			$(document).ready(function() {
				if (navigator.javaEnabled() == true){
					$('#JavaEnabled3ds2').val('yes');
				}else{
					$('#JavaEnabled3ds2').val('no');
				}
			});
		})( jQuery );
		</script>";
		/* JAVASCRIPT ENABLED */
		$html .= '<input type="hidden" id="JavascriptEnabled3ds2" name="JavascriptEnabled3ds2" value="no" >';
		$html .= "<script>
		(function($) {
			$(document).ready(function() {
				$('#JavascriptEnabled3ds2').val('yes');
			});
		})( jQuery );
		</script>";
		/* LANGUAGE ENABLED */
		$html .= '<input type="hidden" id="Language3ds2" name="Language3ds2" >';
		$html .= "<script>
		(function($) {
			$(document).ready(function() {
				$('#Language3ds2').val(navigator.language);
			});
		})( jQuery );
		</script>";
		/* COLOR DEPTH ENABLED */
		$html .= '<input type="hidden" id="ColorDepth3ds2" name="ColorDepth3ds2" >';
		$html .= "<script>
		(function($) {
			$(document).ready(function() {
				$('#ColorDepth3ds2').val(window.screen.colorDepth);
			});
		})( jQuery );
		</script>";
		/* ScreenHeight ENABLED */
		$html .= '<input type="hidden" id="ScreenHeight3ds2" name="ScreenHeight3ds2" >';
		$html .= "<script>
		(function($) {
			$(document).ready(function() {
				$('#ScreenHeight3ds2').val(screen.height);
			});
		})( jQuery );
		</script>";	
		/* ScreenWidth ENABLED */
		$html .= '<input type="hidden" id="ScreenWidth3ds2" name="ScreenWidth3ds2" >';
		$html .= "<script>
		(function($) {
			$(document).ready(function() {
				$('#ScreenWidth3ds2').val(screen.width);
			});
		})( jQuery );
		</script>";
		/* TimeZoneOffset ENABLED */
		$html .= '<input type="hidden" id="TimeZoneOffset3ds2" name="TimeZoneOffset3ds2" >';
		$html .= "<script>
		(function($) {
			$(document).ready(function() {
				$('#TimeZoneOffset3ds2').val(new Date().getTimezoneOffset().toString());
			});
		})( jQuery );
		</script>";	
		/* UserAgent ENABLED */
		$html .= '<input type="hidden" id="UserAgent3ds2" name="UserAgent3ds2" >';
		$html .= "<script>
		(function($) {
			$(document).ready(function() {
				$('#UserAgent3ds2').val(navigator.userAgent);
			});
		})( jQuery );
		</script>";				
		
		
		
		$html = apply_filters( 'mangopay_payment_html', $html );
		echo $html;
	}

	/**
	 * Redirects to MP card payment form
	 * 
	 * @param int $order_id
	 * @return array status
	 */
	public function process_payment( $order_id ) {

		/** 
		 * General data
		 * get data used for all the methods 
		 * 
		 */
		
		/** The user id **/
		if( !$wp_user_id = get_current_user_id() ) {
			$wp_user_id	= WC_Session_Handler::generate_customer_id();
		}
		
		/** The order **/
		$order = wc_get_order( $order_id );
		
		/** Return url **/
		//$return_url	= $this->get_return_url( $order );
		
		/** Locale **/
		$return_url	= $this->get_return_url( $order );
		$locale = 'EN';
		list( $locale_minor, $locale_major ) = preg_split( '/_/', get_locale() );
		if( in_array( $locale_minor, $this->supported_locales ) ) {
			$locale = strtoupper( $locale_minor );
		}
		
		/** Template **/
		$mp_template_url = false;
		if( $custom_template_page_id = $this->get_option( 'custom_template_page_id' ) ) {
			if( $url = get_permalink( $custom_template_page_id ) ) {
				if( !preg_match( '/^https/', $url ) ) {
					$url = preg_replace( '/^http/', 'https', $url );
				}
				$mp_template_url = $url;
			}
		}
		
		$mp_payment_type = false;
		if( !empty( $_POST['mp_payment_type'] )){
			$mp_payment_type =  $_POST['mp_payment_type'];
		}

		/** 
		 * Pre-authorization specifics
		 * if card registration is enabled and 
		 * there is at least one product marked for pre-authorization,
		 * the complete payment needs to be done by pre-authorization
		 */
		global $woocommerce;
		$only_preauth = false;
		if( 'yes' == $this->get_option( 'enabled_card_registration' ) ) {
			foreach ( $woocommerce->cart->cart_contents as $key => $values ) {
				
				$product_id = $values['product_id'];
				$preauth = get_post_meta( $product_id, '_preautorisation', true );
				if($preauth === "yes"){
					$only_preauth = true;
					$mp_payment_type = 'preauthorization';
					break;
				}
				
			} // end foreach		
		} // end if
		
		/**
		 * Depending of the selected method, setup appropriate data
		 */
		$method_selection = 'card_default';
		if( $mp_payment_type ) {
			switch( strtolower( $mp_payment_type ) ) {
				
				case 'card':
					$mp_card_type = $_POST['mp_card_type'];
					$method_selection = 'card_default';
				break;
					
				case 'directdebitweb':
					$mp_card_type = $_POST['mp_directdebitweb_type'];
					$method_selection = 'card_default';
				break;
				
				case 'bank_wire':
					$mp_card_type = $_POST['mp_card_type'];
					$method_selection = 'bank_wire';
				break;
				
				case "registeredcard":
					$method_selection = 'registered_card';
				break;
				
				case 'preauthorization':
					$method_selection = 'preauthorization';
				break;
			
				default:
					$mp_card_type = 'CB_VISA_MASTERCARD';
					$method_selection = 'card_default';
				break;
			}
			
		} else {
			$mp_card_type = 'CB_VISA_MASTERCARD';
			$method_selection = 'card_default';
		}
		
		/** SELECT METHOD **/		
		$return = false;
		switch ($method_selection) {
		
			/** Default method **/
			case 'card_default':		
				$return = mpAccess::getInstance()->get_payin_url(
					$order_id,						// Used to fill-in the "Tag" optional info
					$wp_user_id, 					// WP User ID
					round($order->get_total() * 100),	// Amount
					$order->get_currency(),			// Currency
					0,								// Fees
					$return_url,					// Return URL
					$locale,						// For "Culture" attribute
					$mp_card_type,					// CardType
					$mp_template_url				// Optional template URL
				);
			break;
		
			/** Registered Cards **/
			case 'registered_card':				
				if(isset( $_POST['registered_card_selected']) && !empty($_POST['registered_card_selected'])) {
					$card_id = $_POST['registered_card_selected'];
					$return = mpAccess::getInstance()->card_web_payin(
								$order_id,						// Used to fill-in the "Tag" optional info
								$wp_user_id, 					// WP User ID
								round($order->get_total() * 100),	// Amount
								$order->get_currency(),			// Currency
								0,								// Fees
								$return_url,					// Return URL
								$locale,						// For "Culture" attribute
								$mp_template_url,				// Optional template URL
								$card_id
							);
				}
			break;
			
			/** Pre-authorization **/
			case 'preauthorization':								
				if(isset( $_POST['registered_card_selected']) && !empty($_POST['registered_card_selected'])) {
					
					$mp_user_id = mpAccess::getInstance()->get_mp_user_id($wp_user_id);
					$data_cardauth = array();
					$data_cardauth['Tag'] 			= $order_id;
					$data_cardauth['orderid'] 		= $order_id;
					$data_cardauth['AuthorId'] 		= $mp_user_id;
					$data_cardauth['Currency'] 		= $order->get_currency();
					$data_cardauth['Amount'] 		= round($order->get_total() * 100);
					$data_cardauth['CardId'] 		= $_POST['registered_card_selected'];
					$data_cardauth['AddressLine1'] 	= $_POST['billing_address_1'];
					$data_cardauth['AddressLine2'] 	= $_POST['billing_address_2'];
					$data_cardauth['City'] 			= $_POST['billing_city'];
					$data_cardauth['Region'] 		= $_POST['billing_state'];
					$data_cardauth['PostalCode'] 	= $_POST['billing_postcode'];
					$data_cardauth['Country'] 		= $_POST['billing_country'];
					$data_cardauth['SecureModeReturnURL'] = $this->get_return_url( $order );
					
					$return = mpAccess::getInstance()->create_preauthorization($data_cardauth);			
					
					if( !empty( $return['success'] ) ){
						
						/** Still can be a failure **/
						if($return['result']->Status == "FAILED"){
							
							/** Get the error message **/
							$error_message_mangopay = "";
							if( isset($return['result']->ResultMessage) ) {
								$error_message_mangopay = __( 'Pre-authorization error:', 'mangopay' ) . ' ' . 
									__( $return['result']->ResultMessage, 'mangopay' );
							}
							
							/**
							 * If payment fails, we should throw an error and return null:
							 * @see: https://docs.woocommerce.com/document/payment-gateway-api/
							 */
							wc_add_notice( $error_message_mangopay, 'error' );
							return null;
							
						} elseif( $return['result']->Status == "SUCCEEDED" ){
							
							/** Save the pre-authorization information **/
							update_post_meta( $order_id, 'preauthorization_id', $return['result']->Id );
							
							/** Save the transaction id **/
							$transaction_id = $return['result']->Id;
							update_post_meta( $order_id, 'mangopay_payment_type', 'preauth' );
							update_post_meta( $order_id, 'mangopay_payment_ref', $return );
							update_post_meta( $order_id, 'mp_transaction_id', $transaction_id );
							
							//echo "<pre>", print_r("TRANSACTTION ID 3", 1), "</pre>";
							//echo "<pre>", print_r($return, 1), "</pre>";
							//die();	
							
							/**
							 * Array
							(
							    [success] => 1
							    [result] => MangoPay\CardPreAuthorization Object
							        (
							            [AuthorId] => 11815119
							            [DebitedFunds] => MangoPay\Money Object
							                (
							                    [Currency] => EUR
							                    [Amount] => 240
							                )
							
							            [Status] => SUCCEEDED
							            [PaymentStatus] => WAITING
							            [ResultCode] => 000000
							            [ResultMessage] => Success
							            [ExecutionType] => DIRECT
							            [SecureMode] => DEFAULT
							            [CardId] => 51524375
							            [SecureModeNeeded] => 
							            [SecureModeRedirectURL] => 
							            [SecureModeReturnURL] => 
							            [ExpirationDate] => 1543183181
							            [AuthorizationDate] => 1542621581
							            [PaymentType] => CARD
							            [PayInId] => 
							            [Id] => 57644593
							            [Tag] => 2647
							            [CreationDate] => 1542621579
							        )
							
							)
							*/

							/** Return to thank you page **/							
							return array(
								'result'	=> 'success',
								'redirect'	=> $return_url
							);
							
						} elseif( $return['result']->Status == "CREATED" ){
							
							/** Save the pre-authorization information **/
							update_post_meta( $order_id, 'preauthorization_id', $return['result']->Id );
							
							/** Save the transaction id **/
							$transaction_id = $return['result']->Id;
							update_post_meta( $order_id, 'mangopay_payment_type', 'preauth' );
							update_post_meta( $order_id, 'mangopay_payment_ref', $return );
							update_post_meta( $order_id, 'mp_transaction_id', $transaction_id );
							
							//echo "<pre>", print_r("TRANSACTTION ID 2", 1), "</pre>";
							//echo "<pre>", print_r($return, 1), "</pre>";
							//die();							
							
							/** Return to thank you page **/							
							return array(
								'result'	=> 'success',
								'redirect'	=> $return['result']->SecureModeRedirectURL
							);
							
							//echo "<pre>", print_r("STATUS", 1), "</pre>";
							//echo "<pre>", print_r($return, 1), "</pre>";
							//die();
							
							/**
							 * Array
							(
							    [success] => 1
							    [result] => MangoPay\CardPreAuthorization Object
							        (
							            [AuthorId] => 11815119
							            [DebitedFunds] => MangoPay\Money Object
							                (
							                    [Currency] => EUR
							                    [Amount] => 12000
							                )
							
							            [Status] => CREATED
							            [PaymentStatus] => WAITING
							            [ResultCode] => 
							            [ResultMessage] => 
							            [ExecutionType] => DIRECT
							            [SecureMode] => DEFAULT
							            [CardId] => 55045039
							            [SecureModeNeeded] => 1
							            [SecureModeRedirectURL] => https://api.sandbox.mangopay.com:443/Redirect/ACSWithoutValidation?token=b838296ba9b94709a7015a0fd96d6311
							            [SecureModeReturnURL] => http://wc.celyan.comyouplaboom/?preAuthorizationId=55047431
							            [ExpirationDate] => 
							            [AuthorizationDate] => 
							            [PaymentType] => CARD
							            [PayInId] => 
							            [Id] => 55047431
							            [Tag] => 2163
							            [CreationDate] => 1537369286
							        )
							
							)
							*/
							
						}else{
							wc_add_notice( __( 'Unexpected pre-authorization status', 'mangopay' ), 'error' );
							return null;
						}						
						
					}else{				
						/** Get the message **/
						$error_message_mangopay = "";
						if( isset($return['message']) ) {
							$error_message_mangopay = __( 'Pre-authorization error:', 'mangopay' ) . ' ' . 
								__( $return['message'], 'mangopay' );
						}
						
						/**
						 * If payment fails, we should throw an error and return null:
						 * @see: https://docs.woocommerce.com/document/payment-gateway-api/
						 */
						wc_add_notice( $error_message_mangopay, 'error' );
						return null;
					}					
				}else{
					wc_add_notice( __('No registered card selected', 'mangopay'), 'error' );
					return null;
				}
			break;
			
			/** Bank transfer **/
			case 'bank_wire' :			
				return $this->process_bank_wire( $order_id );
			break;
		}
		
		/** AFTER PROCESS **/
		if( isset( $return['error'] ) || false === $return ) {

			if( isset($return['error']) && isset($return['message']) ) {
				$error_message_mangopay = __( 'Payment error:', 'mangopay' ) . ' ' . __( $return['message'], 'mangopay' );
			} else {
				$error_message = __( 'Could not create the MANGOPAY payment URL', 'mangopay' );
				$error_message_mangopay = __( 'Payment error:', 'mangopay' ) . ' ' . $error_message;
			}
			
			/**
			 * If payment fails, we should throw an error and return null:
			 * @see: https://docs.woocommerce.com/document/payment-gateway-api/
			 * 
			 */
			wc_add_notice( $error_message_mangopay, 'error' );
			return null;
		}

		$transaction_id = $return['transaction_id'];
		update_post_meta( $order_id, 'mangopay_payment_type', 'card' );
		update_post_meta( $order_id, 'mangopay_payment_ref', $return );
		update_post_meta( $order_id, 'mp_transaction_id', $transaction_id );

		/** Update the history of transaction ids for this order **/
		if( 
			( $transaction_ids = get_post_meta( $order_id, 'mp_transaction_ids', true ) ) &&
			is_array( $transaction_ids )
		) {
			$transaction_ids[] = $transaction_id;
		} else {
			$transaction_ids = array( $transaction_id );
		}
		update_post_meta( $order_id, 'mp_transaction_ids', $transaction_ids );

		return array(
			'result'	=> 'success',
			'redirect'	=> $return['redirect_url']
		);
	}
	
	/**
	 * Process Direct Bank Wire payment types
	 * 
	 */
	private function process_bank_wire( $order_id ) {

		$order		= wc_get_order( $order_id );

		if( !$wp_user_id = get_current_user_id() )
			$wp_user_id	= WC_Session_Handler::generate_customer_id();
	
		$return_url	= $this->get_return_url( $order );

        $total_order = $order->get_total(); //$order->order_total
        $data_order = $order->get_data();
        $currency = $data_order['currency']; //$order->order_currency
        
		$ref = mpAccess::getInstance()->bankwire_payin_ref(
			$order_id,              // Used to fill-in the "Tag" optional info
			$wp_user_id,            // WP User ID
			round($total_order * 100),   // Amount
			$currency,              // Currency
			0                       // Fees
		);

		if( !$ref ) {
			$error_message = __( 'MANGOPAY Bankwire Direct payin failed', 'mangopay' );
			
			/**
			 * If payment fails, you should throw an error and return null:
			 * @see: https://docs.woocommerce.com/document/payment-gateway-api/
			 * 
			 */
			wc_add_notice( __( 'Payment error:', 'mangopay' ) . ' ' . $error_message, 'error' );
			return null;
		}
		
		$transaction_id = $ref->Id;
		update_post_meta( $order_id, 'mangopay_payment_type', 'bank_wire' );
		update_post_meta( $order_id, 'mangopay_payment_ref', $ref );
		update_post_meta( $order_id, 'mp_transaction_id', $transaction_id );
		
		/** update the history of transaction ids for this order **/
		if(
			( $transaction_ids = get_post_meta( $order_id, 'mp_transaction_ids', true ) ) &&
			is_array( $transaction_ids )
		) {
			$transaction_ids[] = $transaction_id;
		} else {
			$transaction_ids = array( $transaction_id );
		}
		update_post_meta( $order_id, 'mp_transaction_ids', $transaction_ids );
		
		return array(
			'result'	=> 'success',
			'redirect'	=> $return_url
		);
	}
	
	/**
	 * Process refund.
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund.
	 * a passed in amount.
	 *
	 * @param  int $order_id
	 * @param  float $amount
	 * @param  string $reason
	 * @return bool|WP_Error True or false based on success, or a WP_Error object.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		
		if( !$mp_transaction_id = get_post_meta( $order_id, 'mp_transaction_id', true ) ) {
			$this->log( 'Refund Failed: No MP transaction ID' );
			return new WP_Error( 'error', __( 'Refund Failed: No transaction ID', 'woocommerce' ) );
		}
		
		/** If there is a recorded successful transaction id, take it instead **/
		if( $mp_success_transaction_id = get_post_meta( $order_id, 'mp_success_transaction_id', true ) )
			$mp_transaction_id = $mp_success_transaction_id;
		
		$order 		= new WC_Order( $order_id );
        $order_data = $order->get_data();        
		$wp_user_id = $order_data['customer_id']; //$order->customer_user;
		
		$result = mpAccess::getInstance()->card_refund(
			$order_id,				// Order_id
			$mp_transaction_id, 	// transaction_id
			$wp_user_id, 			// wp_user_id
			round($amount * 100),		// Amount
			$order_data['currency'],//$order->order_currency, // Currency
			$reason					// Reason
		);
		
		if( $result && 'SUCCEEDED' == $result->Status ) {

			$this->log( 'Refund Result: ' . print_r( $result, true ) );

			$order->add_order_note( sprintf( 
				__( 'Refunded %s - Refund ID: %s', 'woocommerce' ), 
				( $result->CreditedFunds->Amount / 100 ), 
				$result->Id 
			) );

			return true;
			
		} else {

			$this->log( 'Refund Failed: ' . $result->ResultCode . ' - ' . $result->ResultMessage );
			return new WP_Error( 'error', sprintf( 
				__( 'Refund failed: %s - %s', 'mangopay' ),
				$result->ResultCode,
				$result->ResultMessage 
			) );
		}
	}
}
?>