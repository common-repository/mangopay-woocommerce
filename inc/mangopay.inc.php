<?php
/**
 * MANGOPAY WooCommerce plugin MANGOPAY access class
 * 
 * @author yann@wpandco.fr & Silver
 * @see: https://github.com/Mangopay/wordpress-plugin
 * 
 * Comment shorthand notations:
 * WP = WordPress core
 * WC = WooCommerce plugin
 * WV = WC-Vendor plugin
 * DB = MANGOPAY dashboard
 * 
 */
class mpAccess {

	/** Class constants **/
	const DEBUG 			= false;	// Turns debugging messages on or off (should be false for production)
	const TMP_DIR_NAME		= 'mp_tmp';
	const SANDBOX_API_URL	= 'https://api.sandbox.mangopay.com';
	const PROD_API_URL		= 'https://api.mangopay.com';
	const SANDBOX_DB_URL	= 'https://dashboard.sandbox.mangopay.com';
	const PROD_DB_URL		= 'https://dashboard.mangopay.com';
	const LOGFILENAME		= 'mp-transactions.log.php';
	const WC_PLUGIN_PATH	= 'woocommerce/woocommerce.php';
	const WV_PLUGIN_PATH	= 'wc-vendors/class-wc-vendors.php';
	const PAYIN_SUCCESS_HK	= 'PAYIN_NORMAL_SUCCEEDED';
	const PAYIN_FAILED_HK	= 'PAYIN_NORMAL_FAILED';
	const USER_CATEGORY_OWNER = 'Owner';
	const USER_CATEGORY_PAYER = 'Payer';
	
	/** Class variables **/
	private $mp_loaded		= false;
	private	$mp_production	= false;	// Sandbox environment is default
	private $mp_client_id	= '';
	private $mp_client_id_error	= '';
	private $mp_passphrase	= '';
	private $mp_db_url		= '';
	private $logFilePath	= '';
	private $errorStatus	= false;
	private $errorMsg;
	private $mangoPayApi;
	
	/**
	 * @var Singleton The reference to *Singleton* instance of this class
	 * 
	 */
	private static $instance;
	
	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @return Singleton The *Singleton* instance.
	 * 
	 */
	public static function getInstance() {
		if( null === static::$instance )
			static::$instance = new mpAccess();
	
		return static::$instance;
	}
	
	/**
	 * Protected constructor to prevent creating a new instance of the
	 * Singleton via the `new` operator from outside of this class.
	 * 
	 */
	protected function __construct() {
		//$this->init();
	}
	
	/**
	 * Sets the MANGOPAY environment to either 'Production' or 'Sandbox'
	 * @param unknown $environment
	 * 
	 */
	public function setEnv( 
		$prod_or_sandbox, 
		$client_id, 
		$passphrase, 
		$default_buyer_status, 
		$default_vendor_status,
		$default_business_type,
		$debug 
	) {
		if( 'prod' != $prod_or_sandbox && 'sandbox' != $prod_or_sandbox ){
			return false;
		}
		
		$this->mp_client_id			= trim($client_id);		
		$this->mp_passphrase		= $passphrase;
		$this->default_buyer_status	= $default_buyer_status;
		$this->default_vendor_status= $default_vendor_status;
		$this->default_business_type= $default_business_type;
		$this->mp_db_url			= self::SANDBOX_DB_URL;
		
		/** @var $this->mp_production is false by default **/
		if( 'prod' == $prod_or_sandbox ) {
			$this->mp_production	= true;
			$this->mp_db_url		= self::PROD_DB_URL;
		}
		$this->init();
	}

	/**
	 * Test if the MP client id is well-formed
	 * @param type $client_id
	 * @return boolean
	 * 
	 */
	public function test_mp_client_id($client_id){

		/** Test if not empty **/
		if( empty( $client_id ) ){
			$this->mp_client_id_error = __( 'Client ID empty.', 'mangopay' );
			return false;
		}
	
		/** Test if string is less than 2 char **/
		if( strlen( $client_id ) < 2 ){
			$this->mp_client_id_error = __( 'Client ID has too few characters.', 'mangopay' );
			return false;
		}

		/** Test if only alphanumeric **/
		/* DO NOT TEST ANYMORE 19/09/2019
		if( !ctype_alnum( $client_id ) ){		
			$this->mp_client_id_error = __( 'Client ID is not alphanumeric.', 'mangopay' );
			return false;
		}
		 * */
	
		/** Test if URL encoding will be same as original **/
		if( $client_id != urlencode( $client_id ) ){		
			$this->mp_client_id_error = __( 'Client ID is not URL-compatible.', 'mangopay' );
			return false;
		}
	
		return $client_id;
	}

	/**
	 * Returns class error status
	 * 
	 * @return array $status
	 * 
	 */
	public function getStatus( $mangopayWCMain ) {
		
		/** Checks that at least one card/payment method is enabled **/
		$card_enabled = false;
		if(	$wc_settings = get_option( 'woocommerce_mangopay_settings' ) ) {
			if( is_array( $wc_settings ) && isset( $wc_settings['enabled'] ) ) {
				$enabled = $wc_settings['enabled'];
				foreach( $wc_settings as $key=>$value )
					if( preg_match( '/^enabled_/', $key ) && 'yes' == $value )
						$card_enabled	= true;
			} else {
				$enabled = false;
			}
		} else {
			if( false === $wc_settings ) {
				/** When the option is not set at all the default is true **/
				$enabled		= 'yes';
				$card_enabled	= true;
			} else {
				$enabled		= false;
			}
		}
		
		/** If Bankwire Direct payment is enabled, check that the incoming webhooks are registered **/
		$webhook_status = false;
		if( $wc_settings && isset( $wc_settings['enabled_BANK_WIRE'] ) && 'yes' == $wc_settings['enabled_BANK_WIRE'] ) {
			$bankwire_enabled = true;
			
			$webhook_status = (
				$this->check_webhook( $mangopayWCMain->options['webhook_key'], self::PAYIN_SUCCESS_HK ) &&
				$this->check_webhook( $mangopayWCMain->options['webhook_key'], self::PAYIN_FAILED_HK )
			);
			
		} else {
			$bankwire_enabled = false;
		}
		
		$status = array(
			'status'			=> $this->errorStatus,
			'message'			=> $this->errorMsg,
			'environment'		=> $this->mp_production,
			'client_id'			=> $this->mp_client_id,
			'client_id_error'	=> $this->mp_client_id_error,
			'loaded'			=> $this->mp_loaded,
			'enabled'			=> $enabled,
			'card_enabled'		=> $card_enabled,
			'bankwire_enabled'	=> $bankwire_enabled,
			'webhook_status'	=> $webhook_status
		);
		return $status;
	}
	
	/**
	 * MANGOPAY init
	 * loads and instantiates the MANGOPAY API
	 * 
	 */
	private function init() {
		
		/** Setup tmp directory **/
		$tmp_path = $this->set_tmp_dir();
		
		$this->logFilePath	= $tmp_path . '/' . self::LOGFILENAME;
		
		/** Initialize log file if not present **/
		if( !file_exists( $this->logFilePath ) ){
			file_put_contents( $this->logFilePath, '<?php header("HTTP/1.0 404 Not Found"); echo "File not found."; exit; /*' );
		}

		/** Add a .htaccess to mp_tmp dir for added security **/
		$htaccess_path = $tmp_path . '/' . '.htaccess';
		if( !file_exists( $htaccess_path ) )
			file_put_contents( $htaccess_path, "order deny,allow\ndeny from all\nallow from 127.0.0.1" );
		$htaccess_path = dirname( $tmp_path ) . '/' . '.htaccess';
		if( !file_exists( $htaccess_path ) )
			file_put_contents( $htaccess_path, "order deny,allow\ndeny from all\nallow from 127.0.0.1" );
		
		/** Instantiate MP API **/
		$sdk_dir = dirname( dirname( __FILE__ ) ) . '/sdk';
		require_once( $sdk_dir . '/MangoPay/Autoloader.php' );
		require_once( 'mock-storage.inc.php' );
		
		$this->mangoPayApi = new MangoPay\MangoPayApi();
		
		/** MANGOPAY API configuration **/
		$this->mangoPayApi->Config->ClientId		= $this->mp_client_id;
		$this->mangoPayApi->Config->ClientPassword	= $this->mp_passphrase;
		$this->mangoPayApi->Config->TemporaryFolder	= $tmp_path . '/';
		$this->mangoPayApi->OAuthTokenManager->RegisterCustomStorageStrategy(new \MangoPay\WPPlugin\MockStorageStrategy());
		if( $this->mp_production ) {
			$this->mangoPayApi->Config->BaseUrl 	= self::PROD_API_URL;
		} else {
			$this->mangoPayApi->Config->BaseUrl 	= self::SANDBOX_API_URL;
		}
		
//		$num_woocomemrce_version = 0;
//		if ( class_exists( 'WooCommerce' ) ) {
//			
//		}
//		global $woocommerce;
//		echo "<pre>", print_r("num_woocomemrce_version", 1), "</pre>";
//		echo "<pre>", print_r($woocommerce->version, 1), "</pre>";
//		die();
//		$add_httpheader = 'MANGOPAY-WooCommerce 0';
//		$a = $this->mangoPayApi->httpClient()->AddRequestHttpHeader($add_httpheader);
		
		return true;
	}
	
	/**
	 * Setup temporary directory
	 * 
	 * @return string
	 */
	private function set_tmp_dir() {
		$uploads			= wp_upload_dir();
		$uploads_path		= $uploads['basedir'];
		$prod_or_sandbox 	= 'sandbox';
		if( $this->mp_production )
			$prod_or_sandbox = 'prod';
		$tmp_path			= $uploads_path . '/' . self::TMP_DIR_NAME . '/' . $prod_or_sandbox;
		wp_mkdir_p( $tmp_path );
		return $tmp_path;
	}
		
