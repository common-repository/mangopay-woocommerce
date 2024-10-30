<?php
/**
 * MANGOPAY WooCommerce plugin admin methods class
 * This class is only loaded and instanciated if is_admin() is true
 *
 * @author yann@wpandco.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 **/
class mangopayWCAdmin{
	
	private $options;
	private $mp;					// This will store our mpAccess class instance
	private $allowed_currencies;	// Loaded from conf.inc.php
	private $mangopayWCMain;		// The mangopayWCMain object that instanciated us
	private $mangopayWCValidation;	// Will hold user profile validation class
  
	/**
	 * Class constructor
	 *
	 */
	public function __construct( $mangopayWCMain=NULL ) {
		$this->mangopayWCMain		= $mangopayWCMain;
		$this->options 				= $mangopayWCMain->options;
		$this->mp 					= mpAccess::getInstance();
		$this->allowed_currencies	= mangopayWCConfig::$allowed_currencies;

		/** Instantiate user profile field validations class **/
		$this->mangopayWCValidation = new mangopayWCValidation( $this );
	}

	public function important_admin_notice__success() {
//		if(!isset($_COOKIE['findforclosing']) || $_COOKIE['findforclosing']!='donotprint'){		
//			echo '<div class="notice notice-success is-dismissible findforclosing">';		
//				$message = $this->mangopayWCMain->mangopay_message_notice();
//				echo '<p>'.__($message, 'mangopay' ).'</p>';
//			echo '</div>';
//		}
	}
		
	/**
	 * Load our custom CSS stylesheet on appropriate admin screens
	 *
	 */
	public function load_admin_styles() {
		
        wp_enqueue_style(
			'mangopay-admin',
			plugins_url( '/css/mangopay-admin.css', dirname( __FILE__ ) ),
			false, 
			$this->options['plugin_version']
		);
        
		$screen = get_current_screen();
		if(
			$screen->id != 'toplevel_page_' . mangopayWCConfig::OPTION_KEY &&
			!( $screen->id == 'woocommerce_page_wc-settings' && isset( $_GET['section'] ) && 'wc_gateway_mangopay'==$_GET['section'] ) &&
			$screen->id != 'user-edit' &&
			!( $screen->id == 'user' && $screen->action == 'add' )
		) {
			return;
		}
		
		/** For datepicker calendar **/
		wp_register_style(
			'jquery-ui',
			plugins_url( '/css/jquery-ui.css', dirname( __FILE__ ) ),
			false, '1.8'
		);
		wp_enqueue_style( 'jquery-ui' );
	}
	
	/**
	 * Load our admin scripts on user edit and create page only
	 *
	 */
	 public function enqueue_mango_admin_scripts(){
	 	
         wp_enqueue_script(
			'wc-admin-kyc-scripts',
			plugins_url( 'js/admin-kyc.js', dirname( __FILE__ ) ) ,
			array( 'jquery'),
         	$this->options['plugin_version']
		);
		 
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        $assets_path          = str_replace( array( 'http:', 'https:' ), '', WC()->plugin_url() ) . '/assets/';
		wp_enqueue_script(
            'wc-country-select2',
             $assets_path.'js/select2/select2' . $suffix . '.js',
            array( 'jquery'),
            $this->options['plugin_version']
        ); 
		  
		$translate_array = array(
			'require_translation' => __( '(required)', 'mangopay' ),
		);
		wp_localize_script( 'wc-admin-kyc-scripts', 'translate_object', $translate_array );		 
		 
	 	$screen = get_current_screen();
	 	if(
 			($screen->id != 'user-edit' && $screen->id != 'profile') 
			&& !( $screen->id == 'user' && $screen->action == 'add' )
			&& !($screen->id == 'toplevel_page_wcv-vendor-shopsettings' && is_admin())
	 	) {
	 		return;
	 	}
	 	
        wp_enqueue_script(
            'wc-country-select',
            plugins_url( 'js/country-select.js', dirname( __FILE__ ) ) ,
            array( 'jquery'),
            $this->options['plugin_version'],
            true
        );
        
        wp_enqueue_script(
            'wc-vendor-banque-form',
            plugins_url( 'js/admin-vendor-banque-form.js', dirname( __FILE__ ) ) ,
            array( 'jquery'),
            $this->options['plugin_version'],
            true
        );
                        
		wp_enqueue_script(
			'wc-admin-scripts',
			plugins_url( 'js/admin-type-user.js', dirname( __FILE__ ) ) ,
			array( 'jquery')
		);
        
        wp_enqueue_script( 
			'wc-state-selector', 
                plugins_url( '/js/front-state-selector.js', dirname( __FILE__ ) ), 
			array( 'jquery')
		);  
	
		/** Initialize our admin script with third-party plugin independent data **/
		$vendor_role = apply_filters('mangopay_vendor_role','vendor');
		$translate_array = array(
			'vendor_role' => $vendor_role,
			'require_translation' => __( '(required)', 'mangopay' ),
		);
		wp_localize_script( 'wc-admin-scripts', 'translate_object', $translate_array );
	}
	
	/**
	 * Add admin settings menu item
	 * This is a WP 'admin_menu' action hook - must be a public method
	 *
	 */
	public function settings_menu() {
		add_menu_page(
		__( 'MANGOPAY', 'mangopay' ),
		__( 'MANGOPAY', 'mangopay' ),
		'manage_options',	// Requires Administrator privilege by default,
		// @see: https://codex.wordpress.org/Roles_and_Capabilities
		mangopayWCConfig::OPTION_KEY,
		array( $this, 'settings_screen' ),
		plugins_url( '/img/mp-icon.png', dirname( __FILE__ ) )
		);
	}
	
	/**
	 * Add admin settings menu screen
	 * @see: https://codex.wordpress.org/User:Wycks/Styling_Option_Pages
	 * This is a WP add_menu_page() callback - must be a public method
	 *
	 */
	public function settings_screen() {
	
		/** Perform a MANGOPAY API connection test **/
		if( isset( $this->options['prod_or_sandbox'] ) ){
			$connection_test_result = $this->mp->connection_test();
		}
	
		/** Display a notice message in admin page header **/
		if( isset( $connection_test_result ) && is_array( $connection_test_result ) ) {
	
			echo '<div class="updated"><p>' .
					__( 'MANGOPAY API connected succesfully!', 'mangopay' ) .
					'</p></div>';
	
		} else {
	
			if( isset( $this->options['prod_or_sandbox'] ) ){
				echo '<div class="error"><p>' .
				__( 'MANGOPAY API Connection problem:', 'mangopay' ) . ' ' .
				__( 'the connection test returned an unexpected result', 'mangopay' ) .
				'</p></div>';
			}
	
			if( mangopayWCConfig::DEBUG && isset( $result ) ){
				var_dump( $result );
			}
		}
		?>
			<div class="wrap">
				<div id="icon-plugins" class="icon32"></div>
				<h2><?php  _e( 'MANGOPAY Settings &amp; Status', 'mangopay' ); ?></h2>
				<div class="description">
				<?php  _e( 'Setup your MANGOPAY credentials &amp; check system configuration.', 'mangopay' ); ?>
				<ul>
					<li><a href="<?php echo mangopayWCConfig::SANDBOX_SIGNUP; ?>"><?php _e( 'Click here to signup for a free MANGOPAY sandbox account', 'mangopay' ); ?></a></li>
					<li><a href="<?php echo mangopayWCConfig::PROD_SIGNUP; ?>"><?php _e( 'Click here to register your marketplace for production', 'mangopay' ); ?></a></li>
				</ul>
				</div>
				
				<br class="clear" />
				
				<form method="post" action="options.php"> 
					<div class="mnt-options">
						<?php settings_fields( 'mpwc-general' ); ?>
						<?php do_settings_sections( mangopayWCConfig::OPTION_KEY ); ?>
						<?php submit_button(); ?>
					</div>
				</form>
				
				<form>
				<div class="metabox-holder">
					<div class="postbox-container mangopay-status">
						
						<!-- /** Standard WP admin block ( div postbox / h3 hndle / div inside / p ) **/ -->
						<div class="health-check-body health-check-debug-tab hide-if-no-js">			
							<div id="health-check-debug" class="health-check-accordion">
								<h3 class="health-check-accordion-heading">
									<button aria-expanded="true" 
											class="health-check-accordion-trigger" 
											aria-controls="health-check-accordion-block-wp-core" 
											type="button">
										<span class="title">
											<?php  _e( 'MANGOPAY status', 'mangopay' ); ?>											
										</span>
									</button>
								</h3>
								<div id="health-check-accordion-block-wp-core" class="health-check-accordion-panel">
									<table class="widefat striped health-check-table" role="presentation">
										<tbody>
											<?php if( isset( $this->options['prod_or_sandbox'] ) ) : ?>
											<?php $this->display_status( $connection_test_result ); ?>
											<?php else : ?>
											<?php _e( 'The MANGOPAY payment gateway needs to be configured.', 'mangopay' ); ?>
											<?php endif; ?>		
										</tbody>
									</table>
								</div>
							</div>
						</div>
			
					</div><!--  // / postbox-container -->
				</div><!--  // / metabox-holder -->
				</form>
			
			</div><!--  // / wrap -->
					<script>
		(function($) {
			$(document).ready(function() {
				$('#UserAgent3ds2').html(navigator.userAgent);
				
				//if it's here, js is enable....
				$('#JavascriptEnabled3ds2_yes').show();
				$('#JavascriptEnabled3ds2_no').hide();
				//java
				if (navigator.javaEnabled() == true){
					$('#JavaEnabled3ds2_yes').show();
				}
				
				var heights = $("ul.mp_checklist li").map(function() {
					return $(this).height();
				}).get(),maxHeight = Math.max.apply(null, heights);
				//$("ul.mp_checklist li").height(maxHeight);
				$("ul.mp_checklist li").css('padding-top',((maxHeight)/2)); //13 px
				$("ul.mp_checklist li").css('padding-bottom',((maxHeight)/2));
				
			});
		})( jQuery );
		</script>
		<?php
	}
	
	/**
	 * Add admin settings options
	 * @see: https://codex.wordpress.org/Creating_Options_Pages
	 * This is a WP 'admin_init' action hook - must be a public method
	 *
	 */
	public function register_mysettings() {
	
		wp_enqueue_script( 'jquery-ui-datepicker' );
		$this->mangopayWCMain->localize_datepicker();
		
		add_settings_section(
		'mpwc-general',								// Section ID
		__( 'General settings', 'mangopay' ),		// Section title
		null,										// Optional callback
		mangopayWCConfig::OPTION_KEY							// Page
		);
	
		add_settings_field(
		'prod_or_sandbox',							// Field ID
		__( 'Production or sandbox', 'mangopay' ),	// Field Title
		array( $this, 'prod_or_sandbox_field_callback' ), // Callback
		mangopayWCConfig::OPTION_KEY,							// Page
		'mpwc-general'								// Section
		);
	
		add_settings_field(
		'sand_client_id',							// Field ID
		__( 'Sandbox Client ID', 'mangopay' ),		// Field Title
		array( $this, 'text_field_callback' ), 		// Callback
		mangopayWCConfig::OPTION_KEY,							// Page
		'mpwc-general',								// Section
		array( 'field_id'=>'sand_client_id', 'label_for'=>__( 'Sandbox Client ID', 'mangopay' ) )
		);
	
		add_settings_field(
		'sand_passphrase',							// Field ID
		__( 'Sandbox API Key', 'mangopay' ),		// Field Title
		array( $this, 'text_field_callback' ), 		// Callback
		mangopayWCConfig::OPTION_KEY,							// Page
		'mpwc-general',								// Section
		array( 'field_id'=>'sand_passphrase', 'label_for'=>__( 'Sandbox API Key', 'mangopay' ) )
		);
	
		add_settings_field(
		'prod_client_id',							// Field ID
		__( 'Production Client ID', 'mangopay' ),	// Field Title
		array( $this, 'text_field_callback' ), 		// Callback
		mangopayWCConfig::OPTION_KEY,							// Page
		'mpwc-general',								// Section
		array( 'field_id'=>'prod_client_id', 'label_for'=>__( 'Production Client ID', 'mangopay' ) )
		);
	
		add_settings_field(
		'prod_passphrase',							// Field ID
		__( 'Production API Key', 'mangopay' ),	// Field Title
		array( $this, 'text_field_callback' ), 		// Callback
		mangopayWCConfig::OPTION_KEY,							// Page
		'mpwc-general',								// Section
		array( 'field_id'=>'prod_passphrase', 'label_for'=>__( 'Production API Key', 'mangopay' ) )
		);
	
		add_settings_field(
		'default_buyer_status',						// Field ID
		__( 'All buyers are', 'mangopay' ),			// Field Title
		array( $this, 'select_field_callback' ), 	// Callback
		mangopayWCConfig::OPTION_KEY,							// Page
		'mpwc-general',								// Section
		array( 'field_id'=>'default_buyer_status', 'label_for'=>__( 'All buyers are', 'mangopay' ) )
		);
	
		add_settings_field(
		'default_vendor_status',					// Field ID
		__( 'All vendors are', 'mangopay' ),		// Field Title
		array( $this, 'select_field_callback' ), 	// Callback
		mangopayWCConfig::OPTION_KEY,							// Page
		'mpwc-general',								// Section
		array( 'field_id'=>'default_vendor_status', 'label_for'=>__( 'All vendors are', 'mangopay' ) )
		);
	
		add_settings_field(
			'default_business_type',					// Field ID
			__( 'All businesses are', 'mangopay' ),		// Field Title
			array( $this, 'select_field_callback' ), 	// Callback
			mangopayWCConfig::OPTION_KEY,							// Page
			'mpwc-general',								// Section
			array( 'field_id'=>'default_business_type', 'label_for'=>__( 'All businesses are', 'mangopay' ) )
		);
	
		/* not yet enabled *
			add_settings_field(
					'per_item_wf',								// Field ID
					__( 'Enable vendor item-level order management', 'mangopay' ),	// Field Title
					array( $this, 'checkbox_field_callback' ), 	// Callback
					mangopayWCConfig::OPTION_KEY,							// Page
					'mpwc-general',								// Section
					array( 'field_id'=>'per_item_wf', 'label_for'=>__( 'Enable vendor item-level order management', 'mangopay' ) )
			);
		/* */
		
		add_settings_field(
			'webhook_key',
			' ',
			array( $this, 'hidden_field_callback' ),
			mangopayWCConfig::OPTION_KEY,
			'mpwc-general',
			array( 'field_id'=>'webhook_key' )
		);
		
		add_settings_field(
			'plugin_version',
			' ',
			array( $this, 'hidden_field_callback' ),
			mangopayWCConfig::OPTION_KEY,
			'mpwc-general',
			array( 'field_id'=>'plugin_version' )
		);
		
		register_setting(
			'mpwc-general', 							// Section (Option group)
			mangopayWCConfig::OPTION_KEY,				// Page (Option name)
			array( $this, 'sanitize_settings' )
		);
	}
	
