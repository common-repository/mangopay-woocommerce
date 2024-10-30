<?php
/**
 * MANGOPAY WooCommerce plugin filter and action hooks class
 *
 * @author yann@wpandco.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 **/
class mangopayWCHooks {
	public static function set_hooks( $mangopayWCMain, $mangopayWCAdmin=NULL ) {
		
		/** SITE WIDE HOOKS **/
		
		/**
		 * Site-wide WP hooks
		 *
		 */
		
		/** change wocommerce "support" filter for refunds **/
		add_filter( 'woocommerce_payment_gateway_supports', array($mangopayWCMain,'mangopay_woocommerce_payment_gateway_supports'),10,3);
		
		/** Load i18n **/
		add_action( 'plugins_loaded', array( 'mangopayWCPlugin', 'load_plugin_textdomain' ) );
		
		/** Load payment gateway class **/
		add_action( 'plugins_loaded', array( 'mangopayWCPlugin', 'include_payment_gateway' ) );
		
		/** Trigger event when user becomes vendor **/
		add_action( 'set_user_role', array( $mangopayWCMain, 'on_set_user_role' ), 10, 3 );
		
		/** Trigger event when user registers (front & back-office) **/
		add_action( 'user_register', array( $mangopayWCMain, 'on_user_register' ), 10, 1 ); //<- not working for front-end reg
		/** Front-end registration; previous action on same hook hereunder **/
		//add_action( 'woocommerce_created_customer',		array( $mangopayWCMain, 'on_user_register' ), 11, 1 );
		
		/** API Key (a.k.a. passphrase) encryption **/
		add_filter( 'pre_update_option_' . mangopayWCConfig::OPTION_KEY, array( $mangopayWCMain, 'encrypt_passphrase' ), 10, 2 );
		add_filter( 'option_' . mangopayWCConfig::OPTION_KEY, array( $mangopayWCMain, 'decrypt_passphrase' ), 10, 1 );
		
		/**
		 * Site-wide WC hooks
		 *
		*/
		
		/** Register MP payment gateway **/
		add_filter( 'woocommerce_payment_gateways', array( 'WC_Gateway_Mangopay', 'add_gateway_class' ) );
		
		/** Do wallet transfers when an order gets completed **/
		add_action( 'woocommerce_order_status_completed', array( $mangopayWCMain, 'on_order_completed' ), 15, 1 );
		
		/** when product is pre auth remove all other payment methods **/
		add_filter( 'woocommerce_available_payment_gateways', array( $mangopayWCMain,'remove_payment_methods') );
		
		/** change query to get orders **/
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $mangopayWCMain,'custom_query_for_orders'), 10, 2 );
		
		/**
		 * Site-wide WV hooks
		 *
		*/
		
		/** Trigger event when WV store settings are updated **/
		add_action( 'wcvendors_shop_settings_saved',	array( $mangopayWCMain, 'on_shop_settings_saved' ), 10, 1 );
		
		/** Add the VAT for flat rate shipping **/
		add_filter( 'wcvendors_shipping_due', array( $mangopayWCMain, 'add_vat_to_shipping' ), 90, 5 );
		
		/** Add html in the front for the vendor to have his list of orders in pre auth **/
		add_filter( 'wcvendors_after_dashboard', array( $mangopayWCMain, 'add_preauth_list_vendor' ), 10 );	
		add_action('wcvendors_pro_table_after_order', array( $mangopayWCMain, 'add_preauth_list_vendor_pro' ));

		/** FRONT END HOOKS **/
		
		/**
		 * Front-end WP hooks
		 * 
		 */
		
		/** Payline form template shortcode **/
		add_shortcode( 'mangopay_payform', array( $mangopayWCMain, 'payform_shortcode' ) );
		
		/**
		 * Front-end WC hooks
		 *
		 */
		
		/** Add required fields to the user registration form **/
		add_action( 'woocommerce_register_form_start',	array( $mangopayWCMain, 'wooc_extra_register_fields' ) );
		add_action( 'woocommerce_register_post',		array( $mangopayWCMain, 'wooc_validate_extra_register_fields' ), 10, 3 );
		add_action( 'woocommerce_created_customer',		array( $mangopayWCMain, 'wooc_save_extra_register_fields' ), 10, 1 );
		
        /** buddy press registration form hooks **/
        add_action( 'bp_account_details_fields',	array( $mangopayWCMain, 'wooc_extra_register_fields' ) );/* add fields*/     
        add_action( 'bp_signup_pre_validate',	array( $mangopayWCMain, 'bp_validate_extra_fields' ),10 ); /* validate fields */
        add_action( 'bp_complete_signup', array( $mangopayWCMain, 'bp_save_extra_fields' ), 1); /* save fields */
        
		/** Add required fields on edit-account form **/
		add_action( 'woocommerce_edit_account_form',	array( $mangopayWCMain, 'wooc_extra_register_fields' ) );
		add_filter( 'woocommerce_save_account_details_required_fields', array( $mangopayWCMain, 'wooc_account_details_required' ) );
		add_action( 'woocommerce_save_account_details',	array( $mangopayWCMain, 'wooc_save_extra_register_fields' ), 10, 1 );
        //for edit front
        add_action( 'woocommerce_save_account_details_errors',	array( $mangopayWCMain, 'wooc_validate_extra_register_fields_userfront' ), 10,2);

		/** Add required fields on checkout form **/
		add_filter( 'woocommerce_checkout_fields', array( $mangopayWCMain, 'custom_override_checkout_fields' ), 99999 );
        add_action( 'woocommerce_checkout_process', array( $mangopayWCMain, 'wooc_validate_extra_register_fields_checkout' ));
		add_action( 'woocommerce_after_order_notes', array( $mangopayWCMain, 'after_checkout_fields' ) );
		add_action( 'woocommerce_checkout_update_user_meta', array( $mangopayWCMain, 'wooc_save_extra_register_fields' ) );
		add_filter( 'woocommerce_add_error', array( $mangopayWCMain, 'custom_woocommerce_add_error' ),999,1 );
        
        
		/** Show MP wallets list on my-account page **/
		//add_action( 'woocommerce_before_my_account', 	array( $mangopayWCMain, 'before_my_account' ) );
		
		/** Process order status after order payment completed **/    
        add_action( 'template_redirect', 			array( $mangopayWCMain, 'order_redirect' ), 1, 1 );
        add_action( 'woocommerce_thankyou', 		array( $mangopayWCMain, 'order_received' ), 1, 1 );
        add_filter( 'woocommerce_add_notice', array( $mangopayWCMain, 'intercept_messages_cancel_order' ), 1,1);
        
		/** When billing address is changed by customer **/
		add_action( 'woocommerce_customer_save_address', array( $mangopayWCMain, 'on_shop_settings_saved' ) );
		
		/** When order received, on thankyou page, display bankwire references if necessary **/
		add_action( 'woocommerce_thankyou_mangopay', array( $mangopayWCMain, 'display_bankwire_ref' ), 10, 1 );
		
		/** woocommerce AND WCV PRO filter -- Add option (pre auth) in admin for product  **/
		add_filter( 'product_type_options', array( $mangopayWCMain, 'get_product_type_options_add_preauth' ),10,1);
		/** woocommerce filter -- Add option (pre auth) in admin for product  **/
		add_action('save_post_product', array( $mangopayWCMain, 'product_save_add_preauth_meta'), 10, 3);
		
		/**
		 * Front-end WV hooks
		 *
		*/		
		/** Bank account fields on the shop settings **/
		add_action( 'wcvendors_settings_after_paypal', array( $mangopayWCMain, 'bank_account_form' ) );
		//add_action( 'wcvendors_shop_settings_saved', array( $mangopayWCMain, 'save_account_form' ) );
        add_action( 'wcvendors_shop_settings_saved', array( $mangopayWCMain, 'shop_settings_saved' ),11,1 );
		//add_action( 'wcv_pro_store_settings_saved', array( $mangopayWCMain, 'save_account_form' ) );	// Support for WV Pro version's front-end store dashboard
		add_action( 'wcv_pro_store_settings_saved', array( $mangopayWCMain, 'shop_settings_saved' ),11,1 );
        
		//@see: https://github.com/wcvendors/wcvendors/blob/8443c27704e59fd222ba8d65a6438e0251820910/classes/admin/class-admin-users.php#L382
		//this hook fires up randomly in the WV version we used for development
		//add_action( 'wcvendors_update_admin_user', array( $mangopayWCMain, 'shop_settings_admin_saved' ), 10, 1 );
		//this hook is present instead:
		add_action( 'wcvendors_shop_settings_admin_saved', array( $mangopayWCMain, 'shop_settings_admin_saved' ), 10, 1 );
		
		/** Refuse item button in vendor dashboard order list **/
		add_filter( 'wcvendors_order_actions', array( $mangopayWCMain, 'record_current_order' ), 10, 2 );
		add_filter( 'woocommerce_order_items_meta_display', array( $mangopayWCMain, 'refuse_item_button' ), 10, 2 );
		
        add_filter( 'woocommerce_after_template_part', array( $mangopayWCMain, 'kyc_doc_upload_form_doaction' ), 10, 4 );
		
        add_filter( 'wcvendors_shipping_due', array( $mangopayWCMain, 'partial_preauth_change_shipping_value_wcv' ), 90, 5 );

		/** add custom messages to front dashboard **/