	/**
	 * Simple API connection test
	 * @see: https://gist.github.com/hobailey/105c53717b8547ba66d7
	 *
	 */
	public function connection_test() {

		if( !self::getInstance()->mp_loaded ){
			$this->init();
		}
		
		if( !$this->test_mp_client_id( $this->mp_client_id ) ){
			echo '<div class="error">';
				echo '<p>';
					echo __( 'MANGOPAY connection test aborted: Malformed Client ID.', 'mangopay' ) . ' ';
					echo $this->mp_client_id_error . ' '; //Already translated
				echo '</p>';
			echo '</div>';
			return false;
		}

		try{
			$pagination	= new MangoPay\Pagination( 1, 1 );
			$sorting	= new \MangoPay\Sorting();
			$sorting->AddField( 'CreationDate', \MangoPay\SortDirection::DESC );
			$result 	= $this->mangoPayApi->Users->GetAll( $pagination, $sorting );
			
			$this->mp_loaded = true;
			return $result;
				
		} catch (MangoPay\Libraries\ResponseException $e) {
		
			echo '<div class="error"><p>' . __( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
						
			$traces = $e->getTrace();
			$error_description = '';
			if($traces){
				foreach($traces as $trace){
					if(isset($trace['args']) && count($trace['args'])>0){
						foreach($trace['args'] as $args){
							if(isset($args->error)){								
								//$args->error
								if(isset($args->error_description)){
									$error_description.='<br>'.$args->error_description;
								}
							}
						}
					}
				}
			}
			
			MangoPay\Libraries\Logs::Debug('MangoPay\ResponseException Code', $e->GetCode());
			MangoPay\Libraries\Logs::Debug('Message', $e->GetMessage());
			MangoPay\Libraries\Logs::Debug('Details', $e->GetErrorDetails().$error_description);
			echo '</p></div>';
		
		} catch (MangoPay\Libraries\Exception $e) {		
			echo '<div class="error"><p>' . __( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			MangoPay\Libraries\Logs::Debug('MangoPay\Exception Message', $e->GetMessage());
			echo '</p></div>';
		
		}  catch (Exception $e) {
			$error_message = __( 'Error:', 'mangopay' ) .
					' ' . $e->getMessage();
			error_log(
				current_time( 'Y-m-d H:i:s', 0 ) . ': ' . $error_message . "\n\n",
				3,
				$this->logFilePath
			);
			
			echo '<div class="error"><p>' . __( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			echo '&laquo;' . $error_message . '&raquo;</p></div>';
		}
		return false;
	}
		
	/**
	 * Checks if wp_user already has associated mp account
	 * if not, creates an mp user account
	 * 
	 * @param string $wp_user_id
	 * 
	 */
	public function set_mp_user( $wp_user_id, $p_type='NATURAL' ) {
		
		/** We store a different mp_user_id for production and sandbox environments **/
		$umeta_key = 'mp_user_id';
		if( !$this->mp_production )
			$umeta_key .= '_sandbox';
		
		$legal_p_type = null;
		$vendor_role = apply_filters( 'mangopay_vendor_role', 'vendor' );
		
		$user_category = self::USER_CATEGORY_PAYER;
		
		if( !$mp_user_id = get_user_meta( $wp_user_id, $umeta_key, true ) ) {
			
			//echo 'p_type: ' . $p_type . '<br/>';	//Debug
			
			if( !$wp_userdata = get_userdata( $wp_user_id ) ){				
				return false;	// WP User has been deleted
			}
			
			/** Vendor or buyer ? **/
			if( 
				( 
					isset( $_POST['apply_for_vendor'] ) && 
					( $_POST['apply_for_vendor'] == 1 || $_POST['apply_for_vendor'] == '1' ) 
				) ||
                isset( $wp_userdata->roles['pending_vendor'] ) || 
                ( 
                	is_array( $wp_userdata->roles ) && 
                	in_array( 'pending_vendor', $wp_userdata->roles , true ) 
                ) ||
				isset( $wp_userdata->roles[$vendor_role] ) || 
				( 
					is_array( $wp_userdata->roles ) && 
					in_array( $vendor_role, $wp_userdata->roles , true ) 
				) ||
				'BUSINESS' == $p_type
			) {
			
				/** Vendor **/
				if( !empty( $this->default_vendor_status ) ) {
				
					if( 'either' == $this->default_vendor_status ) {
							
						$user_mp_status = get_user_meta( $wp_user_id, 'user_mp_status', true );	//Custom usermeta
						if( !$user_mp_status ){
							return false;	// Can't create a MP user in this case
						}
							
						if( 'business' == $user_mp_status ){
							$p_type = 'BUSINESS';
						}
							
						if( 'individual' == $user_mp_status ){
							$p_type = 'NATURAL';
						}
							
						if( !$p_type ){
							return false;
						}
							
					} else {
							
						if( 'businesses' == $this->default_vendor_status ){
							$p_type = 'BUSINESS';
						}
							
						if( 'individuals' == $this->default_vendor_status ){
							$p_type = 'NATURAL';
						}
				
						if( !$p_type ){
							return false;
						}
					}
				
				} else {
					/** The way it worked before (kept for retro-compatibility, but this should in fact never occur) **/
					$p_type = 'BUSINESS';
				}
				
				$user_category = self::USER_CATEGORY_OWNER;
				
			} else {
				
				/** Buyer **/
				if( !empty( $this->default_buyer_status ) ) {
				
					if( 'either' == $this->default_buyer_status ) {
							
						$user_mp_status = get_user_meta( $wp_user_id, 'user_mp_status', true );	//Custom usermeta
						if( !$user_mp_status ){
							return false;	// Can't create a MP user in this case
						}
							
						if( 'business' == $user_mp_status ){
							$p_type = 'BUSINESS';
						}
							
						if( 'individual' == $user_mp_status ){
							$p_type = 'NATURAL';
						}
							
						if( !$p_type ){
							return false;
						}
							
					} else {
							
						if( 'businesses' == $this->default_buyer_status ){
							$p_type = 'BUSINESS';
						}
							
						if( 'individuals' == $this->default_buyer_status ){
							$p_type = 'NATURAL';
						}
				
						if( !$p_type ){
							return false;
						}
					}
				
				} else {
					/** The way it worked before (kept for retro-compatibility, but this should in fact never occur) **/
					$p_type = 'NATURAL';
				}
			}
			
			if( 'BUSINESS' == $p_type ) {
				
				if( 'either' == $this->default_business_type ) {
				
					$user_business_type = get_user_meta( $wp_user_id, 'user_business_type', true );	//Custom usermeta
					if( !$user_business_type ){
						return false;	// Can't create a MP user in this case
					}
					
					if( 'business' == $user_business_type ){
						$legal_p_type = 'BUSINESS';
					}
						
					if( 'organisation' == $user_business_type ){
						$legal_p_type = 'ORGANIZATION';
					}
					
					if( 'soletrader' == $user_business_type ){
						$legal_p_type = 'SOLETRADER';
					}
						
					if( !$legal_p_type ){		
						return false;
					}
					
				} else {
					
					if( 'businesses' == $this->default_business_type ){
						$legal_p_type = 'BUSINESS';
					}
						
					if( 'organisations' == $this->default_business_type ){
						$legal_p_type = 'ORGANIZATION';
					}
					
					if( 'soletraders' == $this->default_business_type ){
						$legal_p_type = 'SOLETRADER';
					}
					
					if( !$legal_p_type ){	
						return false;
					}
				}
			}
			
			/* Debug
			var_dump( $wp_userdata->roles );
			var_dump( in_array( 'vendor', $wp_userdata->roles, true ) );
			var_dump( $p_type ); exit;	//Debug
			*/
			
			/** Required fields **/
			$b_date = strtotime( get_user_meta( $wp_user_id, 'user_birthday', true ) );	//Custom usermeta
			if( $offset = get_option('gmt_offset') ){
				$b_date += ( $offset * 60 * 60 );
			}
			
			$natio	= get_user_meta( $wp_user_id, 'user_nationality', true );			//Custom usermeta
			$ctry	= get_user_meta( $wp_user_id, 'billing_country', true );			//WP usermeta
			if( !$vendor_name = get_user_meta( $wp_user_id, 'pv_shop_name', true ) ){	//WC-Vendor plugin usermeta
				$vendor_name = $wp_userdata->nickname;
			}
			
			if( 
				$mangoUser = $this->createMangoUser( 
					$p_type, 
					$legal_p_type,
					$wp_userdata->first_name, 
					$wp_userdata->last_name, 
					$b_date, 
					$natio, 
					$ctry,
					$wp_userdata->user_email,
					$vendor_name,
					$wp_user_id,
					$user_category
				) 
			) {				
				$mp_user_id = $mangoUser->Id;
				
				/** We store a different mp_user_id for production and sandbox environments **/
				$umeta_key = 'mp_user_id';
				if( !$this->mp_production )
					$umeta_key .= '_sandbox';
				update_user_meta( $wp_user_id, $umeta_key, $mp_user_id );
				
				/** Store effective user_mp_status **/
				$user_mp_status		= 'individual';
				$user_business_type	= '';
				if( 'BUSINESS' == $p_type ) {
					$user_mp_status = 'business';
					$user_business_type = 'business';
					if( 'ORGANIZATION' == $legal_p_type )
						$user_business_type = 'organisation';
					if( 'SOLETRADER' == $legal_p_type )
						$user_business_type = 'soletrader';
				}
				update_user_meta( $wp_user_id, 'user_mp_status', $user_mp_status );
				update_user_meta( $wp_user_id, 'user_business_type', $user_business_type );
				
			} else {			
				return false;
			}
		} elseif( ( $user_ptype = $this->getDBUserPType( $mp_user_id ) ) != $p_type ) {
			
			if( false === $user_ptype ){		
				return false;
            }
			
			if( 'BUSINESS' == $p_type && 'LEGAL' == $user_ptype )
				return $mp_user_id;	// This is Ok.
			
			/* Disabled: we do not want to change MP user status after creation */
			//$this->switchDBUserPType( $wp_user_id, $p_type );
		} else {
			//echo 'p_type: ' . $p_type . '<br/>';	//Debug
		}	
		return $mp_user_id;
	}
	
	/**
	 * Checks if mp_user already has associated wallet(s)
	 * if not,creates a default wallet
	 * 
	 * @param string $mp_user_id - Required
	 * 
	 */
	public function set_mp_wallet( $mp_user_id ) {

		if( !$mp_user_id )
				return false;
		
		/** Check existing MP user & user type **/
		if( !$mangoUser = $this->mangoPayApi->Users->Get( $mp_user_id ) )
			return false;
		
		//var_dump( $mangoUser ); //Debug
		
		if( 'BUSINESS' == $mangoUser->PersonType || 'LEGAL' == $mangoUser->PersonType ) {
			$account_type = 'Business';
		} elseif( 'NATURAL' == $mangoUser->PersonType ) {
			$account_type = 'Individual';
		} else {
			/** Unknown person type **/
			return false;
		}

		$currency = get_woocommerce_currency();
		
		if( !$wallets = $this->mangoPayApi->Users->GetWallets( $mp_user_id ) ) {
			$result = $this->create_the_wallet( $mp_user_id, $account_type, $currency );
			$wallets = $this->mangoPayApi->Users->GetWallets( $mp_user_id );
		}
		
		//var_dump( $result );	//Debug
		
		/** Check that one wallet has the right currency, otherwise create a new one **/
		$found = false;
		foreach( $wallets as $wallet )
			if( $wallet->Currency == get_woocommerce_currency() )
				$found = true;
		if( !$found ) {
			$result = $this->create_the_wallet( $mp_user_id, $account_type, $currency );
			$wallets = $this->mangoPayApi->Users->GetWallets( $mp_user_id );
		}
		
		//var_dump( $result );	//Debug
		
		return $wallets;
	}
	
	/**
	 * Create a new MP wallet
	 * 
	 * @param int $mp_user_id
	 * @param string $account_type
	 * @param string $currency
	 */
	private function create_the_wallet( $mp_user_id, $account_type, $currency ) {
		$Wallet					= new \MangoPay\Wallet();
		$Wallet->Owners			= array( $mp_user_id );
		$Wallet->Description	= "WooCommerce $account_type $currency Wallet";
		$Wallet->Currency		= $currency;		
		$created = $this->mangoPayApi->Wallets->Create($Wallet);		
		return $created;
	}
	
	/**
	 * Register a user's bank account in MP profile
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/bankaccount.php
	 * 
	 * @param inst $mp_user_id
	 * @param string $type
	 * @param string $name
	 * @param string $address1
	 * @param string $address2
	 * @param string $city
	 * @param string $region
	 * @param string $country
	 * @param array $account_data
	 * 
	 */
	public function save_bank_account( 
		$mp_user_id, 
		$wp_user_id,
		$existing_account_id,
		$type, 
		$name, 
		$address1, 
		$address2, 
		$city, 
		$postcode, 
		$region,
		$country, 
		$account_data=array(),
		$account_types
	) {

		/** If there is an existing bank account, fetch it first to get the redacted info we did not store **/
		$ExistingBankAccount = null;
		if( $existing_account_id ) {
			try{
				$ExistingBankAccount = $this->mangoPayApi->Users->GetBankAccount( $mp_user_id, $existing_account_id );
			} catch( Exception $e ) {
				$ExistingBankAccount = null;
			}
		}
		
		$BankAccount 			= new \MangoPay\BankAccount();
		$BankAccount->Type 		= $type;
		$BankAccount->UserId	= $mp_user_id;
		
		$detail_class_name 		= 'MangoPay\BankAccountDetails' . $type;
		$BankAccount->Details 	= new $detail_class_name();
		foreach( $account_types[$type] as $field_name => $field_data ) {
			if(
				!empty( $ExistingBankAccount ) &&
				$type == $ExistingBankAccount->Type && (
					empty( $account_data[$field_name] ) ||
					preg_match( '/\*\*/', $account_data[$field_name] )
				)
			) {
				/** Replace redacted data with data from existing bank account **/
				$BankAccount->Details->{$field_data['mp_property']} = $ExistingBankAccount->Details->{$field_data['mp_property']};
			} else {
				if( isset( $account_data[$field_name] ) )
					$BankAccount->Details->{$field_data['mp_property']} = $account_data[$field_name];
			}
		}
		
		$BankAccount->OwnerName 							= $name;
		$BankAccount->OwnerAddress 							= new \MangoPay\Address();
		$BankAccount->OwnerAddress->AddressLine1 			= $address1;
		$BankAccount->OwnerAddress->AddressLine2 			= $address2;
		$BankAccount->OwnerAddress->City 					= $city;
		$BankAccount->OwnerAddress->Country 				= $country;
		$BankAccount->OwnerAddress->PostalCode 				= $postcode;	// Optional? not really...
		//unset( $BankAccount->OwnerAddress->PostalCode );
		
		//$BankAccount->OwnerAddress->Region 		= 'Region';				// Optional
		unset( $BankAccount->OwnerAddress->Region );
		if( isset( $region ) && $region )
			$BankAccount->OwnerAddress->Region				= $region;		// Mandatory for some countries
				
		$BankAccount->Tag									= 'wp_user_id:' . $wp_user_id;
		
		try{
			$BankAccount = $this->mangoPayApi->Users->CreateBankAccount( $mp_user_id, $BankAccount );
		} catch( Exception $e ) {
									
			$backlink = '<a href="javascript:history.back();">' . __( 'back', 'mangopay' ) . '</a>';
			wp_die( __( 'Error: Invalid bank account data.', 'mangopay' ) . ' ' . $backlink );
		}
			
		return $BankAccount->Id;
	}
	
	/**
	 * Create MANGOPAY User + first wallet
	 *
	 * @param string $p_type		| must be "BUSINESS" or "NATURAL" - Required
	 * @param string $f_name		| first name - Required
	 * @param string $l_name		| last name - Required
	 * @param int $b_date			| birthday (unix timestamp - ex 121271) - Required
	 * @param string $natio			| nationality (2-letter UC country code - ex "FR") - Required
	 * @param string $ctry			| country (2-letter UC country code - ex "FR") - Required
	 * @param string $email			| e-mail address - Required
	 * @param string $vendor_name	| name of business - Required only if $p_type=='BUSINESS'
	 * @param int $wp_user_id		| WP User ID
	 *
	 * @return MangopPayUser $mangoUser
	 * 
	 */
	private function createMangoUser( 
		$p_type, 
		$legal_p_type=null,
		$f_name, 
		$l_name, 
		$b_date, 
		$natio, 
		$ctry, 
		$email, 
		$vendor_name=null, 
		$wp_user_id,
		$user_category = self::USER_CATEGORY_PAYER
	) {
        
        global $creation_mp_on;
        $creation_mp_on = true;

		//FORCE USER TO BE PAYER
		$user_category = self::USER_CATEGORY_PAYER;
				
		/** All fields are required **/
		if( !$p_type || !$f_name || !$l_name || !$ctry || !$email ) {
			if( self::DEBUG ) {
				echo __( 'Error: some required fields are missing in createMangoUser', 'mangopay' ) . '<br/>';
				echo "$p_type || !$f_name || !$l_name || !$b_date || !$natio || !$ctry || !$email <br/>";
			}
			return false;
		}
		
		/** Fields required for business only **/
//		if('BUSINESS'==$p_type && (!$b_date || !$natio)){
//			if( self::DEBUG ) {
//				echo __( 'Error: some required  fields are missing in createMangoUser', 'mangopay' ) . '<br/>';
//				echo "!$b_date || !$natio <br/>";
//			}
////echo __( 'Error: some required  fields are missing in createMangoUser', 'mangopay' ) . '<br/>';
////	echo "!$b_date || !$natio <br/>";
//			return false;
//		}
				
		/** Initialize user data **/
		
		$f_name = $this->cleanup_legalname($f_name);
		$l_name = $this->cleanup_legalname($l_name);
		
		if( 'BUSINESS'==$p_type ) {
			if( !$vendor_name ){	
				if( self::DEBUG ) {
					echo __( 'Error: vendor name missing', 'mangopay' ) . '<br/>';
					echo "!$vendor_name <br/>";
				}
				return false;
			}			
						
			$mangoUser = new \MangoPay\UserLegal();
			$mangoUser->Name 									= $vendor_name;	//Required
			$mangoUser->LegalPersonType							= $legal_p_type;//Required
			$mangoUser->LegalRepresentativeFirstName			= $f_name;		//Required
			$mangoUser->LegalRepresentativeLastName				= $l_name;		//Required
			$mangoUser->LegalRepresentativeBirthday				= $b_date;		//Required
			$mangoUser->LegalRepresentativeNationality			= $natio;		//Required
			$mangoUser->LegalRepresentativeCountryOfResidence	= $ctry;		//Required
		} else {
			$mangoUser = new \MangoPay\UserNatural();
			$mangoUser->PersonType			= $p_type;
			$mangoUser->FirstName			= $f_name;
			$mangoUser->LastName			= $l_name;
//			$mangoUser->Birthday				= $b_date;		//Required
//			$mangoUser->Nationality			= $natio;		//Required			
			$mangoUser->CountryOfResidence	= $ctry;
		}
		$mangoUser->Email		= $email;						//Required
		$mangoUser->Tag			= 'wp_user_id:' . $wp_user_id;		
		$mangoUser->UserCategory = $user_category;				//Required
		
		if( self::DEBUG ) {
			echo '<pre>';			//Debug
			var_dump( $mangoUser );	//Debug
		}
		
		/** Send the request **/
		try{
			$mangoUser = $this->mangoPayApi->Users->Create($mangoUser);
			$mp_user_id = $mangoUser->Id;
		} catch (Exception $e) {
			$error_message = $e->getMessage();
			
			error_log(
				current_time( 'Y-m-d H:i:s', 0 ) . ': ' . $error_message . "\n\n",
				3,
				$this->logFilePath
			);
			
			//$backlink = '<a href="javascript:history.back();">' . __( 'back', 'mangopay' ) . '</a>';				
			$msg = '<div class="error"><p>' . __( 'Error:', 'mangopay' ) . ' ' .
					__( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			$msg .= '&laquo;' . $error_message . '&raquo;</p></div>';						
			echo $msg;
			
			return false;
		}
				
		if( self::DEBUG ) {
			var_dump( $mangoUser );	//Debug
			echo '</pre>';			//Debug
		}
		
		/** If new user has no wallet yet, create one **/
		$this->set_mp_wallet( $mp_user_id );
		
		return $mangoUser;
	}
	
	/**
	 * Update MP User account info
	 * 
	 * $p_type
	 * @param int $mp_user_id
	 * @param array $usermeta
	 * 
	 */
	public function update_user( $mp_user_id, $usermeta=array() ) {

        global $creation_mp_on;
        if(isset($creation_mp_on) && $creation_mp_on == true){
            /* coming from creation, dont need an update */
            return;
        }
        
		if( !$mp_user_id ){
			return;
		}
		
		/** Get existing MP user **/
		if( !$mangoUser = $this->mangoPayApi->Users->Get( $mp_user_id ) ){
			return;
		}
		
		/** mangoUser basic object cleanup **/
		foreach( $mangoUser as $key=>$value )
			if( null==$value ){
				unset( $mangoUser->$key );
			}
		
		$needs_updating = false;
				
		if(!empty($usermeta['termsconditions'])){
			$mangoUser->TermsAndConditionsAccepted = true;		//Required	
			$needs_updating = true;
		}
		
		$firstname = false;
		if(!empty($usermeta['first_name'])){
			//format the name so it's valid 
			$firstname = $this->cleanup_legalname($usermeta['first_name']);	
		}
		
		$lastname = false;
		if(!empty($usermeta['last_name'])){
			//format the name so it's valid 
			$lastname = $this->cleanup_legalname($usermeta['last_name']);
		}
		
		if( 'NATURAL' == $mangoUser->PersonType ) {
			if(
				$firstname 
				&& $mangoUser->FirstName != $firstname
			) {
				$mangoUser->FirstName = $firstname;
				$needs_updating = true;
			}
			if(
				$lastname
				&& $mangoUser->LastName != $lastname
			) {
				$mangoUser->LastName = $lastname;
				$needs_updating = true;
			}
			if(
				isset( $usermeta['address_1'] ) &&
				$usermeta['address_1'] && (
					$mangoUser->Address->AddressLine1 != $usermeta['address_1'] ||
					$mangoUser->Address->City != $usermeta['city'] ||
					$mangoUser->Address->PostalCode != $usermeta['postal_code'] ||
					$mangoUser->Address->Country != $usermeta['billing_country']
				)
			) {
				$mangoUser->Address->AddressLine1 = $usermeta['address_1'];
				$mangoUser->Address->City = $usermeta['city'];
				$mangoUser->Address->PostalCode = $usermeta['postal_code'];
				$mangoUser->Address->Country = $usermeta['billing_country'];
				
				if(
					'US' == $usermeta['billing_country'] ||
					'MX' == $usermeta['billing_country'] ||
					'CA' == $usermeta['billing_country']
				) 
					$mangoUser->Address->Region = $usermeta['billing_state'];
        
				$needs_updating = true;
			}
			if( !empty($usermeta['billing_country']) ) {				
				if( 
					!empty($mangoUser->CountryOfResidence)
					&& $mangoUser->CountryOfResidence == $usermeta['billing_country']
				){
					//skip update
				}else{
					$mangoUser->CountryOfResidence = $usermeta['billing_country'];
					$needs_updating = true;
				}
			}
			
			$timestamp = false;
			if( isset( $usermeta['user_birthday'] ) ) {
				$timestamp = strtotime( $usermeta['user_birthday'] );
				if( $offset = get_option('gmt_offset') )
					$timestamp += ( $offset * 60 * 60 );
				
				/* *
				 echo '<strong>Birthday debug:</strong><br/>';										//Debug
				echo 'stored birth date: ' . $usermeta['user_birthday'] . '<br/>';					//Debug
				echo 'GMT offset: ' . get_option('gmt_offset') . '<br/>';							//Debug
				echo 'Original timestamp: ' . strtotime( $usermeta['user_birthday'] ) . '<br/>';	//Debug
				echo 'Correct UTC timestamp for MP: ' . $timestamp . '<br/>';						//Debug
				exit;																				//Debug
				/* */
			}
			
			if( $timestamp != false ) {				
				if(
					!empty($mangoUser->Birthday)
					&& $mangoUser->Birthday == $timestamp
				){
					//no need update
				}else{
					$mangoUser->Birthday = $timestamp;
					$needs_updating = true;
				}
			}
			if( !empty( $usermeta['user_nationality'] ) ) {
				if(
					!empty($mangoUser->Nationality)
					&& $mangoUser->Nationality == $usermeta['user_nationality']
				){
					//no need update
				}else{
					$mangoUser->Nationality = $usermeta['user_nationality'];
					$needs_updating = true;
				}
			}
			if(
				isset( $usermeta['user_email'] ) &&
				$usermeta['user_email'] &&
				$mangoUser->Email != $usermeta['user_email']
			) {
				$mangoUser->Email = $usermeta['user_email'];
				$needs_updating = true;
			}
		} else {
			/** Business / legal user **/
			if(isset($_POST['compagny_number']) && $_POST['compagny_number']!=""){
				//remouve spaces
				$company_numbers = str_replace(' ', '', $_POST['compagny_number']);
				
				if(	!isset($mangoUser->CompanyNumber) 
					|| $mangoUser->CompanyNumber != $company_numbers) {

					$mangoUser->CompanyNumber = $company_numbers;
					$needs_updating = true;
				}
			}
			
			if(	isset($_POST['headquarters_addressline1']) && $_POST['headquarters_addressline1']!=""
				&& isset($_POST['headquarters_city']) && $_POST['headquarters_city']!=""
				&& isset($_POST['headquarters_region']) && $_POST['headquarters_region']!=""
				&& isset($_POST['headquarters_postalcode']) && $_POST['headquarters_postalcode']!=""
				&& isset($_POST['headquarters_country']) && $_POST['headquarters_country']!=""
			){
				//create adresse
				$mangoUser->HeadquartersAddress->AddressLine1 = $_POST['headquarters_addressline1'];
				if(isset($_POST['headquarters_addressline2']) && $_POST['headquarters_addressline2']!=""){
					$mangoUser->HeadquartersAddress->AddressLine2 = $_POST['headquarters_addressline2'];
				}
				$mangoUser->HeadquartersAddress->City = $_POST['headquarters_city'];
				$mangoUser->HeadquartersAddress->Region = $_POST['headquarters_region'];
				$mangoUser->HeadquartersAddress->PostalCode = $_POST['headquarters_postalcode'];
				$mangoUser->HeadquartersAddress->Country = $_POST['headquarters_country'];
								
				$needs_updating = true;
			}
			
			if(
				isset( $usermeta['pv_shop_name'] ) &&
				$usermeta['pv_shop_name'] &&
				$mangoUser->Name != $usermeta['pv_shop_name']
			) {
				$mangoUser->Name = $usermeta['pv_shop_name'];
				$needs_updating = true;
			}

			if( 
				$firstname
				&&$mangoUser->LegalRepresentativeFirstName != $firstname
			) {											
				$mangoUser->LegalRepresentativeFirstName = $firstname;
				$needs_updating = true;
			}
			if(
				$lastname 
				&& $mangoUser->LegalRepresentativeLastName != $lastname
			) {
				$mangoUser->LegalRepresentativeLastName = $lastname;
				$needs_updating = true;
			}
			if(
				isset( $usermeta['address_1'] ) &&
				$usermeta['address_1'] && (
					$mangoUser->LegalRepresentativeAddress->AddressLine1 != $usermeta['address_1'] ||
					$mangoUser->LegalRepresentativeAddress->City != $usermeta['city'] ||
					$mangoUser->LegalRepresentativeAddress->PostalCode != $usermeta['postal_code'] ||
					$mangoUser->LegalRepresentativeAddress->Country != $usermeta['billing_country']
				)
			) {
				$mangoUser->LegalRepresentativeAddress->AddressLine1 = $usermeta['address_1'];
				$mangoUser->LegalRepresentativeAddress->City = $usermeta['city'];
				$mangoUser->LegalRepresentativeAddress->PostalCode = $usermeta['postal_code'];
				$mangoUser->LegalRepresentativeAddress->Country = $usermeta['billing_country'];
        
        		if(
        			'US' == $usermeta['billing_country'] || 
        			'MX' == $usermeta['billing_country'] || 
        			'CA' == $usermeta['billing_country']
        		)
					$mangoUser->LegalRepresentativeAddress->Region = $usermeta['billing_state'];
        
				$needs_updating = true;
			}
			
			if(
				isset( $usermeta['billing_country'] ) 
				&& $usermeta['billing_country']
			) {
				if(
					empty($mangoUser->LegalRepresentativeCountryOfResidence)
					|| (
						!empty($mangoUser->LegalRepresentativeCountryOfResidence)
						&& $mangoUser->LegalRepresentativeCountryOfResidence != $usermeta['billing_country']
						)
				){
					$mangoUser->LegalRepresentativeCountryOfResidence = $usermeta['billing_country'];
					$needs_updating = true;
				}
			}
			
			if( isset( $usermeta['user_birthday'] ) ) {
				$timestamp = strtotime( $usermeta['user_birthday'] );
				if( $offset = get_option('gmt_offset') )
					$timestamp += ( $offset * 60 * 60 );
			
				/* *
				 echo '<strong>Birthday debug:</strong><br/>';										//Debug
				echo 'stored birth date: ' . $usermeta['user_birthday'] . '<br/>';					//Debug
				echo 'GMT offset: ' . get_option('gmt_offset') . '<br/>';							//Debug
				echo 'Original timestamp: ' . strtotime( $usermeta['user_birthday'] ) . '<br/>';	//Debug
				echo 'Correct UTC timestamp for MP: ' . $timestamp . '<br/>';						//Debug
				exit;																				//Debug
				/* */
			}
					
			if(
				isset( $usermeta['user_birthday'] ) 
				&& $usermeta['user_birthday'] 
			) {
				
				if(
					empty($mangoUser->LegalRepresentativeBirthday)
					|| (
						!empty($mangoUser->LegalRepresentativeBirthday)
						&& $mangoUser->LegalRepresentativeBirthday != $timestamp
						)
				){
					$mangoUser->LegalRepresentativeBirthday = $timestamp;
					$needs_updating = true;
				}
			}
			
			if(
				isset( $usermeta['user_nationality'] )
				&& $usermeta['user_nationality'] 
			) {
				if(
					empty($mangoUser->LegalRepresentativeNationality)
					|| (
						!empty($mangoUser->LegalRepresentativeNationality)
						&& $mangoUser->LegalRepresentativeNationality != $usermeta['user_nationality']
						)
				){				
					$mangoUser->LegalRepresentativeNationality = $usermeta['user_nationality'];
					$needs_updating = true;
				}
			}
			if(
				isset( $usermeta['user_email'] ) &&
				$usermeta['user_email'] &&
				$mangoUser->Email != $usermeta['user_email']
			) {
				$mangoUser->Email = $usermeta['user_email'];
				$needs_updating = true;
			}
            
            if(isset($usermeta['user_business_type']) && $usermeta['user_business_type']!=''){
                if( 'business' == $usermeta['user_business_type']
                    || 'businesses' == $usermeta['user_business_type']){
                    $legal_p_type = 'BUSINESS';
                }
						
                if( 'organisation' == $usermeta['user_business_type']
                    || 'organisations' == $usermeta['user_business_type'] ){
                    $legal_p_type = 'ORGANIZATION';
                }
					
                if( 'soletrader' == $usermeta['user_business_type']
                    || 'soletraders' == $usermeta['user_business_type'] ){
                    $legal_p_type = 'SOLETRADER';
                }
                
                $mangoUser->LegalPersonType = $legal_p_type;
                $needs_updating = true;
            }
		}
		
		/* if vendor add the owner category */
		if(
			!empty($usermeta['user_roles']) 
			&& in_array('vendor', $usermeta['user_roles'])
		){
			$mangoUser->UserCategory = self::USER_CATEGORY_OWNER;
			$needs_updating = true;
		}
		
		if( $needs_updating ) {			
			try{
				$result = $this->mangoPayApi->Users->Update($mangoUser);	
			} catch (Exception $ex) {
//				echo "<pre>", print_r("ex", 1), "</pre>";
//				echo "<pre>", print_r($ex, 1), "</pre>";
//				die('65498498494 find me');
				return false;
			}
		}
		return true;
	}
	
	/**
	 * clean up name according of mangopay rules
	 * @param type $legalname
	 * @return type
	 */
	private function cleanup_legalname($legalname){
		/*
		* Note: The value is considered invalid if any one of the following is encountered: 
		* number, 
		* special character (other than ‘ or -), 
		* two or more - characters, 
		* two or more ‘ characters, no letter.
		*/
		
		$str = $legalname;
		$str = preg_replace('#[^a-zA-Z\ \'\-]#', '', $str);		
		$pieces = explode( '-', $str );
		if( count( $pieces) > 1  ) {
			$str = $pieces[0] . '-' . implode( ' ', array_slice( $pieces, 1 ) );
		}
		$pieces = explode( "'", $str );
		if( count( $pieces) > 1 ) {
			$str = $pieces[0] . "'" . implode( ' ', array_slice( $pieces, 1 ) );
		}
		$legalname = preg_replace('# {2,}#', ' ', $str);
		
		return $legalname;
	}
	
	/**
	 * Call the appropriate URL creation method depending on the mp_card_type
	 * 
	 * @param type $order_id
	 * @param type $wp_user_id
	 * @param type $amount
	 * @param type $currency
	 * @param type $fees
	 * @param type $return_url
	 * @param type $locale
	 * @param type $mp_card_type
	 * @param type $mp_template_url
	 * @return array url and transaction id
	 */
	public function get_payin_url( 
		$order_id, 
		$wp_user_id, 
		$amount, 
		$currency='EUR', 
		$fees, 
		$return_url, 
		$locale,
		$mp_card_type='CB_VISA_MASTERCARD',
		$mp_template_url=''
	){
		if( 'SOFORT' == $mp_card_type || 'GIROPAY' == $mp_card_type ){
			return $this->directdebit_web_payin_url($order_id, $wp_user_id, $amount, $currency, $fees, $return_url, $locale,$mp_card_type,$mp_template_url);
		}
		
		/** else (default): **/
		return $this->card_payin_url($order_id, $wp_user_id, $amount, $currency, $fees, $return_url, $locale,$mp_card_type,$mp_template_url);		
	}

	/**
	 * Generate URL for card payin button
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/payin-card-web.php
	 * 
	 */
	public function card_payin_url( 
		$order_id, 
		$wp_user_id, 
		$amount, 
		$currency='EUR', 
		$fees, 
		$return_url, 
		$locale,
		$mp_card_type='CB_VISA_MASTERCARD',
		$mp_template_url=''
	) {
		
		/** 
		 * Get mp_user_id and mp_wallet_id from wp_user_id
		 * (this should create the user or wallet on-the-fly if not yet created)
		 * 
		 */
		$mp_user_id		= $this->set_mp_user( $wp_user_id );
		$wallets 		= $this->set_mp_wallet( $mp_user_id );

		if( !$wallets && !( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		
			if( self::DEBUG ) {
				echo "<pre>mp_user_id:\n";
				var_dump( $mp_user_id );
				echo "wallets:\n";
				var_dump( $wallets );
				echo '</pre>';
			}
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			wp_die( sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account ) );
		
		} elseif( !$wallets ){

			$error_array = array();
			$error_array['error'] = "no_wallet_found";
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			$error_array['message'] = sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account );
			return $error_array;

		}

		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet ){
			if( $wallet->Currency == $currency ){
				$mp_wallet_id = $wallet->Id;
			}
		}

		/** If still no wallet, abort **/
		if( empty( $mp_wallet_id ) ){
			$error_array = array();
			$error_array['error']	= "no_wallet_with_current_currency";
			$error_array['message']	= __( 'No wallet found with the current currency.', 'mangopay' );
			return $error_array;            
			//return false;
		}
		
		//PayIn/CARD/WEB
		$PayIn 								= new \MangoPay\PayIn();
		$PayIn->CreditedWalletId			= $mp_wallet_id;
		$PayIn->Tag							= 'WC Order #' . $order_id;
		$PayIn->AuthorId 					= $mp_user_id;
		$PayIn->PaymentType 				= 'CARD';
		$PayIn->PaymentDetails 				= new \MangoPay\PayInPaymentDetailsCard();
		$PayIn->PaymentDetails->CardType 	= $mp_card_type;
		$PayIn->DebitedFunds 				= new \MangoPay\Money();
		$PayIn->DebitedFunds->Currency		= $currency;
		$PayIn->DebitedFunds->Amount		= $amount;
		$PayIn->Fees 						= new \MangoPay\Money();
		$PayIn->Fees->Currency 				= $currency;
		$PayIn->Fees->Amount 				= $fees;
		$PayIn->ExecutionType 				= 'WEB';
		$PayIn->ExecutionDetails 			= new \MangoPay\PayInExecutionDetailsWeb();
		$PayIn->ExecutionDetails->ReturnURL	= $return_url;
		$PayIn->ExecutionDetails->Culture	= $locale;
				
		$PayIn = $this->create_billing_shipping_data($PayIn,$order_id);
		$PayIn = $this->create_ip_browser_data($PayIn);

		if( $mp_template_url ) {
			$PayIn->ExecutionDetails->TemplateURLOptions = array( 'PAYLINEV2' => $mp_template_url );
		}
		$result = $this->mangoPayApi->PayIns->Create($PayIn);

		if( !isset($result->Id) || !isset($result->ExecutionDetails->RedirectURL) ) {
			$error_array = array();
			$error_array['error']	= "error_mangopay_PayIns_Create";
			$error_array['message']	= print_r( $result, true ); //TODO: extract actual error from the object
			return $error_array; 
		}
		
		/** Return the RedirectUrl and the transaction_id **/
		return array(
			'redirect_url'		=> $result->ExecutionDetails->RedirectURL,
			'transaction_id'	=> $result->Id
		);
	}
	
	/**
	 * Generate URL for for card web payment types
	 * 
	 * @param type $order_id
	 * @param type $wp_user_id
	 * @param type $amount
	 * @param type $currency
	 * @param type $fees
	 * @param type $return_url
	 * @param type $locale
	 * @param type $mp_template_url
	 * @param type $card_id
	 * @return array url and transaction id
	 */
	public function card_web_payin(
		$order_id, 
		$wp_user_id, 
		$amount, 
		$currency='EUR', 
		$fees, 
		$return_url, 
		$locale,
		$mp_template_url='',
		$card_id
	) {		
		
		$mp_user_id		= $this->set_mp_user( $wp_user_id );
		$wallets 		= $this->set_mp_wallet( $mp_user_id );		
		foreach( $wallets as $wallet ){
			if( $wallet->Currency == $currency ){
				$mp_wallet_id = $wallet->Id;
			}
		}
		
		if( empty( $mp_wallet_id ) ){
			$error_array = array();
			$error_array['error']	= "no_wallet_with_current_currency";
			$error_array['message']	= __( 'No wallet found with the current currency.', 'mangopay' );
			//return $error_array;            
			//return false;
		}
		//PayIn/CARD/DIRECT
		$PayIn 								= new \MangoPay\PayIn();
		$PayIn->CreditedWalletId			= $mp_wallet_id;
		$PayIn->Tag							= 'WC Order #' . $order_id;
		$PayIn->AuthorId 					= $mp_user_id;
		
		$PayIn->PaymentType 				= 'CARD';
		$PayIn->PaymentDetails 				= new \MangoPay\PayInPaymentDetailsCard();
		$PayIn->PaymentDetails->CardId		= $card_id;
		
		$PayIn->DebitedFunds 				= new \MangoPay\Money();
		$PayIn->DebitedFunds->Currency		= $currency;
		$PayIn->DebitedFunds->Amount		= $amount;
		$PayIn->Fees 						= new \MangoPay\Money();
		$PayIn->Fees->Currency 				= $currency;
		$PayIn->Fees->Amount 				= $fees;
		
		$PayIn->ExecutionType 				= 'DIRECT';
		$PayIn->ExecutionDetails 			= new \MangoPay\PayInExecutionDetailsDirect();
		$PayIn->ExecutionDetails->SecureModeReturnURL = $return_url;
		
		$PayIn = $this->create_billing_shipping_data($PayIn,$order_id);
		$PayIn = $this->create_ip_browser_data($PayIn);
		
		$result = $this->mangoPayApi->PayIns->Create($PayIn);
		
		if( !isset($result->Id)) {
			$error_array = array();
			$error_array['error']	= "error_mangopay_PayIns_Create";
			$error_array['message']	= print_r( $result, true ); //TODO: extract actual error from the object
			return $error_array;    
		}

		/** Return the RedirectUrl and the transaction_id **/
		return array(
			'transaction_id'	=> $result->Id,
			'redirect_url'		=> $return_url,
			'data_transaction'	=> $result
		);

	}
	
	/**
	 * Generate URL for for direct debit web payment types (like Sofort & Giropay)
	 * 
	 * @param type $wp_user_id
	 * @param type $amount
	 * @param type $currency
	 * @param type $fees
	 * @param type $return_url
	 * @param type $locale
	 * @param type $mp_card_type
	 * @param type $mp_template_url
	 * @return array url and transaction id
	 */
	public function directdebit_web_payin_url($order_id, 
		$wp_user_id, 
		$amount, 
		$currency='EUR', 
		$fees, 
		$return_url, 
		$locale,
		$mp_card_type='SOFORT',
		$mp_template_url=''
	) {
		
		/** 
		 * Get mp_user_id and mp_wallet_id from wp_user_id
		 * (this should create the user or wallet on-the-fly if not yet created)
		 * 
		 */
		$mp_user_id		= $this->set_mp_user( $wp_user_id );
		$wallets 		= $this->set_mp_wallet( $mp_user_id );

		if( !$wallets && !( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			wp_die( sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account ) );
		
		} elseif( !$wallets ){

			$error_array = array();
			$error_array['error'] = "no_wallet_found";
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			$error_array['message'] = sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account );
			return $error_array;

		}

		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet ){
			if( $wallet->Currency == $currency ){
				$mp_wallet_id = $wallet->Id;
			}
		}

		/** If still no wallet, abort **/
		if( empty( $mp_wallet_id ) ){
			$error_array = array();
			$error_array['error']	= "no_wallet_with_current_currency";
			$error_array['message']	= __( 'No wallet found with the current currency.', 'mangopay' );
			return $error_array;            
			//return false;
		}

		$PayIn 								= new \MangoPay\PayIn();
		$PayIn->CreditedWalletId			= $mp_wallet_id;
		$PayIn->Tag							= 'WC Order #' . $order_id;
		$PayIn->AuthorId 					= $mp_user_id;
		
		$PayIn->PaymentType 				= 'DIRECT_DEBIT';
		$PayIn->PaymentDetails 				= new \MangoPay\PayInPaymentDetailsDirectDebit(); //PayInPaymentDetailsCard();
		$PayIn->PaymentDetails->DirectDebitType 	= $mp_card_type;
		
		$PayIn->DebitedFunds 				= new \MangoPay\Money();
		$PayIn->DebitedFunds->Currency		= $currency;
		$PayIn->DebitedFunds->Amount		= $amount;
		$PayIn->Fees 						= new \MangoPay\Money();
		$PayIn->Fees->Currency 				= $currency;
		$PayIn->Fees->Amount 				= $fees;
		$PayIn->ExecutionType 				= 'WEB';
		$PayIn->ExecutionDetails 			= new \MangoPay\PayInExecutionDetailsWeb();
		$PayIn->ExecutionDetails->ReturnURL	= $return_url;
		$PayIn->ExecutionDetails->Culture	= $locale;

		/* 19/08/2021 exclude sofort and giropay from custom template */
		if( $mp_template_url 
			&& $mp_card_type!='SOFORT' 
			&& $mp_card_type!='GIROPAY' 
			) {
			$PayIn->ExecutionDetails->TemplateURLOptions = array( 'PAYLINEV2' => $mp_template_url );
		}

		$result = $this->mangoPayApi->PayIns->Create($PayIn);

		if( !isset($result->Id) || !isset($result->ExecutionDetails->RedirectURL) ) {
			$error_array = array();
			$error_array['error']	= "error_mangopay_PayIns_Create";
			$error_array['message']	= print_r( $result, true ); //TODO: extract actual error from the object
			return $error_array; 
		}

		/** Return the RedirectUrl and the transaction_id **/
		return array(
			'redirect_url'		=> $result->ExecutionDetails->RedirectURL,
			'transaction_id'	=> $result->Id
		);
	}