	/**
	 * Sanitize plugin settings fields
	 * @param array $settings
	 * @return array $settings
	 * This is a WP register_setting() callback - must be a public method
	 */
	public function sanitize_settings( $settings ) {
	
		$settings['prod_or_sandbox']	= ( 'prod'==$settings['prod_or_sandbox']?'prod':'sandbox' );
		$settings['sand_client_id']		= sanitize_text_field( $settings['sand_client_id'] );
		$settings['sand_passphrase']	= sanitize_text_field( $settings['sand_passphrase'] );
		return $settings;
	}
	
	/**
	 * Display a hidden field in the plugin settings screen
	 * 
	 * @param array $args
	 */
	public function hidden_field_callback( $args ) {
		$html = '';
		$f_id	= $args['field_id'];
		$options = $this->options;
		$value = '';
		if( isset( $options[ $f_id ] ) )
			$value	= esc_attr( $options[ $f_id ] );
		
		$html .= '<input type="hidden" id="' . $f_id . '" ' .
				'name="' . mangopayWCConfig::OPTION_KEY . '[' . $f_id . ']" ' .
				'value="' . $value . '" ' .
				'/>';
		
		echo $html;
	}
	
	/**
	 * Display settings radio field to select prod or sandbox
	 * This is a WP add_settings_field() callback - must be a public method
	 *
	 */
	public function prod_or_sandbox_field_callback() {
	
		$options = $this->options;
		$html = '';
	
		$current = isset($options['prod_or_sandbox'])?$options['prod_or_sandbox']:'sandbox';
			
		$html .= '<input type="radio" id="prod_or_sandbox_prod" name="' . mangopayWCConfig::OPTION_KEY . '[prod_or_sandbox]" value="prod"' . checked( 'prod', $current, false ) . '/>';
		$html .= '<label for="prod_or_sandbox_prod">' . __( 'Production', 'mangopay' ) . '</label> ';
			
		$html .= '<input type="radio" id="prod_or_sandbox_sandbox" name="' . mangopayWCConfig::OPTION_KEY . '[prod_or_sandbox]" value="sandbox"' . checked( 'sandbox', $current, false ) . '/>';
		$html .= '<label for="prod_or_sandbox_sandbox">' . __( 'Sandbox', 'mangopay' ) . '</label>';
	
		$html .= "
			<script>
			(function($) {
				$(document).ready(function() {
					if( $('#prod_or_sandbox_prod').is(':checked') ){
						envSwitch( 'prod' );
					} else {
						envSwitch( 'sandbox' );
					}
					$('#prod_or_sandbox_prod').on( 'change', function( e ){
						envSwitch($(this).val());
					});
					$('#prod_or_sandbox_sandbox').on( 'change', function( e ){
						envSwitch($(this).val());
					});
				});
				function envSwitch( current ) {
					switch( current ) {
						case 'prod':
							$('#sand_client_id').closest('tr').hide();
							$('#sand_passphrase').closest('tr').hide();
							$('#prod_client_id').closest('tr').show();
							$('#prod_passphrase').closest('tr').show();
							break;
						case 'sandbox':
							$('#sand_client_id').closest('tr').show();
							$('#sand_passphrase').closest('tr').show();
							$('#prod_client_id').closest('tr').hide();
							$('#prod_passphrase').closest('tr').hide();
							break;
					}
				}
			})( jQuery );
			</script>
		";
	
		echo $html;
	}
	
	/**
	 * Display settings text fields
	 * @param array $args
	 * This is a WP add_settings_field() callback - must be a public method
	 *
	 */
	public function text_field_callback( $args ) {
	
		$type = 'text';
		$value = '';
		$options = $this->options;
		$html = '';
		$f_id	= $args['field_id'];
		if( isset( $options[ $f_id ] ) ){
			$value	= trim(esc_attr( $options[ $f_id ] ));
		}
	
		/** Redact passphrases API Key **/
		if( preg_match( '/pass/', $f_id ) ) {
			$type	= 'password';
			$value	= str_repeat( '*', strlen( $value ) );
		}
	
		$html .= '<input type="' . $type . '" id="' . $f_id . '" ' .
				'name="' . mangopayWCConfig::OPTION_KEY . '[' . $f_id . ']" ' .
				'value="' . $value . '" ' .
				'class="regular-text ltr" />';
	
		echo $html;
	}
	
	/**
	 * Display settings checkbox fields
	 * @param array $args
	 * This is a WP add_settings_field() callback - must be a public method
	 *
	 */
	public function checkbox_field_callback( $args ) {
	
		if( !isset( $options['per_item_wf'] ) )
			$options['per_item_wf'] = '';
	
		$options = $this->options;
		$html = '';
		$f_id	= $args['field_id'];
		$current = isset($options[ $f_id ])?$options[ $f_id ]:'';
	
		$html .= '<input type="checkbox" id="' . $f_id . '" ' .
				'name="' . mangopayWCConfig::OPTION_KEY . '[' . $f_id . ']" ' .
				'value="yes" ' .
				checked( 'yes', $current, false ) .
				' class="" />';
	
		echo $html;
	}
	
	/**
	 * Display settings select fields
	 * @param array $args
	 * This is a WP add_settings_field() callback - must be a public method
	 *
	 */
	public function select_field_callback( $args ) {
	
		$options = $this->options;
	
		if( !isset( $options['default_buyer_status'] ) )
			$options['default_buyer_status'] = 'individuals';
	
		if( !isset( $options['default_vendor_status'] ) )
			$options['default_vendor_status'] = 'either';
	
		if( !isset( $options['default_business_type'] ) )
			$options['default_business_type'] = 'either';
	
		$html = '';
		$f_id	= $args['field_id'];
		$current = isset($options[ $f_id ])?$options[ $f_id ]:'';
	
		$html .= '<select id="' . $f_id . '" ' .
				'name="' . mangopayWCConfig::OPTION_KEY . '[' . $f_id . ']">';
	
		if( 'default_business_type' == $f_id ) {
			$html .= "<option value='organisations' " . selected( 'organisations', $current, false ) . '>' .
					__( 'Organisations', 'mangopay' ) . '</option>';
			$html .= "<option value='soletraders' " . selected( 'soletraders', $current, false ) . '>' .
					__( 'Soletraders', 'mangopay' ) . '</option>';
		} else {
			$html .= "<option value='individuals' " . selected( 'individuals', $current, false ) . '>' .
					__( 'Individuals', 'mangopay' ) . '</option>';
		}
		$html .= "<option value='businesses' " . selected( 'businesses', $current, false ) . '>' .
				__( 'Businesses', 'mangopay' ) . '</option>';
		$html .= "<option value='either' " . selected( 'either', $current, false ) . '>' .
				__( 'Either', 'mangopay' ) . '</option>';
	
		$html .= '</select>';
	
		echo $html;
	}
	
	/**
	 * Check that a valid multivendor plugin is present
	 * 
	 * @param string $wp_plugin_path
	 * @return boolean
	 * 
	 */
	public function test_plugin_multivendor_active( $wp_plugin_path ){    
		$plugin_active = apply_filters(
			'mangopay_vendors_plugin_test',
			is_plugin_active( $wp_plugin_path )
		);
		return $plugin_active;
	}
	
	/**
	 * Test if all vendors have their KYC validated
	 * @return boolean
	 */
	private function test_all_vendors_kyc(){

		/** get all vendors **/
		$args = array( 'role' => 'vendor'); 
		$list_vendors = get_users( $args );

		if($list_vendors){
			foreach($list_vendors as $vendor){		
				$result = $this->mangopayWCMain->test_vendor_kyc($vendor->ID);
				
				/** no account in WP db found**/
				if($result === "no_account_bd"){
					/** TODO **/					
					return false;
				}
				/** no account in Mangopay found**/
				if($result === "no_count_mp_found"){
					/** TODO **/
					return false;
				}
				
				if(!$result){
					return false;
				}
			}
		}		
		return true;		
	}
	
	/**
	 * Test if all vendors have a bank account
	 * @return boolean
	 */
	private function test_all_vendors_bankaccount(){

		/** get all vendors **/
		$args = array( 'role' => 'vendor'); 
		$list_vendors = get_users( $args );

		if($list_vendors){
			foreach($list_vendors as $vendor){		
				$result = mpAccess::getInstance()->has_bank_account($vendor->ID);
				if(!$result){
					return false;
				}
			}
		}		
		return true;		
	}
	
	private function test_all_vendors_personnal_informations(){

		/** get all vendors **/
		$args = array( 'role' => 'vendor'); 
		$list_vendors = get_users( $args );

		if($list_vendors){
			foreach($list_vendors as $vendor){		
				$result = mpAccess::getInstance()->has_vendor_completed_informations($vendor->ID);
				if(!$result){
					return false;
				}
			}
		}		
		return true;		
	}
		
	/**
	 * 
	 * @return boolean
	 */
	private function test_all_vendors_compagnynumber(){
		/** get all vendors **/
		$args = array( 'role' => 'vendor'); 
		$list_vendors = get_users( $args );

		if($list_vendors){
			foreach($list_vendors as $vendor){		
				
				/** only for businness **/
				$user_mp_status = get_user_meta($vendor->ID, 'user_mp_status', true);
				$user_business_type = get_user_meta($vendor->ID, 'user_business_type', true);
				if($user_mp_status == 'business' && $user_business_type == 'business'){				
					$compagny_number = get_user_meta( $vendor->ID, 'compagny_number', true );
					if(!$compagny_number){
						return false;
					}
					/** Test if company number is in pattern **/
					$result = mpAccess::getInstance()->check_company_number_patterns($compagny_number);				
					if($result == 'nopattern'){
						return false;
					}
				}
			}
		}		
		return true;
	}
	
	private function test_all_owners_termsandconditions_validated(){
		/** get all vendors **/
		$args = array( 'role' => 'vendor'); 
		$list_vendors = get_users( $args );

		if($list_vendors){
			foreach($list_vendors as $vendor){
				
				
				$termsconditions = get_user_meta($vendor->ID, 'termsconditions', true );
				if(!$termsconditions){
					return false;
				}
				
//				$umeta_key = 'mp_user_id';
//				if( !$this->mp->is_production() ){
//					$umeta_key .= '_sandbox';
//				}		
//				$mp_vendor_id = get_user_meta(  $vendor->ID, $umeta_key, true );			
//				if($mp_vendor_id){
//					$mp_user_data = mpAccess::getInstance()->get_user_properties($mp_vendor_id);										
//					if(
//						empty($mp_user_data) 
//						|| empty($mp_user_data->TermsAndConditionsAccepted)
//						|| ($mp_user_data->TermsAndConditionsAccepted != 1 && $mp_user_data->TermsAndConditionsAccepted != "1")
//					){
//						return false;
//					}
//				}				
			}
		}		
		return true;
	}	
	
	private function get_php_version(){
		return phpversion();
	}
	
	private function get_curl_version(){
		$version = curl_version();
		return $version['version'];
	}
	
	/**
	 * 
	 * @return boolean
	 */
	private function test_all_vendors_ubodeclaration(){
		/** get all vendors **/
		$args = array( 'role' => 'vendor'); 
		$list_vendors = get_users( $args );

		if($list_vendors){
			foreach($list_vendors as $vendor){
				
				//can return "true" "false" "na"
				$result = $this->mp->test_vendor_ubo($vendor->ID);
				if($result === false){
					return false;
				}
				
			}
		}		
		return true;
	}	
	
	/**
	 * 
	 * @return boolean
	 */
	private function test_all_vendors_tc(){
		/** get all vendors **/
		$args = array( 'role' => 'vendor'); 
		$list_vendors = get_users( $args );

		if($list_vendors){
			foreach($list_vendors as $vendor){		
				$tc = get_user_meta( $vendor->ID, 'termsconditions', true );				
				if(!$tc || trim($tc)==''){
					return false;
				}
			}
		}		
		return true;
	}	
	
	
	
	/**
	 * Part of the health-check display
	 *
	 * @param unknown $connection_test_result
	 */
	public function display_status( $connection_test_result ) {
		$status = $this->mp->getStatus( $this->mangopayWCMain );
		$plugin_data = get_plugin_data( dirname( dirname( __FILE__ ) ) . '/mangopay-woocommerce.php' );
        
        /** Test for an activated multivendor plugin **/
		$wc_plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . mangopayWCConfig::WC_PLUGIN_PATH );
		$wp_plugin_path = apply_filters( 
			'mangopay_vendors_plugin_path',
			mangopayWCConfig::WV_PLUGIN_PATH 
		);
        $plugin_active = $this->test_plugin_multivendor_active($wp_plugin_path);
        