//		add_action( 'wcvendors_before_dashboard', array($mangopayWCMain,'mangopay_messages_notices'),90 );
//		add_action( 'wcv_pro_before_dashboard', array($mangopayWCMain,'mangopay_messages_notices'),90 );
		add_action( 'wp_enqueue_scripts', array( $mangopayWCMain, 'mangopay_messages_notices' ),90 );
		
		/* for new options in mangopay, sometimes we need to initilise them */
		add_action( 'upgrader_process_complete', array( $mangopayWCAdmin, 'change_options_installplugin' ), 90, 3 );
	
		
		/** BACK OFFICE HOOKS **/
		
		/**
		 * Back-office WP hooks
		 *
		*/
		if ( !is_admin() )
			return;
		
		/** Load admin CSS stylesheet **/
		add_action( 'admin_enqueue_scripts', array( $mangopayWCAdmin, 'load_admin_styles' ) );
		
		/** Load admin JS script **/
		add_action( 'admin_enqueue_scripts', array( $mangopayWCAdmin,'enqueue_mango_admin_scripts' ) );
		
		/** Add admin settings menu item **/
		add_action( 'admin_menu',	array( $mangopayWCAdmin, 'settings_menu' ) );
		
		/** Add admin settings options **/
		add_action( 'admin_init',	array( $mangopayWCAdmin, 'register_mysettings' ) );
		
		/** Custom admin notice if config is incomplete **/
		add_action( 'admin_notices', array( $mangopayWCAdmin, 'admin_notices' ) );
		
		/** Failed payouts & refused KYCs admin dashboard widget **/
		add_action( 'wp_dashboard_setup', array( $mangopayWCAdmin, 'add_dashboard_widget' ) );
		
		/** Add required fields to user-edit profile admin page **/
		add_action( 'show_user_profile', 		array( $mangopayWCAdmin, 'user_edit_required' ), 1 );
		add_action( 'edit_user_profile', 		array( $mangopayWCAdmin, 'user_edit_required' ), 1 );
		add_action( 'user_new_form',	 		array( $mangopayWCAdmin, 'user_edit_required' ), 1 );
		
		add_action( 'personal_options_update',	array( $mangopayWCAdmin, 'user_edit_save' ), 100, 1 );
		add_action( 'edit_user_profile_update',	array( $mangopayWCAdmin, 'user_edit_save' ), 100, 1 );
		add_action( 'user_register',			array( $mangopayWCAdmin, 'user_edit_save' ), 9, 1 );
		add_action( 'user_profile_update_errors', array( $mangopayWCAdmin, 'user_edit_checks' ), 10, 3);
			
		/** Custom column to show if users have an MP account **/
		add_filter( 'manage_users_columns', array( $mangopayWCAdmin, 'manage_users_columns' ) );
		add_filter( 'manage_users_sortable_columns', array( $mangopayWCAdmin, 'manage_sortable_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( $mangopayWCAdmin, 'users_custom_column' ), 20, 3 );
		add_filter( 'pre_user_query', array( $mangopayWCAdmin, 'user_column_orderby' ) );
		
		/** Update message **/
		//12/08/2021 emove message
		//add_action( 'admin_notices', array( $mangopayWCAdmin, 'important_admin_notice__success') );
		
		/**
		 * Back-office WC hooks
		 *
		 */
		
		/** Display custom info on the order admin screen **/
		add_action( 'add_meta_boxes', array( $mangopayWCAdmin, 'add_meta_boxes' ), 20 );
		
		/** Register webhook when activating direct bankwire payment **/
		add_action('woocommerce_update_options_payment_gateways_mangopay', array( $mangopayWCAdmin, 'register_all_webhooks' ) );
		
		add_action( 'add_meta_boxes', array( $mangopayWCAdmin,'preauth_add_meta_boxes'), 10, 1 );	
         
		/** for preauth save, update info and save data **/
		add_filter( 'wp_insert_post_data' , array( $mangopayWCAdmin, 'post_save_preauth_process' ), 99, 2 );

		/**
		 * Back-office WV hooks
		 *
		 */
        //add_filter( 'wcvendors_settings_after_shop_description', array( $mangopayWCMain, 'kyc_doc_upload_form_doaction_admin' ), 10);
		add_action( 'wcvendors_settings_after_paypal', array( $mangopayWCMain, 'kyc_doc_upload_form_doaction_admin' ) );
		/**
		 * Add bulk action to pay commissions
		 *
		 */
		add_action( 'admin_footer-woocommerce_page_pv_admin_commissions', array( $mangopayWCAdmin, 'addBulkActionInFooter' ) );	// WV < 2.0
		add_action( 'admin_footer-wc-vendors_page_wcv-commissions', array( $mangopayWCAdmin, 'addBulkActionInFooter' ) );		// WV >= 2.0
		add_action( 'load-woocommerce_page_pv_admin_commissions', array( $mangopayWCAdmin, 'vendor_payouts' ) );				// WV < 2.0
		add_action( 'load-wc-vendors_page_wcv-commissions', array( $mangopayWCAdmin, 'vendor_payouts' ) );						// WV >= 2.0
	}
}
?>