	/**
	 * Get WireReference and BankAccount data for a bank_wire payment
	 * 
	 */
	public function bankwire_payin_ref(
		$order_id,						// Used to fill-in the "Tag" optional info
		$wp_user_id, 					// WP User ID
		$amount,						// Amount
		$currency='EUR',				// Currency
		$fees							// Fees
	) {
		/** Get mp_user_id and mp_wallet_id from wp_user_id **/
		$mp_user_id		= $this->set_mp_user( $wp_user_id );
		$wallets 		= $this->set_mp_wallet( $mp_user_id );
		
		if( !$wallets && !( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			if( self::DEBUG ) {
				echo "<pre>mp_user_id:\n";
				var_dump( $mp_user_id );
				echo "wallets:\n";
				var_dump( $wallets );
				echo '</pre>';
			}
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			wp_die( sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account ) );
		}
		
		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet )
			if( $wallet->Currency == $currency )
			$mp_wallet_id = $wallet->Id;
		
		/** If no wallet abort **/
		if( !isset( $mp_wallet_id ) || !$mp_wallet_id )
			return false;
		
		$PayIn 								= new \MangoPay\PayIn();
		$PayIn->CreditedWalletId			= $mp_wallet_id;
		$PayIn->Tag							= 'WC Order #' . $order_id;
		$PayIn->AuthorId 					= $mp_user_id;
		$PayIn->PaymentType 				= 'BANK_WIRE';
		$PayIn->PaymentDetails 				= new \MangoPay\PayInPaymentDetailsBankWire();
		$PayIn->PaymentDetails->DeclaredDebitedFunds 				= new \MangoPay\Money();
		$PayIn->PaymentDetails->DeclaredDebitedFunds->Currency		= $currency;
		$PayIn->PaymentDetails->DeclaredDebitedFunds->Amount		= $amount;
		$PayIn->PaymentDetails->DeclaredFees 			= new \MangoPay\Money();
		$PayIn->PaymentDetails->DeclaredFees->Currency	= $currency;
		$PayIn->PaymentDetails->DeclaredFees->Amount	= $fees;
		$PayIn->ExecutionDetails 			= new \MangoPay\PayInExecutionDetailsDirect();
		