		if( $plugin_active ) {
			$wv_plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $wp_plugin_path );
		} else {
			$wv_plugin_data = NULL;
		}
		$currency = null;
		if( function_exists( 'get_woocommerce_currency' ) )
			$currency	= get_woocommerce_currency();
		$guest_checkout = get_option( 'woocommerce_enable_guest_checkout' ) == 'yes' ? true : false;
		if( 'prod' == $this->options['prod_or_sandbox'] ) {
			$which_passphrase = 'prod_passphrase';
		} else {
			$which_passphrase = 'sand_passphrase';
		}
		?>
			
		<!-- CURRENT VERSION -->
		<tr>
			<td>
				<?php _e( 'Current environment:', 'mangopay' ); ?>
			</td>
			<td>
				<?php 
				if( $status['environment'] ) :
					_e( 'Production', 'mangopay' ); 
				else : 
					_e( 'Sandbox', 'mangopay' );
				endif; 
				?>
			</td>
		</tr>	
		<!-- CLIENT ID -->
		<tr>
			<td>
				<?php _e( 'Client ID:', 'mangopay' ); ?>
			</td>
			<td>
				<?php 
				if( $status['client_id'] ) : 
					echo '<span class="mp_checklist_status success">';
					_e( 'Present!', 'mangopay' ); 
					echo '</span>';
				else :
					if( isset( $status['client_id_error'] ) && $status['client_id_error'] != '' ) :
						$title_mp_client_id_error = 'title="' . htmlspecialchars( $status['client_id_error'] ) . '"';
					else:
						$title_mp_client_id_error = '';	
					endif
				?>
					<span class="mp_checklist_status failure" <?php echo $title_mp_client_id_error; ?>>
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- API KEY -->
		<tr>
			<td>
				<?php _e( 'API Key:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( isset($this->options[$which_passphrase]) && !empty($this->options[$which_passphrase]) ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Present!', 'mangopay' ); ?>
					</span>
				<?php else : ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Missing :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- Mangopay API -->
		<tr>
			<td>
			<?php _e( 'MANGOPAY API Connection:', 'mangopay' ); ?>
			</td>
			<td>
			<?php if( $status['loaded'] || $connection_test_result ) : ?>
				<span class="mp_checklist_status success">
				<?php _e( 'Success!', 'mangopay' ); ?>
				</span>
			<?php else: ?>
				<span class="mp_checklist_status failure">
				<?php _e( 'Failure :(', 'mangopay' ); ?>
				</span>
			<?php endif; ?>
			</td>
		</tr>
		<!-- MANGOPAY-WooCommerce plugin version -->
		<tr>
			<td>
				<?php _e( 'MANGOPAY-WooCommerce plugin version:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( isset( $plugin_data['Version'] ) ) : ?>
					<span class="mp_checklist_status success">
					<?php echo $plugin_data['Version']; ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Unknown', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- WooCommerce plugin -->
		<tr>
			<td>
				<?php _e( 'Required WooCommerce plugin present &amp; activated:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( is_plugin_active( mangopayWCConfig::WC_PLUGIN_PATH ) ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- WooCommerce plugin version -->
		<tr>
			<td>
				<?php _e( 'WooCommerce plugin version:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( $wc_plugin_data && is_array( $wc_plugin_data ) && isset( $wc_plugin_data['Version'] ) ) : ?>
					<span class="mp_checklist_status success">
					<?php echo $wc_plugin_data['Version']; ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Unknown', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- MANGOPAY enabled as a WooCommerce payment gateway -->
		<tr>
			<td>
				<a href="?page=wc-settings&tab=checkout&section=wc_gateway_mangopay">
				<?php _e( 'MANGOPAY enabled as a WooCommerce payment gateway:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( 'yes' == $status['enabled'] ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Enabled', 'woocommerce' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Disabled', 'woocommerce' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- PAEYMENT METHOD ENABLE -->
		<tr>
			<td>
				<a href="?page=wc-settings&tab=checkout&section=wc_gateway_mangopay">
				<?php _e( 'At least one card type or payment method is enabled:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( $status['card_enabled'] ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		
		<!-- bankwire enabled -->
		<?php if( $status['bankwire_enabled'] ): ?>
		<tr>
			<td>
				<a href="?page=wc-settings&tab=checkout&section=wc_gateway_mangopay">
				<?php _e( 'Bankwire Direct payment is enabled:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<span class="mp_checklist_status success">
				<?php _e( 'Enabled', 'woocommerce' ); ?>
				</span>
			</td>
		</tr>
		<tr>
			<td>
				<a href="?page=wc-settings&tab=checkout&section=wc_gateway_mangopay">
				<?php _e( 'The webhook for Bankwire Direct is registered:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( $status['webhook_status'] ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php endif; ?>
		
		<!-- WooCommerce guest checkout should be disabled -->
		<tr>
			<td>
				<a href="?page=wc-settings&tab=checkout">
				<?php _e( 'WooCommerce guest checkout should be disabled', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( !$guest_checkout ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Disabled', 'woocommerce' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Enabled', 'woocommerce' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- Current WooCommerce currency is supported by MANGOPAY -->
		<tr>
			<td>
				<a href="?page=wc-settings&tab=general">
				<?php _e( 'Current WooCommerce currency is supported by MANGOPAY:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( in_array( $currency, $this->allowed_currencies ) ) : ?>
					<span class="mp_checklist_status success">
					<?php echo $currency; ?>
					<?php _e( 'Supported', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php echo $currency; ?>
					<?php _e( 'Unsupported', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- WC-Vendors present -->
		<tr>
			<td>
				<?php printf(  
					__( 'Required %1$s plugin present &amp; activated:', 'mangopay' ),
					apply_filters(
						'mangopay_vendors_plugin_name',
						'WC-Vendors'
 					)	
				); ?>
			</td>
			<td>
				<?php                                
                if( $plugin_active ) :                     
                ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- WC-Vendors VERSION -->
		<tr>
			<td>
				<?php printf(
					__( '%1$s plugin version:', 'mangopay' ),
					apply_filters(
						'mangopay_vendors_plugin_name',
						'WC-Vendors'						
					)
				); ?>
			</td>
			<td>
				<?php if( $wv_plugin_data && is_array( $wv_plugin_data ) && isset( $wv_plugin_data['Version'] ) ) : ?>
					<span class="mp_checklist_status success">
					<?php echo $wv_plugin_data['Version']; ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Unknown', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- PHP cypher library available -->
		<tr>
			<td>
				<?php _e( 'PHP cypher library available:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( function_exists("mcrypt_encrypt") || function_exists('openssl_encrypt') ) : ?>
					<?php if( function_exists('openssl_encrypt') ) : ?>
						<span class="cypher_lib">openssl</span>
					<?php endif; ?>
					<?php if( function_exists('openssl_encrypt') ) : ?>
						<span class="cypher_lib">mcrypt</span>
					<?php endif; ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Unavailable. Your API Key will be stored as clear text in the WordPress database.', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- PHP cUrl library available -->
		<tr>
			<td>
				<?php _e( 'PHP cUrl library available:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( function_exists('curl_version') ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure.', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- Keyfile directory is writable -->
		<tr>
			<td>
				<?php _e( 'Keyfile directory is writable:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( is_writable( dirname( $this->mp->get_tmp_dir() ) ) ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- Temporary directory is writable -->
		<tr>
			<td>
				<?php _e( 'Temporary directory is writable:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( is_writable( $this->mp->get_tmp_dir() ) ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- All active vendors have a MANGOPAY account -->
		<tr>
			<td>
				<a href="users.php?role=vendor">
				<?php _e( 'All active vendors have a MANGOPAY account:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( !$this->vendors_without_account() ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- All products are assigned to a vendor -->
		<!-- test if all products have a vendor assign (and not an admin or other) --> 
		<tr>
			<td>
				<a href="edit.php?post_type=product">
				<?php _e( 'All products are assigned to a vendor:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( $this->products_with_vendors() == true ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>	
		<!-- All vendors have bank account -->
		<tr>
			<td>
				<a href="users.php?role=vendor">
				<?php _e( 'All vendors have a bank account:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( $this->test_all_vendors_bankaccount() == true ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>		
		<tr>
			<td>
				<a href="users.php?role=vendor">
				<?php _e( 'All vendors have their personnal informations completed:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( $this->test_all_vendors_personnal_informations() == true ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- All vendors are KYC-approved -->
		<tr>
			<td>
				<a href="users.php?role=vendor">
				<?php _e( 'All vendors are KYC/UBO approved:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( $this->test_all_vendors_kyc() == true ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- All vendors have terms and conditions accepted -->
		<tr>
			<td>
				<a href="users.php?role=vendor">
				<?php _e( 'All vendors have accepted terms and conditions:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( $this->test_all_owners_termsandconditions_validated() == true ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<!-- All vendors that operate as businesses have a company number -->
		<tr>
			<td>
				<a href="users.php?role=vendor">
				<?php _e( 'All vendors that operate as businesses have a company number:', 'mangopay' ); ?>
				</a>
			</td>
			<td>
				<?php if( $this->test_all_vendors_compagnynumber() == true ) : ?>
					<span class="mp_checklist_status success">
					<?php _e( 'Success!', 'mangopay' ); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>			
		<!-- PHP Version -->
		<tr>
			<td>
				<?php _e( 'PHP Version:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( $this->get_php_version() != false ) : ?>
					<span class="mp_checklist_status success">
					<?php echo $this->get_php_version(); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>		
		<!-- Curl Version -->
		<tr>
			<td>
				<?php _e( 'Curl Version:', 'mangopay' ); ?>
			</td>
			<td>
				<?php if( $this->get_curl_version() != false ) : ?>
					<span class="mp_checklist_status success">
					<?php echo $this->get_curl_version(); ?>
					</span>
				<?php else: ?>
					<span class="mp_checklist_status failure">
					<?php _e( 'Failure :(', 'mangopay' ); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>		
		<!-- Custom template -->
		<tr>
			<td>
				<?php _e( 'Custom template:', 'mangopay' ); ?>		
			</td>
			<td>
				<?php
				$custom_template = __( 'No', 'mangopay' );
				$wc_option = get_option('woocommerce_mangopay_settings');
				if($wc_option 
					&& isset($wc_option['custom_template_page_id'])
					&& $wc_option['custom_template_page_id']!=""
					&& $wc_option['custom_template_page_id']!=0
				):
					$custom_template = __( 'Yes', 'mangopay' );
				endif;
				echo $custom_template;
				?>
			</td>
		</tr>		
		<!-- Enabled MANGOPAY Payment Types -->
		<tr>
			<td>
				<?php _e( 'Enabled MANGOPAY Payment Types:', 'mangopay' ); ?>
			</td>
			<td>
				<?php
				$list_mangopay_payements = array();
				$wc_options = get_option('woocommerce_mangopay_settings');
				if($wc_options ):

					$list_names_methods = array(
					'enabled_CB_VISA_MASTERCARD'	=> 'CB/Visa/Mastercard',
					'enabled_MAESTRO'				=> 'Maestro', 
					'enabled_AMEX'					=> 'American Express (beta)', 
					'enabled_BCMC'					=> 'Bancontact/Mister Cash', 
					'enabled_P24'					=> 'Przelewy24', 
					'enabled_DINERS'				=> 'Diners', 
					'enabled_PAYLIB'				=> 'PayLib',
					'enabled_IDEAL'					=> 'iDeal', 
					'enabled_MASTERPASS'			=> 'MasterPass',
					'enabled_BANK_WIRE'				=> 'Bankwire Direct',
					'enabled_SOFORT'				=> 'Sofort',
					'enabled_GIROPAY'				=> 'Giropay'
					);					
					foreach($wc_options as $wc_option_key=>$wc_option_value):
						if(
							isset($list_names_methods[$wc_option_key]) 
							&& $wc_option_value == "yes"
						):
							$list_mangopay_payements[] = $list_names_methods[$wc_option_key];	
						endif;
					endforeach;											
				endif;

				echo implode(', ',$list_mangopay_payements);
				?>
			</td>
		</tr>		
		<!-- Other enabled payment gateways -->
		<tr>
			<td>
				<?php _e( 'Other enabled payment gateways:', 'mangopay' ); ?>
			</td>
			<td>
				<?php
				$gateways = WC()->payment_gateways->get_available_payment_gateways();
				$options_gateways = array();
				foreach ( $gateways as $id => $gateway ):
					$options_gateways[$id] = $gateway->get_method_title();
				endforeach;		
				echo implode(', ',$options_gateways);
				?>
			</td>
		</tr>	
		<!-- Wordpress Version -->
		<tr>
			<td>
				<?php _e( 'WordPress Version:', 'mangopay' ); ?>
			</td>
			<td>
				<?php
				global $wp_version;
				echo $wp_version;
				?>
			</td>
		</tr>		
		<!-- Wordpress Theme -->
		<tr>
			<td>
				<?php _e( 'Wordpress Theme:', 'mangopay' ); ?>	
			</td>
			<td>
				<?php
				$the_theme = wp_get_theme();
				echo esc_html( $the_theme->get( 'Name' ) ).' ('.esc_html( $the_theme->get( 'Version' ) ).')';
				?>
			</td>
		</tr>
		
		<!-- SECTION HTML ---------------------------------->
					</tbody>
				</table>
			</div>
		</div>
		<div id="health-check-debug" class="health-check-accordion">
		<h3 class="health-check-accordion-heading">
			<button aria-expanded="true" 
					class="health-check-accordion-trigger" 
					aria-controls="health-check-accordion-block-wp-core" 
					type="button">
				<span class="title">
					<?php _e('3DS2 Browser Information','mangopay'); ?>								
				</span>
			</button>
		</h3>
		<div id="health-check-accordion-block-wp-core" class="health-check-accordion-panel">
			<table class="widefat striped health-check-table" role="presentation">
				<tbody>
				<!-- Browser IP -->
				<tr>
					<td>
						<?php _e( 'Browser IP:', 'mangopay' ); ?>	
					</td>
					<td>
						<?php echo $_SERVER['REMOTE_ADDR']; ?>
					</td>
				</tr>
				<!-- Proxy IP -->
				<tr>
					<td>
						<?php _e( 'Proxy IP:', 'mangopay' ); ?>
					</td>
					<td>
					<?php 
					if( isset($_SERVER['HTTP_X_FORWARDED_FOR']) ) :
						echo $_SERVER['HTTP_X_FORWARDED_FOR'];
					else:
						_e( 'No Proxy IP', 'mangopay' );
					endif; 
					?>
					</td>
				</tr>
				<!-- UserAgent -->
				<tr>
					<td>
						<?php _e( 'UserAgent:', 'mangopay' ); ?>
					</td>
					<td>
						<?php //will be filled by js ?>
						<span class="mp_checklist_status " id="UserAgent3ds2"></span>
					</td>
				</tr>
				<!-- Javascript Enabled -->
				<tr>
					<td>
						<?php _e( 'Javascript Enabled:', 'mangopay' ); ?>
					</td>
					<td>
						<span class="mp_checklist_status " 
								id="JavascriptEnabled3ds2_yes" 
								style="display: none;">
							  <?php _e( 'Yes', 'mangopay' ); ?>
						</span>
						<span  class="mp_checklist_status "
								 id="JavascriptEnabled3ds2_no">
							  <?php _e( 'No', 'mangopay' ); ?>
						</span>
					</td>
				</tr>
				<!-- Java Enabled -->
				<tr>
					<td>
						<?php _e( 'Java Enabled:', 'mangopay' ); ?>
					</td>
					<td>
						<span class="mp_checklist_status " 
							  id="JavaEnabled3ds2_yes" 
							  style="display: none;">
							<?php _e( 'Yes', 'mangopay' ); ?>
						</span>
						<span class="mp_checklist_status " 
							  id="JavaEnabled3ds2_no">
							<?php _e( 'No', 'mangopay' ); ?>
						</span>
					</td>
				</tr>
				<!-- 3DS2 forced -->
				<tr>
					<td>
						<?php _e( '3DS2 forced:', 'mangopay' ); ?>				
					</td>
					<td>
						<?php
						$wc_settings = get_option( 'woocommerce_mangopay_settings' );
						if(isset($wc_settings['enabled_3DS2']) && $wc_settings['enabled_3DS2'] == 'yes'):
							_e( 'Yes', 'mangopay' );
						else:
							_e( 'No', 'mangopay' );
						endif;
						?>
					</td>
				</tr>
						
		<?php /* do not close, this intersects with an already existing page  */ ?>
				
		<?php        
	}

	private function list_all_vendors_healthcheck(){
		
		$args = array( 'role' => 'vendor'); 
		$list_vendors = get_users( $args );

		$list_to_show = array();
		
		$span_start_ok = '<span class="mp_checklist_status success">'.__( 'Yes', 'mangopay' ).'</span>';
		$span_start_nok = '<span class="mp_checklist_status failure">'.__( 'No', 'mangopay' ).'</span>';
		
		if($list_vendors){
			foreach($list_vendors as $vendor){		

				$list_to_show_temp = array();
				
				//DISPLAY NAME
				$list_to_show_temp['display_name'] = $vendor->display_name;
				//KYC
				if($this->mangopayWCMain->test_vendor_kyc($vendor->ID)){
					$list_to_show_temp['kyc'] = $span_start_ok;
				}else{
					$list_to_show_temp['kyc'] = $span_start_nok;
				}
				
				//UBO //ONLY FOR BUSINESS
				$ubo_result = $this->mp->test_vendor_ubo($vendor->ID);
				if($ubo_result == "na"){
					$list_to_show_temp['ubo'] = __( 'Optional', 'mangopay' );
				}else{
					if($ubo_result){
						$list_to_show_temp['ubo'] = $span_start_ok;
					}else{
						$list_to_show_temp['ubo'] = $span_start_nok;
					}
				}
				
				//TERMS AND CONDITIONS
				$tc = get_user_meta( $vendor->ID, 'termsconditions', true );				
				if(!$tc || trim($tc)==''){
					$list_to_show_temp['tc'] = $span_start_nok;
				}else{
					$list_to_show_temp['tc'] = $span_start_ok;
				}
				
				$list_to_show[] = $list_to_show_temp;				
			}
		}
		
		return $list_to_show;
	}
	
	
	/**
	 * Checks if all products are assigned to vendors
	 * ( a frequent mistake is to assign products to the site administrator
	 * and then conduct shopping tests with that product. This will cause errors. )
	 * 
	 * @return boolean
	 */
	private function products_with_vendors(){
		global $wpdb;
		$vendor_role = apply_filters( 'mangopay_vendor_role', 'vendor' );
		$query = new WC_Product_Query( array(
			'status'	=> array( 'draft', 'pending', 'private', 'publish' ),
			'limit'		=> -1,
			'orderby'	=> 'date',
			'order'		=> 'DESC',
			'return'	=> 'ids',
		) );
		$products = $query->get_products();
		foreach( $products as $productid ) {
			$user_id	= get_post_field( 'post_author', $productid );
			$user_data	= get_user_meta( $user_id, $wpdb->prefix.'capabilities' );

			$return = false;
			foreach($user_data as $ud) { 
				foreach( $ud as $wp_cap=>$val ){
					if( $wp_cap == $vendor_role && $val == 1 ) {
						$return = true;
						break;
					}
				}
			}

			if(!$return) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks if any active vendors don't have an MP user account
	 * (and thus we cannot create a wallet)
	 *
	 * @return boolean
	 */
	private function vendors_without_account() {
		$vendor_role = apply_filters( 'mangopay_vendor_role', 'vendor' );
		if( $vendors = get_users( array( 'role' => $vendor_role, 'fields' => 'ID' ) ) ) {
				
			/** We store a different mp_user_id for production and sandbox environments **/
			$umeta_key = 'mp_user_id';
			if( !$this->mp->is_production() ) {
				$umeta_key .= '_sandbox';
			}
			
			foreach( $vendors as $vendor ) {
				if( ! $mp_user_id = get_user_meta( $vendor, $umeta_key, true ) ) {
					return true;
				}
			}
			return false;
		}
		return false;	// No vendors yet
	}
	
	/**
	 * Displays an admin error notice
	 * as long as the production client id and API Key of the plugin have not been set-up
	 *
	 * @see: https://codex.wordpress.org/Plugin_API/Action_Reference/admin_notices
	 *
	 */
	public function admin_notices() {
	
		if( !empty($this->options['prod_or_sandbox']) && 'prod' == $this->options['prod_or_sandbox'] ) {
			$which_passphrase	= 'prod_passphrase';
			$which_client_id	= 'prod_client_id';
		} else {
			$which_passphrase	= 'sand_passphrase';
			$which_client_id	= 'sand_client_id';
		}
	
		if(
				empty( $this->options['prod_or_sandbox'] ) ||
				empty( $this->options[$which_passphrase] ) ||
				empty( $this->options[$which_client_id] ) ||
				!class_exists( 'woocommerce' ) ||
				!class_exists( apply_filters( 'mangopay_vendors_required_class', 'WC_Vendors' ) )
		) {
			$class = 'notice notice-error';
				
			$message = __( 'The MANGOPAY payment gateway needs to be configured.', 'mangopay' ) .
			' <a href="admin.php?page=' . mangopayWCConfig::OPTION_KEY . '">' .
			__( 'Please click here', 'mangopay' ) .
			'</a>.';
				
			printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
		}
	
		/** Costly checks will only be performed on some specific admin pages **/
		$enabled_screens = array(
				'dashboard',
				'woocommerce_page_wc-settings',
				'toplevel_page_mangopay_settings',
				'edit-shop_order',
				'edit-product'
		);
		$screen = get_current_screen();
		
		if($screen->id == 'shop_order' && isset($_GET['post'])){
			$preauth_message_order_admin = get_post_meta( $_GET['post'], 'preauth_message_order_admin',true);
			if(isset($preauth_message_order_admin) 
				&& $preauth_message_order_admin!='' 
				&& $preauth_message_order_admin!=false){
				$class		= 'notice notice-error';
				printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $preauth_message_order_admin );
				update_post_meta( $_GET['post'], 'preauth_message_order_admin', false);
			}
		}
		
		if( in_array( $screen->id, $enabled_screens ) ) {
			

				
			if( get_option( 'woocommerce_enable_guest_checkout' ) == 'yes' ) {
				$class		= 'notice notice-error';
				$message	= __( 'MANGOPAY warning', 'mangopay' ) . '<br/>';
				$message	.= __( 'WooCommerce guest checkout should be disabled', 'mangopay' );
				printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
			}
	
			$status = $this->mp->getStatus( $this->mangopayWCMain );
				
			if( 'yes' != $status['enabled'] ) {
				$class		= 'notice notice-error';
				$message	= __( 'MANGOPAY warning', 'mangopay' ) . '<br/>';
				$message	.= __( 'MANGOPAY is disabled', 'mangopay' );
				printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
			}
				
			$currency = null;
			if( function_exists( 'get_woocommerce_currency' ) )
				$currency	= get_woocommerce_currency();
			if( !in_array( $currency, $this->allowed_currencies ) ) {
				$class		= 'notice notice-error';
				$message	= __( 'MANGOPAY warning', 'mangopay' ) . '<br/>';
				$message	.= __( 'The current WooCommerce currency is unsupported by MANGOPAY', 'mangopay' );
				printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
			}
				
			/* DEBUG: *
				echo '<div class="notice notice-error"><p>Debug:<br/>';
			$alloptions = wp_load_alloptions();
			echo 'option: ' . $alloptions['woocommerce_enable_guest_checkout'];
			echo 'screen: ' . $screen->id;
			echo '</p></div>';
			/* */
		}
	}
	
	/**
	 * Add our admin dashboard widget
	 * for displaying failed payout transactions and refused KYC docs
	 *
	 */
	public function add_dashboard_widget() {
	
		/** Only show this widget to site administrators **/
		if ( !current_user_can( 'manage_options' ) )
			return;

		wp_add_dashboard_widget(
			'mp_failed_db',
			__( 'MANGOPAY failed transactions', 'mangopay' ),
			array( $this, 'failed_transaction_widget_loader' ),
			$control_callback = null
		);

		/** Force our widget to the top **/
		global $wp_meta_boxes;
		$normal_dashboard = $wp_meta_boxes['dashboard']['normal']['core'];
		$our_widget_backup = array( 'mp_failed_db' => $normal_dashboard['mp_failed_db'] );
		unset( $normal_dashboard['mp_failed_db'] );
		$sorted_dashboard = array_merge( $our_widget_backup, $normal_dashboard );
		$wp_meta_boxes['dashboard']['normal']['core'] = $sorted_dashboard;
	}
	
	/**
	 * Loads the transaction widget content asynchronously
	 * using ajax
	 * 
	 * @see: inc/ajax.inc.php
	 */
	public function failed_transaction_widget_loader(){
		echo '<div id="failed_transactiondiv">';
		echo __( 'Loading...', 'mangopay' );
		echo '</div>';
		echo '<div id="failed_transactiondiv_error" style="display:none;">';
		echo __('An error has occurred, please try again later.','mangopay');
		echo '</div>';        
		?>
		<script>
		(function($) {
			$(document).ready(function() {                
				$.post( ajaxurl, {
					action: 'failed_transaction_widget'
				}, function( data ) {
					if(data!=false){
						$('#failed_transactiondiv').html(data);
					}
				}).done(function() {
					//console.log( "Ajax failed_transaction_widget success" );	//Debug                    
				}).fail(function() {
					console.log( "Ajax failed_transaction_widget error" );		//Debug
					$('#failed_transactiondiv_error').show();
					$('#failed_transactiondiv').hide();
				}).always(function() {
					//console.log( "Ajax failed_transaction_widget finished" );	//Debug
				});
			});
		})( jQuery );
		</script>
	<?php
	}
	
	/**
	 * Displayed on user-edit and user-new profile admin page
	 * Adds custom fields required by MP 
	 * (birthday, nationality, country, user status and business type, where needed)
	 * This is a WP action hook tied to:
	 * - 'show_user_profile'
	 * - 'edit_user_profile'
	 * - 'user_new_form'
	 * Must therefore be a public method
	 *
	 * @see: http://wordpress.stackexchange.com/questions/4028/how-to-add-custom-form-fields-to-the-user-profile-page
	 */
	public function user_edit_required( $user ) {
		
//		wp_enqueue_script( 'jquery-ui-datepicker' );
//		$this->mangopayWCMain->localize_datepicker();	//TODO: the localization should be called earlier in case the datepicker has already been enqueued by another plugin (eg. ACF)
		
		$screen = get_current_screen();
		$company_number = '';
		$headquarters_address = '';
		$field_value_checked = '';
						
		if( $screen->id=='user' && $screen->action=='add' ) {
			/** We are in the WP admin User -> Add = wp-admin/user-new.php **/
			if( 
				($screen->id=='user' && $screen->action=='add')
				//|| ($screen->id == 'toplevel_page_wcv-vendor-shopsettings' && is_admin())
				|| ($screen->id == 'user-edit' && $screen->id == 'profile') 
			) {			
				/** Necessary scripts and CSS for WC's nice country/state drop-downs **/
				$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
				wp_enqueue_script( 
					'wc-users', 
					WC()->plugin_url() . '/assets/js/admin/users' . $suffix . '.js', 
					array( 'jquery', 'wc-enhanced-select' ), 
					WC_VERSION, 
					true 
				);
				wp_localize_script(
					'wc-users',
					'wc_users_params',
					array(
						'countries'              => json_encode( array_merge( WC()->countries->get_allowed_country_states(), WC()->countries->get_shipping_country_states() ) ),
						'i18n_select_state_text' => esc_attr__( 'Select an option&hellip;', 'woocommerce' ),
					)
				);
				
				wp_enqueue_style( 
					'woocommerce_admin_styles', WC()->plugin_url() . '/assets/css/admin.css', 
					array(), 
					WC_VERSION 
				);
			}	
			
			/** headquarters data **/
			if( !empty( $_POST['headquarters_addressline1'] ) ){
				$headquarters_addressline1 = $_POST['headquarters_addressline1'];
			}
			
			if( !empty( $_POST['headquarters_addressline2'] ) ){
				$headquarters_addressline2 = $_POST['headquarters_addressline2'];
			}
			
			if( !empty( $_POST['headquarters_city'] ) ){
				$headquarters_city = $_POST['headquarters_city'];
			}
			
			if( !empty( $_POST['headquarters_region'] ) ){
				$headquarters_region = $_POST['headquarters_region'];
			}
			
			if( !empty( $_POST['headquarters_postalcode'] ) ){
				$headquarters_postalcode = $_POST['headquarters_postalcode'];
			}
			
			if( !empty( $_POST['headquarters_country'] ) ){
				$headquarters_country = $_POST['headquarters_country'];
			}
			
			/** company number **/			
			if( !empty( $_POST['compagny_number'] ) ){
				$company_number = $_POST['compagny_number'];	
			}
			
			$user_birthday 		= '';
			if( !empty( $_POST['user_birthday'] ) ){
				$user_birthday = $_POST['user_birthday'];
			}
			
			$user_nationality	= '';
			if( !empty( $_POST['user_nationality'] ) ){
				$user_nationality = $_POST['user_nationality'];
			}
			
			$billing_country	= '';
			if( !empty( $_POST['billing_country'] ) ){
				$billing_country = $_POST['billing_country'];
			}
			
			$user_mp_status		= '';
			/** Apply default user status where needed **/
			if( 
				isset( $this->options['default_buyer_status'] ) &&
				'businesses' == $this->options['default_buyer_status'] &&
				isset( $this->options['default_vendor_status'] ) &&
				'businesses' == $this->options['default_vendor_status']
			){
				$user_mp_status = 'business';
			}
			
			if( !empty( $_POST['user_mp_status'] ) ){
				$user_mp_status = $_POST['user_mp_status'];
			}
			
			$user_business_type	= '';
			if( !empty( $_POST['user_business_type'] ) ){
				$user_business_type = $_POST['user_business_type'];
			}
			
		} else {
			/** We are editing an existing user in the WP admin **/

			$user_birthday = esc_attr( get_the_author_meta( 'user_birthday', $user->ID ) );
			$user_birthday = date_i18n( $this->mangopayWCMain->supported_format( get_option( 'date_format' ) ), strtotime( $user_birthday ) );
				
			$user_nationality	= get_the_author_meta( 'user_nationality', $user->ID );
			
			$user_mp_status		= get_the_author_meta( 'user_mp_status', $user->ID );
						
			if(isset($_POST['compagny_number'])){
				$company_number = $_POST['compagny_number'];
			}else{
				$company_number = get_the_author_meta( 'compagny_number', $user->ID );
			}
			
			/** headquarters data **/
			if( !empty( $_POST['headquarters_addressline1'] ) ){
				$headquarters_addressline1 = $_POST['headquarters_addressline1'];
			}else{
				$headquarters_addressline1 = get_the_author_meta( 'headquarters_addressline1', $user->ID );
			}
			
			if( !empty( $_POST['headquarters_addressline2'] ) ){
				$headquarters_addressline2 = $_POST['headquarters_addressline2'];
			}else{
				$headquarters_addressline2 = get_the_author_meta( 'headquarters_addressline2', $user->ID );
			}
			
			if( !empty( $_POST['headquarters_city'] ) ){
				$headquarters_city = $_POST['headquarters_city'];
			}else{
				$headquarters_city = get_the_author_meta( 'headquarters_city', $user->ID );
			}
			
			if( !empty( $_POST['headquarters_region'] ) ){
				$headquarters_region = $_POST['headquarters_region'];
			}else{
				$headquarters_region = get_the_author_meta( 'headquarters_region', $user->ID );
			}
			
			if( !empty( $_POST['headquarters_postalcode'] ) ){
				$headquarters_postalcode = $_POST['headquarters_postalcode'];
			}else{
				$headquarters_postalcode = get_the_author_meta( 'headquarters_postalcode', $user->ID );
			}
			
			if( !empty( $_POST['headquarters_country'] ) ){
				$headquarters_country = $_POST['headquarters_country'];
			}else{
				$headquarters_country = get_the_author_meta( 'headquarters_country', $user->ID );
			}			
			            
			/** Fix for users that did not get a needed default status when created **/
			if(
				!$user_mp_status &&
				isset( $this->options['default_buyer_status'] ) &&
				'businesses' == $this->options['default_buyer_status'] &&
				isset( $this->options['default_vendor_status'] ) &&
				'businesses' == $this->options['default_vendor_status']
			){
				$user_mp_status = 'business';
			}
			
			$user_business_type	= get_the_author_meta( 'user_business_type', $user->ID );
					
		}
    
		/**
		 * For country drop-down
		 * @see: https://wordpress.org/support/topic/woocommerce-country-registration-field-in-my-account-page-not-working
		 *
		 */
		$countries_obj = new WC_Countries();
		$countries = $countries_obj->__get('countries');
		?>
		  <h3><?php _e( 'Extra profile information for MANGOPAY', 'mangopay' ); ?></h3>
		  <table class="form-table">
			  
		<?php
		/* we are in admin, we let admins add infos, and for vendors who have access */

		/** ONLY FOR EDIT **/
		if( ($screen->id=='user-edit' && $screen->action != 'add')
			|| ($screen->id=='profile') 
			//|| ($screen->id == 'toplevel_page_wcv-vendor-shopsettings' && is_admin())
				
		):
			/**only for legal users **/
			if($user_mp_status == 'business'):	 // && $user_business_type == 'business'
		?>
			<?php if($user_business_type == 'business'): ?>
		    <tr>
				<th>
					<label for="compagny_number">
						<?php _e( 'Company number', 'mangopay' ); ?> 
						<span class="description required">
							<?php _e( '(required)', 'mangopay' ); ?>
						</span>
					</label>
				</th>
				<td>
					<input type="text" name="compagny_number" id="compagny_number" class="regular-text" autocomplete="off"
						value="<?php echo $company_number; ?>" /><br />
					<span class="description"></span>
				</td>
		    </tr>
			<?php endif; ?>
			
		    <tr>
				<th>
					<label for="headquarters_address">
						<?php _e( 'Headquarters address', 'mangopay' ); ?> 
					</label>
				</th>
				<td>
					<table>
						<tr>
							<td>
								 <label for="headquarters_addressline1">
									 <?php _e( 'Headquarters address', 'mangopay' ); ?> <span class="description required">
										 <?php _e( '(required)', 'mangopay' ); ?>
									 </span>
								 </label>
							</td>
							<td>
								<input type="text" 
								name="headquarters_addressline1" 
								id="headquarters_addressline1" 
								value="<?php echo $headquarters_addressline1; ?>" 
								class="regular-text" />
								<br>
								<input type="text" 
								name="headquarters_addressline2" 
								id="headquarters_addressline2" 
								value="<?php echo $headquarters_addressline2; ?>" 
								class="regular-text" />
							</td>
						</tr>
						<tr>
							<td>
								<label for="headquarters_city">
									<?php _e( 'Headquarters city', 'mangopay' ); ?> <span class="description required">
										<?php _e( '(required)', 'mangopay' ); ?>
									</span>
								</label>
							</td>
							<td>
								<input type="text" 
								name="headquarters_city" 
								id="headquarters_city" 
								value="<?php echo $headquarters_city; ?>" 
								class="regular-text" />
							</td>
						</tr>
						<tr>
							<td>
								 <label for="headquarters_region">
									 <?php _e( 'Headquarters region', 'mangopay' ); ?> <span class="description required">
										 <?php _e( '(required)', 'mangopay' ); ?>
									 </span>
								 </label>
							</td>
							<td>
								<input type="text" 
								name="headquarters_region" 
								id="headquarters_region" 
								value="<?php echo $headquarters_region; ?>" 
								class="regular-text" />
							</td>
						</tr>
						<tr>
							<td>
								 <label for="headquarters_postalcode">
									 <?php _e( 'Headquarters postalcode', 'mangopay' ); ?> <span class="description required">
										 <?php _e( '(required)', 'mangopay' ); ?>
									 </span>
								 </label>
							</td>
							<td>
								<input type="text" 
								name="headquarters_postalcode" 
								id="headquarters_postalcode" 
								value="<?php echo $headquarters_postalcode; ?>" 
								class="regular-text" />
							</td>
						</tr>
						<tr>
							<td>
								 <label for="headquarters_country">
									 <?php _e( 'Headquarters country', 'mangopay' ); ?> <span class="description required">
										 <?php _e( '(required)', 'mangopay' ); ?>
									 </span>
								 </label>
							</td>
							<td>
								<select class="nationality_select js_field-country" name="headquarters_country" id="headquarters_country">
								<option value=""><?php _e( 'Select a country...', 'mangopay' ); ?></option>
								<?php foreach ($countries as $key => $value): 
									$selected=($key==$headquarters_country?'selected="selected"':'');
								?>
								<option value="<?php echo $key?>" <?php echo $selected; ?>><?php echo $value?></option>
								<?php endforeach; ?>
								</select>								
							</td>
						</tr>
					</table>
				</td>
		    </tr>			
				<?php endif; ?>
			<?php endif; //if != of action add ?>       
			
		    <?php if( $screen->id=='user' && $screen->action=='add' ) :	
		    	/** 
		    	 * Only on the create new user screen:
		    	 * The billing_country field is already present when editing an existing user 
		    	 * 
		    	 **/
		    	?>            
			    <tr>
					<th><label for="billing_country"><?php _e( 'Country', 'mangopay' ); ?> <span class="description required"></span></label></th>
					<td>
			        <select class="billing_country_select js_field-country" name="billing_country" id="billing_country">
			        <option value=""><?php _e( 'Select a country...', 'mangopay' ); ?></option>
			        <?php foreach ($countries as $key => $value): 
			        	$selected=($key==$billing_country?'selected="selected"':'');
			        	?>
			        	<option value="<?php echo $key?>" <?php echo $selected; ?>><?php echo $value?></option>
					<?php endforeach; ?>
					</select>
					</td>
				</tr>
				<!-- billing state 98d4fs64e8 -->
				<tr class="billing_state_field" id="billing_state_field">
					<th><label for="billing_state">
						<?php _e( 'State/County', 'woocommerce' ); ?>&nbsp;
							<span class="description required billing_state_field_required" style="display: none;">
							<?php _e( '(required)', 'mangopay' ); ?>
							</span>
						</label>
					</th>
					<td>
			        <input type="hidden" class="billing_state_select js_field-state" name="billing_state" id="billing_state" />
					</td>
				</tr>
			<?php endif; ?>
			
            <?php /* we always add the form invisible javascript will determine if we show it */ ?>
            <tr id="block_tr_user_mp_status">
                <th>
                    <label for="user_mp_status">
                        <?php _e( 'User status', 'mangopay' ); ?> 
                        <span class="description required"></span>
                    </label>
                </th>
                <td>
                    <input type="hidden" id="actual_user_mp_status" value="<?php echo $user_mp_status; ?>" />
                    <input type="hidden" id="actual_default_buyer_status" value="<?php echo $this->options['default_buyer_status']; ?>" />
                    <input type="hidden" id="actual_default_vendor_status" value="<?php echo $this->options['default_vendor_status']; ?>" />
                <select class="mp_status_select" name="user_mp_status" id="user_mp_status" disabled>
                    <option value=""><?php _e( 'Select option...', 'mangopay' ); ?></option>
                    <option value="individual" <?php selected( $user_mp_status, 'individual' ); ?>><?php _e( 'Individual', 'mangopay' ); ?></option>
                    <option value="business" <?php selected( $user_mp_status, 'business' ); ?>><?php _e( 'Business user', 'mangopay' ); ?></option>
                </select>
                <input type="hidden" name="user_mp_status" id="hidden_user_mp_status" value="<?php echo $user_mp_status; ?>" />
                </td>
            </tr>
            <tr class="hide_business_type" id="block_tr_user_business_type">
                <th><label for="user_business_type"><?php _e( 'Business type', 'mangopay' ); ?> <span class="description required"></span></label></th>
                <td>
                <input type="hidden" id="actual_default_business_type" value="<?php echo $this->options['default_business_type']; ?>" />   
                <select class="mp_btype_select" name="user_business_type" id="user_business_type" disabled>
                    <option value=""><?php _e( 'Select option...', 'mangopay' ); ?></option>
                    <option value="organisation" <?php selected( $user_business_type, 'organisation' ); ?>><?php _e( 'Organisation', 'mangopay' ); ?></option>
                    <option value="business" <?php selected( $user_business_type, 'business' ); ?>><?php _e( 'Business', 'mangopay' ); ?></option>
                    <option value="soletrader" <?php selected( $user_business_type, 'soletrader' ); ?>><?php _e( 'Soletrader', 'mangopay' ); ?></option>
                </select>
                <input type="hidden" name="user_business_type" id="hidden_user_business_type" value="<?php echo $user_business_type; ?>" />
                <?php
                $business_edit = 0;
                if( $screen->id=='user-edit' && $screen->action!='add' ) {
                    $business_edit = 1;
                } ?>
                <input type="hidden" name="business_type_edit" id="business_type_edit" value="<?php echo $business_edit; ?>" />
                </td>
            </tr>
				
			<?php if( $screen->id != 'user' && $screen->action != 'add' ) :	
				/** Not on the create new user screen **/
			?>
				<?php if( $this->mangopayWCMain->is_vendor( $user->ID ) ) : ?>
					<?php if( false && $user_mp_status ) : ?>
						<tr>
							<th><?php _e( 'User status', 'mangopay' ); ?></th>
							<td><?php echo __( $user_mp_status, 'mangopay' ); ?></td>
						</tr>
						<?php if( 'business'==$user_mp_status && $user_business_type ) : ?>
							<tr>
								<th><?php _e( 'Business type', 'mangopay' ); ?></th>
								<td><?php echo __( $user_business_type, 'mangopay' ); ?></td>
							</tr>
						<?php endif; ?>
					<?php endif; ?>
					<tr>
						<th><?php _e( 'Bank account data', 'mangopay' ); ?></th>
						<td>
						<?php $this->mangopayWCMain->bank_account_form( $user->ID ); ?>
						</td>
					</tr>
					<?php $this->mangopayWCMain->mangopay_wallet_table(); ?>
				<?php else : ?>
					<?php if( false && $user_mp_status ) : ?>
						<tr>
							<th><?php _e( 'User status', 'mangopay' ); ?></th>
							<td><?php echo __( $user_mp_status, 'mangopay' ); ?></td>
						</tr>
						<?php if( 'business'==$user_mp_status && $user_business_type ) : ?>
							<tr>
								<th><?php _e( 'Business type', 'mangopay' ); ?></th>
								<td><?php echo __( $user_business_type, 'mangopay' ); ?></td>
							</tr>
						<?php endif; ?>
					<?php endif; ?>
					<?php $this->mangopayWCMain->mangopay_wallet_table(); ?>
			    <?php endif; ?>
		    <?php endif; ?>
		    
		  </table>
		<?php
	}
	
	/**
	 * 
	 * @param type $user_id
	 * @return boolean
	 */
	public function user_edit_save( $user_id ) {
		$saved = false;
		if ( current_user_can( 'edit_user', $user_id ) ) {

			if( !empty( $_POST['user_birthday'] ) ){
				$birthday = $this->mangopayWCMain->convertDate( sanitize_text_field( $_POST['user_birthday'] ) );			
				update_user_meta( $user_id, 'user_birthday', $birthday );
			}
			
			if( !empty( $_POST['user_nationality'] ) ){
				update_user_meta( $user_id, 'user_nationality', sanitize_text_field( $_POST['user_nationality'] ) );
			}
			
			if( isset( $_POST['billing_country'] ) )
				update_user_meta( $user_id, 'billing_country', sanitize_text_field( $_POST['billing_country'] ) );
			
			if( isset( $_POST['billing_state'] ) )
				update_user_meta( $user_id, 'billing_state', sanitize_text_field( $_POST['billing_state'] ) );
			
			if( isset( $_POST['user_mp_status'] ) )
				update_user_meta( $user_id, 'user_mp_status', sanitize_text_field( $_POST['user_mp_status'] ) );
			
			if( isset( $_POST['user_business_type'] ) )
				update_user_meta( $user_id, 'user_business_type', sanitize_text_field( $_POST['user_business_type'] ) );
					
			$saved = true;
		}
		
		/** 
		 * We cannot update MP user account yet because of Coutry/State requirements for US / CA / MX
		 * -> moved over to user_edit_checks()
		 * @see: https://codex.wordpress.org/Plugin_API/Action_Reference/user_profile_update_errors
		 * which says: " If you want to validate some custom fields before saving, 
		 * a workaround is to check the $errors array in this same callback, 
		 * after performing your validations, and save the data if it is empty."
		 */
		//$this->mangopayWCMain->on_shop_settings_saved( $user_id );
		
		/* indepedent save */
		$this->mangopayWCMain->save_account_form_companynumber( $user_id );
		/* indepedent save */
		$this->mangopayWCMain->save_account_form_headquarter( $user_id );
		
		/** Update bank account data if set && valid **/
		$errors = new WP_Error;
		

		//TODO generate errors but are not printed
		$this->mangopayWCMain->validate_bank_account_data( $errors, NULL, $user_id );
		
		$e = $errors->get_error_code();
		if( empty( $e ) ){
			$this->mangopayWCMain->save_account_form( $user_id );		
		}
		return $saved;
	}
	
	/**
	 * Enforce user profile required fields
	 * 
	 * hooked on the 'user_profile_update_errors' action by hooks.inc.php
	 *
	 * @param object $errors	| WP Errors object
	 * @param unknown $update
	 * @param unknown $user
	 */
	public function user_edit_checks( &$errors, $update, $user ) {
	
		$data_post = $_POST;
		$list_post_keys = array(     
			'first_name'			=> 'single',
			'last_name'				=> 'single',
			'billing_country'		=> 'country',
//			'user_mp_status'		=> 'status',
//			'user_business_type'	=> 'businesstype',
            //'billing_postcode'      =>'postalcode'			
		);
				
		/* get role by user id ($_POST['role'] is missing on "profile" user non admin */
		if(!empty($_POST['role'])){
			$list_post_keys['user_mp_status'] = 'status';
			$list_post_keys['user_business_type'] = 'businesstype';
			
			if(!empty($_POST['action']) && $_POST['action']=="update"){				
				$list_post_keys['user_birthday'] = 'date';
				$list_post_keys['user_nationality'] = 'nationality';
			}
			
		}else{
			$user_id = $data_post['user_id'];
			$user_info = get_userdata( $user_id );
			if(!empty($user_info->caps['vendor'])){

				$list_post_keys['user_mp_status'] = 'status';
				$list_post_keys['user_business_type'] = 'businesstype';
				$list_post_keys['user_birthday'] = 'date';
				$list_post_keys['user_nationality'] = 'nationality';

			}
		}
						
		if($_POST['user_mp_status']=='business'){
			$list_post_keys['compagny_number'] = 'single';
			$list_post_keys['headquarters_addressline1'] = 'single';
			$list_post_keys['headquarters_city'] = 'single';
			$list_post_keys['headquarters_region'] = 'single';
			$list_post_keys['headquarters_postalcode'] = 'postalcode';
			$list_post_keys['headquarters_country'] = 'country';			
		}
				
		foreach ( $list_post_keys as $key => $value ) {
			$function_name = 'validate_' . $value;
			$data_to_send = array(
				'data_post'			=> $data_post,
				'key_field' 		=> $key,
				'wp_error'			=> &$errors,
				'main_options'		=> $this->options,
				'double_test'		=> array( 'user_birthday' => 1 ),
				'caller_func'		=> 'user_edit_checks'
			);
			$this->mangopayWCValidation->$function_name( $data_to_send );
		}

    	/** 
		 * Update MP user account
		 * We must do this here because of Coutry/State requirements for US / CA / MX
		 * @see: https://codex.wordpress.org/Plugin_API/Action_Reference/user_profile_update_errors
		 * which says: " If you want to validate some custom fields before saving, 
		 * a workaround is to check the $errors array in this same callback, 
		 * after performing your validations, and save the data if it is empty."
		 */
		/* *
		var_dump( is_wp_error( $errors ) );
		var_dump( empty( $errors ) );
		var_dump( $user );
		var_dump( $errors ); exit;	// Debug
		/* */
		if( is_wp_error( $errors ) && !$errors->get_error_code() && isset( $user->ID ) )
			$this->mangopayWCMain->on_shop_settings_saved( $user->ID );
    
		/** Bank account data **/		

		if( 
			!empty( $_POST['vendor_account_type'] ) 
			|| !empty( $_POST['vendor_account_name'] ) 
			|| !empty( $_POST['vendor_account_address1'] ) 
			|| !empty( $_POST['vendor_account_city'] ) 
			|| !empty( $_POST['vendor_account_country'] ) 
			|| !empty( $_POST['vendor_account_postcode'] ) 			
		){
			$this->mangopayWCMain->validate_bank_account_data( $errors, $update, $user->ID );
		}
		
	} // function user_edit_checks()
	
	/**
	 * Add our custom column to the user list admin screen
	 * to show if they have an MP account for this environment
	 *
	 */
	public function manage_users_columns( $columns ) {
		$columns['mp_account'] = __( 'MANGOPAY Account', 'mangopay' );
//		$columns['mp_kyc'] = __( 'KYC/UBO', 'mangopay' );
		//$columns['mp_tc'] = __( 'T&C', 'mangopay' );
//		$columns['mp_compagnynumber'] = __( 'Company number', 'mangopay' );		
		return $columns;
	}

	/**
	 * Make our custom column sortable
	 *
	 * @param array $columns
	 * @return array
	 * 
	 */
	public function manage_sortable_users_columns( $columns ) {
		$columns['mp_account'] = 'has_mp_account';
//		$columns['mp_kyc'] = 'has_mp_kyc';
		//$columns['mp_tc'] = 'has tandc';
//		$columns['mp_compagnynumber'] = 'has_compagnynumber';
		return $columns;
	}
	
	/**
	 * Content of our custom column
	 *B
	 * @param string $value
	 * @param string $column_name
	 * @param int $wp_user_id
	 * @return string
	 * 
	 */
	public function users_custom_column(  $value, $column_name, $wp_user_id  ) {
	
		if('mp_account' == $column_name){
			
			$user_data = get_userdata($wp_user_id);
			$vendor_role = apply_filters( 'mangopay_vendor_role', 'vendor' );
			
			if(
				is_array( $user_data->roles ) && 
				in_array( $vendor_role, $user_data->roles , true )
			){
				
				$html_to_return = "";
				$kyc_ubo = "";
				$ubo = "";
				$mp_account_result = "";
				$tc_result = "";
				$compagny_number_result = "";
				$kyc_text = __('KYC','mangopay');

				$span_start_ok = '<span class="mp_checklist_status success">'.__( 'Yes', 'mangopay' ).'</span>';
				$span_start_nok = '<span class="mp_checklist_status failure">'.__( 'No', 'mangopay' ).'</span>';
				
				$user_mp_status = get_user_meta($wp_user_id, 'user_mp_status', true);
				$user_business_type = get_user_meta($wp_user_id, 'user_business_type', true);
					
				$total_check = $span_start_ok;
				
				/** We store a different mp_user_id for production and sandbox environments **/
				$umeta_key = 'mp_user_id';
				if( !$this->mp->is_production() ){
					$umeta_key .= '_sandbox';	
				}

				$mp_user_id = get_user_meta( $wp_user_id, $umeta_key, true );
				if( $mp_user_id ) {
					$mp_account_result = $span_start_ok;
				}else{
					$mp_account_result = $span_start_nok;
					$total_check = $span_start_nok;
				}

				$tc = get_user_meta( $wp_user_id, 'termsconditions', true );
				$tc_result = $span_start_ok;
				if(!$tc || trim($tc)==''){
					$tc_result =$span_start_nok;
					$total_check = $span_start_nok;
				} 

				if(isset($user_data->caps['vendor']) && $user_data->caps['vendor'] == 1){
					$kyc_result = $this->mangopayWCMain->test_vendor_kyc($wp_user_id);				
					if(!$kyc_result){
						$kyc_ubo = $span_start_nok;
						$total_check = $span_start_nok;
					} else {
						if($kyc_result === true){
							$kyc_ubo = $span_start_ok;
						}					
						if($kyc_result === 'no_account_bd'){
							$kyc_ubo = $span_start_nok;
							$total_check = $span_start_nok;
						}
						if($kyc_result === 'no_count_mp_found'){					
							$kyc_ubo = $span_start_nok;
							$total_check = $span_start_nok;
						}
					}				
					//test ubos
					$kyc_text = __('KYC','mangopay');
					if($user_mp_status == 'business' || $user_business_type == 'business'){		
						$kyc_text = __('KYC/UBO','mangopay');
					}
				}

				$compagny_number = get_the_author_meta( 'compagny_number', $wp_user_id );			
				$user_mp_status = get_user_meta($wp_user_id, 'user_mp_status', true);
				$user_business_type = get_user_meta($wp_user_id, 'user_business_type', true);
				if($user_mp_status == 'business' && $user_business_type == 'business'){
					if(!$compagny_number){
						$compagny_number_result = $span_start_nok;
						$total_check = $span_start_nok;
					} else {
						$compagny_number_result = $span_start_ok;
					}
				}else{
					$compagny_number_result = __( 'Optional', 'mangopay' );
				}
								
				$html_to_return.= '<table class="table_user_list_resume" >';
				$html_to_return.= '<tbody>';

				$html_to_return.= '<tr class="openusermangopaydetails" data-id="'.$wp_user_id.'" >';
				$html_to_return.= '<td>'.__( 'Healthcheck details', 'mangopay' ).'</td>';
				$html_to_return.= '<td>'.$total_check.'<span class="arrow_'.$wp_user_id.' arrowtableusers arrowtableusersleft"></span></td>';
				$html_to_return.= '</tr>';
				
				$html_to_return.= '<tr class="sublineusermangopay trid_'.$wp_user_id.'" >';
				$html_to_return.= '<td>'.__( 'MANGOPAY Account', 'mangopay' ).'</td>';
				$html_to_return.= '<td>'.$mp_account_result.'</td>';
				$html_to_return.= '</tr>';

				$html_to_return.= '<tr class="sublineusermangopay trid_'.$wp_user_id.'" >';
				$html_to_return.= '<td>'.__( 'T&C', 'mangopay' ).'</td>';
				$html_to_return.= '<td>'.$tc_result.'</td>';
				$html_to_return.= '</tr>';

				$html_to_return.= '<tr class="sublineusermangopay trid_'.$wp_user_id.'" >';
				$html_to_return.= '<td>'.$kyc_text.'</td>';
				$html_to_return.= '<td>'.$kyc_ubo.'</td>';
				$html_to_return.= '</tr>';

				$html_to_return.= '<tr class="sublineusermangopay trid_'.$wp_user_id.'" >';
				$html_to_return.= '<td>'.__('Company number','mangopay').'</td>';
				$html_to_return.= '<td>'.$compagny_number_result.'</td>';
				$html_to_return.= '</tr>';

				$html_to_return.= '</tbody>';
				$html_to_return.= '</table>';
			
			}else{
				//is not vendor
				$html_to_return = __('N/A','mangopay');
			}
			
			return $html_to_return;
		}
		
//		if('mp_account' == $column_name){
//			/** We store a different mp_user_id for production and sandbox environments **/
//			$umeta_key = 'mp_user_id';
//			if( !$this->mp->is_production() )
//				$umeta_key .= '_sandbox';
//
//			$mp_user_id = get_user_meta( $wp_user_id, $umeta_key, true );
//			if( $mp_user_id ) {
//				return __( 'Yes', 'mangopay' );
//			} else {
//				return __( 'No', 'mangopay' );
//			}
//		}
//		
//		if('mp_kyc' == $column_name){
//			$user_data = get_userdata($wp_user_id);
//			if(isset($user_data->caps['vendor']) && $user_data->caps['vendor'] == 1){
//				$kyc_result = $this->mangopayWCMain->test_vendor_kyc($wp_user_id);				
//				if(!$kyc_result){
//					$kyc = __( 'No', 'mangopay' );
//				} else {
//					if($kyc_result === true){
//						$kyc = __( 'Yes', 'mangopay' );
//					}					
//					if($kyc_result === 'no_account_bd'){
//						$kyc = __( 'No', 'mangopay' );
//					}
//					if($kyc_result === 'no_count_mp_found'){					
//						$kyc = __( 'No', 'mangopay' );
//					}
//				}
//				
//				//test ubos
//				$ubo_result = $this->mp->test_vendor_ubo($wp_user_id);	
//				if(!$ubo_result){
//					$ubo = __( 'No', 'mangopay' );
//				} else if($ubo_result === true){
//					$ubo = __( 'Yes', 'mangopay' );
//				}
//				
//				return $kyc.'/'.$ubo;
//				
//			}else{
//				return '';
//			}
//		}
//		
////		if('mp_tc' == $column_name){
////			$tc = get_user_meta( $wp_user_id, 'wcv_policy_terms', true );
////			if(!$tc || trim($tc)==''){
////				return __( 'No', 'mangopay' );
////			} else {
////				return __( 'Yes', 'mangopay' );
////			}			
////		}
//		
//		if('mp_compagnynumber' == $column_name){
//			$compagny_number = get_the_author_meta( 'compagny_number', $wp_user_id );			
//			$user_mp_status = get_user_meta($wp_user_id, 'user_mp_status', true);
//			$user_business_type = get_user_meta($wp_user_id, 'user_business_type', true);
//			if($user_mp_status == 'business' && $user_business_type == 'business'){
//				if(!$compagny_number){
//					return __( 'No', 'mangopay' );
//				} else {
//					return __( 'Yes', 'mangopay' );
//				}
//			}else{
//				return '';
//			}
//		}
		
		return $value;
	}
	
	/**
	 * Manage the sorting of our custom column
	 *
	 * @param object $query
	 * @return object $query
	 * 
	 */
	public function user_column_orderby( $query ) {
	
		if( 'WP_User_Query' != get_class( $query ) )
			return $query;
	
		$vars = $query->query_vars;
	
		//echo '<pre>'; var_dump( get_class( $query ) ); echo '</pre>'; exit; //Debug
	
		if ( !isset( $vars['orderby'] ) || 'has_mp_account' != $vars['orderby'] )
			return $query;
	
		/** We store a different mp_user_id for production and sandbox environments **/
		$umeta_key = 'mp_user_id';
		if( !$this->mp->is_production() )
			$umeta_key .= '_sandbox';
	
		global $wpdb;
		$query->query_from .= " LEFT JOIN $wpdb->usermeta m ON ($wpdb->users.ID = m.user_id  AND m.meta_key = '$umeta_key')";
		$query->query_orderby = "ORDER BY m.meta_value ".$vars['order'];
	
		return $query;
	}
	

	/**
	 * Display custom info on the order admin screen (DEBUG mode only)
	 * (this part adds the meta box)
	 * hooks $this->metabox_order_data()
	 *
	 */
	public function add_meta_boxes() {
	
		if( !mangopayWCConfig::DEBUG )
			return;
	
		foreach ( wc_get_order_types( 'order-meta-boxes' ) as $type ) {
			$order_type_object = get_post_type_object( $type );
			add_meta_box(
			'woocommerce-order-mpdata',
			sprintf( __( '%s MANGOPAY Data', 'mangopay' ), $order_type_object->labels->singular_name ),
			array( $this, 'metabox_order_data' ),
			$type,
			'normal',
			'high'
					);
		}
	}
	
	/**
	 * Display custom info on the order admin screen
	 * (this part does the display)
	 * hooked by $this->add_meta_boxes()
	 *
	 */
	public function metabox_order_data( $post ) {
		$order = new WC_Order( $post->ID );
		if( class_exists( 'WCV_Vendors' ) ){
			$dues  = WCV_Vendors::get_vendor_dues_from_order( $order, false );
			echo '<h3>WCV_Vendors::get_vendor_dues_from_order</h3><pre>';
			var_dump( $dues );
			echo '</pre>';
			echo '<h3>mangopay_payment_type post_meta</h3><pre>';
			var_dump( get_post_meta( $post->ID, 'mangopay_payment_type', true ) );
			echo '</pre>';
			echo '<h3>mangopay_payment_ref post_meta</h3><pre>';
			var_dump( get_post_meta( $post->ID, 'mangopay_payment_ref', true ) );
			echo '</pre>';
		}
	}
	
	/**
	 * Adds a new bulk action to the WV back-office Commissions screen
	 * To make MP payments of commissions
	 *
	 * @param array $actions
	 *
	 * This hook does not work to add bulk actions :/
	 public function bulk_actions( $actions ) {
	 $actions['mp_payout'] = __( 'MP Payout', 'mangopay' );
	 //var_dump( $actions );exit;
	 return $actions;
	 }*/
	public function addBulkActionInFooter() {
		?>
		<script>
		(function($) {
			$(document).ready(function() {
				$('<option>').val('mp_payout').text('<?php _e( 'MANGOPAY Payout', 'mangopay' ); ?>').appendTo("select[name='action']");
				$('<option>').val('mp_payout').text('<?php _e( 'MANGOPAY Payout', 'mangopay' ); ?>').appendTo("select[name='action2']");
			});
		})( jQuery );
		</script>
		<?php 
	}
	
	/**
	 * Custom bulk action on the WV vendor commission admin screen
	 * This will perform MP payouts to vendors if the vendor has an active
	 * MP bank account registered. Otherwise an error will be displayed.
	 * Due commissions will be applied.
	 *
	 */
	public function vendor_payouts() {
		$wp_list_table = _get_list_table('WP_Posts_List_Table');
		$action = $wp_list_table->current_action();
		if( 'mp_payout' != $action ) {
			return;
		}

		if( !isset($_REQUEST['id']) || !$_REQUEST['id'] || !is_array($_REQUEST['id']) ) {
			return;
		}
		
		/** Failed payouts can only be retried once **/
		if( !empty( $_REQUEST['mp_initial_transaction'] ) ) {
			$ressource_id = $_REQUEST['mp_initial_transaction'];
			$mp_ignored_failed_po = get_option( 'mp_ignored_failed_po', array() );
			if( in_array( $ressource_id, $mp_ignored_failed_po ) ) {
				echo '<div class="error">';
				echo '<p>' . __( '-Error: this commission payout has already been retried:', 'mangopay' ) . ' ' .
					'#' . $ressource_id . '.</p>';
				echo '</div>';
				return;
			}
		}

		echo '<div class="updated">';
		echo '<p>' . __( 'Paying selected vendors...', 'mangopay' ) . '</p>';
		echo '</div>';

		$commission_ids = $_REQUEST['id'];
		foreach( $commission_ids as $pv_commission_id ) {

			/**
			 * The bulk action id parameter refers to an entry of WV's
			 * custom pv_commission table.
			 * We must query this table to get order and vendor info
			 * @see /plugins/wc-vendors/classes/class-commission.php
			 *
			 */
			$row = $this->mp->get_wcv_commission_line($pv_commission_id);
			if($row){
				$wp_user_id = $row->vendor_id;
			}else {
				echo '<div class="error">';
				echo '<p>' . __( '-Error: bad wc-vendors commission ID:', 'mangopay' ) . ' ' .
					'#' . $pv_commission_id . '.</p>';
				echo '</div>';
				continue;
			}
	
			$vendor_info	= get_userdata( $wp_user_id );
			$pv_shop_name	= get_user_meta( $wp_user_id, 'pv_shop_name', true );
			if( !$pv_shop_name ) {
				$pv_shop_name = $vendor_info->display_name;
			}
			$admin_link		= 'user-edit.php?user_id=' . $wp_user_id;
					
			if(
				'due' != $row->status &&
				empty( $_REQUEST['mp_initial_transaction'] )
			) {
				//TODO: translation string to convert as sprintf %s...
				echo '<div class="updated">';
				echo '<p>' . __( '-Commission of', 'mangopay' ) . ' ' . $pv_shop_name . ' ' .
					__( 'on order', 'mangopay' ) . ' ' . $row->order_id . ' ' .
					__( 'is already marked as', 'mangopay' ) . ' ' . $row->status . '. ' .
					__( 'Skipping.', 'mangopay' ) . '</p>';
				echo '</div>';
				continue;
			}

			/** We store a different mp_account_id for production and sandbox environments **/
			$umeta_key = 'mp_account_id';
			if( !$this->mp->is_production() ) {
				$umeta_key .= '_sandbox';
			}

			/* DEBUG *
			echo "<pre>", print_r("TEST ----------- ", 1), "</pre>";
			echo "<pre>", print_r($wp_user_id, 1), "</pre>";
			echo "<pre>", print_r($umeta_key, 1), "</pre>";
			echo "<pre>", print_r(get_user_meta( $wp_user_id, $umeta_key, true ), 1), "</pre>";
			* */
			
			if( !$mp_account_id = get_user_meta( $wp_user_id, $umeta_key, true ) ) {
				//TODO: translation string to convert as sprintf %s...
				echo '<div class="error">';
				echo '<p>' . __( '-Warning: vendor', 'mangopay' ) . ' ' .
					'<a href="' . $admin_link . '">&laquo;' . $pv_shop_name . '&raquo;</a> ' .
					'(#' . $wp_user_id . ') ' .
					__( 'does not have a MANGOPAY bank account', 'mangopay' ) . '</p>';
				echo '</div>';
			} else {

				/**
				 * Initiate MP payout transaction
				 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/payout.php
				 *
				 */
				$order_id	= $row->order_id;
				$currency	= get_woocommerce_currency();
				$total_due = $this->mp->payout_calcul_amount($pv_commission_id);
				$result = false;
				if($total_due){
					$total_due	= apply_filters( 'mp_commission_due', $total_due , $row ); 
					$fees		= 0;
					$result		= $this->mp->payout( $wp_user_id, $mp_account_id, $order_id, $currency, $total_due, $fees );
				}
				if(	
					isset( $result->Status ) &&
					( 'SUCCEEDED' == $result->Status || 'CREATED' == $result->Status )
				) {
					//hook
					do_action('mangopay_payout_success',$order_id,$result);
					
					//TODO: translation string to convert as sprintf %s...
					$this->mangopayWCMain->set_commission_paid( $pv_commission_id );
					echo '<div class="updated">';
					echo '<p>' . __( '-Success: commission paid to vendor', 'mangopay' ) . ' ' .
						'<a href="' . $admin_link . '">&laquo;' . $pv_shop_name . '&raquo;</a> ' .
						'</p>';
					echo '</div>';
				} else {
					//TODO: translation string to convert as sprintf %s...
					echo '<div class="error">';
					echo '<p>' . __( '-Error: vendor', 'mangopay' ) . ' ' .
						'<a href="' . $admin_link . '">&laquo;' . $pv_shop_name . '&raquo;</a> ' .
						'(#' . $wp_user_id . ') ' .
						__( 'MANGOPAY payout transaction failed', 'mangopay' ) . '</p>';
					if( isset($result->ResultMessage) && $result->ResultMessage ) {
						echo '<p>' . $result->ResultMessage . '</p>';
					}
					echo '</div>';								
				} //endif

				/**
				 * If this is a failed payout retry from the dashboard widget,
				 * hide the original transaction
				 *
				 */
				if( !empty( $_REQUEST['mp_initial_transaction'] ) ) {
					$ressource_id = $_REQUEST['mp_initial_transaction'];
					$mp_ignored_failed_po = get_option( 'mp_ignored_failed_po', array() );
					if( $ressource_id && !in_array( $ressource_id, $mp_ignored_failed_po ) ) {
						$mp_ignored_failed_po[] = $ressource_id;
						update_option( 'mp_ignored_failed_po', $mp_ignored_failed_po );
					}
				}
			} //endif
		} //endforeach
	} //end function

	/**
	 * If the bankwire payment method is enabled,
	 * this will check that a webhook callback is registered with the MP API
	 * 
	 */
	public function register_all_webhooks() {
		if( 
			!isset( $_POST['woocommerce_mangopay_enabled_BANK_WIRE'] ) ||
			!1 == $_POST['woocommerce_mangopay_enabled_BANK_WIRE']
		)
			return;

		if( !isset( $this->options['webhook_key'] ) || !$this->options['webhook_key'] )
			$this->generate_webhook_key();
		
		/* We normally do not display this for security reasons *
		echo '<div class="updated"><p>' .
			__( 'Your MANGOPAY webhook key is: ', 'mangopay' ) .
			$this->options['webhook_key'] .
			'</p></div>';
		/* */
		
		$success = true;
		$error_notices = array();
		
		/** Check the PAYIN_NORMAL_SUCCEEDED hook **/
		$success1 = $this->register_webhook( mpAccess::PAYIN_SUCCESS_HK );
		
		/** Check the PAYIN_NORMAL_FAILED hook **/
		$success2 = $this->register_webhook( mpAccess::PAYIN_FAILED_HK );
		
		if( $success1 && $success2 ) {
			echo '<div class="updated"><p>' .
				__( 'The webhooks for Bankwire Direct payment are properly setup.', 'mangopay' ) .
				'</p></div>';
			
		} else {
			if( $error_notices )
				foreach( $error_notices as $notice )
					echo $notice;
			
			echo '<div class="notice notice-error"><p>' .
				__( 'MANGOPAY Error: invalid webhook setup for Bankwire Direct', 'mangopay' ) .
				'</p></div>';
		}
		
		return $success;
	}
	
	/**
	 * Register a webhook callback of the specified type with the MP API
	 * 
	 * @param string $event_type
	 */
	private function register_webhook( $event_type ) {

		$success = true;

		if( $hook = $this->mp->get_webhook_by_type( $event_type ) ) {
				
			if( !$this->mp->hook_is_valid( $hook ) ) {
				$error_notices[] = '<div class="notice notice-error"><p>' .
						sprintf(
								__( 'Error: the MANGOPAY %1$s webhook is DISABLED or INVALID - please update it via %2$s', 'mangopay' ),
								$event_type,
								'<a href="' . $this->mp->getDBWebhooksUrl() . '" target="_out">' .
								__( 'the Dashboard', 'mangopay' ) .
								'</a>'
						) .
						'</p></div>';
		
				$success = $this->mp->update_webhook(
						$hook,
						mangopayWCWebHooks::WEBHOOK_PREFIX,
						$this->options['webhook_key'],
						$event_type
				);
			}
				
			if ($success ) {
				$inboundPayinWPUrl = site_url(
						mangopayWCWebHooks::WEBHOOK_PREFIX . '/' .
						$this->options['webhook_key'] . '/' .
						$event_type
				);
				if( $hook->Url != $inboundPayinWPUrl ) { // $inboundPayinWPUrl being the URL does not match the URL that it should be for this WP setup
					$error_notices[] = '<div class="notice notice-error"><p>' .
							sprintf(
									__( 'Error: the URL of the MANGOPAY %1$s webhook is not correct and should be %2$s - please update it via %3$s', 'mangopay' ),
									$event_type,
									'<a href="' . $inboundPayinWPUrl . '">' . $inboundPayinWPUrl . '</a>',
									'<a href="' . $this->mp->getDBWebhooksUrl() . '" target="_out">' .
									__( 'the Dashboard', 'mangopay' ) .
									'</a>'
							) .
							'</p></div>';
		
					$success = $this->mp->update_webhook(
							$hook,
							mangopayWCWebHooks::WEBHOOK_PREFIX,
							$this->options['webhook_key'],
							$event_type
					);
				}
			}
				
		} else {
			$success = $this->mp->create_webhook(
					mangopayWCWebHooks::WEBHOOK_PREFIX,
					$this->options['webhook_key'],
					$event_type
			);
		}
		
		return $success;
	}
	
	/**
	 * Generate a unique webhook endpoint that will be used for this site
	 * This prevents outside persons to try and send webhooks to our site
	 * without knowing that somewhat secret and unique key
	 * 
	 */
	private function generate_webhook_key() {
		$this->options['webhook_key'] = md5( time() );
		$this->mangopayWCMain->options['webhook_key'] = $this->options['webhook_key'];
		update_option ( mangopayWCConfig::OPTION_KEY, $this->options );
	}
	
	/**
	 * Box to display pre-authorization meta-data
	 * 
	 * @global type $post
	 * @global type $woocommerce
	 */
	public function preauth_block_data() { 
		
		global $post;
		global $woocommerce; 
		echo '<div id="postcustomstuff">';
		if( get_post_type($post->ID) == 'shop_order' ){
			$order_id = $post->ID;
			$pre_auth_id = get_post_meta($order_id,'preauthorization_id',true);

			if($pre_auth_id){
				$result = mpAccess::getInstance()->get_preauthorization_by_id($pre_auth_id);
				if($result['success']){

					echo '<div id="result_capture"><p>';
					
						$display_waiting = "display:none;";
						$display_capture = "display:none;";
						$display_cancel = "display:none;";
						$display_expired = "display:none;";
						if($result['result']->PaymentStatus == "WAITING"){
							$display_waiting = '';
						}
						if($result['result']->PaymentStatus == "VALIDATED"){
							$display_capture = '';
						}
						if($result['result']->PaymentStatus == "CANCELED"){
							$display_cancel = '';
						}
						if($result['result']->PaymentStatus == "EXPIRED"){
							$display_expired = '';
						}
						
						echo '<div id="result_capture_waiting" style="'.$display_waiting.'">';
							echo __( "Payment for this order has been successfully pre-authorized by MANGOPAY. Click the [Capture] button to withdraw the full pre-authorized amount from the customer's card and capture the effective payment (ie to cash-in a deposit). Click the [Cancel] button to avoid withdrawing any funds and cancel this pre-authorization (ie to refund a deposit).",'mangopay');
						echo '</div>';

						echo '<div id="result_capture_captured" style="'.$display_capture.'">';
							echo	__("The pre-authorized payment was captured (ie the deposit was cashed-in).",'mangopay');
							
							if(isset($result['result']->PayInId) && $result['result']->PayInId!=0 && $result['result']->PayInId!=''){
								$data_payin = mpAccess::getInstance()->get_payin($result['result']->PayInId);
								if($data_payin && isset($data_payin->CreditedFunds->Amount)){
									echo '<div>';
									echo __( 'The captured amount is:', 'mangopay' ) . ' ' . 
										wc_price( $data_payin->CreditedFunds->Amount / 100 );
									echo '</div>';
								}
							}
						echo '</div>';

						echo '<div id="result_capture_canceled" style="'.$display_cancel.'">';
							echo __("The pre-authorized payment was cancelled (ie the deposit was refunded)." ,'mangopay');
						echo '</div>';

						echo '<div id="result_capture_expired" style="'.$display_expired.'">';
							echo __("The pre-authorized payment expired (ie the deposit cannot be cashed-in)." ,'mangopay');							
							if(isset($result['result']->PayInId) && $result['result']->PayInId!=0 && $result['result']->PayInId!=''){
								$data_payin = mpAccess::getInstance()->get_payin($result['result']->PayInId);
								if($data_payin && isset($data_payin->CreditedFunds->Amount)){
									echo '<div>';
									echo __( 'The captured amount is:', 'mangopay') . ' ' .
										wc_price( $data_payin->CreditedFunds->Amount / 100 );
									echo '</div>';
								}
							}
						echo '</div>';
						
						echo '<div id="waitingmessage_capture" style="display:none;">';
							echo __( 'Processing...', 'mangopay' );
						echo '</div>';
						
					echo '</p></div>';
					
					/** Can be captured **/
					if($result['result']->Status == "SUCCEEDED" && $result['result']->PaymentStatus == "WAITING"){
						
						$order = new WC_Order($order_id);
						$mp_user_id = $result['result']->AuthorId;						
						/** The locale optional parameter for payins is unused right now **/
						$locale = 'EN';	// Needed but unused
						
						echo '<div class="capturebuttondiv">';							
							echo '<input type="hidden" id="applycompletecapture" name="applycompletecapture" value="false">';		
							echo '<input type="hidden" name="PreauthorizationId" value="'.$result['result']->Id.'">';
							echo '<input type="hidden" name="mp_user_id" value="'.$mp_user_id.'">';
							echo '<input type="hidden" name="locale" value="'.$locale.'">';
							/** Capture a part of the pre-authorization **/					
							echo '<input id="capture_preauth_button" class="button button-primary partialcapturepreauth" '
										. 'data-PreauthorizationId="'.$result['result']->Id.'" '
										. 'data-order_id="'.$order_id.'" '
										. 'data-order_total="'.$order->get_total().'" '
										. 'data-mp_user_id="'.$mp_user_id.'" '
										. 'data-locale="'.$locale.'" '	
										. 'type="button" '
										. 'value="' . __( 'Capture', 'mangopay' ) . '">';

							echo '&nbsp;';

							/** Cancel the pre-authorization by Ajax **/
							echo '<input type="hidden" id="cancelcapture" name="cancelcapture" value="false">';
							echo '<input id="cancel_preauth" class="button button-primary cancelpreauth" '
										. 'data-PreauthorizationId="'.$result['result']->Id.'" '
										. 'data-order_id="'.$order_id.'" '
										. 'type="button" '
										. 'value="' . __( 'Cancel', 'mangopay' ) . '">';							
						echo '</div>';							

					} //endif result success
				}
				
			} else {
				echo __( 'No pre-authorization', 'mangopay' );
			}
		}
		echo "</div>";
		
		//v2.01/ClientId/preauthorizations/PreAuthorizationId/
		//echo '<div class="order_data_column">'; // will be closed after the do_action(), do not close in here		
		//echo "<h1>Pre authorization</h1>";
	}


	/**
	 * force update the vendor commission
	 * @global type $wpdb
	 * @param type $order_id
	 */
	public function mp_calculate_order_commissions( $order_id ) {

		$order = new WC_Order($order_id);		
		foreach( $order->get_items() as $item ) {
			
			$product       = wc_get_product( $item->get_product_id() );
			$order_id      = $item->get_order_id();
			$product_price = $item->get_total();
			$product_id    = $product->get_id();
			$qty           = $item->get_quantity();
			$vendor_id     = get_post_field( 'post_author', $product->get_id());

			$commission    = WCV_Commission::calculate_commission( $product_price, $product_id, $order, $qty );
			
			$data = array(
				'qty'            => $qty,
				'total_due'      => $commission,
				'time'           => date_i18n( 'Y-m-d H:i:s', current_time('timestamp') ),
			);

			global $wpdb;
			$table = $wpdb->prefix . "pv_commission";

			$where = array(
				'vendor_id'  => $vendor_id,
				'order_id'   => $order_id,
				'product_id' => $product_id
			);

			// Is the commission already paid?
			$count = WCV_Commission::check_commission_status( $where, 'paid' );

			if ( 0 == $count ) {
				$update = $wpdb->update( $table, $data, $where  );
			}
		}
	}
	
	/**
	 * 
	 * @param type $data
	 * @param type $postarr
	 * @return string
	 */
	public function post_save_preauth_process( $data,$postarr ) {
		
		if($data['post_type']!='shop_order'){
			return $data;
		}
		
		$mp_user_id = false;
		if(isset($postarr['mp_user_id'])){
			$mp_user_id			= $postarr['mp_user_id'];
		}
		$PreauthorizationId = false;
		if(isset($postarr['PreauthorizationId'])){
			$PreauthorizationId = $postarr['PreauthorizationId'];
		}
		$order_id = false;
		if(isset($postarr['post_ID'])){
			$order_id			= $postarr['post_ID'];
		}
		$locale = false;
		if(isset($postarr['locale'])){
			$locale				= $postarr['locale'];
		}		
		
		/** force calcul commission wcv  **/
		if(!class_exists('WCVendors_Pro_Commission_Controller') 
			|| !method_exists('WCVendors_Pro_Commission_Controller','calculate_order_commissions')){
			$this->mp_calculate_order_commissions($order_id);
		}
				
		/** CANCEL **/
		if($PreauthorizationId 
			&& $order_id
			&& isset($postarr['cancelcapture']) 
			&& ($postarr['cancelcapture'] == "true" || $postarr['cancelcapture']===true)){
			$tag = 'WC Order #' . $order_id;
			$result = mpAccess::getInstance()->cancel_preathorization_by_id($PreauthorizationId,$order_id,$tag);
			
			if(isset($result['success']) && isset($result['result']->PaymentStatus)){		
				remove_filter( 'wp_insert_post_data' , array( $this, 'woocommerce_change_preauth_amount' ), 99, 2 );
				/** Change the order status **/
				$order = new WC_Order($order_id);
				$order->update_status('cancelled');
				$order->save();				
				add_filter( 'wp_insert_post_data' , array( $this, 'woocommerce_change_preauth_amount' ), 99, 2 );
				$data['post_status'] = "wc-cancelled";
			}
			
			return $data;
		}
				
		/** FULL CAPTURE **/
		if($PreauthorizationId 
			&& $order_id
			&& !($postarr['cancelcapture'] == "true" || $postarr['cancelcapture']===true)
			&& ($postarr['applycompletecapture'] == "true" || $postarr['applycompletecapture']===true)
			){
			remove_filter( 'wp_insert_post_data' , array( $this, 'woocommerce_change_preauth_amount' ), 99, 2 );

			$result = mpAccess::getInstance()->capture_pre_authorization_by_id($order_id,$mp_user_id,$locale,$PreauthorizationId);

			if(isset($result->Status) && $result->Status == "SUCCEEDED"){			
				/** Change the order status **/
				$order = new WC_Order($order_id);
				$order->update_status('processing');
				$order->save();
				$data['post_status'] = "wc-processing";
			}else{
				$transaction_id = mpAccess::getInstance()->get_preauthorization_initial_amount($PreauthorizationId);

				$intial_amount = 0;
				if($transaction_id){
					$intial_amount = number_format(round(floatval($transaction_id->DebitedFunds->Amount)/100,2), 2, ',', ' ');
					$intial_amount_currency = $transaction_id->DebitedFunds->Currency;
				}
				
				$preauth_message_order_admin = sprintf( __( 'The pre-authorized amount is %1$s %2$s.', 'mangopay' ), $intial_amount_currency, floatval( $intial_amount ) ) .
					' ' . __( 'You cannot capture more than this amount for this order', 'mangopay' );
				
				update_post_meta( $order_id, 'preauth_message_order_admin', $preauth_message_order_admin);
			}
			
			add_filter( 'wp_insert_post_data' , array( $this, 'woocommerce_change_preauth_amount' ), 99, 2 );			
			return $data;
		}		
		
		return $data;
	}
	
	/**
	 * Add meta box to the admin woocommerce order
	 * Informations and action for  the pre authorization
	 * @global type $post
	 */
	public function preauth_add_meta_boxes(){
		
		global $post;
		if(get_post_type($post->ID) == "shop_order"){

			$order_id = $post->ID;
			$pre_auth_id = get_post_meta($order_id,'preauthorization_id',true);
			
			if($pre_auth_id){
				add_meta_box( 'preauth_data', 
					__('Pre-authorization','mangopay'), 
					array( $this,'preauth_block_data'), 
					'shop_order', 
					'normal',
					'core' );
			}
		}
    }
	
	/**
	 * When update the plugin, check num version and change options accordingly
	 * @param type $package
	 * @param type $type_upgrade
	 */
	public function change_options_installplugin( $package,$type_upgrade ){
		//only on update of the mangopay plugin
		if(
			$type_upgrade['action'] == "update"
			&& $type_upgrade['type'] == "plugin"
			&& $type_upgrade['plugins'][0] == "mangopay-woocommerce/mangopay-woocommerce.php"
		){			
			//TEST EN DUR 3.5.2
			$version_number = intval(str_replace('.','',$package->skin->plugin_info['Version']));
			if($version_number<352){
				//version currently replace is inferieur
				//-> we force check
				$wc_settings = get_option( 'woocommerce_mangopay_settings' );
				$wc_settings['show_optional_user_fields'] = "yes";
				update_option( 'woocommerce_mangopay_settings', $wc_settings);
			}
			
		}
	}
}
?>