		return $this->mangoPayApi->PayIns->Create($PayIn);
	}
	
	/**
	 * Processes card payin refund
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/refund-payin.php
	 *
	 */
	public function card_refund( $order_id, $mp_transaction_id, $wp_user_id, $amount, $currency, $reason ) {
		
		$mp_user_id	= $this->set_mp_user( $wp_user_id );
		
		//$PayIn = $this->get_payin( $mp_transaction_id );
		//var_dump( $PayIn ); exit; //Debug;
		
		$PayInId						= $mp_transaction_id;
		$Refund							= new \MangoPay\Refund();
		$Refund->AuthorId				= $mp_user_id;
		$Refund->DebitedFunds			= new \MangoPay\Money();
		$Refund->DebitedFunds->Currency	= $currency;
		$Refund->DebitedFunds->Amount	= $amount;
		$Refund->Fees					= new \MangoPay\Money();
		$Refund->Fees->Currency			= $currency;
		$Refund->Fees->Amount			= 0;
		$Refund->Tag					= 'WC Order #' . $order_id . ' - ' . $reason . ' - ValidatedBy:' . wp_get_current_user()->user_login;
		$result = $this->mangoPayApi->PayIns->CreateRefund( $PayInId, $Refund );
	
		return $result;
	}
	
	/**
	 * Perform MP wallet-to-wallet transfer with retained fees
	 * 
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/transfer.php
	 * 
	 * @param int $order_id
	 * @param int $mp_transaction_id
	 * @param int $wp_user_id
	 * @param int $vendor_id
	 * @param string $mp_amount		| money amount
	 * @param string $mp_fees		| money amount
	 * @param string $mp_currency
	 * @return object Transfer result
	 * 
	 */
	public function wallet_trans( $order_id, $mp_transaction_id, $wp_user_id, $vendor_id, $mp_amount, $mp_fees, $mp_currency ) {

		$mp_user_id		= $this->set_mp_user( $wp_user_id );
		$mp_vendor_id	= $this->set_mp_user( $vendor_id );

		/** Get the user wallet that was used for the transaction **/
		$transaction = $this->mangoPayApi->PayIns->Get( $mp_transaction_id );
		$mp_user_wallet_id = $transaction->CreditedWalletId;

		/** Get the vendor wallet **/
		$wallets 		= $this->set_mp_wallet( $mp_vendor_id );
		
		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet ){
			if( $wallet->Currency == $mp_currency ){
				$mp_vendor_wallet_id = $wallet->Id;
			}
		}
		
		$Transfer							= new \MangoPay\Transfer();
		$Transfer->AuthorId					= $mp_user_id;
		$Transfer->DebitedFunds				= new \MangoPay\Money();
		$Transfer->DebitedFunds->Currency	= $mp_currency;
		$Transfer->DebitedFunds->Amount		= round($mp_amount * 100);
		$Transfer->Fees						= new \MangoPay\Money();
		$Transfer->Fees->Currency			= $mp_currency;
		$Transfer->Fees->Amount				= round($mp_fees * 100);
		$Transfer->DebitedWalletId			= $mp_user_wallet_id;
		$Transfer->CreditedWalletId			= $mp_vendor_wallet_id;
		$Transfer->Tag						= 'WC Order #' . $order_id . ' - ValidatedBy:' . wp_get_current_user()->user_login;
		
		$result = $this->mangoPayApi->Transfers->Create($Transfer);
		
		return $result;
	}
	
	/**
	 * Get a list of failed payout transactions
	 * For display in the dedicated admin dashboard widget
	 * 
	 * @see: https://gist.github.com/hobailey/ae06c3ef51c1245132a7
	 * 
	 */
	public function get_failed_payouts() {

		$pagination = new \MangoPay\Pagination(1, 100);
		
		$filter = new \MangoPay\FilterEvents();
		$filter->EventType = \MangoPay\EventType::PayoutNormalFailed;
		
		$sorting = new \MangoPay\Sorting();
		$sorting->AddField("Date", \MangoPay\SortDirection::DESC);
		
		try{
			$failed_payouts = $this->mangoPayApi->Events->GetAll( $pagination, $filter, $sorting );
		} catch (Exception $e) {
			$failed_payouts = array();
		}
			
		/** get refused kyc docs **/
		$pagination = new \MangoPay\Pagination(1, 100);
		
		$filter = new \MangoPay\FilterEvents();
		$filter->EventType = \MangoPay\EventType::KycFailed;
		
		$sorting = new \MangoPay\Sorting();
		$sorting->AddField("Date", \MangoPay\SortDirection::DESC);
		
		try{
			$refused_kycs = $this->mangoPayApi->Events->GetAll( $pagination, $filter, $sorting );
		} catch (Exception $e) {
			$refused_kycs = array();
		}
		
		return array(
			'failed_payouts'	=> $failed_payouts,
			'refused_kycs'		=> $refused_kycs
		);
	}
	
	/**
	 * To check if the MP API is running in production or sandbox environment
	 * 
	 * @return boolean
	 */
	public function is_production() {
		return $this->mp_production;
	} 
	
	/**
	 * Get temporary folder path
	 * 
	 */
	public function get_tmp_dir() {
		if( !$this->mp_loaded )
			return $this->set_tmp_dir();
			
		return $this->mangoPayApi->Config->TemporaryFolder;
	}
	
	/**
	 * Get payin info (to confirm payment executed)
	 * 
	 * @param int $transaction_id
	 * 
	 */
	public function get_payin( $transaction_id ) {
		$PayIn = false;
		try {
			$PayIn = $this->mangoPayApi->PayIns->Get($transaction_id);

		} catch(MangoPay\Libraries\ResponseException $e) {
			// handle/log the response exception with code $e->GetCode(), message $e->GetMessage() and error(s) $e->GetErrorDetails() 
//			echo "<pre>", print_r("--------------------", 1), "</pre>";
//			echo "<pre>", print_r($e->GetErrorDetails() , 1), "</pre>";
//			echo "<pre>", print_r($e->GetCode(), 1), "</pre>";
//			echo "<pre>", print_r($e->GetMessage(), 1), "</pre>";
			echo '<p>' . __( 'Error: MANGOPAY payin was not found because transaction did not succeed.', 'mangopay' ) . '</p>';			
		} catch(MangoPay\Libraries\Exception $e) {
			// handle/log the exception $e->GetMessage() 
//			echo "<pre>", print_r("----------------", 1), "</pre>";
//			echo "<pre>", print_r($e->GetMessage(), 1), "</pre>";
			echo '<p>' . __( 'Error: MANGOPAY payin was not found because transaction did not succeed.', 'mangopay' ) . '</p>';
		} 
		return $PayIn;
	}
	
	
	/**
	 * Get the URL to access a User's MP dashboard page
	 * 
	 * @param int $mp_user_id
	 * @return string URL
	 * 
	 */
	public function getDBUserUrl( $mp_user_id ) {
		return $this->mp_db_url . '/User/' . $mp_user_id . '/Details';
	}
	
	/**
	 * Get the URL to access a User's MP Transactions page
	 * 
	 * @param int $mp_user_id
	 * @return string URL
	 * 
	 */
	public function getDBUserTransactionsUrl( $mp_user_id ) {
		return $this->mp_db_url . '/User/' . $mp_user_id . '/Transactions/Transactions';
	}
	
	/**
	 * Get the URL to access a Wallet's MP Transactions page
	 * 
	 * @param int $mp_user_id
	 * @return string URL
	 * 
	 */
	public function getDBUserWalletTransactionsUrl( $mp_user_id, $mp_wallet_id ) {
		return $this->mp_db_url . '/User/' . $mp_user_id . '/Wallets/' . $mp_wallet_id;
	}
	
	/**
	 * DEPRECATED: Get the URL to access a Wallet's MP Payout Operation page
	 * Suppressed in the new Dashboard interface, August 2018
	 * 
	 * @param int $mp_wallet_id
	 * @return string URL
	 *
	 *
	public function getDBPayoutUrl( $mp_wallet_id ) {
		return $this->mp_db_url . '/Operations/PayOut?walletId=' . $mp_wallet_id;
	}
	*/
	
	/**
	 * Get the URL to upload a KYC Document for that user
	 * 
	 * @param string $mp_user_id
	 * @return string URL
	 */
	public function getDBUploadKYCUrl( $mp_user_id ) {
		//return $this->mp_db_url . '/Operations/UploadKycDocument?userId=' . $mp_user_id;  //DEPRECATED: Suppressed in the new Dashboard interface, August 2018
		return $this->mp_db_url . '/User/' . $mp_user_id . '/Kyc';
	}
	
	/**
	 * Get the URL of the webhooks dashboard
	 *
	 * @return string URL
	 */
	public function getDBWebhooksUrl() {
		return $this->mp_db_url . '/Notifications';
	}
	
	/**
	 * Gets the profile type of an existing MP user account
	 * 
	 * @param int $mp_user_id
	 * @return string|boolean
	 */
	private function getDBUserPType( $mp_user_id ) {
		try{
			$mangoUser = $this->mangoPayApi->Users->Get( $mp_user_id );
			
		}  catch (Exception $e) {
			$error_message = $e->getMessage();
			
			error_log(
				current_time( 'Y-m-d H:i:s', 0 ) . ': ' . $error_message . "\n\n",
				3,
				$this->logFilePath
			);
			
			echo '<div class="error"><p>' . __( 'Error:', 'mangopay' ) . ' ' .
				__( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			echo '&laquo;' . $error_message . '&raquo;</p></div>';
		}
		if( isset( $mangoUser ) && $mangoUser ) {
			//var_dump( $mangoUser->PersonType );	//Debug
			return $mangoUser->PersonType;
		} else {
			return false;
		}
	}
	
	/**
	 * Will create BUSINESS type account for Customers that become Vendors
	 * 
	 * NOT USED
	 * 
	 * @param int $wp_user_id	| WP user ID
	 * @param string $p_type	| MP profile type
	 */
	private function switchDBUserPType( $wp_user_id, $p_type ) {
		
		/** 
		 * We only switch accounts when a Customer becomes a Vendor,
		 * ie vendors that become customers keep their existing vendor account
		 * 
		 */
		if( 'BUSINESS' != $p_type ) 
			return;
			
		/** We will creata a new MP BUSINESS account for that user **/
		
		/** We store a different mp_user_id for production and sandbox environments **/
		$umeta_key = 'mp_user_id';
		if( !$this->mp_production )
			$umeta_key .= '_sandbox';
		delete_user_meta( $wp_user_id, $umeta_key );
		$this->set_mp_user( $wp_user_id, 'BUSINESS' );
	}
	
	/**
	 * Get the wc vendors plugin commission line by line id
	 * @global type $wpdb
	 * @param type $wcv_commission_id
	 * @return boolean
	 */
	public function get_wcv_commission_line($wcv_commission_id){
		global $wpdb;
		$table_name = $wpdb->prefix . mangopayWCConfig::WV_TABLE_NAME; 
		//remove select limited product_id, order_id, vendor_id, status, total_due, total_shipping
		$query = "
			SELECT * 
			FROM `{$table_name}`
			WHERE id = %d;
		";
		$query = $wpdb->prepare( $query, $wcv_commission_id );
		if( $row = $wpdb->get_row( $query ) ) {
			return $row;
		}else{
			return false;
		}
	}
	
	/**
	 * Get the wc vendors plugin commission line by order and wp user id (vendor)
	 * @global type $wpdb
	 * @param type $order_id
	 * @param type $wpuserid
	 * @return boolean
	 */
	public function get_commission_row_by_orderid_and_wpuser($order_id,$wpuserid){
		global $wpdb;
		$table_name = $wpdb->prefix . mangopayWCConfig::WV_TABLE_NAME;
		$query = "
			SELECT * 
			FROM `{$table_name}`
			WHERE order_id = %d 
			AND vendor_id = %d;
		";
		$query = $wpdb->prepare( $query, $order_id,$wpuserid );
		if( $row = $wpdb->get_row( $query ) ) {
			return $row;
		}else{
			return false;
		}
	}
	
	/**
	 * Calculate payout depending on the selected options
	 * @param type $wcv_commission_id
	 * @return type
	 */
	public function payout_calcul_amount($wcv_commission_id){
		
		$row = $this->get_wcv_commission_line($wcv_commission_id);
		if($row){
			$total_due = floatval($row->total_due);				
			/** wc vendors test if tax is due to vendor **/
			$include_taxe = apply_filters('mp_commission_due_authorise_wcv_tax',true,$row);
			$give_taxes = wc_string_to_bool( get_option( 'wcvendors_vendor_give_taxes', 'no' ) );
			if($include_taxe && isset($row->tax) && $give_taxes){
				$total_due = $total_due+floatval($row->tax);
			}
			/** wc vendors test if shipping is due to vendor **/
			$include_ship = apply_filters('mp_commission_due_authorise_wcv_shipping',true,$row);
			$give_ship = wc_string_to_bool( get_option( 'wcvendors_vendor_give_shipping', 'no' ) );
			if($include_ship && isset($row->total_shipping) && $give_ship){
				$total_due = $total_due+floatval($row->total_shipping);
			}
			
			return $total_due;
		}else{
			false;
		}
	}
	
	/**
	 * MP payout transaction (for vendors)
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/payout.php
	 * 
	 * @param int $wp_user_id
	 * @param int $mp_account_id
	 * @param int $order_id
	 * @param string $currency
	 * @param float $amount
	 * @param float $fees
	 * @return boolean
	 * 
	 */
	public function payout(  $wp_user_id, $mp_account_id, $order_id, $currency, $amount, $fees ){
		
		/** The vendor **/
		$mp_vendor_id	= $this->set_mp_user( $wp_user_id );
		
		/** Get the vendor wallet **/
		$wallets 		= $this->set_mp_wallet( $mp_vendor_id );
		
		if(!$wallets || count($wallets)<1){	
			return false;
		}
		
		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet ){
			if( $wallet->Currency == $currency ){
				$mp_vendor_wallet_id = $wallet->Id;
			}
		}
		
		if( !$mp_vendor_wallet_id ){			
			return false;
		}
		
		if(!$this->test_vendor_can_get_payout($wp_user_id,$mp_vendor_id)){
			$order = new WC_Order($order_id);
			$order->add_order_note( __( "Cannot do the payout. Mandatory vendor info is missing.", 'mangopay' ) );			
			return false;
		}
		
		$PayOut = new \MangoPay\PayOut();
		$PayOut->AuthorId								= $mp_vendor_id;
		$PayOut->DebitedWalletID						= $mp_vendor_wallet_id;
		$PayOut->DebitedFunds							= new \MangoPay\Money();
		$PayOut->DebitedFunds->Currency					= $currency;
		$PayOut->DebitedFunds->Amount					= round($amount * 100);
		$PayOut->Fees									= new \MangoPay\Money();
		$PayOut->Fees->Currency							= $currency;
		$PayOut->Fees->Amount							= round($fees * 100);
		$PayOut->PaymentType							= "BANK_WIRE";
		$PayOut->MeanOfPaymentDetails					= new \MangoPay\PayOutPaymentDetailsBankWire();
		$PayOut->MeanOfPaymentDetails->BankAccountId	= $mp_account_id;
		$PayOut->MeanOfPaymentDetails->BankWireRef		= 'ID ' . $order_id;
		$PayOut->Tag									= 'Commission for WC Order #' . $order_id . ' - ValidatedBy:' . wp_get_current_user()->user_login;
		
		//var_dump( $PayOut );	//Debug
		$result = false;
		try{
			$result = $this->mangoPayApi->PayOuts->Create($PayOut);			
		} catch (Exception $ex) {
//			echo "<pre>", print_r("Payout - failed create payout", 1), "</pre>";
//			echo "<pre>", print_r($ex, 1), "</pre>";
		}
		
		return $result;
	}
	
	/**
	 * Test if the vendor has his data uptodate, test kyc, ubo and company number depending of the type of mp_user
	 * @param type $wp_user_id
	 * @param type $mp_vendor_id
	 * @return boolean 
	 */
	public function test_vendor_can_get_payout($wp_user_id,$mp_vendor_id){
	
		/** We test KYC -  if they are uptodate we DO NOT TEST the rest **/
		/** test if vendor has KYC valid **/
		$result_kyc = $this->test_vendor_kyc($mp_vendor_id);		
		if(!$result_kyc || $result_kyc === "no_account_bd" || $result_kyc === "no_count_mp_found"){
			return false;
		}else{
			return true;
		}
		
		/** get user type **/
		$user_mp_status = get_user_meta($wp_user_id, 'user_mp_status', true);
		$user_business_type = get_user_meta($wp_user_id, 'user_business_type', true);
		/** if business type test company number and UBO **/
		if($user_mp_status == 'business' && $user_business_type == 'business'){	
		
			/** test if vendor has company number valid (if necessary) **/
			$compagny_number = get_user_meta( $wp_user_id, 'compagny_number', true );
			if(!$compagny_number){
				return false;
			}
			/** Test if company number is in pattern **/
			$result = mpAccess::getInstance()->check_company_number_patterns($compagny_number);				
			if($result == 'nopattern'){
				return false;
			}
		
			/** test if vendor has UBO valid (if necessary) **/
			$liste_ubos = mpAccess::getInstance()->get_all_ubo_declarations($mp_vendor_id);
			if(count($liste_ubos)>0){
				$validated = false;
				foreach($liste_ubos as $ubo_declaration){
					if($ubo_declaration->Status == 'VALIDATED'){
						//means one is validated, we can skip this vendor and test the rest
						$validated = true;
						break;
					}
				}
				//the loop is finish if $validated is false, we are done
				if(!$validated){
					return false;
				}
			}else{
				return false;
			}			
		}
		
		/** nothing is wrong so we are ok **/
		return true;
	}
	
	/**
	 * Test if user has all ubo requirements
	 * @param type $wp_vendor_id
	 * @return boolean
	 */
	public function test_vendor_ubo($wp_vendor_id){
		
		$umeta_key = 'mp_user_id';
		if( !$this->is_production() )
			$umeta_key .= '_sandbox';
		
		$user_mp_status = get_user_meta($wp_vendor_id, 'user_mp_status', true);
		$user_business_type = get_user_meta($wp_vendor_id, 'user_business_type', true);
		if($user_mp_status != 'business' || $user_business_type != 'business'){		
			return "na";
		}
			
		$existing_account_id = get_user_meta( $wp_vendor_id , $umeta_key, true );
		if(!$existing_account_id){
			//account is missing
			return false;
		}
		
		/** get declaration and check if there is a validated **/
		$liste_ubos = array();
		try{
			$liste_ubos = mpAccess::getInstance()->get_all_ubo_declarations($existing_account_id);
		} catch (Exception $exc) {
			//echo $exc->getTraceAsString();
			return false;
		}

		if(count($liste_ubos)>0){
			$validated = false;
			foreach($liste_ubos as $ubo_declaration){
				if($ubo_declaration->Status == 'VALIDATED'){
					//means one is validated, we can skip this vendor and test the rest
					$validated = true;
					break;
				}
			}
			//the loop is finish if $validated is false, we are done
			if(!$validated){
				return false;
			}else{
				return true;
			}
		}else{
			//means at least one vendor doesn't have done his declaration
			return false;
		}
	}
	
	/**
	 * 
	 * @param type $mp_vendor_id
	 * @return boolean|string
	 */
	public function test_vendor_kyc($mp_vendor_id){
		try{
			$mp_user =  $this->get_mp_user($mp_vendor_id);
		}  catch (Exception $error){
			/** in case mangopay id is corrupt in wp or doesn't exist anymore in mangopay **/
//			echo "<pre>", print_r("error", 1), "</pre>";
//			echo "<pre>", print_r($error, 1), "</pre>";
		}

		if( empty($mp_user) ){
			 /** means a vendor as not a mangopay account **/
			return "no_count_mp_found";
		}
		
		$vendor = $this->get_user_properties($mp_vendor_id);
		if(isset($vendor->KYCLevel) && $vendor->KYCLevel == 'LIGHT'){
			/** we are light or there is one not set we kill it **/
			return false;
		}	

//		$persontype = $mp_user->PersonType;
//		$list_to_show = $this->get_list_kyc_documents_types($persontype,$mp_user);
//
//		/*get all documents of that user */
//		$all_docs = $this->get_kyc_documents($mp_vendor_id);
//		
//		/** if we dont have the same count we have a problem **/
//		foreach($list_to_show as $necessary_doc_key=>$necessary_doc_text){
//									
//			$found = false;
//			foreach($all_docs as $doc){		
//				if($doc->Type == $necessary_doc_key){
//					$found = true;
//					if($doc->Status != "VALIDATED"){ //CREATED, VALIDATION_ASKED, VALIDATED, REFUSED
//						return false;
//					}
//				}
//			}
//			//if not found in the list of docs, we kick out
//			if(!$found){
//				return false;
//			}			
//		}
		
		/** if everything is fine we can return true **/
		return true;
	}
	
	/**
	 * 
	 * @param type $persontype
	 * @param type $mp_user
	 * @return type
	 */
    public function get_list_kyc_documents_types($persontype,$mp_user){
        $IdentityProof_const = MangoPay\KycDocumentType::IdentityProof;
        $IdentityProof_text = __( 'Identity proof', 'mangopay' );
        $RegistrationProof_const = MangoPay\KycDocumentType::RegistrationProof;
        $RegistrationProof_text = __( 'Registration proof', 'mangopay' );
        $ArticlesOfAssociation_const = MangoPay\KycDocumentType::ArticlesOfAssociation;
        $ArticlesOfAssociation_text = __( 'Articles of association', 'mangopay' );
//        $ShareholderDeclaration_const = MangoPay\KycDocumentType::ShareholderDeclaration;
//        $ShareholderDeclaration_text = __( 'Shareholder declaration', 'mangopay' );
        $AddressProof_const = MangoPay\KycDocumentType::AddressProof;
        $AddressProof_text = __( 'Adress proof', 'mangopay' );
       
        /* depending on the mp user type some fields are open */
        $list_to_show = array();
        $list_to_show[$IdentityProof_const] = $IdentityProof_text; /* in any case */
        if($persontype == 'LEGAL' && isset($mp_user->LegalPersonType)){
            switch ($mp_user->LegalPersonType) {
                case 'BUSINESS':
                    $list_to_show[$ArticlesOfAssociation_const] = $ArticlesOfAssociation_text;
                    $list_to_show[$RegistrationProof_const] = $RegistrationProof_text;
                    //$list_to_show[$ShareholderDeclaration_const] = $ShareholderDeclaration_text;
                break;
           
                case 'ORGANIZATION':
                    $list_to_show[$ArticlesOfAssociation_const] = $ArticlesOfAssociation_text;
                    $list_to_show[$RegistrationProof_const] = $RegistrationProof_text;
                break;
           
                case 'SOLETRADER':
                    $list_to_show[$RegistrationProof_const] = $RegistrationProof_text;
                break;           
            }
        }else {
            //$list_to_show[$AddressProof_const] = $AddressProof_text; /* only for natural */
        }
        return $list_to_show;
    }	
	
	/**
	 * Retrieve info about an existing (past) payout
	 * 
	 * @param int $payOutId
	 * @return object \MangoPay\PayOut
	 */
	public function get_payout( $payOutId ) {
		return $this->mangoPayApi->PayOuts->Get( $payOutId );
	}
	
	/**
	 * Retrieve info about an existing KYV document
	 *
	 * @param int $kycDocumentId
	 * @return object \MangoPay\KycDocument
	 */
	public function get_kyc( $kycDocumentId ) {
		return $this->mangoPayApi->KycDocuments->Get( $kycDocumentId );
	}
	
	/**
	 * Do user have a bank account configured
	 * @param type $wp_user_id
	 * @return boolean
	 */
	public function has_bank_account($wp_user_id){		
		$mp_account_id = mpAccess::getInstance()->get_mp_user_id($wp_user_id);
		if($mp_account_id){
			$account = $this->mangoPayApi->Users->GetBankAccounts( $mp_account_id );
			if($account){
				return true;
			}else{
				return false;
			}			
		}else{
			return false;
		}
	}
	
	public function has_vendor_completed_informations($wp_user_id){
		$user_birthday		= get_user_meta($wp_user_id, 'user_birthday',true);	
		$user_nationality	= get_user_meta($wp_user_id, 'user_nationality',true);
		if(!$user_birthday || !$user_nationality){
			return false;
		}else{
			return true;
		}
	}
    
	/**
	 * Create KYV document
	 * @param int $kycDocumentId
	 * @return object \MangoPay\KycDocument
	 */
	public function create_kyc_document( $existing_account_id, $kyc_file_type) {
		return $this->mangoPayApi->Users->CreateKycDocument($existing_account_id,$kyc_file_type);
	}
	
    /**
     * Add page (file) to a document
     * @param type $existing_account_id
     * @param type $kycDocumentId
     * @param type $file
     * @return type
     */
    public function create_kyc_page_from_file( $existing_account_id, $kycDocumentId, $file){
		return $this->mangoPayApi->Users->CreateKycPageFromFile($existing_account_id, $kycDocumentId, $file);
	}
    
    /**
     * update document (inside the kycdocument we set some vars like $KycDocument->Status = "VALIDATION_ASKED"
     * @param type $KycDocument
     * @return type
     */
    public function update_kyc_document($userId,$KycDocument){
		return $this->mangoPayApi->Users->UpdateKycDocument($userId,$KycDocument);
	}
    
    /**
     * Get document by mp doc id
     * @param type $userId
     * @param type $KYCDocumentId
     * @return type
     */
    public function get_kyc_document($userId,$KYCDocumentId){
        return $this->mangoPayApi->Users->GetKycDocument($userId,$KYCDocumentId);
    }
    
    /**
	 * 
	 * @param type $userId
	 * @param type $pagination
	 * @param type $sorting
	 * @param type $filter
	 * @return type
	 */
    public function get_kyc_documents($userId, $pagination = null, $sorting = null, $filter = null){
		if(!$pagination){
			$pagination = new \MangoPay\Pagination();
			$pagination->Page = 1;
			$pagination->ItemsPerPage = 100; //100 is the maximum
		}
        return $this->mangoPayApi->Users->GetKycDocuments($userId, $pagination , $sorting , $filter );
    }
	
    public function get_user_properties($userId){
        return $this->mangoPayApi->Users->Get($userId);
    }	
	
	/**
	 * 
	 * @param type $userId
	 * @param type $pagination
	 * @param type $sorting
	 * @return type
	 */
	public function get_all_ubo_declarations($userId, $pagination = null, $sorting = null){
		
		if(!$pagination){
			$pagination = new \MangoPay\Pagination();
			$pagination->Page = 1;
			$pagination->ItemsPerPage = 100; //100 is the maximum
		}
		
		return $this->mangoPayApi->UboDeclarations->GetAll($userId,$pagination,$sorting);
	}
	
	/**
	 * 
	 * @param type $userId
	 * @param type $uboDeclarationId
	 * @return type
	 */
	public function get_ubo_declaration_by_id($userId, $uboDeclarationId){
		return $this->mangoPayApi->UboDeclarations->Get($userId, $uboDeclarationId);	
	}
	
	/**
	 * 
	 * @param type $userId
	 * @param type $uboDeclarationId
	 * @param type $uboId
	 * @return type
	 */
	public function get_ubo_element_by_id($userId, $uboDeclarationId, $uboId){
		return $this->mangoPayApi->UboDeclarations->GetUbo($userId, $uboDeclarationId, $uboId);	
	}
	
	/**
	 * 
	 * @param type $userId
	 * @return type
	 */
	public function create_ubo_declaration($userId){
		return $this->mangoPayApi->UboDeclarations->Create($userId);	
	}
	
	/**
	 * 
	 * @param type $UserId
	 * @param type $UboDeclarationId
	 * @param type $ubo
	 * @return type
	 */
	public function create_ubo_element($UserId,$UboDeclarationId,$ubo){		
		return $this->mangoPayApi->UboDeclarations->CreateUbo($UserId, $UboDeclarationId, $ubo);
	}
	
	/**
	 * 
	 * @param type $UserId
	 * @param type $UboDeclarationId
	 * @param type $ubo
	 * @return type
	 */
	public function update_ubo_element($UserId,$UboDeclarationId,$ubo){
		return $this->mangoPayApi->UboDeclarations->UpdateUbo($UserId, $UboDeclarationId, $ubo);
	}
	
	public function ask_ubo_validation($userId, $uboDeclarationId){
		return $this->mangoPayApi->UboDeclarations->SubmitForValidation($userId, $uboDeclarationId);
	}
    
    /**
     * get user mp data
     * @param type $mp_user_id
     * @return type
     */
    public function get_mp_user($mp_user_id){
		if(!$mp_user_id || $mp_user_id=="" || intval($mp_user_id)<=0){
			return false;
		}		
        return $this->mangoPayApi->Users->Get($mp_user_id);
    }
    
	/**
	 * Returns plugin's log file path
	 * 
	 */
	public function get_logfilepath() {
		return apply_filters( 'mangopay_logfilepath', $this->logFilePath );
	}
	
	/**
	 * Returns the webhook for successful payins
	 * 
	 * NOT USED
	public function get_successful_payin_hook() {
		return $this->get_webhook_by_type( self::PAYIN_SUCCESS_HK );
	}
	/** **/
	
	/**
	 * Get a webhook registered in the MP API by its type.
	 * Return false if not present.
	 * 
	 */
	public function get_webhook_by_type( $webhook_type ) {
		$pagination = new \MangoPay\Pagination(1, 100);//get the first page with 100 elements per page
		try{
			$list = $this->mangoPayApi->Hooks->GetAll( $pagination );
		} catch (Exception $e) {
			return false;
		}
		foreach($list as $hook)
			if( $hook->EventType == $webhook_type )
				return $hook;	// We don't care about the rest of the list
		
		return false;
	}
	
	/**
	 * Check that a MANGOPAY incoming webhook is enabled & valid
	 * 
	 * @param object $hook - MANGOPAY Hook object
	 * @return boolean
	 */
	public function hook_is_valid( $hook ) {
		if( $hook->Status != 'ENABLED' )
			return false;
		
		if( $hook->Validity != 'VALID' )
			return false;
		
		return true;
	}
	
	/**
	 * Register all necessary webhooks
	 * 
	 * NOT USED
	public function create_all_webhooks( $webhook_prefix, $webhook_key ) {
		$r1 = $this->create_webhook( $webhook_prefix, $webhook_key, self::PAYIN_SUCCESS_HK );
		$r2 = $this->create_webhook( $webhook_prefix, $webhook_key, self::PAYIN_FAILED_HK );
		return $r1 && $r2;
	}
	*/
	
	/**
	 * Registers a new webhook with the MANGOPAY API
	 * creates the webhook and returns its Id if successful, false otherwise
	 * 
	 */
	public function create_webhook( $webhook_prefix, $webhook_key, $event_type ) {
		
		$inboundPayinWPUrl = site_url( $webhook_prefix . '/' . $webhook_key . '/' . $event_type );
		$hook = new \MangoPay\Hook();
		$hook->Url			= $inboundPayinWPUrl;
		$hook->Status		= 'ENABLED';
		$hook->Validity		= 'VALID';
		$hook->EventType	= $event_type;
		try{
			$result = $this->mangoPayApi->Hooks->Create( $hook );
		} catch (Exception $e) {
			return false;
		}	
		
		if( $result->Id )
			return $result->Id;
		
		return false;
	}
	
	/**
	 * Updates an existing webhook of the MANGOPAY API
	 * returns its Id if successful, false otherwise
	 *
	 */
	public function update_webhook( $existing_hook, $webhook_prefix, $webhook_key, $event_type ) {
		$inboundPayinWPUrl = site_url( $webhook_prefix . '/' . $webhook_key . '/' . $event_type );
		$hook = new \MangoPay\Hook();
		$hook->Url			= $inboundPayinWPUrl;
		$hook->Status		= 'ENABLED';
		$hook->Validity		= 'VALID';
		$hook->EventType	= $event_type;
		$hook->Id			= $existing_hook->Id;
		try{
			$result = $this->mangoPayApi->Hooks->Update( $hook );
		} catch (Exception $e) {
			return false;
		}
		
		if( $result->Id )
			return $result->Id;
		
		return false;
	}
	
	/**
	 * Check that a webhook of the specified type is registered
	 * 
	 * @param string $event_type
	 * @return boolean
	 */
	private function check_webhook( $webhook_key, $event_type ) {
		if( $hook = $this->get_webhook_by_type( $event_type ) ) {
			if(
				!empty($webhook_key) &&
				$this->hook_is_valid( $hook )
			) {
				$inboundPayinWPUrl = site_url(
					mangopayWCWebHooks::WEBHOOK_PREFIX . '/' .
					$webhook_key . '/' .
					$event_type
				);
				if( $inboundPayinWPUrl == $hook->Url )
					return true;
			}
		}
		return false;
	}
	
	/**
	 * Register a credit card for mp user
	 * 
	 * @param type $wp_user_id
	 * @param type $currency
	 * @param type $card_type
	 * @param type $nickname
	 * @return boolean
	 */
	public function register_card( $wp_user_id, $currency, $card_type, $nickname ){
		try {
			$mp_user_id	= $this->set_mp_user( $wp_user_id );
			$CardRegistration = new \MangoPay\CardRegistration();
			$CardRegistration->Tag = $nickname;
			$CardRegistration->UserId = $mp_user_id;
			$CardRegistration->Currency = $currency;
			$CardRegistration->CardType = $card_type;
			$result = $this->mangoPayApi->CardRegistrations->Create($CardRegistration);
			
			return array(
			  'success'=>true,
			  'result'=>$result);	
		
		} catch(MangoPay\Libraries\ResponseException $e) {
		// handle/log the response exception with code $e->GetCode(), message $e->GetMessage() and error(s) $e->GetErrorDetails()
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage(),
			  'details'=>$e->GetErrorDetails());
		} catch(MangoPay\Libraries\Exception $e) {
		// handle/log the exception $e->GetMessage()	
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage());
		}
		return false;
	}
	
	/**
	 * Create a credit card pre-authorization
	 * 
	 * @param array $data_cardauth
	 * @return boolean[]|NULL[]|boolean[]|\MangoPay\Libraries\Error[]|NULL[]|boolean
	 */
	public function create_preauthorization( $data_cardauth ){
		
		$Tag					= $data_cardauth['Tag'];
		$AuthorId				= $data_cardauth['AuthorId'];
		$Currency				= $data_cardauth['Currency'];
		$Amount					= $data_cardauth['Amount'];		
		$CardId					= $data_cardauth['CardId'];
		$AddressLine1			= $data_cardauth['AddressLine1'];
		$AddressLine2			= $data_cardauth['AddressLine2'];
		$City					= $data_cardauth['City'];
		$Region					= $data_cardauth['Region'];
		$PostalCode				= $data_cardauth['PostalCode'];
		$Country				= $data_cardauth['Country'];
		
		//get card infos to see if it's a secure one
		$SecureModeReturnURL	= $data_cardauth['SecureModeReturnURL'];
		
		try {
			$CardPreAuthorization = new \MangoPay\CardPreAuthorization();
			$CardPreAuthorization->Tag						= $Tag;
			$CardPreAuthorization->AuthorId					= $AuthorId;
			$CardPreAuthorization->DebitedFunds				= new \MangoPay\Money();
			$CardPreAuthorization->DebitedFunds->Currency	= $Currency;
			$CardPreAuthorization->DebitedFunds->Amount		= $Amount;
//			$CardPreAuthorization->Billing					= new \MangoPay\MangoPayApi();
//			$CardPreAuthorization->Billing->Address			= new \MangoPay\Address();
//			$CardPreAuthorization->Billing->Address->AddressLine1	= $AddressLine1;
//			$CardPreAuthorization->Billing->Address->AddressLine2	= $AddressLine2;
//			$CardPreAuthorization->Billing->Address->City			= $City;
//			$CardPreAuthorization->Billing->Address->Region			= $Region;
//			$CardPreAuthorization->Billing->Address->PostalCode		= $PostalCode;
//			$CardPreAuthorization->Billing->Address->Country		= $Country;
			$CardPreAuthorization->SecureMode						= "DEFAULT";
			$CardPreAuthorization->CardId							= $CardId;
			$CardPreAuthorization->SecureModeReturnURL				= $SecureModeReturnURL;
			
			//add ip and browser info in the object
			$CardPreAuthorization = $this->create_billing_shipping_data($CardPreAuthorization,$data_cardauth['orderid']);
			$CardPreAuthorization = $this->create_ip_browser_data($CardPreAuthorization);
				
			$result = $this->mangoPayApi->CardPreAuthorizations->Create($CardPreAuthorization);
			$order = new WC_Order($data_cardauth['orderid']);
			$order->add_order_note( sprintf( __( 'Payment for this order has been successfully pre-authorized by MANGOPAY on %1s', 'mangopay' ), date( 'd F Y H:i' ) ) );
			/* Debug *
			$note_debug = print_r( $CardPreAuthorization, true );
			$order->add_order_note( 'debug:' . $note_debug, true );
			/* */
			
			return array(
			  'success'=>true,
			  'result'=>$result
			);

		} catch(MangoPay\Libraries\ResponseException $e) {
			// handle/log the response exception with code $e->GetCode(), message $e->GetMessage() and error(s) $e->GetErrorDetails()
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage(),
			  'details'=>$e->GetErrorDetails()
			);
			
		} catch(MangoPay\Libraries\Exception $e) {
			// handle/log the exception $e->GetMessage()
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage()
			);
		}
		return false;
	}
	
	public function create_billing_shipping_data($object,$order_id){
		
		$order = wc_get_order( $order_id );
		
		$shipping = array();
		$shipping['full_name']	= $order->get_formatted_shipping_full_name();
		$shipping['first_name']	= $order->get_shipping_first_name();
		$shipping['last_name']	= $order->get_shipping_last_name();
		$shipping['address_1']	= $order->get_shipping_address_1();
		$shipping['city']		= $order->get_shipping_city();
		$shipping['state']		= $order->get_shipping_state();
		$shipping['postcode']	= $order->get_shipping_postcode();
		$shipping['country']	= $order->get_shipping_country();
		$add_shipping = false;
		foreach($shipping as $key_shipping=>$shipping_detail){
			if($shipping_detail!="" && $shipping_detail!=" "){
				$add_shipping = true;
			}
		}
		
		if($add_shipping){
			$object->Shipping						= new \MangoPay\ShippingAddress();
			$object->Shipping->RecipientName		= $order->get_formatted_shipping_full_name();
			$object->Shipping->Address				= new \MangoPay\Address();	
			$object->Shipping->FirstName			= $order->get_shipping_first_name();
			$object->Shipping->LastName				= $order->get_shipping_last_name();//			
			$object->Shipping->Address->AddressLine1= $order->get_shipping_address_1();
			$object->Shipping->Address->AddressLine2= $order->get_shipping_address_2();
			$object->Shipping->Address->City		= $order->get_shipping_city();
			$object->Shipping->Address->Region		= $order->get_shipping_state();
			$object->Shipping->Address->PostalCode	= $order->get_shipping_postcode();
			$object->Shipping->Address->Country		= $order->get_shipping_country();
		}
			
		$object->Billing						= new \MangoPay\Billing();
		$object->Billing->Address				= new \MangoPay\Address();	
		$object->Billing->FirstName				= $order->get_billing_first_name();
		$object->Billing->LastName				= $order->get_billing_last_name();//			
		$object->Billing->Address->AddressLine1	= $order->get_billing_address_1();
		$object->Billing->Address->AddressLine2	= $order->get_billing_address_2();
		$object->Billing->Address->City			= $order->get_billing_city();
		$object->Billing->Address->Region		= $order->get_billing_state();
		$object->Billing->Address->PostalCode	= $order->get_billing_postcode();
		$object->Billing->Address->Country		= $order->get_billing_country();		
		
		return $object;
	}
	
	public function create_ip_browser_data($object){
		
		/* ADD IP */
		$object->IpAddress = $_SERVER['REMOTE_ADDR'];
		
		$valueJavaEnabled3ds2 = false;
		if($_POST['JavaEnabled3ds2'] == "yes"){
			$valueJavaEnabled3ds2 = true;
		}
		
		$valueJavascriptEnabled3ds2 = false;
		if($_POST['JavascriptEnabled3ds2'] == "yes"){
			$valueJavascriptEnabled3ds2 = true;
		}
		
		/* ADD Browser data */
		$object->BrowserInfo					= new \MangoPay\BrowserInfo();
		$object->BrowserInfo->AcceptHeader		= $_SERVER['HTTP_ACCEPT'];
		$object->BrowserInfo->JavaEnabled		= $valueJavaEnabled3ds2;
		$object->BrowserInfo->Language			= $_POST['Language3ds2'];
		$object->BrowserInfo->ColorDepth		= $_POST['ColorDepth3ds2'];
		$object->BrowserInfo->ScreenHeight		= $_POST['ScreenHeight3ds2'];
		$object->BrowserInfo->ScreenWidth		= $_POST['ScreenWidth3ds2'];
		$object->BrowserInfo->TimeZoneOffset	= $_POST['TimeZoneOffset3ds2'];
		$object->BrowserInfo->UserAgent			= $_POST['UserAgent3ds2'];
		$object->BrowserInfo->JavascriptEnabled	= $valueJavascriptEnabled3ds2;
		
		/* force 3DS2 only for sandbox option in mangopay/woocommerce options */
		$wc_settings = get_option( 'woocommerce_mangopay_settings' );
		if( !mpAccess::getInstance()->is_production()
			&& isset($wc_settings['enabled_3DS2']) && $wc_settings['enabled_3DS2'] == 'yes'){
			$object->Requested3DSVersion  = "V2_1";
			$object->SecureMode  = "FORCE";
		}		
		
		return $object;
	}
	
	/**
	 * De-activate a pre-authorized card
	 * 
	 * @param unknown $card_id
	 * @return boolean[]|boolean[]|\MangoPay\Libraries\Error[]|NULL[]|boolean[]|NULL[]
	 */	
	 public function deactivate_card($card_id){
		try {
			$Card = new \MangoPay\Card();
			$Card->Id = $card_id;
			$Card->Active = false;
			$this->mangoPayApi->Cards->Update($Card);
			
			return array('success'=>true);		
			
		} catch(MangoPay\Libraries\ResponseException $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage(),
			  'details'=>$e->GetErrorDetails()
			);
		} catch(MangoPay\Libraries\Exception $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage()
			);
		}
	}
		
	/**
	 * Create a woocommerce refund
	 * (unused)
	 * 
	 * @param type $order_id
	 * @param type $refund_amount
	 * @param type $refund_reason
	 * @return type
	 * 
	 *
	public function create_woocommerce_refund($order_id, $refund_amount , $refund_reason = ''){
		
		$data_refund = array(
			'amount'         => $refund_amount,
			'reason'         => $refund_reason,
			'order_id'       => $order_id,
		);
		
		$refund = wc_create_refund( $data_refund );
        if (is_wp_error($refund)) {
            throw new Exception($refund->get_error_message());
        }
		
		return $refund;
	}
	*/
	
	/**
	 * Get the initial amount of the transaction
	 * @param type $transfer_id
	 * @return object data transfert
	 */
	public function get_preauthorization_initial_amount($transfer_id){
		
		try {
			return $this->mangoPayApi->CardPreAuthorizations->Get($transfer_id);

		} catch(MangoPay\Libraries\ResponseException $e) {
		// handle/log the response exception with code $e->GetCode(), message $e->GetMessage() and error(s) $e->GetErrorDetails()
//			echo "<pre>", print_r("ResponseException", 1), "</pre>";
//			echo "<pre>", print_r($e, 1), "</pre>";
		} catch(MangoPay\Libraries\Exception $e) {
		// handle/log the exception $e->GetMessage()
//			echo "<pre>", print_r("Exception", 1), "</pre>";
//			echo "<pre>", print_r($e, 1), "</pre>";
		}	
		
		return false;
	}
	
	
	/**
	 * Capture a pre-authorization based on the Order ID
	 * @param unknown $order_id
	 * @param unknown $mp_user_id
	 * @param unknown $locale
	 * @param unknown $PreauthorizationId
	 * @param number $amount
	 * @return boolean[]|NULL[]|boolean[]|string[]|NULL[]|unknown|\MangoPay\PayIn|boolean[]|\MangoPay\Libraries\Error[]|NULL[]
	 * 
	 */
	public function capture_pre_authorization_by_id( 
		$order_id, 
		$mp_user_id,
		$locale,				//Unused (optional)
		$PreauthorizationId,
		$amount = 0
	) {

		$order = new WC_Order($order_id);					
		$currency = $order->get_currency();
		$total_amount = round($order->get_total() * 100);
		$part_payement = false;
		
		/** If there is no amount specified, take the total **/
		if($amount==0){
			$amount = $total_amount;
		}else{
			/** If the amout is superior to the total, someone might be trying to hack the system=> block **/
			if($amount>$total_amount){
				
				return array(
				  'success'=>false,
				  'message'=>__('Amount is too high.','mangopay')
				);
			}else{
				/** Update the amount to add the decimals **/
				$amount = round($amount * 100);
				$part_payement = true;
			}
		}
		
		$fees = 0;
		$wallets = $this->set_mp_wallet( $mp_user_id );

		if( !$wallets && !( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
		
			if( self::DEBUG ) {
				echo "<pre>mp_user_id:\n";
				var_dump( $mp_user_id );
				echo "wallets:\n";
				var_dump( $wallets );
				echo '</pre>';
			}
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			wp_die( sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account ) );
		
		} elseif( !$wallets ){

			$error_array = array();
			$error_array['success'] = false;
			$error_array['error'] = "no_wallet_found";
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			$error_array['message'] = sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account );
			return $error_array;

		}

		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet ){
			if( $wallet->Currency == $currency ){
				$mp_wallet_id = $wallet->Id;
			}
		}

		/** If still no wallet, abort **/
		if( empty( $mp_wallet_id ) ){
			$error_array = array();
			$error_array['success'] = false;
			$error_array['error']	= "no_wallet_with_current_currency";
			$error_array['message']	= __( 'No wallet found with the current currency.', 'mangopay' );
			return $error_array;
		}
				
		$PayIn 								= new \MangoPay\PayIn();
		$PayIn->CreditedWalletId			= $mp_wallet_id;
		$PayIn->Tag							= 'WC Order #' . $order_id;
		$PayIn->AuthorId 					= $mp_user_id;
		
		$PayIn->PaymentType 				= 'PREAUTHORIZED';
		$PayIn->PaymentDetails 				= new \MangoPay\PayInPaymentDetailsPreAuthorized();
		$PayIn->PaymentDetails->PreauthorizationId 	= $PreauthorizationId;
		
		$PayIn->DebitedFunds 				= new \MangoPay\Money();
		$PayIn->DebitedFunds->Currency		= $currency;
		$PayIn->DebitedFunds->Amount		= $amount;
		
		$PayIn->Fees 						= new \MangoPay\Money();
		$PayIn->Fees->Currency 				= $currency;
		$PayIn->Fees->Amount 				= $fees;
		
		$PayIn->ExecutionDetails 			= new \MangoPay\PayInExecutionDetailsDirect();
						
		try{
		
			$payincreate = $this->mangoPayApi->PayIns->Create($PayIn);			
			if(isset($payincreate->Status) && $payincreate->Status == "SUCCEEDED"){
				$order->add_order_note( sprintf( __( 'The pre-authorized payment was captured on %1s', 'mangopay' ), date('d F Y H:i') ) );
				if($part_payement){
					$order->add_order_note( sprintf( __( 'The pre-authorized amount is %1s', 'mangopay' ), floatval( $amount/100 ) ) );
				}	
			}
			
			return $payincreate;
			
		} catch(MangoPay\Libraries\ResponseException $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage(),
			  'details'=>$e->GetErrorDetails()
			);
		} catch(MangoPay\Libraries\Exception $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage()
			);
		}
	}
	
	/**
	 * Get a pre-authorization by its ID
	 * @param unknown $PreAuthorizationId
	 * @return boolean[]|unknown[]|boolean[]|\MangoPay\Libraries\Error[]|NULL[]|boolean[]|NULL[]
	 * 
	 */
	public function get_preauthorization_by_id($PreAuthorizationId){
		try {
			$CardPreAuthorization = $this->mangoPayApi->CardPreAuthorizations->Get($PreAuthorizationId);
			return array(
			  'success'=>true,
			  'result'=>$CardPreAuthorization
			);	
		} catch(MangoPay\Libraries\ResponseException $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage(),
			  'details'=>$e->GetErrorDetails()
			);
		} catch(MangoPay\Libraries\Exception $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage()
			);
		}
	}
	
	/**
	 * Cancel a pre-authorization by its ID
	 * @param unknown $PreAuthorizationId
	 * @param unknown $order_id
	 * @param string $tag
	 * @return boolean[]|unknown[]|boolean[]|\MangoPay\Libraries\Error[]|NULL[]|boolean[]|NULL[]
	 * 
	 */
	public function cancel_preathorization_by_id($PreAuthorizationId,$order_id,$tag = ''){
		try {
			$CardPreAuthorization = new \MangoPay\CardPreAuthorization();
			$CardPreAuthorization->Tag = $tag;
			$CardPreAuthorization->Id = $PreAuthorizationId;
			$CardPreAuthorization->PaymentStatus = "CANCELED";

			$order = new WC_Order($order_id);
			$order->add_order_note( sprintf( __( 'The pre-authorized payment was cancelled  on %1s', 'mangopay' ), date('d F Y H:i') ) );
			
			$Result = $this->mangoPayApi->CardPreAuthorizations->Update($CardPreAuthorization);
			
			return array(
			  'success'=>true,
			  'result'=>$Result
			);				

		} catch(MangoPay\Libraries\ResponseException $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage(),
			  'details'=>$e->GetErrorDetails()
			);
		} catch(MangoPay\Libraries\Exception $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage()
			);
		}
	}
	
	/**
	 * Update registered cards
	 * @param unknown $id_card
	 * @param unknown $RegistrationData
	 * @return boolean[]|\MangoPay\CardRegistration[]|boolean[]|\MangoPay\Libraries\Error[]|NULL[]|boolean[]|NULL[]|boolean
	 * 
	 */
	public function update_register_card($id_card,$RegistrationData){
		$result = false;
		
		try {
			$CardRegistration = new \MangoPay\CardRegistration();
			$CardRegistration->Id = $id_card;
			$CardRegistration->RegistrationData = $RegistrationData;

			return array(
			  'success'=>true,
			  'result'=>$this->mangoPayApi->CardRegistrations->Update($CardRegistration)
			);

		} catch(MangoPay\Libraries\ResponseException $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage(),
			  'details'=>$e->GetErrorDetails()
			);
		} catch(MangoPay\Libraries\Exception $e) {
			return array(
			  'success'=>false,
			  'message'=>$e->GetMessage()
			);
		}
		
		return $result;
	}
	
	/**
	 * Get all registered cards for this user ID
	 * @param unknown $user_id
	 * @return \MangoPay\Card[]
	 * 
	 */
	public function get_all_user_cards($user_id){
		//get mangopay user
		$mp_user_id = mpAccess::getInstance()->get_mp_user_id($user_id);
		//create sorting
		$pagination = new \MangoPay\Pagination();
		$pagination->Page = 1;
		$pagination->ItemsPerPage = 100;
		
		//get cards (page 1 limited to 100 first)
		$list_cards = $this->mangoPayApi->Users->GetCards($mp_user_id,$pagination);
		
		foreach ($list_cards as $key => $card){			
			if($card->Active == NULL || $card->Active == false ){
				unset($list_cards[$key]);
			}
		}
		
		return $list_cards;
	}
	
	/**
	 * Get the MANGOPAY user ID corresponding to a WP user ID
	 * 
	 * @param int $user_id
	 * @return int mp_user_id
	 */
	public function get_mp_user_id($user_id){
		$umeta_key = 'mp_user_id';
		if( !mpAccess::getInstance()->is_production() ){
			$umeta_key .= '_sandbox';
		}	
		return get_user_meta( $user_id, $umeta_key, true);
	}	
	
	/**
	 * Get pre-authorization data based on the pre-authorization ID
	 * 
	 * @param int $pre_auth_id
	 * @return object $CardPreAuthorization
	 */
	public function get_pre_authorization_data_by_id($pre_auth_id){
		$CardPreAuthorization =  $this->mangoPayApi->CardPreAuthorizations->Get($pre_auth_id);
		return $CardPreAuthorization;
	}	
	
	/**
	 * check the number given to the patterns of mangopay
	 * @param type $company_number
	 * @return string
	 */
	public function check_company_number_patterns($company_number){
		
		$company_number = str_replace(' ', '', $company_number);
		$company_number = str_replace('.', '', $company_number);
		$company_number = str_replace('_', '', $company_number);
		$company_number = str_replace('-', '', $company_number);
		
		$patterns = array();
		$patterns[] = '^([a-z]{2})([0-9]{6})([a-z]{1})$'; //Austria 2 letters + 6 numbers + 1 letter (LLXXXXXXL)
		
		$patterns[] = '^([0-9]{1,6})([a-z]{1})$'; //Austria variation 1-6 numbers + 1 letter (XXXXXXL)
		
		$patterns[] = '^([0-9]{3,6})$'; //Portugal 3-6 numbers (XXXXXX)
		$patterns[] = '^([0-9]{7,10})$'; //Slovenia variation 7 - 10 numbers (XXXXXXXXXX)
		
		$patterns[] = '^([0-9]{5})$'; //Ireland variation 5 numbers (XXXXXX)
		$patterns[] = '^([0-9]{6})$'; //Ireland|Poland 6 numbers (XXXXXX)
		$patterns[] = '^([0-9]{8})$'; //United Kingdom|Croatia|Czech Republic|Denmark|Estonia|Finland|Netherlands|Poland|Romania|Slovakia 8 numbers (XXXXXXXX)
		$patterns[] = '^([0-9]{9})$'; //Bulgaria|France|Greece variation|Lithuania|Norway|Portugal|Switzerland variation 9 numbers (XXXXXXXXX)
		$patterns[] = '^([0-9]{10})$'; //Belgium|Island|Slovenia|Sweden 10 numbers (XXXXXXXXXX)
		$patterns[] = '^([0-9]{11})$'; //Croatia|Italy|Latvia|Norway 	11 numbers (XXXXXXXXXXX)
		$patterns[] = '^([0-9]{12})$'; //Greece 12 numbers (XXXXXXXXXXXX)
		$patterns[] = '^([0-9]{13})$'; //Bulgaria variation 13 numbers (XXXXXXXXXXXXX)
		$patterns[] = '^([0-9]{14})$'; //France 14 numbers (XXXXXXXXXXXXXX)
		
		$patterns[] = '^([a-z]{1})([0-9]{5})$'; //Malta 1 letter + 5 numbers (LXXXXX)
		$patterns[] = '^([a-z]{1})([0-9]{6})$'; //Luxembourg 1 letter + 6 numbers (LXXXXXX)
		$patterns[] = '^([a-j]{1})([0-9]{3,6})$'; //Luxembourg variation 1 letter (from A to J) + 3-6 numbers
		$patterns[] = '^([a-z]{1})([0-9]{8})$'; //Spain 1 letter + 8 numbers (LXXXXXXXX)
		
		$patterns[] = '^([a-z]{1})([0-9]{7})([a-z]{1})$'; //Spain variation 1 letter + 7 numbers + 1 letter (LXXXXXXXL)
		
		$patterns[] = '^([a-z]{2})([0-9]{6})$'; //Cyprus|Germany 2 letters + 6 numbers (HEXXXXXX)
		$patterns[] = '^([a-z]{2})([0-9]{7})$'; //Italy variation 2 letters + 7 numbers (LLXXXXXXX)
		$patterns[] = '^([a-z]{2})([0-9]{10})$'; //Hungary 2 letters + 10 numbers (LLXXXXXXXXXX)
		
		$patterns[] = '^([a-z]{3})([0-9]{6})$'; //Germany 3 letters + 6 numbers (HEXXXXXX)
		$patterns[] = '^([a-z]{3})([0-9]{9})$'; //Liechtenstein 3 letters + 9 numbers (LLLXXX XXX XXX)
		
		//United Kingdom variations 
		$patterns[] = '^(oc)([0-9]{6})$'; //OC + 6 numbers (OCXXXXXX) 
		$patterns[] = '^(sc)([0-9]{6})$'; //SC + 6 numbers (SCXXXXXX) 
		$patterns[] = '^(ni)([0-9]{6})$'; //NI + 6 numbers (NIXXXXXX)
		$patterns[] = '^(r)([0-9]{7})$'; //R + 7 numbers (RXXXXXXX)
		$patterns[] = '^(ip)([0-9]{5})(r)$'; //IP + 5 numbers + R (IPXXXXXR)
		
		//Germany variations
		$patterns[] = '^([a-z]{2,3})([0-9]{1,6})$'; //2-3 letters + 1-6 numbers (LLLXXXX)
		$patterns[] = '^([a-z]{2,3})([0-9]{1,6})([a-z]{1})$'; //2-3 letters + 1-6 numbers + 1 letter (LLLXXXXXXL)
		$patterns[] = '^([a-z]{2,3})([0-9]{1,6})([a-z]{1,2})$'; //2-3 letters + 1-6 numbers + 1-2 letter (LLLXXXXXXLL)
		
		$decision = "nopattern";
		foreach($patterns as $pattern){
			if(preg_match('#'.$pattern.'#i',$company_number)){
				$decision = apply_filters( 'mangopay_valid_companynumber', 'found', $company_number );
				return $decision;
			}
		}
		
		$decision = apply_filters( 'mangopay_valid_companynumber', $decision, $company_number );
		return $decision;
	}
	
	
	public function azr(){
		
	}
}
?>