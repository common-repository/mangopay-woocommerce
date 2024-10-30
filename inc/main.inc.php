<?php
/**
 * MANGOPAY WooCommerce plugin main class
 *
 * @author yann@wpandco.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 * Comment shorthand notations:
 * WP = WordPress core
 * WC = WooCommerce plugin
 * WV = WC-Vendor plugin
 * DB = MANGOPAY dashboard
 *
 */
class mangopayWCMain {
   
    /** Configuration variables loaded from conf.inc.php by load_config() **/
    private $defaults;                // Will hold plugin default values
    private $allowed_currencies;
    private $account_types;
    private $mangopayWCValidation;    // Will hold user profile validation class
       
    /** Class variables **/
    private $mp;                // This will store our mpAccess class instance
    private $_current_order;    // This stores the current order when listing orders in the WV dashboard
    private $instapay = false;    // WV feature: Instantly pay vendors their commission when an order is made
    public $options;            // Public because shared with mangopayWCAdmin. TODO: refactor
    //TODO: options should not be public because they contain the decrypted API key (passphrase)!
   
    /* message from post processing */
    public $_message_red;
    public $_message_green;
   
    /**
     * Class constructor
     *
     */
    public function __construct( $version='0.2.2' ) {
    	
        /** Load configuration values from config.inc.php **/
        $this->load_config();
        
        /** Switch PHP debug mode on/off **/
        if( mangopayWCConfig::DEBUG ) {
            error_reporting( -1 );    // to enable all errors
            ini_set( 'display_errors', 1 );
            ini_set( 'display_startup_errors', 1 );
        }
               
        add_action( 'wp_enqueue_scripts', array($this,'enqueue_mango_scripts' ));
		add_action( 'admin_enqueue_scripts', array($this,'enqueue_mango_scripts' ),10,1);
		
        /** Instantiate mpAccess (need to do before decrypt options because need tmp dir) **/
        $this->mp = mpAccess::getInstance();
   
		/** Get stored plugin settings **/
		$this->defaults['plugin_version'] = $version;
		$this->options = $this->decrypt_passphrase( get_option( mangopayWCConfig::OPTION_KEY, $this->defaults ) );
		
		/* Debug *
		var_dump( $this->defaults );		//Debug
		var_dump( $this->options ); // exit;	//Debug
	    /* */
		
        /** Set the MANGOPAY environment ( production or sandbox + login and API key (passphrase) ) **/
        //TODO: move details of this logic outside the constructor
        if( isset( $this->options['prod_or_sandbox'] ) ){
            if( 'prod' == $this->options['prod_or_sandbox'] ) {
                $this->mp->setEnv(
                    'prod',
                    $this->options['prod_client_id'],
                    $this->options['prod_passphrase'],
                    $this->options['default_buyer_status'],
                    $this->options['default_vendor_status'],
                    $this->options['default_business_type'],
                     mangopayWCConfig::DEBUG
                );
            } else {
                $this->mp->setEnv(
                    'sandbox',
                    $this->options['sand_client_id'],
                    $this->options['sand_passphrase'],
                    $this->options['default_buyer_status'],
                    $this->options['default_vendor_status'],
                    $this->options['default_business_type'],
                    mangopayWCConfig::DEBUG
                );
            }
		}
		
        /** Get WV instapay option status **/
        $wv_options = get_option( mangopayWCConfig::WV_OPTION_KEY );
        $wv2_instantpay = get_option( mangopayWCConfig::WV_INSTAPAY_KEY );
        if( 
        	( !empty( $wv_options ) && is_array( $wv_options ) && isset( $wv_options['instapay'] ) && $wv_options['instapay'] ) ||
        	( !empty( $wv2_instantpay ) && 'yes' == $wv2_instantpay )
        ) {
            $this->instapay = true;
        }
        /** Setting of WV 2.x takes precedence **/
        if( !empty( $wv2_instantpay ) && 'no' == $wv2_instantpay ) {
        	$this->instapay = false;
        }
        	
		/** if instapay is false (or not setable by new wcv version we let mangopay set it **/
		if($this->instapay == false){
			$mp_settings = get_option( 'woocommerce_mangopay_settings' );
			if(isset($mp_settings['instapay']) && $mp_settings['instapay']=="yes"){
				$this->instapay = true;
			}
		}
				
        /** The activation hook must be a static function **/
        register_activation_hook( __FILE__, array( 'mangopayWCPlugin', 'on_plugin_activation' ) );

        /** Instantiate user profile field validations class **/
        $this->mangopayWCValidation = new mangopayWCValidation( $this );
       
        /** Instantiate admin interface class if necessary **/
        $mangopayWCAdmin = null;
        if( is_admin() )
            $mangopayWCAdmin = new mangopayWCAdmin( $this );
       
        /** Instantiate incoming webhooks class if necessary **/
        if( !is_admin() )
            $mangopayWCWebHooks = new mangopayWCWebHooks( $this );
       
        /** Setup all our WP/WC/WV hooks **/
        mangopayWCHooks::set_hooks( $this, $mangopayWCAdmin );
       
        /** Manage plugin upgrades **/
        if( empty( $this->options['plugin_version'] ) ) {
            mangopayWCPlugin::upgrade_plugin( '0.2.2', $version, $this->options );
        } elseif( $this->options['plugin_version'] != $version ) {
            mangopayWCPlugin::upgrade_plugin( $this->options['plugin_version'], $version, $this->options );
        }
       
        /* post process */
        add_action( 'init', array($this,'mangopay_process_post') );
	   
        /* add shortodes */
        add_shortcode( 'kyc_doc_upload_form', array($this,'kyc_doc_upload_form_func') );
        add_shortcode( 'kyc_doc_user_infos', array($this,'kyc_doc_user_infos') );
       
        //add_action( 'wp_print_scripts', array($this,'wc_dequeue_script'), 100 );
    }
		
    /** Messages displayed on the front-end vendor dashboards **/
	public function mangopay_messages_notices(){

		/** wcv test to know if we are on the vendor settings **/
		global $post;
		if(
			!is_a( $post, 'WP_Post' )
			|| is_admin() 
			|| !is_user_logged_in()
			|| !$this->is_vendor( get_current_user_id() )
			|| (
				!has_shortcode( $post->post_content, 'wcv_shop_settings' ) 
				&& !has_shortcode( $post->post_content, 'wcv_vendor_dashboard' )
				&& !has_shortcode( $post->post_content, 'wcv_pro_dashboard' )
				)
		){
			return;
		}
		
		/* get user */
		$user_id = get_current_user_id();
		
		/* 1) test if the user has MP account */
		$kyc_valid = $this->test_vendor_kyc($user_id);
		//no account
		if($kyc_valid === "no_account_bd"){
			
			wc_add_notice('<div class="notice notice-error is-dismissable">'
					.__('Your wallet is not yeat configured.','mangopay')
					.'</div>','error');	
			
			//stop testing, the rest will have no interest as the base doesn't work
			return;
		}
		
		/* 2) no bank account */
		$has_bank_account = $this->mp->has_bank_account($user_id);
		if(!$has_bank_account){
			wc_add_notice('<div class="notice notice-error is-dismissable">'
						.__('You need to supply a bank account.','mangopay')
						.'</div>','error');
		}
		
		/* 2) TEST KYC */
		if(!$kyc_valid){			
			$user_mp_status = get_user_meta($user_id, 'user_mp_status', true);
			$user_business_type = get_user_meta($user_id, 'user_business_type', true);			
			if($user_mp_status == 'business' || $user_business_type == 'business'){		
				wc_add_notice('<div class="notice notice-error is-dismissable">'
					.__('You need to validate your KYC/UBO documents to do a payout.','mangopay')
					.'</div>','error');
			}else{
				wc_add_notice('<div class="notice notice-error is-dismissable">'
					.__('You need to validate your KYC documents to do a payout.','mangopay')
					.'</div>','error');
			}			
		}
		
		/* 3) TEST T&C */
		$tc = get_user_meta( $user_id, 'termsconditions', true );
		if(!$tc || trim($tc)==''){
			wc_add_notice('<div class="notice notice-error is-dismissable">'
				.__('You need to accept the Terms and Conditions to do a payout.','mangopay')
				.'</div>','error');
		}
				
		/* 4) TEST UBO */
//		$ubo_result = $this->mp->test_vendor_ubo($user_id);	
//		if($ubo_result === false){
//			wc_add_notice('<div class="notice notice-error is-dismissable">'
//				.__('You need to validate your UBO documents to do a payout.','mangopay')
//				.'</div>','error');
//		}
							
		/* 5) Company number, birthday, nationality  */
		$user_mp_status = get_user_meta($user_id, 'user_mp_status', true);
		$user_business_type = get_user_meta($user_id, 'user_business_type', true);
		if($user_mp_status == 'business'){
			
			//user legal, need birthday
			$user_birthday		= get_user_meta( 'user_birthday', $user_id );			
			if($user_birthday){
				wc_add_notice('<div class="notice notice-error is-dismissable">'
						.__('You need to supply a birthday date.','mangopay')
						.'</div>','error');
			}
			//user legal, need nationality
			$user_nationality	= get_user_meta( 'user_nationality', $user_id );			
			if($user_nationality){
				wc_add_notice('<div class="notice notice-error is-dismissable">'
						.__('You need to supply your nationality.','mangopay')
						.'</div>','error');
			}
			//user is business need company number
			if($user_business_type == 'business'){			
				$company_number = get_user_meta( $user_id, 'compagny_number', true );
				if(!$company_number){
					wc_add_notice('<div class="notice notice-error is-dismissable">'
						.__('You need to supply a valid company number to do a payout.','mangopay')
						.'</div>','error');
				}		
			}
		}
		
		/* get general message DEACTIVATED */
//		if($this->mangopay_message_notice() != ''){
//			$message = $this->mangopay_message_notice_front();
//			wc_add_notice('<div class="notice notice-notice is-dismissable">'
//				.$message
//				.'</div>','notice');
//		}
	}
	
	/**
	 * Message to return to the admin vendor dashboard
	 * @return string
	 */
//	public function mangopay_message_notice(){
//		$message = __(
//			"<strong>Important notice from the MANGOPAY-WooCommerce payment gateway plugin</strong><br>" .
//			"Due to regulatory changes for on-line payments, starting <strong>1st of September 2019</strong>, " .
//			"all vendors will need to have checked the marketplace's Terms and Conditions and validate their KYC documents " .
//			"in order to be able to receive payouts. Legal users that operate as businesses will additionally need to " .
//			"have a valid company number. Use <a href=\"./admin.php?page=mangopay_settings\">the plugin's dashboard</a> " .
//			"and <a href=\"./users.php\">the users list</a> to check that all your vendors do comply with those " .
//			"mandatory requirements before this date.", 
//			'mangopay'
//		);
//		
//		return $message;
//	}
	
	/**
	 * Message to return to the the front vendor dashboard
	 * @return string
	 */
//	public function mangopay_message_notice_front(){
//		$message = __(
//			"<strong>Important notice from the MANGOPAY-WooCommerce payment gateway plugin</strong><br>" .
//			"Due to regulatory changes for on-line payments, starting <strong>1st of September 2019</strong>, " .
//			"all vendors will need to have checked the marketplace's Terms and Conditions and validate their KYC documents " .
//			"in order to be able to receive payouts. Legal users that operate as businesses will additionally need to " .
//			"have a valid company number. " .
//			"Please make sure to comply with those mandatory conditions before this date.", 
//			'mangopay'
//		);
//		
//		return $message;
//	}	
	
    /**
     * Returns HTML for the KYC docs info of the current user
     * 
     * @return string HTML
     * 
     */
    public function kyc_doc_user_infos(){
        
        /** Get mp user **/
        $umeta_key = 'mp_user_id';
        if( !$this->mp->is_production() ){
            $umeta_key .= '_sandbox';
        }
        $wp_user_id = get_current_user_id();
        $existing_account_id = get_user_meta( $wp_user_id , $umeta_key, true );
               
        if($existing_account_id){
            $html = '';
            
            $mp_user = $this->mp->get_mp_user($existing_account_id);
			if( empty($mp_user) ){
				return '';
			}
			
            $persontype = $mp_user->PersonType;
            $list_to_show = $this->get_list_kyc_documents_types($persontype,$mp_user);
           
            /* add banner to inform about the level of completion */
            $text_banner = __("You must upload the following documents to complete the compliance checks", 'mangopay' );
            if($mp_user->KYCLevel == "REGULAR"){
                $text_banner = __("You have successfully completed all the compliance checks - thank you!", 'mangopay' );
            }
            $html.= '<div class="kyc_list_documents_banner '.strtolower($mp_user->KYCLevel).'">';
                $html.= $text_banner;
            $html.= '</div>';
			            
            $html.= '<table class="kyc_list_documents_ul">';
            /*get all documents of that user */
            $all_docs = $this->mp->get_kyc_documents($existing_account_id);
                foreach($all_docs as $doc){
                   
                    //get document utilise en usermeta
                    $data_file_user_meta = get_user_meta($wp_user_id,'kyc_document_'.$doc->Id,true);
					$data_error_file_user_meta = get_user_meta($wp_user_id,'kyc_error_'.$doc->Id,true);
                   
                    //unset from list an,d print the rest after
                    unset($list_to_show[$doc->Type]);
                   
                    $text = strtolower($doc->Status);
                    $text = str_replace('_', ' ', $text);
                    $text = ucfirst($text);
                    $text_status = __($text, 'mangopay' );
                   
                   
                    $html.= '<tr class="kyc_list_documents_li" data-id="'.$doc->Id.'">';

                        /* 1 ICONE  */
                        $html.= '<td class="kyc_list_documents_td_status_icon">';
                       
                        $html.= '<mark class="kyc '.strtolower($doc->Status).'">';
                        $html.= $text_status;
                        $html.= '</mark>';
                        $html.= '</td>';
                   
                        /* 2 DOCUMENT TYPE */
                        $html.= '<td class="kyc_list_documents_span_type">';
                        
                        $text = strtolower($doc->Type);
                        $text = str_replace('_', ' ', $text);
                        $text = ucfirst($text);
                        $html.= __($text, 'mangopay' );
                        $html.= '</td>';
           
                        /* 4 DATE */
                        $wp_date_format = get_option('date_format');
                        $wp_time_format = get_option('time_format');
                        
                        $html .= '<td class="kyc_list_documents_span_time">';
                        $html .= __( 'Uploaded on', 'mangopay' ) . ' ';
                        
                        $html .= date($wp_date_format,$doc->CreationDate);
//                        $html .= date('Y-m-d H:i',$doc->CreationDate);
                        $html .= '</td>';                   
                   
                        /* 5 STATUS */
                        $english_textes = array(
                            'DOCUMENT_UNREADABLE' => 'Document unreadable'
                            ,'DOCUMENT_NOT_ACCEPTED' => 'Document not acceptable'
                            ,'DOCUMENT_HAS_EXPIRED' => 'Document has expired'
                            ,'DOCUMENT_INCOMPLETE' => 'Document incomplete'
                            ,'DOCUMENT_MISSING' => 'Document missing'
                            ,'DOCUMENT_DO_NOT_MATCH_USER_DATA'=>'Document does not match user data'
                            ,'DOCUMENT_DO_NOT_MATCH_ACCOUNT_DATA'=>'Document does not match account data'
                            ,'SPECIFIC_CASE'=>'Specific case, please contact us'
                            ,'DOCUMENT_FALSIFIED'=>'Document has been falsified'
                            ,'UNDERAGE_PERSON'=>'Underage person'
                            ,'OTHER'=>'Other'
                            ,'TRIGGER_PEPS'=>'PEPS check triggered'
                            ,'TRIGGER_SANCTIONS_LISTS'=>'Sanction lists check triggered'
                            ,'TRIGGER_INTERPOL'=>'Interpol check triggered'
                        );
                        $html.= '<td class="kyc_list_documents_span_status">';
                        $html.= $text_status;
                            if(isset($doc->RefusedReasonMessage) && $doc->RefusedReasonMessage != ""){
                                $html.= ' : '.__($doc->RefusedReasonMessage, 'mangopay');
                            }elseif(isset($doc->RefusedReasonType ) && $doc->RefusedReasonType  != ""){
                                $html.= ' : '.__($english_textes[$doc->RefusedReasonType], 'mangopay');
                            }
							
							if($data_error_file_user_meta){
								foreach($data_error_file_user_meta as $key_error_message=>$message_error){
									$html.= '<br>'.__($message_error, 'mangopay');
								}
							}
							
                        $html.= '</td>';

                    $html.= '</tr>';
                }
               
                if(count($list_to_show)>0){
                    foreach($list_to_show as $keydocfromlist=>$docfromlist){
                        $html.= '<tr class="kyc_list_documents_li">';
                       
                        /* 1 ICONE  */
                        $html.= '<td class="kyc_list_documents_td_status_icon">';
                        $html.= '<mark class="kyc not_sent">';
                        $html.= __('NOT_SENT', 'mangopay' );
                        $html.= '</mark>';
                        $html.= '</td>';
                   
                        /* 2 DOCUMENT TYPE */
                        $html.= '<td class="kyc_list_documents_span_type">';
                            $text = strtolower($keydocfromlist);
                            $text = str_replace('_', ' ', $text);
                            $text = ucfirst($text);
                            $html.= __($text, 'mangopay' );
                        $html.= '</td>';

//                        /* 3 FILE NAMES */
//                        $html.= '<td class="kyc_list_documents_span_filename">';
//                        $html.= '&nbsp;</td>';                   
                   
                        /* 4 DATE */
                        $html.= '<td class="kyc_list_documents_span_time">';
                        $html.= '&nbsp;</td>';                   
                   
                        /* 5 STATUS */
                        $html.= '<td class="kyc_list_documents_span_status">';
                        $html.= __('Not uploaded yet', 'mangopay' );
                        $html.= '</td>';
                       
                        /* 6 ERROR from Mango */
//                        $html.= '<td class="kyc_list_documents_span_errormessage">';
//                        if($doc->RefusedReasonMessage){
//                        $html.= $doc->RefusedReasonMessage;
//                        }
//                        $html.= '</td>';

                        $html .= '</tr>';
                    }
                }
            $html.= '</table>';
           
            return $html;
        }       
    }
   
    /**
     * Everything you need for the init
     */
    public function mangopay_process_post() {
		                
        if(isset($_FILES['kyc_file']) && $_FILES['kyc_file']["tmp_name"][0] != ""){

            $umeta_key = 'mp_user_id';
            if( !$this->mp->is_production() ){
                $umeta_key .= '_sandbox';
            }
            $wp_user_id = get_current_user_id();
            $existing_account_id = get_user_meta( $wp_user_id , $umeta_key, true );
			
			/** test if already posted **/
			$kyc_file_timestop = get_user_meta($wp_user_id,'kyc_file_timestop_'.$_POST['kyc_file_timestop'],true);
			if($kyc_file_timestop){
				//if exit we get out;
				return;
			}

            $data_user_meta = array();
            if(isset($_FILES['kyc_file']) && $existing_account_id && isset($_POST['kyc_file_type'])){

                /* 0 pre test files */
                /*
                The maximum size per page is about 7Mb (or 10Mb when base64encoded).
                The following formats are accepted for the documents : .pdf, .jpeg, .jpg, .gif and .png.
                The minimum size is 1Kb.
                 * */
                $number_files = count($_FILES["kyc_file"]["name"])-1;
                $error = false;
                for($alpha=0;$alpha<=$number_files;$alpha++){
                    $KycPage = $_FILES["kyc_file"]["tmp_name"][$alpha];

                    /*test type*/
                    $filetype = $_FILES["kyc_file"]["type"][$alpha];
                    if(!preg_match('#pdf|jpeg|jpg|gif|png#', $filetype)){
                        //$this->_message_red[] = $_FILES["kyc_file"]["name"][$alpha].' : '.__('This file type is not permited. (.pdf, .jpeg, .jpg, .gif and .png only)', 'mangopay' );   
                        $error = true;
                        wc_add_notice($_FILES["kyc_file"]["name"][$alpha].' : '.__('Unauthorized file type (.pdf, .jpeg, .jpg, .gif and .png only)', 'mangopay' ),"error");
                    }
                    $filesize = $_FILES["kyc_file"]["size"][$alpha];
					
					//only for identity proof
					if(intval($filesize)<32000 && $_POST['kyc_file_type'] == MangoPay\KycDocumentType::IdentityProof){ 
						$error = true;
                        wc_add_notice($_FILES["kyc_file"]["name"][$alpha].' : '.__('This file is too small. (32KB min.)', 'mangopay' ),"error");
					}elseif(intval($filesize)<1000){ //else for the others
                        //$this->_message_red[] = $_FILES["kyc_file"]["name"][$alpha].' : '.__('This file is too small. (1Kb min.)', 'mangopay' );
                        $error = true;
                        wc_add_notice($_FILES["kyc_file"]["name"][$alpha].' : '.__('This file is too small. (1KB min.)', 'mangopay' ),"error");
                    }
                    if(intval($filesize)>7000000){
                        //$this->_message_red[] = $_FILES["kyc_file"]["name"][$alpha].' : '.__('This file is too big. (7Mb Max)', 'mangopay' );
                        $error = true;
                        wc_add_notice($_FILES["kyc_file"]["name"][$alpha].' : '.__('This file is too big. (7MB max.)', 'mangopay' ),"error");
                    }
                }  
                /* there is at least a message, so we stop all process */
                if($error){ //$this->_message_red
                    return;
                }               
               
                $KycDocument = new \MangoPay\KycDocument();
                $KycDocument->Tag = "wp_user_id:".$wp_user_id;
                $KycDocument->Type = $_POST['kyc_file_type'];

                /* 1 create document */     
                try{
                    $document_created = $this->mp->create_kyc_document($existing_account_id,$KycDocument);
                    $kycDocumentId = $document_created->Id;
                }  catch (Exception $e){
                    //echo "<pre>", print_r("create document", 1), "</pre>";
                    //echo "<pre>", print_r($e, 1), "</pre>";
                }
               

                if($kycDocumentId){
                    /* 2 add pages */
                    try{
                        $number_files = count($_FILES["kyc_file"]["name"])-1;
                        for($alpha=0;$alpha<=$number_files;$alpha++){
                            $KycPage = $_FILES["kyc_file"]["tmp_name"][$alpha];
                            $this->mp->create_kyc_page_from_file($existing_account_id, $kycDocumentId, $KycPage);
                            $data_user_meta['name_'.$alpha] = $_FILES["kyc_file"]["name"][$alpha];
                        }

                        /* 3 submit a document ask for VALIDATION_ASKED */
                        //i don't why i can't use directly the $KycDocument, but that way it does not do a fatal error
                        $the_doc = $this->mp->get_kyc_document($existing_account_id,$kycDocumentId);
                        $the_doc->Status = "VALIDATION_ASKED";
                        $result = $this->mp->update_kyc_document($existing_account_id,$the_doc);
                    }  catch (Exception $e){
						//echo "<pre>", print_r($e, 1), "</pre>";						
                        if(isset($e->GetErrorDetails()->Errors)){
							update_user_meta( $wp_user_id, 'kyc_error_'.$kycDocumentId, $e->GetErrorDetails()->Errors);
						}
                    }
					
                    /* save document to user meta */
                    if($result){
                        //update_user_meta( $wp_user_id, 'key', 'val');
                        $data_meta['type'] = $_POST['kyc_file_type'];
                        $data_meta['id_mp_doc'] = $kycDocumentId;
                        $data_meta['creation_date'] = $result->CreationDate;
                        $data_meta['document_name'] = $_FILES["kyc_file"]["name"];
                        update_user_meta( $wp_user_id, 'kyc_document_'.$kycDocumentId, $data_meta);
						
						/** unset to stop reload (F5) of the navigator to upload again **/
						update_user_meta( $wp_user_id, 'kyc_file_timestop_'.$_POST['kyc_file_timestop'],$kycDocumentId );
						
                        //wc_add_notice(__('The document has been successfully uploaded and submitted for validation', 'mangopay'),"success");
                        //$this->_message_green = __('Your document was sucessefully submited and is waiting a review.');
                    }else{
                        wc_add_notice(__('Something went wrong (no validation asked)', 'mangopay'),"error");
                        //$this->_message_red = __('Something went wrong (no validation asked)', 'mangopay' );
                    }
                   
                }else{
                    wc_add_notice(__('Something went wrong (no validation asked)', 'mangopay'),"error");
                    //$this->_message_red = __('Something went wrong (document not created)', 'mangopay' );
                }
            }else{
                wc_add_notice(__('Something went wrong (no validation asked)', 'mangopay'),"error");
                //$this->_message_red = __('Something went wrong (no access)', 'mangopay' );
            }
        }	
    }
   
   
    public function kyc_doc_upload_form_doaction_admin(){
        $atts = array();
        $atts['wcvendorform'] = 'wcvendors_admin';
        echo $this->kyc_doc_upload_form_func($atts);
    }
    
    public function kyc_doc_upload_form_doaction( $template_name, $template_path, $located, $args){
        
        if($template_path.$template_name == "wc-vendors/dashboard/store-settings.php"){
            $atts = array();
            $atts['wcvendorform'] = 'wcvendors';
            $atts['wcvpro'] = true;
            echo $this->kyc_doc_upload_form_func($atts);            
        }
        
        if($template_path.$template_name == "wc-vendors/dashboard/settings/settings.php"){
            $atts = array();
            $atts['wcvendorform'] = 'wcvendors';
            echo $this->kyc_doc_upload_form_func($atts);            
        }
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
	 * 
	 * @param type $wp_user_id
	 * @return boolean|string
	 */
	public function test_vendor_kyc($wp_user_id){
		//NB there is a sister TEST 
		/** We store a different mp_account_id for production and sandbox environments **/
		$umeta_key = 'mp_user_id';
		if( !$this->mp->is_production() ){
			$umeta_key .= '_sandbox';
		}		
		$mp_vendor_id = get_user_meta(  $wp_user_id, $umeta_key, true );
		if(!$mp_vendor_id || empty($mp_vendor_id)){
			return 'no_account_bd';
		}
		
		return $this->mp->test_vendor_kyc($mp_vendor_id);
	}
	
	/**
	 * Returns HTML for the KYC doc upload form
	 * 
	 * @param type $atts
	 */
	public function kyc_doc_upload_form_func($atts){
		
		wp_enqueue_script('jquery-ui-datepicker');
		$this->localize_datepicker();
		wp_register_style(
		  'jquery-ui',
		  plugins_url( '/css/jquery-ui.css', dirname( __FILE__ ) ),
		  false, '1.8'
		);
		wp_enqueue_style( 'jquery-ui' );
   
		/** Get user MP id **/
		$umeta_key = 'mp_user_id';
		if( !$this->mp->is_production() ){
			$umeta_key .= '_sandbox';
		}
		$wp_user_id = get_current_user_id();
		$existing_account_id = get_user_meta( $wp_user_id , $umeta_key, true );

		/** Get user MP status **/
		$mp_user = $this->mp->get_mp_user($existing_account_id);
		if( empty($mp_user) ){
			return '';
		}

		$persontype = $mp_user->PersonType;
		$legalperson = '';

		/** MP KYC info **/
		$list_to_show = $this->get_list_kyc_documents_types($persontype,$mp_user);

		$hide_before_javascript = ''; 
		if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors_admin'){
			$hide_before_javascript = 'style="display:none;"';
		}

		if(isset($atts['wcvendorform']) && isset($atts['wcvpro']) && $atts['wcvpro']){
			$hide_before_javascript = 'style="display:none;"';
		}

		$html = '';
		
		if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors_admin'){
			$html.= '<tr><td>';
		}
        $html.= '<div id="kyc_div_global" '.$hide_before_javascript.'>';
       
        if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors'){
            $html.= '<div style="display:block;margin-top:20px;"><div>';
            $html.= $this->kyc_doc_user_infos();
        }
        
        if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors_admin'){
            $html.= $this->kyc_doc_user_infos();
        }
               
        $html.= '<div class="kyc_form_block">';
            $html.= '<p><strong>' . __( 'Upload a new document:', 'mangopay' ) . '</strong></p>';
            $html.= '<div id="pv_shop_kyc_container">';
            
            if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors_admin'){
                
            }else{
                $html.= '<form action="" method="post" enctype="multipart/form-data" class="kyc_form kyc_form wcv-formvalidator">';// wcv-form 
            }
               
				$html.= '<input type="hidden" name="kyc_file_timestop" value="'.time().'">';
                $html.= '<div class="kyc_select_type_div">';
                    $html.= '<p><select name="kyc_file_type" class="kyc_select_type">';
                   
                    foreach($list_to_show as $value_type=>$text_type){
                        $html.= '<option value="'.$value_type.'">';
                            $html.= $text_type;
                        $html.= '</option>';
                    }
                   
                    $html.= '</select></p>';     
                 $html.= '</div>';  
                
                $html.= '<div class="kyc_input_file_div">';
                    $html .= '<p><input type="file" name="kyc_file[]" id="kyc_file" multiple class="kyc_input_file"></p>';
                    $html .= '<p>';
                    $html .= __('Use the Ctrl key to select multiple files to upload if necessary','mangopay').'<br>';
                    $html .= '</p>';
                $html.= '</div>';                
       
                $html.= '<div class="kyc_submit_div">'; 
                
                    
                    $class_button_kyc = 'kyc_submit';
                    if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors_admin'){
                        $class_button_kyc = 'button button-primary';    
                    }
                
                    $html.= '<p><input type="submit" value="'.__( 'Confirm', 'mangopay' ).'" class="'.$class_button_kyc.' wcv-button"></p>';
                $html.= '</div>';
                
				/** UBO **/
				$html.= $this->ubo_form_html();
				
				
            if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors_admin'){
                
            }else{
                $html.= '</form>';
            }
            
             $html.= '</div>';//pv_shop_kyc_container
       
        $html.= '</div>';//kyc_form_block
       
        /*to hide last buttonfrom other form do action wcvendors_settings_after_shop_description */
//        if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors_admin'){           
//            //$html.= '<div style="display:none">';
//        }       
       
        $html.='</div>';//end kyc div globale
		
		if(isset($atts['wcvendorform']) && $atts['wcvendorform'] == 'wcvendors_admin'){
			$html.= '</td></tr>';
		}
		
        return $html;
    }
   
	/**
	 * 
	 * @return string
	 */
	public function ubo_form_html(){
		$html = '';
		
		$umeta_key = 'mp_user_id';
		if( !$this->mp->is_production() ){
			$umeta_key .= '_sandbox';
		}
		$wp_user_id = get_current_user_id();
        $existing_account_id = get_user_meta( $wp_user_id , $umeta_key, true );
               
        if($existing_account_id){
            $html = '';
			$html.= '<h3 clas="ubo_title_h">'.__('UBO Declaration','mangopay').'</h3>';
			$html.= '<div class="ubo_description_hint_div">'.__('UBOs (Ultimate Beneficial Owners) are the individuals possessing more than 25% of the shares or voting rights of a company. This declaration replaces the old shareholder declaration.','mangopay').'</div>';
            
            $mp_user = $this->mp->get_mp_user($existing_account_id);
			if( empty($mp_user) ){
				return '';
			}
			
            $persontype = $mp_user->PersonType; //LEGAL
			$legalpersontype = false;
			if(isset($mp_user->LegalPersonType)){
				$legalpersontype = $mp_user->LegalPersonType; //BUSINESS
			}
			
			if($persontype != "LEGAL" || $legalpersontype != "BUSINESS"){
				return '';
			}
			
			$html.= '<div id="ubo_data" data-mpid="'.$existing_account_id.'">'.__('Loading','mangopay').'</div>';
		}
		
		$html = apply_filters('mangopay_ubo_form_html', $html );
		return $html;
	}
   
    /**
     * Cancel our lone cancel notices
     * 
     * @param type $message
     * @return boolean
     */       
    public function intercept_messages_cancel_order($message ){
        $all_notices  = WC()->session->get( 'wc_notices', array() );
        foreach($all_notices as $notices){
        	
        	/** Cancel any cancel message **/
            foreach($notices as $notice){   
            	
            	/** WooCommerce 3.9 and above: notices are now arrays **/
            	if( is_array( $notice ) ) {     		
            		if( !empty( $notice['notice'] ) ) {
            			if( preg_match('#span class\=\"cancelmessagealone#', $notice['notice'] ) ) {
            				return false;
            			}
            		}
            	}
            	
            	/** WooCommerce 3.8 and before: notices were strings **/
            	if( is_string( $notice ) ) {
            		if( preg_match('#span class\=\"cancelmessagealone#', $notice ) ) {	
                   		return false;
                	}
            	}
            	
            } // endforeach $notices
        } //endforeach $all_notices
        return $message;
    }
   
    public function enqueue_mango_scripts($hook_suffix){
		
		/** if admin we limit to the suffix **/
		if(is_admin() && $hook_suffix != 'toplevel_page_wcv-vendor-shopsettings'){
			return;
		}
		/** if front we dont limit **/
		
        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        $assets_path = str_replace( array( 'http:', 'https:' ), '', WC()->plugin_url() ) . '/assets/';
       
//        wp_enqueue_style(
//            'wc-country-select2-css',
//             $assets_path.'css/select2.css',
//            array(),
//            '2.6.4'
//        );        
        wp_enqueue_style(
            'mango-front-css',
             plugins_url( 'css/mangopay.css', dirname( __FILE__ ) ),
             array(),
             $this->options['plugin_version']
        );        
//        wp_enqueue_style(
//            'wc-country-select2admin-css',
//             $assets_path.'css/admin.css',
//             array(),
//             $this->options['plugin_version']
//        );   
//        wp_enqueue_script(
//            'wc-country-select2',
//             $assets_path.'js/select2/select2' . $suffix . '.js',
//            array( 'jquery'),
//            $this->options['plugin_version']
//        ); 

		wp_enqueue_script(
            'front-mangopay-kit',
            plugins_url( 'js/mangopay-kit.js', dirname( __FILE__ ) ) ,
            array( 'jquery'),
            $this->options['plugin_version'],
            true
        );				
        wp_enqueue_script(
            'front-mangopay',
            plugins_url( 'js/front-mangopay.js', dirname( __FILE__ ) ) ,
            array( 'jquery'),
            $this->options['plugin_version'],
            true
        );
		wp_localize_script('front-mangopay', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
		/*init language translation for javascript*/
		$translation_array = $this->translate_error_cards_registration();
		wp_localize_script( 'front-mangopay', 'translated_data', $translation_array );
		
        /* overload the wc vendors script with the same name */
        wp_enqueue_script(
            'wc-country-select',
            plugins_url( 'js/country-select.js', dirname( __FILE__ ) ) ,
            array( 'jquery'),
            $this->options['plugin_version'],
            true
        );
        /* overload the wc vendors PRO script with the same name */
        wp_enqueue_script(
            'wcv-country-select',
            plugins_url( 'js/country-select.js', dirname( __FILE__ ) ) ,
            array( 'jquery'),
            $this->options['plugin_version'],
            true
        );
    }
	
	
	/**
	 * translate error messages for javascript
	 * @return array
	 */
	public function translate_error_cards_registration(){
		// Localize the script with new data
		$translation_array = array(
			'base_message' => __("Error occured while registering the card: ",'mangopay'),
			'009999' => __( 'Browser does not support making cross-origin Ajax calls', 'mangopay' ),
			'001596' => __( "An HTTP request was blocked by the User's computer (probably due to an antivirus)", 'mangopay' ),
			'001597' => __( "An HTTP request failed", 'mangopay' ),
			'001599' => __( 'Token processing error', 'mangopay' ),
			'101699' => __( "CardRegistration should return a valid JSON response", 'mangopay' ),
			'105204' => __( "CVV_FORMAT_ERROR", 'mangopay' ),
			'105203' => __( "PAST_EXPIRY_DATE_ERROR", 'mangopay' ),
			'105202' => __( "CARD_NUMBER_FORMAT_ERROR", 'mangopay' ),
			'You can not create a declaration because you already have a declaration in progress' => __( "You can not create a declaration because you already have a declaration in progress", 'mangopay' ),
		);
		$translation_array = apply_filters( 'mangopay_translate_error_cards_registration', $translation_array );
		return $translation_array;
	}

	/**
	 * Load plugin configuration and default values from config.inc.php
	 *
	 */
	private function load_config() {
		$this->defaults				= mangopayWCConfig::$defaults;
		$this->allowed_currencies	= apply_filters( 'mp_allowed_currencies', mangopayWCConfig::$allowed_currencies );
		$this->account_types		= apply_filters( 'mp_account_types', mangopayWCConfig::$account_types );
	}

	/**
     * Add new register fields for WooCommerce registration.
     * We need this to enforce mandatory/required fields that we need for createMangoUser
     * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
     * This is a WC 'woocommerce_register_form_start' action hook - must be a public method
     *
     * @return string Register fields HTML.
     *
     */
    public function wooc_extra_register_fields() {
		
        wp_enqueue_script('jquery-ui-datepicker');
        $this->localize_datepicker();
       
        //wp_register_style('jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css');
        wp_register_style(
            'jquery-ui',
            plugins_url( '/css/jquery-ui.css', dirname( __FILE__ ) ),
            array(),
            '1.8'
        );
        wp_enqueue_style( 'jquery-ui' );
       
        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        $assets_path          = str_replace( array( 'http:', 'https:' ), '', WC()->plugin_url() ) . '/assets/';
        $frontend_script_path = $assets_path . 'js/frontend/';
       
        wp_enqueue_script(
            'wc-country-select2',
             $assets_path.'js/select2/select2' . $suffix . '.js',
            array( 'jquery'),
            $this->options['plugin_version']
        ); 
       
        wp_enqueue_script(
            'wc-user-type',
            plugins_url( '/js/front-type-user.js', dirname( __FILE__ ) ),
            array( 'jquery')
        );
        wp_enqueue_script(
            'wc-state-selector',
            plugins_url( '/js/front-state-selector.js', dirname( __FILE__ ) ),
            array( 'jquery')
        );       
       
        /** Initialize our front-end script with third-party plugin independent data **/
        $vendor_role = apply_filters( 'mangopay_vendor_role', 'vendor' );
        $translate_array = array(
            'vendor_role' => $vendor_role
        );
        wp_localize_script( 'wc-user-type', 'translate_object', $translate_array );

        /**
         * For country drop-down
         * @see: https://wordpress.org/support/topic/woocommerce-country-registration-field-in-my-account-page-not-working
         *
         */
        $countries_obj = new WC_Countries();
        $countries = $countries_obj->__get('countries');
		
		$wp_user_id = get_current_user_id();
		
		if( !is_wc_endpoint_url( 'edit-account' ) ) : 
			
            $user_first_name = '';
            if ( ! empty( $_POST['billing_first_name'] ) ){
                $user_first_name = esc_attr__( $_POST['billing_first_name'] );
            }else if(get_user_meta($wp_user_id,'billing_first_name')){
                $user_first_name = get_user_meta($wp_user_id,'billing_first_name',true);
            }
            ?>
            <?php do_action( 'bp_billing_first_name_errors' ); ?>
            <p class="form-row form-row-first">
                <label for="reg_billing_first_name"><?php _e( 'First name', 'woocommerce' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="billing_first_name" id="reg_billing_first_name" value="<?php echo $user_first_name; ?>" />
            </p>
       
            <?php
            $user_last_name = '';
            if ( ! empty( $_POST['billing_last_name'] ) ){
                $user_last_name = esc_attr__( $_POST['billing_last_name'] );
            }else if(get_user_meta($wp_user_id,'billing_last_name')){
                $user_last_name = get_user_meta($wp_user_id,'billing_last_name',true);
            }
            ?>
            <?php do_action( 'bp_billing_last_name_errors' ); ?>
            <p class="form-row form-row-last">
                <label for="reg_billing_last_name"><?php _e( 'Last name', 'woocommerce' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="billing_last_name" id="reg_billing_last_name" value="<?php echo $user_last_name; ?>" />
            </p>
           
        <?php endif; ?>
           
        <div class="clear"></div>
        <?php
		$allfields = WC()->checkout->checkout_fields;
		if($this->show_optional_user_fields($wp_user_id)):
			
			$is_extrafelds_mandatory = $this->do_user_need_extrafields($wp_user_id);
			$required_text = '';
			if($is_extrafelds_mandatory):
				$required_text =  ' <span class="required">*</span>';
			endif;
		
		//creation utilisateur front		
        $value = '';
        if( ! empty( $_POST['user_birthday'] ) ) :
            $value = esc_attr( $_POST['user_birthday'] );
        endif;
        if( $wp_user_id ):
            $value = date_i18n( $this->supported_format( get_option( 'date_format' ) ), strtotime( get_user_meta( $wp_user_id, 'user_birthday', true ) ) );
        endif;
        ?>
        <?php do_action( 'bp_user_birthday_errors' ); ?>
        <p class="form-row form-row-wide">
            <label for="reg_user_birthday">
				<?php _e( 'Birthday', 'mangopay' ); ?>
				<?php echo $required_text; ?>
			</label>
            <input type="text" class="input-text calendar" name="user_birthday" id="reg_user_birthday" autocomplete="off" value="<?php echo $value; ?>" />
        </p>
               
        <?php
        $cur_value = '';
        if( ! empty( $_POST['user_nationality'] ) ):
            $cur_value = esc_attr( $_POST['user_nationality'] );
        endif;
        if( $wp_user_id ):
            $cur_value = get_user_meta( $wp_user_id, 'user_nationality', true );
        endif;
        ?>
        <?php
        /* Nationality */
        do_action( 'bp_user_nationality_errors' );
        
        $for_user_nationality = $allfields['billing']['billing_country'];
        $for_user_nationality['label'] = __( 'Nationality', 'mangopay' );
        $for_user_nationality['autocomplete'] = 'user_nationality';       
        //woocommerce_form_field( 'user_nationality', $for_user_nationality, $cur_value);
         ?>
        <p class="form-row form-row-wide">
            <label for="reg_user_nationality">
				<?php _e( 'Nationality', 'mangopay' ); ?>
				<?php echo $required_text; ?>
			</label>
            <select class="nationality_select" name="user_nationality" id="reg_user_nationality">
                <option value=""><?php _e( 'Select a country...', 'mangopay' ); ?></option>
            <?php foreach ($countries as $key => $value): $selected=($key==$cur_value?'selected="selected"':''); ?>
                <option value="<?php echo $key?>" <?php echo $selected?>><?php echo $value?></option>
            <?php endforeach; ?>
            </select>
        </p>
		
		<?php endif; //end if optional ?>
		
                     
        <?php       
        /* Country */
        do_action( 'bp_billing_country_errors' );
        woocommerce_form_field( 'billing_country', $allfields['billing']['billing_country'], WC()->checkout->get_value( 'billing_country' ) );
       
        /* State */
        do_action( 'bp_billing_state_errors' );
        woocommerce_form_field( 'billing_state', $allfields['billing']['billing_state'], WC()->checkout->get_value( 'billing_state' ) );
        
        //for traduction purposes we get the traductions sur the label "STATE"
        $wc_state_o = new WC_Countries();
        $locale = $wc_state_o->get_country_locale();
        ?>
        <?php if( !empty( $locale['CA'] ) ) : ?>
        	<?php 
        		$wc_country_state_CA_label = ( !empty( $locale['CA']['state']['label'] ) ) ? 
        			$locale['CA']['state']['label'] : 'Province'; 
        	?>
        	<input type="hidden" id="wc_country_state_CA" value="<?php echo $wc_country_state_CA_label; ?>" />
        <?php endif; ?>
        <?php if( !empty( $locale['US'] ) ) : ?>
        	<?php 
        		$wc_country_state_US_label = ( !empty( $locale['US']['state']['label'] ) ) ? 
        			$locale['US']['state']['label'] : 'State';
        	?>
        	<input type="hidden" id="wc_country_state_US" value="<?php echo $wc_country_state_US_label; ?>" />
        <?php endif; ?>
        <input type="hidden" id="wc_country_state_default" value="<?php echo __( 'State / County', 'woocommerce' ); ?>" />
        <?php     
        /** data for fields **/
        $user_mp_status_form = get_user_meta( $wp_user_id, 'user_mp_status', true );
        $user_business_type_form = get_user_meta( $wp_user_id, 'user_business_type', true );
        ?>
         
        <?php do_action( 'bp_user_mp_status_errors' ); ?>
		
		<?php
		//if user is vendor and didn't have 
		$display_form = "display:none;";
		$business_status_form = "display:none;";
		$disabled_status_field = "";
		$is_vendor = false;
		if( is_user_logged_in() && $this->is_vendor($wp_user_id)):
			$is_vendor = true;
			$display_form = "";
			if($user_mp_status_form):
				$disabled_status_field = "disabled";
			endif;
			if($user_mp_status_form == "business"):
				$business_status_form = "";
			endif;
		endif;
		?>
		<!-- user_mp_status form -->
        <p class="form-row form-row-wide" id="block_user_mp_status" style="<?php echo $display_form; ?>">
            <label for="reg_user_mp_status"><?php _e( 'User status', 'mangopay' ); ?> <span class="required">*</span></label>
            <input type="hidden" id="actual_user_connected" value="<?php echo $wp_user_id; ?>" />
            <input type="hidden" id="actual_user_mp_status" value="<?php echo $user_mp_status_form; ?>" />
            <input type="hidden" id="actual_default_buyer_status" value="<?php echo $this->options['default_buyer_status']; ?>" />
            <input type="hidden" id="actual_default_vendor_status" value="<?php echo $this->options['default_vendor_status']; ?>" />
            <select class="mp_status_select" 
					name="user_mp_status" 
					id="reg_user_mp_status" 
					data-changesomething="1"
					<?php echo $disabled_status_field; ?>
					>
                <option value=''><?php _e( 'Select option...', 'mangopay' ); ?></option>
                <option value='individual' <?php selected( 'individual', $user_mp_status_form ); ?>>
					<?php _e( 'Individual', 'mangopay' ); ?>
				</option>
                <option value='business' <?php selected( 'business', $user_mp_status_form ); ?>>
					<?php _e( 'Business user', 'mangopay' ); ?>
				</option>
            </select>
        </p>
        <?php do_action( 'bp_user_business_type_errors' ); ?>
        <p class="form-row form-row-wide hide_business_type" 
		   id="block_user_business_type" 
		   style="<?php echo $business_status_form; ?>">
            <label for="reg_user_business_type"><?php _e( 'Business type', 'mangopay' ); ?> <span class="required">*</span></label>
            <input type="hidden" id="actual_default_business_type" value="<?php echo $this->options['default_business_type']; ?>" /> 
            <select class="mp_btype_select" name="user_business_type" id="reg_user_business_type">
                <option value=''><?php _e( 'Select option...', 'mangopay' ); ?></option>
                <option value='organisation' <?php selected( 'organisation', $user_business_type_form ); ?>><?php _e( 'Organisation', 'mangopay' ); ?></option>
                <option value='business' <?php selected( 'business', $user_business_type_form ); ?>><?php _e( 'Business', 'mangopay' ); ?></option>
                <option value='soletrader' <?php selected( 'soletrader', $user_business_type_form ); ?>><?php _e( 'Soletrader', 'mangopay' ); ?></option>
            </select>
        </p>	      
        <script>
        (function($) {
            $(document).ready(function() {
				
				if(typeof datepickerL10n != "undefined" && datepickerL10n!=""){
					$('input.calendar').datepicker(datepickerL10n);
				}
                if( 'business'==$('#reg_user_mp_status').val() ){
                    $('.hide_business_type').show();
				}
            });

            $('#reg_user_mp_status').on('change',function(e){
                if( 'business'==$('#reg_user_mp_status').val() ) {
                    $('.hide_business_type').show();
                } else {
                    $('.hide_business_type').hide();
                }
            });
        })( jQuery );
        </script>
        <?php
    }
	
    public function wooc_account_details_required( $required ) {
        
        $required['billing_country']    = __( 'Country of residence', 'mangopay' );
		
		if($this->do_user_need_extrafields()){
			$required['user_birthday']        = __( 'Birthday', 'mangopay' );
			//$required['user_nationality']    = __( 'Nationality', 'mangopay' );
		}
		
        return $required;
    }
	
	/**
	 * Rules to check if we need to validate the extrafields
	 * it's not dependent on if we show them or not (function show_optional_user_fields)
	 * @return boolean
	 */
	public function do_user_need_extrafields($user_id = false){
		$need_fields = false;
		
		if($user_id==false){
			$user_id = get_current_user_id();
		}
		
		if(is_user_logged_in()){			
			//for vendors only
			$user = get_user_by('ID', $user_id);
						
			if(!empty($user->roles) && in_array('vendor',$user->roles)){
				
				//birthday and nationality mandatory for owner, individual or legal
				$need_fields = true;				
				
//				$user_mp_status	= get_the_author_meta( 'user_mp_status', $user->ID );
//				$user_business_type	= get_the_author_meta( 'user_business_type', $user->ID );
				//NATURAL
//				echo "<pre>", print_r("user_mp_status", 1), "</pre>";
//				echo "<pre>", print_r($user_mp_status, 1), "</pre>";
//				echo "<pre>", print_r("----user_business_type", 1), "</pre>";
//				echo "<pre>", print_r($user_business_type, 1), "</pre>";
//				die();
//				if(
//					!empty($user_mp_status) 
//					&& ($user_mp_status == 'individual'
//					|| 
//				){
//					$need_fields = true;
//				}else{
//					//is vendor, we fields are mandatory
//					$need_fields = false;
//				}
			}else{
				//not vendor in roles no need
				$need_fields = false;
			}			
		}else{
			//not logged, do not need
			$need_fields = false;
		}
		
		return apply_filters('mangopay_user_need_extrafields',$need_fields,$user_id);
	}
	
	
	/**
	 * do we show fields. it's not depedent on the validation -> check do_user_need_extrafields
	 * @return boolean
	 */
	public function show_optional_user_fields($user_id=false){
		
		$show = false;
		
		if(!$user_id){
			$user_id = get_current_user_id();
		}
		
		//get woocommerce options
		$wc_settings = get_option( 'woocommerce_mangopay_settings' );
		
		//if it's set check we are a go
		if(!empty($wc_settings['show_optional_user_fields']) && $wc_settings['show_optional_user_fields'] == "yes" ){
			$show = true;
		}
		
		//force show if user need fields
		if($this->do_user_need_extrafields($user_id)){
			$show = true;
		}
		
		return apply_filters('mangopay_show_optional_user_fields',$show,$wc_settings,$user_id);
	}	
           
	/**
     * Validate the extra register fields.
     * We need this to enforce mandatory/required fields that we need for createMangoUser
     * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
     * This is a WC 'woocommerce_register_post' action hook - must be a public method
     *
     * @param  string $username          Current username.
     * @param  string $email             Current email.
     * @param  object $validation_errors WP_Error object.
     *
     * @return void
     */
    public function wooc_validate_extra_register_fields_user( $validation_errors,$dontknow, $user) {
   
		$data_post = $_POST;

		$list_post_keys = array(
			//'user_birthday'=>'date', //24/10/2022 FIELD NOT MANDATORY ANYMORE
			//'user_nationality'=>'nationality',//24/10/2022 FIELD NOT MANDATORY ANYMORE
			'billing_country'=>'country',
			'user_mp_status'=>'status',
			'user_business_type'=>'businesstype',
		);
		
		//needed for loggedin users of vendor type
		if($this->do_user_need_extrafields()){
			$list_post_keys['user_birthday'] = 'date';
			$list_post_keys['user_nationality'] = 'nationality';
		}	

		foreach ($list_post_keys as $key=>$value) {
			$function_name = 'validate_'.$value;
			$data_to_send = array();
			$data_to_send['data_post'] = $data_post;
			$data_to_send['key_field'] = $key;
			$data_to_send['wp_error'] = $validation_errors;
			$data_to_send['main_options'] = $this->options;
			$data_to_send['caller_func'] = 'wooc_validate_extra_register_fields_user';		
			$this->mangopayWCValidation->$function_name($data_to_send);
		}
   
    }
 
    public function bp_save_extra_fields(){
        $user = get_user_by( 'email', $_POST['signup_email'] );       
        if($user){
            $this->wooc_save_extra_register_fields( $user->ID );
        }
    }
   
    /**
     * Validate the extra register fields.
     * We need this to enforce mandatory/required fields that we need for createMangoUser
     * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
     * This is a WC 'woocommerce_register_post' action hook - must be a public method
     *
     * @return void
     */
    public function bp_validate_extra_fields(){

        $data_post = $_POST;

        $list_post_keys = array(
			'billing_first_name'=>'single',
			'billing_last_name'=>'single',
			'billing_country'=>'country',
			'user_mp_status'=>'status',
			'user_business_type'=>'businesstype',
        );
		
		//needed for loggedin users of vendor type
		if($this->do_user_need_extrafields()){
			$list_post_keys['user_birthday'] = 'date';
			$list_post_keys['user_nationality'] = 'nationality';
		}		

        foreach ($list_post_keys as $key=>$value) {
			$function_name = 'validate_'.$value;
			$data_to_send = array();
			$data_to_send['data_post'] = $data_post;
			$data_to_send['key_field'] = $key;
			$data_to_send['bp_error'] =  buddypress();
			$data_to_send['main_options'] = $this->options;
			$data_to_send['double_test'] = array('user_birthday'=>1);
			$data_to_send['caller_func'] = 'wooc_validate_extra_register_fields';
			$this->mangopayWCValidation->$function_name($data_to_send);
        }
   
    }
       
    /**
     * Validate the extra register fields.
     * We need this to enforce mandatory/required fields that we need for createMangoUser
     * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
     * This is a WC 'woocommerce_register_post' action hook - must be a public method
     *
     * @return void
     */
    public function bp_ajax_validate_extra_fields(){

        $data_post = $_POST;

        $list_post_keys = array(
          'billing_first_name'=>'single',
          'billing_last_name'=>'single',
          'billing_country'=>'country',
          'user_mp_status'=>'status',
          'user_business_type'=>'businesstype',
        );
		
		//needed for loggedin users of vendor type
		if($this->do_user_need_extrafields()){
			$list_post_keys['user_birthday'] = 'date';
			$list_post_keys['user_nationality'] = 'nationality';
		}		

        foreach ($list_post_keys as $key=>$value) {
          $function_name = 'validate_'.$value;
          $data_to_send = array();
          $data_to_send['data_post'] = $data_post;
          $data_to_send['key_field'] = $key;
          $data_to_send['main_options'] = $this->options;
          $data_to_send['double_test'] = array('user_birthday'=>1);
          $data_to_send['caller_func'] = 'wooc_validate_extra_register_fields';
          $this->mangopayWCValidation->$function_name($data_to_send);
        }
   
    }
   
    public function wooc_validate_extra_register_fields_userfront(&$errors, &$user) {

        $data_post = $_POST;
		
		if(!empty($user->ID)){
			$data_post['user_id'] = $user->ID;
		}

        $list_post_keys = array(
          'billing_country'=>'country',
          'user_mp_status'=>'status',
          'user_business_type'=>'businesstype',
        );
		
		//needed for loggedin users of vendor type
		if($this->do_user_need_extrafields($user->ID)){
			$list_post_keys['user_birthday'] = 'date';
			$list_post_keys['user_nationality'] = 'nationality';
		}
		

		
        foreach ($list_post_keys as $key=>$value) {
			$function_name = 'validate_'.$value;
			$data_to_send = array();
			$data_to_send['data_post'] = $data_post;
			$data_to_send['key_field'] = $key;
			$data_to_send['main_options'] = $this->options;
			$data_to_send['caller_func'] = 'wooc_validate_extra_register_fields_userfront';
			$this->mangopayWCValidation->$function_name($data_to_send);
        }
   
    }
 
 
    /**
     * Validate the extra register fields.
     * We need this to enforce mandatory/required fields that we need for createMangoUser
     * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
     * This is a WC 'woocommerce_register_post' action hook - must be a public method
     *
     * @param  string $username          Current username.
     * @param  string $email             Current email.
     * @param  object $validation_errors WP_Error object.
     *
     * @return void
     */
    public function wooc_validate_extra_register_fields( $username, $email, $validation_errors ) {
       
        $data_post = $_POST;
		
        $list_post_keys = array(
          'billing_first_name'=>'single',
          'billing_last_name'=>'single',
          'billing_country'=>'country',
          'user_mp_status'=>'status',
          'user_business_type'=>'businesstype',
        );
		
		//needed for loggedin users of vendor type
		if($this->do_user_need_extrafields()){
			$list_post_keys['user_birthday'] = 'date';
			$list_post_keys['user_nationality'] = 'nationality';
		}

        foreach ($list_post_keys as $key=>$value) {
          $function_name = 'validate_'.$value;
          $data_to_send = array();
          $data_to_send['data_post'] = $data_post;
          $data_to_send['key_field'] = $key;
          $data_to_send['wp_error'] = $validation_errors;
          $data_to_send['main_options'] = $this->options;
          $data_to_send['double_test'] = array('user_birthday'=>1);
          $data_to_send['caller_func'] = 'wooc_validate_extra_register_fields';
          $this->mangopayWCValidation->$function_name($data_to_send);
        }   
    }
	/**
     * Validate the extra register fields.
     * We need this to enforce mandatory/required fields that we need for createMangoUser
     * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
     * This is a WC 'woocommerce_checkout_process' action hook - must be a public method
     *
     * @return void
     */
    public function wooc_validate_extra_register_fields_checkout() {
        $data_post = $_POST;
        $list_post_keys = array(
          //'billing_first_name'=>'single',
          //'billing_last_name'=>'single',
          'billing_country'=>'country',
          //'user_mp_status'=>'status',
          'user_business_type'=>'businesstype',
          'billing_postcode'=>'postalcode'
        );
        
        if('either' == $this->options['default_buyer_status']){
			if(!get_user_meta( get_current_user_id(), 'user_mp_status', true )){
				$list_post_keys['user_mp_status'] = 'status';
			}
		}		
		
		//needed for loggedin users of vendor type
		if($this->do_user_need_extrafields()){
			$list_post_keys['user_birthday'] = 'date';
			$list_post_keys['user_nationality'] = 'nationality';
		}
		
        foreach ($list_post_keys as $key=>$value) {
          $function_name = 'validate_'.$value;
          $data_to_send = array();
          $data_to_send['data_post'] = $data_post;
          $data_to_send['key_field'] = $key;
          $data_to_send['main_options'] = $this->options;
          $data_to_send['caller_func'] = 'wooc_validate_extra_register_fields_checkout';
          $this->mangopayWCValidation->$function_name($data_to_send);
        }
   
    }
           
    /**
     * Save the extra register fields.
     * We need this to enforce mandatory/required fields that we need for createMangoUser
     * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
     * This is a WC 'woocommerce_created_customer' action hook - must be a public method
     *
     * @param  int  $customer_id Current customer ID.
     *
     * @return void
     */
    public function wooc_save_extra_register_fields( $customer_id ) {
               
        if ( isset( $_POST['billing_first_name'] ) ) {
            // WordPress default first name field.
            update_user_meta( $customer_id, 'first_name', sanitize_text_field( $_POST['billing_first_name'] ) );
   
            // WooCommerce billing first name.
            update_user_meta( $customer_id, 'billing_first_name', sanitize_text_field( $_POST['billing_first_name'] ) );
        }
   
        if ( isset( $_POST['billing_last_name'] ) ) {
            // WordPress default last name field.
            update_user_meta( $customer_id, 'last_name', sanitize_text_field( $_POST['billing_last_name'] ) );
   
            // WooCommerce billing last name.
            update_user_meta( $customer_id, 'billing_last_name', sanitize_text_field( $_POST['billing_last_name'] ) );
        }
   
        if ( isset( $_POST['user_birthday'] ) ) {
            // New custom user meta field
            update_user_meta(
                $customer_id,
                'user_birthday',
                $this->convertDate( $_POST['user_birthday'] )
            );
        }
       
        if ( isset( $_POST['user_nationality'] ) ) {
            // New custom user meta field
            update_user_meta( $customer_id, 'user_nationality', sanitize_text_field( $_POST['user_nationality'] ) );
        }
       
        if ( isset( $_POST['billing_country'] ) ) {
            // WooCommerce billing country.
            update_user_meta( $customer_id, 'billing_country', sanitize_text_field( $_POST['billing_country'] ) );
        }
       
        if ( isset( $_POST['billing_state'] ) ) {
            // WooCommerce billing state.
            update_user_meta( $customer_id, 'billing_state', sanitize_text_field( $_POST['billing_state'] ) );
        }
       		
        if ( isset( $_POST['user_mp_status'] ) ) {
			// New custom user meta field
			update_user_meta( $customer_id, 'user_mp_status', sanitize_text_field( $_POST['user_mp_status'] ) );
        }
       
        if ( isset( $_POST['user_business_type'] ) ) {
            // New custom user meta field
            update_user_meta( $customer_id, 'user_business_type', sanitize_text_field( $_POST['user_business_type'] ) );
        }
       
        $mp_user_id = $this->mp->set_mp_user( $customer_id );
        $this->mp->set_mp_wallet( $mp_user_id );
       
        /** Update MP user account **/
        $this->on_shop_settings_saved( $customer_id );
    }
   
   
    /**
     * Add the required fields to the checkout form
     * @see: https://docs.woothemes.com/document/tutorial-customising-checkout-fields-using-actions-and-filters/
     *
     * @param array $fields
     * @return array $fields
     */
    public function custom_override_checkout_fields( $fields ) {
   
        /** We store a different mp_user_id for production and sandbox environments **/
        $umeta_key = 'mp_user_id';
        if( !$this->mp->is_production() )
            $umeta_key .= '_sandbox';

        //----------------------------------------------------------------------------------------------
        ////possibility - 1, the conf : default_buyer_status is on "individuals" -> no need to had the field
        //do nothing

        //possibility - 2, the conf : default_buyer_status is on "either" -> add the field "user mp status" AND if "business" selected "business type"
        //if user does NOT have USER MP STATUS we ask
        if('either' == $this->options['default_buyer_status']):
            if(!get_user_meta( get_current_user_id(), 'user_mp_status', true )):
                $fields = $this->add_usermpstatus_field($fields);
            else:
                $fields = $this->add_usermpstatus_hidden_field($fields);
            endif;
        endif;

        //possibility - 3, the conf : default_buyer_status is on "business" or either -> add the field "business type"
        //this field will be hidden by javascript, it's dependent of "user mp status" field

        if('business' == $this->options['default_buyer_status'] || 'businesses' == $this->options['default_buyer_status'] || 'either' == $this->options['default_buyer_status']):
          //and user does not have it AND the user is business type
          if(!get_user_meta( get_current_user_id(), 'user_mp_status', true ) == "business"
              && !get_user_meta( get_current_user_id(), 'user_business_type', true )):
            $fields = $this->add_userbusinesstype_field($fields);
          endif;
        endif;

		if($this->show_optional_user_fields()){
			//if user has no nationality ask
			if( !get_user_meta( get_current_user_id(), 'user_nationality', true ) ) {
			  $fields = $this->add_usernationality_field($fields);
			}
				//if user has no birthday ask
			if( !get_user_meta( get_current_user_id(), 'user_birthday', true ) ) {
			  $fields = $this->add_userbirthday_field($fields);
			}
		}

        return $fields;
    }
 
    public function add_userbirthday_field($fields){
      wp_enqueue_script('jquery-ui-datepicker');           
      $this->localize_datepicker();

      //wp_register_style('jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css');
      wp_register_style(
        'jquery-ui',
        plugins_url( '/css/jquery-ui.css', dirname( __FILE__ ) ),
        false, '1.8'
      );
      wp_enqueue_style( 'jquery-ui' );
	  
	  $required = $this->do_user_need_extrafields();
	  
      $fields['billing']['user_birthday'] = array(
        'label'			=> __( 'Birthday', 'mangopay' ),
        'required'		=> $required,
        'class'			=> array( 'calendar' )
      );
      return $fields;
    }
 
    public function add_usernationality_field($fields){
      $countries_obj = new WC_Countries();
      $countries = $countries_obj->__get('countries');
      //array_unshift( $countries, __( 'Select a country...', 'mangopay' ) );
      $countries[NULL] = __( 'Select a country...', 'mangopay' );
	  
	  $required = $this->do_user_need_extrafields();
	  
      $fields['billing']['user_nationality'] = array(
        'type'        => 'select',
        'label'        => __( 'Nationality', 'mangopay' ),
        'options'    => $countries,
        'required'    => $required
      );
      return $fields;
    }
 
    public function add_userbusinesstype_field ($fields){
      $fields['billing']['user_business_type'] = array(
        'type'        => 'select',
        'label'        => __( 'Business type', 'mangopay' ),
        'options'    => array(
            ''                => __( 'Select option...', 'mangopay' ),
            'organisation'    => __( 'Organisation', 'mangopay' ),
            'business'        => __( 'Business', 'mangopay' ),
            'soletrader'        => __( 'Soletrader', 'mangopay' )
        ),
        'required'    => true,
        'class'        => array( 'hide_business_type' )
      );
      return $fields;
    }
 
    private function add_usermpstatus_field($fields){
     $fields['billing']['user_mp_status'] = array(
        'type'        => 'select',
        'label'        => __( 'User status', 'mangopay' ),
        'options'    => array(
          ''                => __( 'Select option...', 'mangopay' ),
          'individual'    => __( 'Individual', 'mangopay' ),
          'business'        => __( 'Business user', 'mangopay' )
        ),
        'required'    => true,
      );
     return $fields;
    }
    
    private function add_usermpstatus_hidden_field($fields){
        if(get_user_meta( get_current_user_id(), 'user_mp_status', true )){
            $fields['billing']['user_mp_status'] = array(
                'type' => 'text',
                'value' => get_user_meta( get_current_user_id(), 'user_mp_status', true ),
                'class' => array('hidden mp_hidden')
              );
        }
        return $fields;
    }
   
    /**
     * To enable the jQuery-ui calendar for the birthday field on the checkout form
     */
    public function after_checkout_fields() {

        /** If the user is already logged-in no birthday field is present **/
        if(
            is_user_logged_in() &&
            get_user_meta( get_current_user_id(), 'user_birthday', true ) &&
            get_user_meta( get_current_user_id(), 'user_mp_status', true )
        )
            return;

        ?>
        <script>
        (function($) {
            $(document).ready(function() {
                if( 'business'==$('#user_mp_status').val() ) {
                    $('.hide_business_type').show();
                } else {
                    <?php if( 'businesses' != $this->options['default_buyer_status'] || 'either' != $this->options['default_business_type'] ) : ?>
                    $('.hide_business_type').hide();
                    $('#user_business_type').val('organisation');
                    <?php endif; ?>
                }
            });
            $('#user_mp_status').on('change',function(e){
                if( 'business'==$('#user_mp_status').val() ) {
                    $('.hide_business_type').show();
                    $('#user_business_type').val('');
                } else {
                    $('.hide_business_type').hide();
                    $('#user_business_type').val('organisation');
                }
            });
        })( jQuery );
        </script>
        <?php

        if( !wp_script_is( 'jquery-ui-datepicker', 'enqueued' ) )
            return;

        ?>
        <script>
        (function($) {
            $(document).ready(function() {
                $('input.calendar, #user_birthday').datepicker(datepickerL10n);
            });
        })( jQuery );
        </script>
        <?php
    }
   
    /**
     * Fires up when user role has been changed,
     * ie. when pending_vendor becomes vendor
     * This is a WP 'set_user_role' action hook - must be a public method
     *
     * @param int $user_id
     * @param string $role
     * @param array $old_roles
     *
     */
    public function on_set_user_role( $wp_user_id, $role, $old_roles ) {
        $vendor_role = apply_filters( 'mangopay_vendor_role', 'vendor' );
        if( $vendor_role != $role || array( $vendor_role) == $old_roles ) {
            return;
        }
       
        /** This will create a BUSINESS MP account for that user if they did not have one **/
        $this->mp->set_mp_user( $wp_user_id, 'BUSINESS' );
    }
   
    /**
     * Fires up when user profile has been registered,
     * ie. when new user is created in the WP back-office
     * This is a WP 'user_register' action hook - must be a public method
     *
     * @param int $wp_user_id
     */
    public function on_user_register( $wp_user_id ) {

        /** Don't register the new user as MP User if he's pending vendor **/
        $wp_userdata = get_userdata( $wp_user_id );
        if(
                isset( $wp_userdata->roles['pending_vendor'] ) ||
                ( is_array($wp_userdata->roles) && in_array( 'pending_vendor', $wp_userdata->roles , true ))
        )
            return false;

        $mp_user_id = $this->mp->set_mp_user( $wp_user_id );
    }
   
    /**
     * Fires up when WC shop settings have been saved
     * This is a WV 'wcvendors_shop_settings_saved' action hook - must be a public method
     *
     * @param int $wp_user_id
     *
     * Shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function on_shop_settings_saved( $wp_user_id ) {

        /* *
         var_dump( $wp_userdata ); exit; //Debug
         /* gives:
         object(WP_User)#431 (7) { ["data"]=> object(stdClass)#430 (10) {
             ["ID"]=> string(2) "49"
             ["user_login"]=> string(8) "y.dubois"
             ["user_pass"]=> string(34) "(hashed pw)"
             ["user_nicename"]=> string(8) "y-dubois"
             ["user_email"]=> string(19) "email@address.com"
             ["user_url"]=> string(0) ""
             ["user_registered"]=> string(19) "2016-02-26 10:23:07"
             ["user_activation_key"]=> string(0) ""
             ["user_status"]=> string(1) "0"
             ["display_name"]=> string(8) "y.dubois"
         }
         ["ID"]=> int(49)
         ["caps"]=> array(1) { ["customer"]=> bool(true) }
         ["cap_key"]=> string(15) "wp_capabilities"
         ["roles"]=> array(1) { [0]=> string(8) "customer" }
         ["allcaps"]=> array(2) { ["read"]=> bool(true) ["customer"]=> bool(true) }
         ["filter"]=> NULL }
        */
				
		//get current
        $usermeta['user_birthday']       = get_user_meta( $wp_user_id, 'user_birthday', true );
        $usermeta['user_nationality']    = get_user_meta( $wp_user_id, 'user_nationality', true );
		
		$date_formated = false;
		/* todo i dont think it's used, cause it's tested before */
		if($this->do_user_need_extrafields($wp_user_id) && !is_admin()){
			
			$return_error = false;
			
			//if set in post ovveride current user meta
			if(!empty($_POST['user_birthday']) ){
				$date_formated = $this->convertDate( $_POST['user_birthday'] );
								
				if(strtotime($date_formated)< strtotime("today")){
					$usermeta['user_birthday'] = $this->convertDate( $_POST['user_birthday'] );
				}else{
					$field_error = '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
						__( 'Birthday', 'mangopay' ) . ' ' .
						__( 'needs to be valid!', 'mangopay' );
					wc_add_notice( $field_error, 'error' );
					$return_error = true;
				}
			}
			
			//final check, if not here and user need fields cut save
			if(!$usermeta['user_birthday']){
				$field_error = '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
					__( 'Birthday', 'mangopay' ) . ' ' .
					__( 'is required!', 'mangopay' );
				wc_add_notice( $field_error, 'error' );
				$return_error = true;
			}
			
			//if set in post ovveride current user meta
			if(!empty($_POST['user_nationality'])){
				$usermeta['user_nationality'] = $_POST['user_nationality'];
			}			
			if(!$usermeta['user_nationality']){
				$field_error = '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
					__( 'Nationality', 'mangopay' ) . ' ' .
					__( 'is required!', 'mangopay' );
				wc_add_notice( $field_error, 'error' );
				$return_error = true;
			}
			
			if($return_error){
				return false;
			}
		}
		
        $wp_userdata = get_userdata( $wp_user_id );
        $usermeta['user_email'] = $wp_userdata->user_email;
		$usermeta['user_roles'] = $wp_userdata->roles;
		
        /** For first and last name, we take the billing info if available **/
        $usermeta['first_name']            = get_user_meta( $wp_user_id, 'first_name', true );
        if( isset( $_POST['first_name'] ) && $_POST['first_name'] ){
            $usermeta['first_name']        = $_POST['first_name'];
		}
        if( $first_name = get_user_meta( $wp_user_id, 'billing_first_name', true ) ){
            $usermeta['first_name']        = $first_name;
		}
		
        $usermeta['last_name']            = get_user_meta( $wp_user_id, 'last_name', true );
        if( isset( $_POST['last_name'] ) && $_POST['last_name'] ){
            $usermeta['last_name']        = $_POST['last_name'];
		}
		
        if( $last_name = get_user_meta( $wp_user_id, 'billing_last_name', true ) ){
            $usermeta['last_name']        = $last_name;
		}
       
        $usermeta['address_1']            = get_user_meta( $wp_user_id, 'billing_address_1', true );
        $usermeta['city']                = get_user_meta( $wp_user_id, 'billing_city', true );
        $usermeta['postal_code']        = get_user_meta( $wp_user_id, 'billing_postcode', true );
        $usermeta['pv_shop_name']        = get_user_meta( $wp_user_id, 'pv_shop_name', true );
        $usermeta['billing_country']    = get_user_meta( $wp_user_id, 'billing_country', true );
        if( isset( $_POST['billing_state'] ) ){
          $usermeta['billing_state']    = get_user_meta( $wp_user_id, 'billing_state', true );
		}
 	       
        $usermeta['user_mp_status'] = get_user_meta( $wp_user_id, 'user_mp_status', true );
        $usermeta['user_business_type'] = get_user_meta( $wp_user_id, 'user_business_type', true );
       
		//TermsAndConditionsAccepted true/false
		//test the current state, cannot go back once approuved
		$termsconditions = get_user_meta($wp_user_id, 'termsconditions', true );
		if(!$termsconditions){
			if(!empty($_POST['vendor_terms_and_conditions_accepted'])
				&& (
					$_POST['vendor_terms_and_conditions_accepted']==1 
					|| $_POST['vendor_terms_and_conditions_accepted'] == "1"
				)
			){
				$usermeta['termsconditions'] = true;
			}		
		}
		
        $mp_user_id = $this->mp->set_mp_user( $wp_user_id );
				
		if($mp_user_id != false){
			$result = $this->mp->update_user( $mp_user_id, $usermeta );
			if($result){
				
				if($usermeta['user_birthday']){
					update_user_meta( $wp_user_id, 'user_birthday', $usermeta['user_birthday'] );
				}
				if($usermeta['user_nationality'] ){
					update_user_meta( $wp_user_id, 'user_nationality', $usermeta['user_nationality'] );
				}				

				/* saved in a second time to be sure it's saved in mangopay */
				if(!empty($_POST['vendor_terms_and_conditions_accepted'])
					&& (
						$_POST['vendor_terms_and_conditions_accepted']==1 
						|| $_POST['vendor_terms_and_conditions_accepted'] == "1"
					)
				){
					update_user_meta($wp_user_id, 'termsconditions', true );
				}

				/** Create a default MP wallet if the user has none **/
				$this->mp->set_mp_wallet( $mp_user_id );
			}
		}else{
			//todo handle error?
//			echo "<pre>", print_r("Error todo", 1), "</pre>";
//			echo "<pre>", print_r($result, 1), "</pre>";
//			die("------");
		}	
    }
	   
    /**
     * Displayed on the user-edit profile admin page
     *
     * Public because shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function mangopay_wallet_table() {

        if( !current_user_can( 'administrator' ) )
            return;
           
        global $current_user;
           
        $wp_user_id = $current_user->ID;
           
        /** If we're on user_edit profile screen, add some styles and inject some html **/
        if( is_admin() ) {
            global $profileuser;
            if( !empty($wp_user_id) && !empty($profileuser->ID) ) {
            	$wp_user_id = $profileuser->ID;
            } elseif( !empty($_GET['user_id']) ) {
            	$wp_user_id = intval( $_GET['user_id'] );
            }
            	
            ?>
            <style>
                .table-vendor-mp_wallets{
                    border:1px solid #333;
                    background:#FFF;
                }
                .table-vendor-mp_wallets th,
                .table-vendor-mp_wallets td{
                    padding: 5px 10px;
                    text-align: left;
                   
                }
            </style>
            <tr>
            <th><?php _e( 'MANGOPAY info', 'mangopay' ); ?></th>
            <td>
            <?php
        }
       
        if ( $mp_user_id = $this->mp->set_mp_user( $wp_user_id ) ) {

            $dashboard_user_url        = $this->mp->getDBUserUrl( $mp_user_id );
            $dashboard_user_link    = '<a target="_mp_db" href="' . $dashboard_user_url . '">';
           
            $dashboard_trans_url    = $this->mp->getDBUserTransactionsUrl( $mp_user_id );
            $dashboard_trans_link    = '<a target="_mp_db" href="' . $dashboard_trans_url . '">';
                       
            $wallets = $this->mp->set_mp_wallet( $mp_user_id );
           
            if( !$wallets )
                echo '<p>' .
                    __( 'No MANGOPAY wallets. Please check that all required fields have been completed in the user profile.', 'mangopay' ) .
                    '</p>';
           
            if( false && mangopayWCConfig::DEBUG ) {
                echo "<pre>Wallets debug:\n";
                var_dump( $wallets );
                echo '</pre>';
            }
           
            echo '<p>' . $dashboard_user_link . sprintf(__( 'View the user (#%s) in the MANGOPAY Dashboard', 'mangopay' ), $mp_user_id ) . '</a></p>';
            echo '<p>' . $dashboard_trans_link . __( 'View user&apos;s MANGOPAY transactions', 'mangopay' ) . '</a></p>';
       
            ?>
            <table class="table table-condensed table-vendor-mp_wallets form-table">
                <thead>
                <tr>
                    <th class="mpw-id-header"><?php _e( 'Wallet #', 'mangopay' ); ?></th>
                    <th class="mpw-creation-header"><?php _e( 'Creation Date', 'mangopay' ); ?></th>
                    <th class="mpw-description-header"><?php _e( 'Description', 'mangopay' ); ?></th>
                    <th class="mpw-balance-header"><?php _e( 'Balance', 'mangopay' ); ?></th>
                    <th class="mpw-options-header"><?php _e( 'Wallet Options', 'mangopay' ); ?></th>
                </tr>
                </thead>
                <tbody>
            <?php
            if( $wallets && is_array($wallets) ) {
                foreach( $wallets as $wallet ) {
   
                    //$dashboard_wallet_url    = $dashboard_user_url . '/Wallets/' . $wallet->Id;
                    $dashboard_wallet_url	= $this->mp->getDBUserWalletTransactionsUrl( $mp_user_id, $wallet->Id );
                    $dashboard_wallet_title	= sprintf( __( 'See MANGOPAY transactions for wallet #%s', 'mangopay' ), $wallet->Id );
                    $dashboard_wallet_link    = '<a target="_mp_db" href="' . $dashboard_wallet_url . '" title="' . $dashboard_wallet_title . '">';
                   
                    if( $this->is_vendor( $wp_user_id ) ) {
                        //$dashboard_payout_url = $this->mp->getDBPayoutUrl( $wallet->Id ); // DEPRECATED: Suppressed in the new Dashboard interface, August 2018
                        $dashboard_payout_title = sprintf( __( 'Do a MANGOPAY payout for wallet #%s', 'mangopay' ), $wallet->Id );
                        $dashboard_payout_link    = '<a target="_mp_db" href="' . $dashboard_wallet_url . '" title="' . $dashboard_payout_title . '">';
                    }
                   
                    echo '<tr>';
                   
                    echo '<td>' . $wallet->Id . '</a></td>';
                   
                    echo '<td>' . get_date_from_gmt( date( 'Y-m-d H:i:s', $wallet->CreationDate ), 'F j, Y H:i:s' )  . '</td>';
                        //@see: http://wordpress.stackexchange.com/questions/94755/converting-timestamps-to-local-time-with-date-l18n
                       
                    echo '<td>' . $wallet->Description . '</td>';
                    echo '<td>' . number_format_i18n( $wallet->Balance->Amount/100, 2 ) . ' ' . $wallet->Currency . '</td>';
                                       
                    echo '<td>';
                   
                    echo $dashboard_wallet_link . __( 'View transactions', 'mangopay' ) . '</a><br>';
                   
                    if( $this->is_vendor( $wp_user_id ) )
                        echo $dashboard_payout_link . __( 'Do a PayOut', 'mangopay' ) . '</a> ';
                   
                    echo '</td>';
                   
                    echo '</tr>';
                }
            } else {
                if( mangopayWCConfig::DEBUG )
                    var_dump( $wallets );
            }
            ?>
                </tbody>
            </table>
            <?php
        } else {
            echo '<p>' .
                __( 'No MANGOPAY wallets. Please check that all required fields have been completed in the user profile.', 'mangopay' ) .
                '</p>';

            return false;
        }
       
        if( is_admin() ) {
            echo '</td></tr>';
        }
    }
       
    /**
     * Displays bank account form for vendors
     * on shop settings page of the front-end vendor dashboard
     * This is a WV action hook
     * @see: https://www.wcvendors.com/help/topic/how-to-add-custom-field-on-vendor-shop-setting/
     * @see: https://docs.mangopay.com/api-references/bank-accounts/
     *
     * Shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function bank_account_form( $wp_user_id ) {
		
        $screen = null;
        if( is_admin() && function_exists('get_current_screen') )
            $screen = get_current_screen();
        //var_dump( $screen ); //debug
		
		/* remove this script for wc vendor shop settings page in bo */
		if(is_admin() 
			&& !empty($screen->id) 
			&& $screen->id == 'toplevel_page_wcv-vendor-shopsettings'
		){
			return;
		}		

        if( !$wp_user_id && (
            !is_admin() ||
            preg_match( '/wcv-vendor-shopsettings/', $screen->id )
        ) ){
            $wp_user_id = get_current_user_id();
        }

		/** in admin we are in a table **/
		if(is_admin()):
			echo '<tr><td colspan="2">';
		endif;
		
        $countries_obj = new WC_Countries();
        $countries = $countries_obj->__get('countries');
       
		$user_mp_status = get_user_meta($wp_user_id, 'user_mp_status', true);
		$user_business_type = get_user_meta($wp_user_id, 'user_business_type', true);
		
		$umeta_key = 'mp_user_id';
		if( !$this->mp->is_production() ){
			$umeta_key .= '_sandbox';
		}
		$mp_user_id = get_user_meta( $wp_user_id , $umeta_key, true );
		$mp_user_data = $this->mp->get_mp_user($mp_user_id);
		/* user informations section */
		$allfields = WC()->checkout->checkout_fields;
		if($this->do_user_need_extrafields($wp_user_id)):
			
			$required_text =  ' <span class="required">*</span>';
		
			//birthday//////////////////////////////
			$value_birthday = '';
			if( $wp_user_id ):
				$value_birthday = date_i18n( $this->supported_format( get_option( 'date_format' ) ), strtotime( get_user_meta( $wp_user_id, 'user_birthday', true ) ) );
			endif;
			if( ! empty( $_POST['user_birthday'] ) ) :
				$value_birthday = esc_attr( $_POST['user_birthday'] );
			endif;
			//nationality//////////////////////////////
			$cur_value = '';
			if( $wp_user_id ):
				$cur_value = get_user_meta( $wp_user_id, 'user_nationality', true );
			endif;
			if( ! empty( $_POST['user_nationality'] ) ):
				$cur_value = esc_attr( $_POST['user_nationality'] );
			endif;
			
			$for_user_nationality = $allfields['billing']['billing_country'];
			$for_user_nationality['label'] = __( 'Nationality', 'mangopay' );
			$for_user_nationality['autocomplete'] = 'user_nationality';  
		?>
				
		<?php
		$user_mp_status_form = get_user_meta( $wp_user_id, 'user_mp_status', true );
        $user_business_type_form = get_user_meta( $wp_user_id, 'user_business_type', true );
		//if user is vendor and didn't have 
		$display_form = "display:none;";
		$business_status_form = "display:none;";
		$disabled_status_field = "";		
		if($this->is_vendor($wp_user_id)):
			$display_form = "";
			if($user_mp_status_form):
				$disabled_status_field = "disabled";
			endif;
			if($user_mp_status_form == "business"):
				$business_status_form = "";
			endif;
		endif;
		?>		
		<!-- USER Informations -->
		<div class="mp_merchant_bank_account_container">
			<p>
				<b>
					<?php _e( 'User informations', 'mangopay' ); ?>
				</b>
			</p>
			<table>           
				<tbody >					
					<!-- birthday -->
					<tr>
						<td>
							<label for="reg_user_birthday">
								<?php _e( 'Birthday', 'mangopay' ); ?>
								<?php echo $required_text; ?>
							</label>
						</td>
						<td>
							<input type="text" 
								   class="input-text calendar" 
								   name="user_birthday" 
								   id="reg_user_birthday" 
								   autocomplete="off" 
								   value="<?php echo $value_birthday; ?>" />
						</td>
					</tr>
					<!-- nationality -->
					<tr>
						<td>
							<label for="reg_user_nationality">
								<?php _e( 'Nationality', 'mangopay' ); ?>
								<?php echo $required_text; ?>
							</label>
						</td>
						<td>
							<select class="nationality_select" 
									name="user_nationality" 
									id="reg_user_nationality">
								<option value="">
									<?php _e( 'Select a country...', 'mangopay' ); ?>
								</option>
							<?php foreach ($countries as $key => $value): $selected=($key==$cur_value?'selected="selected"':''); ?>
								<option value="<?php echo $key?>" <?php echo $selected?>>
									<?php echo $value?>
								</option>
							<?php endforeach; ?>
							</select>
						</td>
					</tr>
					
					<!-- USER STATUS front dashboard -->
					<tr id="block_user_mp_status" style="<?php echo $display_form; ?>">
						<td>
							<label for="reg_user_mp_status">
								<?php _e( 'User status', 'mangopay' ); ?> <span class="required">*</span>
							</label>
						</td>
						<td>
							<input type="hidden" id="actual_user_connected" value="<?php echo $wp_user_id; ?>" />
							<input type="hidden" id="actual_user_mp_status" value="<?php echo $user_mp_status_form; ?>" />
							<input type="hidden" id="actual_default_buyer_status" value="<?php echo $this->options['default_buyer_status']; ?>" />
							<input type="hidden" id="actual_default_vendor_status" value="<?php echo $this->options['default_vendor_status']; ?>" />
							<select class="mp_status_select" 
									name="user_mp_status" 
									id="reg_user_mp_status" 
									data-changesomething="1"
									<?php echo $disabled_status_field; ?>
									>
								<option value=''><?php _e( 'Select option...', 'mangopay' ); ?></option>
								<option value='individual' <?php selected( 'individual', $user_mp_status_form ); ?>>
									<?php _e( 'Individual', 'mangopay' ); ?>
								</option>
								<option value='business' <?php selected( 'business', $user_mp_status_form ); ?>>
									<?php _e( 'Business user', 'mangopay' ); ?>
								</option>
							</select>
						</td>
					</tr>	
					
					<!-- business type -->
					<tr id="block_user_business_type" style="<?php echo $business_status_form; ?>">
						<td>
							<label for="reg_user_business_type">
								<?php _e( 'Business type', 'mangopay' ); ?> <span class="required">*</span>
							</label>
						</td>
						<td>
							<input type="hidden" id="actual_default_business_type" value="<?php echo $this->options['default_business_type']; ?>" /> 
							<select class="mp_btype_select" name="user_business_type" id="reg_user_business_type">
								<option value=''><?php _e( 'Select option...', 'mangopay' ); ?></option>
								<option value='organisation' <?php selected( 'organisation', $user_business_type_form ); ?>>
									<?php _e( 'Organisation', 'mangopay' ); ?>
								</option>
								<option value='business' <?php selected( 'business', $user_business_type_form ); ?>>
									<?php _e( 'Business', 'mangopay' ); ?>
								</option>
								<option value='soletrader' <?php selected( 'soletrader', $user_business_type_form ); ?>>
									<?php _e( 'Soletrader', 'mangopay' ); ?>
								</option>
							</select>
						</td>
					</tr>					
					
				</tbody>				
			</table>		
		</div>   
		<script>
        (function($) {
            $(document).ready(function() {
                $('input.calendar, #user_birthday').datepicker(datepickerL10n);
                if( 'business'==$('#reg_user_mp_status').val() ){
                    $('.hide_business_type').show();
				}
            });
            $('#reg_user_mp_status').on('change',function(e){
                if( 'business'==$('#reg_user_mp_status').val() ) {
                    $('.hide_business_type').show();
                } else {
                    $('.hide_business_type').hide();
                }
            });
        })( jQuery );
        </script>		
		<?php endif; //end if optional ?>	
		
		<?php
		
		if($user_mp_status == 'business' && !is_admin()): //&& $user_business_type == 'business'
			$company_number = '';
			if(isset($_POST['compagny_number'])):
				$company_number = $_POST['compagny_number'];
			else:
				$company_number = get_user_meta($wp_user_id, 'compagny_number', true);	
			endif;
			
			/** headquarters data **/
			if( !empty( $_POST['headquarters_addressline1'] ) ):
				$headquarters_addressline1 = $_POST['headquarters_addressline1'];
			else:
				$headquarters_addressline1 = get_the_author_meta( 'headquarters_addressline1', $wp_user_id );
			endif;
			
			if( !empty( $_POST['headquarters_addressline2'] ) ):
				$headquarters_addressline2 = $_POST['headquarters_addressline2'];
			else:
				$headquarters_addressline2 = get_the_author_meta( 'headquarters_addressline2', $wp_user_id );
			endif;
			
			if( !empty( $_POST['headquarters_city'] ) ):
				$headquarters_city = $_POST['headquarters_city'];
			else:
				$headquarters_city = get_the_author_meta( 'headquarters_city', $wp_user_id );
			endif;
			
			if( !empty( $_POST['headquarters_region'] ) ):
				$headquarters_region = $_POST['headquarters_region'];
			else:
				$headquarters_region = get_the_author_meta( 'headquarters_region', $wp_user_id );
			endif;
			
			if( !empty( $_POST['headquarters_postalcode'] ) ):
				$headquarters_postalcode = $_POST['headquarters_postalcode'];
			else:
				$headquarters_postalcode = get_the_author_meta( 'headquarters_postalcode', $wp_user_id );
			endif;
			
			if( !empty( $_POST['headquarters_country'] ) ):
				$headquarters_country = $_POST['headquarters_country'];
			else:
				$headquarters_country = get_the_author_meta( 'headquarters_country', $wp_user_id );
			endif;
		?>			
		<!-- company number -->	
		<div class="mp_merchant_bank_account_container">
			<p>
				<b>
					<?php _e( 'Company number', 'mangopay' ); ?>
				</b>
			</p>
			<table>           
			<thead>
				
			<?php if( $user_business_type == 'business'): ?>
            <tr>
				<td>
				<label for="vendor_account_type" class="required">
					<?php _e( 'Company number', 'mangopay' ); ?>
					<span class="description required">
						<?php _e( '(required)', 'mangopay' ); ?>
					</span>
				</label>
				</td>
				<td>
					<input type="text" 
						   name="compagny_number" 
						   id="compagny_number" 
						   value="<?php echo $company_number; ?>" 
						   class="regular-text" />
				</td>
            </tr>
			<?php endif; ?>
			
			<!-- headquater address -->
			<tr>
            <td>
            <label for="headquarters_address" class="required">
				<?php _e( 'Headquarters address', 'mangopay' ); ?>
			</label>
            </td>
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
            </thead>
			</table> 
		</div>				
		<?php endif; ?>
				
		<!-- BANK ACCOUNT -->
        <div class="mp_merchant_bank_account_container">       
       
            <?php if( !is_admin() ) : ?>
            <p><b><?php _e( 'Bank account info', 'mangopay' ); ?></b></p>
            <?php endif; ?>
           
            <table>
           
            <thead>

            <?php if( is_admin() && preg_match( '/wcv-vendor-shopsettings/', $screen->id ) ) : ?>
                <tr><td><b><?php _e( 'Bank account info', 'mangopay' ); ?></b></td><td>&nbsp;</td></tr>
            <?php endif; ?>
            <tr>
            <td>
            <label for="vendor_account_type" class="required">
				<?php _e( 'Account type:', 'mangopay' ); ?>
				<span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span>
			</label>
            </td><td>
            <select name="vendor_account_type" id="vendor_account_type">
                <option value=""></option>
                <?php foreach( $this->account_types as $type => $fields ) :
                    if(isset($_POST['vendor_account_type']) && $_POST['vendor_account_type'] == $type){
                        $selected = 'selected="selected"';
                    }else{
                        $selected=(get_user_meta( $wp_user_id, 'vendor_account_type', true )==$type)?'selected="selected"':'';
                    }
                ?>   
                    <option <?php echo $selected; ?>><?php echo $type; ?></option>
                <?php endforeach; ?>
            </select>
            </td>
            </tr>
            </thead>
           
            <?php foreach( $this->account_types as $type => $fields ) : $hidden=(get_user_meta( $wp_user_id, 'vendor_account_type', true )==$type)?'':'style="display:none;"'; ?>
            <tbody class="vendor_account_fields <?php echo $type; ?>_fields" <?php echo $hidden; ?>>
           
                <?php foreach( $fields as $field => $c ) : list( $ftype, $n ) = explode( ':', $c['format'] ); ?>
                <tr>
                <td>
                <label for="<?php echo $field; ?>" class="<?php echo ($c['required']?'required':''); ?>">
                    <?php _e( $c['label'], 'mangopay' ); ?>
                    <?php if( $c['required'] ) : ?>
                     <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span>
                    <?php endif; ?>
                </label>
                </td><td>
                <?php if( 'text' == $ftype || 'number' == $ftype ) : ?>
                <?php
                $field_value = '';
                if(isset($_POST[$field])){
                    $field_value = $_POST[$field];
                }else{
                    $field_value = get_user_meta( $wp_user_id, $field, true );
                }
                ?>
                <input type="text" name="<?php echo $field; ?>" id="<?php echo $field; ?>" placeholder="<?php echo $c['placeholder']; ?>" value="<?php echo $field_value; ?>" class="regular-text" />
                <?php elseif( 'select' == $ftype ) : ?>
                <select name="<?php echo $field; ?>" id="<?php echo $field; ?>">
                    <?php
                    foreach( explode( ',', $n ) as $option ) :
                        if(isset($_POST[$field]) && $_POST[$field] == $option){
                            $selected = 'selected="selected"';
                        }else{
                            $selected=($option==get_user_meta( $wp_user_id, $field, true )?'selected="selected"':'');
                        }
                     ?>
                    <option <?php echo $selected; ?>><?php echo $option; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php elseif( 'country' == $ftype ) : ?>
                <select name="<?php echo $field; ?>" id="<?php echo $field; ?>">
                 <option value=""><?php _e( 'Select a country...', 'mangopay' ); ?></option>
                <?php foreach ($countries as $key => $value):
                    if(isset($_POST[$field]) && $_POST[$field] == $key){
                        $selected = 'selected="selected"';
                    }else{
                        $selected=($key==get_user_meta( $wp_user_id, $field, true )?'selected="selected"':'');
                    }
                ?>
                    <option value="<?php echo $key?>" <?php echo $selected; ?>><?php echo $value?></option>
                <?php endforeach; ?>
                </select>
                <?php endif; ?>
                </td>
                </tr>
                <?php endforeach; ?>

            </tbody>
            <?php endforeach; ?>
           
            <tbody class="bank_account_address">
                <tr>
                <td>
                <label for="vendor_account_name"><?php _e( 'Account holder&apos;s name', 'mangopay' ); ?> <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span></label>
                </td>
                <td>
                <?php
                $field_value = '';
                if(isset($_POST['vendor_account_name'])){
                    $field_value = $_POST['vendor_account_name'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_name', true );
                }
                ?>
                <input type="text" name="vendor_account_name" id="vendor_account_name" value="<?php echo $field_value; ?>" class="regular-text" />
                </td>
                </tr>
               
                <tr>
                <td>
                <label for="vendor_account_address1"><?php _e( 'Account holder&apos;s address', 'mangopay' ); ?> <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span></label>
                </td>
                <td>
                <?php
                $field_value = '';
                if(isset($_POST['vendor_account_address1'])){
                    $field_value = $_POST['vendor_account_address1'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_address1', true );
                }
                ?>
                <input type="text" name="vendor_account_address1" id="vendor_account_address1" value="<?php echo $field_value; ?>" class="regular-text" /><br/>
                <?php
                $field_value = '';
                if(isset($_POST['vendor_account_address2'])){
                    $field_value = $_POST['vendor_account_address2'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_address2', true );
                }
                ?>               
                <input type="text" name="vendor_account_address2" id="vendor_account_address2" value="<?php echo $field_value; ?>" class="regular-text" />
                </td>
                </tr>
               
                <tr>
                <td>
                <label for="vendor_account_city"><?php _e( 'Account holder&apos;s city', 'mangopay' ); ?> <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span></label>
                </td>
                <td>
                <?php
                $field_value = '';
                if(isset($_POST['vendor_account_city'])){
                    $field_value = $_POST['vendor_account_city'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_city', true );
                }
                ?>                      
                <input type="text" name="vendor_account_city" id="vendor_account_city" value="<?php echo $field_value; ?>" class="regular-text" />
                </td>
                </tr>
               
                <tr>
                <td>
                <label for="vendor_account_postcode"><?php _e( 'Account holder&apos;s postal code', 'mangopay' ); ?> <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span></label>
                </td>
                <td>
                <?php
                $field_value = '';
                if(isset($_POST['vendor_account_postcode'])){
                    $field_value = $_POST['vendor_account_postcode'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_postcode', true );
                }
                ?>                     
                <input type="text" name="vendor_account_postcode" id="vendor_account_postcode" value="<?php echo $field_value; ?>" class="regular-text" />
                </td>
                </tr>       
                <?php if(is_admin()){ ?>
                <tr>
                    <td>
                        <label for="vendor_account_country">
                            <?php _e( 'Account holder&apos;s country', 'mangopay' ); ?>
                            <span class="description required">
                                <?php _e( '(required)', 'mangopay' ); ?>
                            </span>
                        </label>
                    <td>
                    <select class="vendor_account_select js_field-country" name="vendor_account_country" id="vendor_account_country">
                    <option value=""><?php _e( 'Select a country...', 'mangopay' ); ?></option>
                    <?php foreach ($countries as $key => $value):
                        if(isset($_POST['vendor_account_country']) && $_POST['vendor_account_country'] == $key){
                            $selected = 'selected="selected"';
                        }else{
                            $selected=($key==get_user_meta( $wp_user_id, 'vendor_account_country', true )?'selected="selected"':'');
                        }
                        ?>
                        <option value="<?php echo $key?>" <?php echo $selected; ?>><?php echo $value?></option>
                    <?php endforeach; ?>
                    </select>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="vendor_account_region">
							<?php _e( 'Account holder&apos;s region', 'mangopay' ); ?>
							<span class="description required" 
								  id="mangopay_vendor_account_region_labelrequire" 
								  style="display:none; ">
								<?php _e( '(required)', 'mangopay' ); ?>
							</span>
						</label>
                    </td>
                    <td id="mangopay_vendor_account_country_td">
                        <?php
                        $field_value = '';
                        if(isset($_POST['vendor_account_region'])){
                            $field_value = $_POST['vendor_account_region'];
                        }else{
                            $field_value = get_user_meta( $wp_user_id, 'vendor_account_region', true );
                        }
                        ?> 
                        <input type="text" class="vendor_account_select js_field-state" name="vendor_account_region" id="vendor_account_region" value="<?php echo $field_value; ?>" />
                    </td>
                </tr>			
                <?php }else{ //if in front ?>
                <tr>
                    <td>
                        <label for="vendor_account_country">
                            <?php _e( 'Account holder&apos;s country', 'mangopay' ); ?>
                            <span class="description required">
                                <?php _e( '(required)', 'mangopay' ); ?>
                            </span>
                        </label>
                    <td>             
                        <?php
                        $field_value = '';
                        if(isset($_POST['vendor_account_country'])){
                            $field_value = $_POST['vendor_account_country'];
                        }else{
                            $field_value = get_user_meta( $wp_user_id, 'vendor_account_country', true );
                        }
                        $vendor_account_country_options = array();
                        $vendor_account_country_options['type'] = 'country';
                        $vendor_account_country_options['class'] = array('form-row-wide','address-field','update_totals_on_change');
                        $vendor_account_country_options['required'] = 1;
                        $vendor_account_country_options['autocomplete'] = 'country';
                        $this->mangopay_form_field( 'vendor_account_country', $vendor_account_country_options, $field_value );
                        ?>   
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="vendor_account_region">
							<?php _e( 'Account holder&apos;s region', 'mangopay' ); ?>
							<span class="description required" 
								  id="mangopay_vendor_account_region_labelrequire"
								  style="display: none;">
								<?php _e( '(required)', 'mangopay' ); ?>
							</span>
						</label>
                    </td>
                    <td id="mangopay_vendor_account_country_td">
                        <?php
                        $field_value = '';
                        if(isset($_POST['vendor_account_region'])){
                            $field_value = $_POST['vendor_account_region'];
                        }else{
                            $field_value = get_user_meta( $wp_user_id, 'vendor_account_region', true );
                        }
                        $vendor_account_region_options = array();
                        $vendor_account_region_options['type'] = 'state';
                        $vendor_account_region_options['required'] = 1;
                        $vendor_account_region_options['class'] = array('form-row-first','address-field');
                        $vendor_account_region_options['validate'] = array('state');
                        $vendor_account_region_options['countrykey'] = 'vendor_account_country';
                        $vendor_account_region_options['autocomplete'] = 'address-level1';
                        $vendor_account_region_options['userid'] = $wp_user_id;
                        $this->mangopay_form_field( 'vendor_account_region', $vendor_account_region_options, $field_value );
                        ?>                       
                     </td>
                </tr>			
                <?php }//end if admin ?>
				
				<?php
				$TermsAndConditionsAccepted = "";
				$mp_vendor_id = get_user_meta(  $wp_user_id, $umeta_key, true );	
				$terms_mandatory = false;
				if($mp_vendor_id):
					$mp_user_data = mpAccess::getInstance()->get_user_properties($mp_vendor_id);		
					if(	!empty($mp_user_data) 
						&& !empty($mp_user_data->TermsAndConditionsAccepted)
						&& ($mp_user_data->TermsAndConditionsAccepted == 1 
							|| $mp_user_data->TermsAndConditionsAccepted == "1")
					):
						$TermsAndConditionsAccepted = "checked";
					endif;
					$terms_mandatory = true;
				endif;
				?>
				<tr>
                    <td>
                        <label for="vendor_account_region">
							<?php _e( 'Mangopay Terms and conditions', 'mangopay' ); ?> 
							<span class="description required"></span></label>
                    </td>
                    <td>
                        <input name="vendor_terms_and_conditions_accepted" 
								type="checkbox" 
								id="vendor_terms_and_conditions_accepted"
								<?php echo $TermsAndConditionsAccepted; ?>
								value="1">	                      
                     </td>
                </tr>					
				
            </tbody>
                       
            </table>
           
            <script>
            (function($) {
                $(document).ready(function() {
                    $('.vendor_account_fields').hide();
                    if( $('#vendor_account_type').val() )
                        $('.vendor_account_fields.' + $('#vendor_account_type').val() + '_fields').show();
                    $('#vendor_account_type').on( 'change', function(e) {
                        $('.vendor_account_fields').hide();
                        $('.vendor_account_fields.' + $(this).val() + '_fields').show();
                    });
                });
            })( jQuery );
            </script>
        </div>
        <?php if( is_admin() && preg_match( '/wcv-vendor-shopsettings/', $screen->id ) ) : ?>
            <p>&nbsp;</p>
        <?php endif; ?>
			
			
        <?php
		/** in admin we are in a table **/
		if(is_admin()):
			echo '</td></tr>';
		endif;
    }
   
    /**
     * Save redacted bank account info hints in vendor's usermeta
     * Registers or updates actual bank info with MP API
     * @see: https://docs.mangopay.com/api-references/bank-accounts/
     *
     * Shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function save_account_form( $wp_user_id ) {
		
		if( !isset( $_POST['vendor_account_type'] ) || !$_POST['vendor_account_type'] ){
			return true;
		}

		if( !isset( $this->account_types[$_POST['vendor_account_type']] ) ){
			return false;
		}

		$account_type = $this->account_types[$_POST['vendor_account_type']];
		$needs_update = false;
		$account_data = array();

		/** Record redacted bank account data in vendor's usermeta **/
		foreach( $account_type as $field => $c ) {
			if(
				isset( $_POST[$field] ) &&
				$_POST[$field] &&
				!preg_match( '/\*\*/', $_POST[$field] )
			) {
				if( isset( $c['redact'] ) && $c['redact'] ) {
					$needs_update = true;
					list( $obf_start, $obf_end ) = explode( ',', $c['redact'] );
					$strlen = strlen( $_POST[$field] );

					/**
					 * if its <=5 characters, lets just redact the whole thing
					 * @see: https://github.com/Mangopay/wordpress-plugin/issues/12
					 */
					if( $strlen <= 5 ) {
						$to_be_stored = str_repeat( '*', $strlen );

					} else {
						$obf_center = $strlen - $obf_start - $obf_end;
						if( $obf_center < 2 )
							$obf_center = 2;
						$to_be_stored = substr( $_POST[$field], 0, $obf_start ) .
							str_repeat( '*', $obf_center ) .
							substr( $_POST[$field], -$obf_end, $obf_end );
					}
				} else {
					if( get_user_meta( $wp_user_id, $field, true ) != $_POST[$field] )
						$needs_update = true;
					$to_be_stored = $_POST[$field];
				}
				update_user_meta( $wp_user_id, $field, $to_be_stored );
				$account_data[$field] = $_POST[$field];
			}
		}
			           
		/** Record clear text bank account data in vendor's usermeta **/
		$account_clear_data = array(
			'vendor_account_type',
			'vendor_account_name',
			'vendor_account_address1',
			'vendor_account_address2',
			'vendor_account_city',
			'vendor_account_postcode',
			'vendor_account_region',
			'vendor_account_country',
			'vendor_terms_and_conditions_accepted'
		);
		foreach( $account_clear_data as $field ) {
			/** update_user_meta() returns "false" if the value is unchanged **/
			if(isset($_POST[$field]) && update_user_meta( $wp_user_id, $field, $_POST[$field] ) ){
				$needs_update = true;
			}
		}
           
		if( $needs_update ) {
			$mp_user_id = $this->mp->set_mp_user( $wp_user_id );

			/** We store a different mp_account_id for production and sandbox environments **/
			$umeta_key = 'mp_account_id';
			if( !$this->mp->is_production() )
				$umeta_key .= '_sandbox';

			$existing_account_id = get_user_meta(  $wp_user_id, $umeta_key, true );

			$vendor_account_country = '';
			if(isset($_POST['vendor_account_country']) && $_POST['vendor_account_country']!=""){
				$vendor_account_country = $_POST['vendor_account_country'];
			}

			$mp_account_id = $this->mp->save_bank_account(
				$mp_user_id,
				$wp_user_id,
				$existing_account_id,
				$_POST['vendor_account_type'],
				$_POST['vendor_account_name'],
				$_POST['vendor_account_address1'],
				$_POST['vendor_account_address2'],
				$_POST['vendor_account_city'],
				$_POST['vendor_account_postcode'],
				$_POST['vendor_account_region'],
				$vendor_account_country,
				$account_data,
				$this->account_types
			);

			/** update others data from shop  **/
			update_user_meta( $wp_user_id, $umeta_key, $mp_account_id );
		}
    }
	
	/**
	 * Save wp side of headquarters
	 * @param type $wp_user_id
	 */
    public function save_account_form_headquarter( $wp_user_id ) {
			           
		/** Record clear text bank account data in vendor's usermeta **/
		$account_clear_data = array(
			'headquarters_addressline1',
			'headquarters_addressline2',
			'headquarters_city',
			'headquarters_region',
			'headquarters_postalcode',
			'headquarters_country',
		);
		foreach( $account_clear_data as $field ) {
			/** update_user_meta() returns "false" if the value is unchanged **/
			if(isset($_POST[$field])){
				update_user_meta( $wp_user_id, $field, $_POST[$field] );
			}
		}
    }   
	
	/**
	 * ave wp side of company number
	 * @param type $wp_user_id
	 */
	public function save_account_form_companynumber( $wp_user_id ) {
           			
		/** independent test only for compagny number**/
		if(isset($_POST['compagny_number']) ){
			//remouve spaces
			$company_numbers = str_replace(' ', '', $_POST['compagny_number']);
			update_user_meta( $wp_user_id, 'compagny_number', $company_numbers );
		}
    }
	
    public function shop_settings_saved($wp_user_id){
		
		/* indepedent save */
		$this->save_account_form_companynumber( $wp_user_id );
		/* indepedent save */
		$this->save_account_form_headquarter( $wp_user_id );
       
        /** Update bank account data if set && valid **/
		$errors = new WP_Error;
        $this->validate_bank_account_data( $errors, NULL, $wp_user_id );
        $e = $errors->get_error_code();			
        if( empty( $e ) ) {
            $this->save_account_form( $wp_user_id );
            return true;
        }
       
        foreach( $errors->errors as $error ) {
            wc_add_notice( $error[0], 'error' );
        }           
       
    }
   
    /**
     * Specific procedure to validate and save bank account data when in the
     * /wp-admin/admin.php?page=wcv-vendor-shopsettings back-office screen
     * (WV specific)
     *
     * @param int $wp_user_id
     */
    public function shop_settings_admin_saved( $wp_user_id ) {
       
		/* indepedent save */
		$this->save_account_form_companynumber( $wp_user_id );
		/* indepedent save */
		$this->save_account_form_headquarter( $wp_user_id );
		
        /** Update bank account data if set && valid **/
        $errors = new WP_Error;
        $this->validate_bank_account_data( $errors, NULL, $wp_user_id );
        $e = $errors->get_error_code();		
        if( empty( $e ) ) {
            $this->save_account_form( $wp_user_id );
            return true;
        }
       
        foreach( $errors->errors as $error ) {
            echo '<div class="error"><p>';
            echo $error[0];
            echo '</p></div>';
        }
        return $errors;
    }
   
    /**
     * Child method of user_edit_checks()
     * Specifically checks data related to bank accounts
     *
     * @param object $errors
     * @param unknown $update
     * @param unknown $wp_user_id
     *
     * Shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function validate_bank_account_data( &$errors, $update, $wp_user_id ) {
               
        $required = array(		  
			'vendor_account_type'        => __( 'Account type', 'mangopay' ),
            'vendor_account_name'        => __( 'Account holder&apos;s name', 'mangopay' ),
            'vendor_account_address1'    => __( 'Account holder&apos;s address', 'mangopay' ),
            'vendor_account_city'        => __( 'Account holder&apos;s city', 'mangopay' ),
            'vendor_account_country'    => __( 'Account holder&apos;s country', 'mangopay' )
        );

        $mandatory_region_countries = array( 'MX', 'CA', 'US' );
       
        if( 
			isset( $_POST['vendor_account_country'] ) 
			&& in_array( $_POST['vendor_account_country'], $mandatory_region_countries ) 
		){
            $required['vendor_account_region'] = __( 'Account holder&apos;s region', 'mangopay' );
        }
       
        $no_postcode_countries = array(
            "AO", "AG", "AW", "BS", "BZ", "BJ", "BW", "BF", "BI",
            "CM", "CF", "KM", "CG", "CD", "CK", "CI", "DJ", "DM",
            "GQ", "ER", "FJ", "TF", "GM", "GH", "GD", "GN", "GY",
            "HK", "IE", "JM", "KE", "KI", "MO", "MW", "ML", "MR",
            "MU", "MS", "NR", "AN", "NU", "KP", "PA", "QA", "RW",
            "KN", "LC", "ST", "SA", "SC", "SL", "SB", "SO", "ZA",
            "SR", "SY", "TZ", "TL", "TK", "TO", "TT", "TV", "UG",
            "AE", "VU", "YE", "ZW"
        );
       
        if( 
			isset( $_POST['vendor_account_country'] ) 
			&& !in_array( $_POST['vendor_account_country'], $no_postcode_countries ) 
		){
            $required['vendor_account_postcode'] = __( 'Account holder&apos;s postal code', 'mangopay' );
		}

        $account_type = array();
        if( !empty( $_POST['vendor_account_type'] ) ) {
            if( !isset( $this->account_types[$_POST['vendor_account_type']] ) ) {
                $errors->add(
                    'invalid_vendor_account_type',
                    '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
                    __( 'not a valid bank account type', 'mangopay' ),
                    array( 'form-field' => 'vendor_account_type' )
                );
            } else {
                $account_type = $this->account_types[$_POST['vendor_account_type']];
            }
        }
       
        /** Check that required clear-text fields are present **/
        foreach( $required as $field => $label ) {
            if ( isset( $_POST[$field] ) && empty( $_POST[$field] ) ) {
                $errors->add(
                    $field . '_required_error',
                    '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
                    $label . ' ' .
                    __( 'is required!', 'mangopay' )
                );
            }
        }

        /** Validate postal code **/
        if( !empty( $_POST['vendor_account_postcode'] ) ) {
            if( !preg_match( '/^[a-z0-9 \-]+$/i', $_POST['vendor_account_postcode'] ) )
                $errors->add(
                        'vendor_account_postcode_invalid_error',
                        '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
                        __( 'Account holder&apos;s postal code', 'mangopay' ) . ' ' .
                        __( 'is invalid!', 'mangopay' )
                );
        }
       
        /** Validate country **/
        if( isset( $_POST['vendor_account_country'] ) ) {
            $countries_obj = new WC_Countries();
            $countries = $countries_obj->__get('countries');
            if( !isset( $countries[$_POST['vendor_account_country']] ) )
                $errors->add(
                        'vendor_account_country_invalid_error',
                        '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
                        __( 'Account holder&apos;s country', 'mangopay' ) .
                        __( 'is invalid!', 'mangopay' )
                );
        }
       
        /** Check that required bank account fields are present and either redacted or valid **/
        $allobfuscated = true;
        foreach( $account_type as $field => $c ) {

            /** Check for required fields **/
            if(
                isset( $c['required'] ) &&
                $c['required'] &&
                ( !isset( $_POST[$field] ) || !$_POST[$field] )
            )
                $errors->add(
                    'missing_' . $field,
                    '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
                    __( $c['label'], 'mangopay' ) . ' ' .
                    __( 'is required!', 'mangopay' ),
                    array( 'form-field' => $field )
                );
           
            /** All of them or none of them can be redacted **/
            if( $c['redact'] && !preg_match( '/\*\*/', $_POST[$field] ) )
                $allobfuscated = false;
           
            /** Validation rules (regexp based) **/
            if( isset( $_POST[$field] ) && $_POST[$field] )
                if(
                    ( !$allobfuscated && preg_match( '/\*\*/', $_POST[$field] ) ) ||
                    (    
                        !preg_match( '/\*\*/', $_POST[$field] ) &&
                        !preg_match( '/' . $c['validate'] . '/', $_POST[$field] )
                    )
                ) {
                    $errors->add(
                        'invalid_' . $field,
                        '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
                        __( $c['label'], 'mangopay' ) . ' ' .
                        __( 'is invalid!', 'mangopay' ),
                        array( 'form-field' => $field )
                    );
                }
        }
		
		/** test company number if necessary **/
		$user_mp_status = get_the_author_meta( 'user_mp_status', $wp_user_id );
		$user_business_type	= get_the_author_meta( 'user_business_type', $wp_user_id );
		if($user_mp_status == 'business' && $user_business_type == 'business'){
			if ( isset( $_POST['compagny_number'] ) && empty( $_POST['compagny_number'] ) ) {
				$errors->add(
						'invalid_vendor_account_type',
						'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
						__( 'company number is mandatory', 'mangopay' )
					);
			}elseif(isset( $_POST['compagny_number'] )){		
				$company_numbers = str_replace(' ', '', $_POST['compagny_number']);
				$result = mpAccess::getInstance()->check_company_number_patterns($company_numbers);	
				if($result == 'nopattern'){
					$errors->add(
						'invalid_vendor_account_type',
						'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
						__( 'company number format not recognized', 'mangopay' )
					);
				}
			}
			
			/** **/
			$list_headquarters_fields = array(
				"headquarters_addressline1" => "headquarters address",
				"headquarters_city"			=> "headquarters city",
				"headquarters_region"		=> "headquarters region",
				"headquarters_postalcode"	=> "headquarters postalcode",
				"headquarters_country"		=> "headquarters country",
			);
			foreach($list_headquarters_fields as $headquarters_field=>$hq_name){
				if ( isset( $_POST[$headquarters_field] ) && empty( $_POST[$headquarters_field] ) ) {
					$errors->add(
							'invalid_vendor_account_type',
							'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
							__( $hq_name.' is mandatory', 'mangopay' )
						);
				}
			}
		}				
    }
 
	public function order_redirect(){
		global $wp;
		
		if ( is_checkout() && ! empty( $wp->query_vars['order-received'] ) ):

			/** get the order id **/
			$order_id = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
			/** get the order **/
			$order    = wc_get_order( $order_id );
			
			/** get the payement type**/
			$payment_type = get_post_meta( $order_id, 'mangopay_payment_type', 'card',true );
			/** if it's not card get out **/
			if($payment_type != "card"):
				return;
			endif;

			/** get the ref for the transaction id **/
			$payment_ref = get_post_meta( $order_id, 'mangopay_payment_ref',true);
			/** if no ref get out **/
			if(!$payment_ref):
				return;
			endif;

			/** get the data from transaction **/
			if(!isset($payment_ref['transaction_id'])){
				return;
			}
						
			$mp_transaction = $this->mp->get_payin( $payment_ref['transaction_id'] );
			/** if no data get out **/
			if(!$mp_transaction):
				return;
			endif;

			/** if status is failed, send to cancel order url **/
			if($mp_transaction->Status == "FAILED"):
				/** user message **/
				//wc_add_notice(__( $mp_transaction->ResultMessage, 'mangopay') , "notice");//error, success or notice
				/** spÃ©cial code to be intercepted, do NOT change it **/
				wc_add_notice( '<span class="cancelmessagealone">'.__( $mp_transaction->ResultMessage, 'mangopay').'</span>', "error");//error, success or notice

				/** status to cancel + message admin **/
				$order->update_status( 'failed', __( $mp_transaction->ResultMessage, 'mangopay')); // message pour admin

				/** to get te cancel url **/
				$redirect_url = $order->get_cancel_order_url_raw();
				/** and let's go **/
				wp_redirect($redirect_url );
				exit;
			endif;//status is pending
       
		endif;//we are on chekout page and order recieved is empty
	}
 
   
    /**
     * Verify payment payin transaction and update order status appropriately
     * Checks that payment status is SUCCEEDED
     * Checks that order_total == payment total
     * Checks that order_currency == payment currency
     * Store MP transaction ID in order meta
     *
     * If Everything OK, order status is changed to processing
     *
     * This all takes place on the order-received/thank-you WC page on the front-office
     *
     */
    public function order_received( $order_id ) {

        if( !$order_id ) {
            return false;
        }
       
        if( !$order = new WC_Order( $order_id ) ) {
            return false;
        }
       
        if ( 'failed' == $order->get_status() ) {
            return false;
        }
		
		if( !isset( $_GET['transactionId'] ) || !$_GET['transactionId'] ) {
			$transaction_id = get_post_meta( $order_id, 'mp_transaction_id', true );
		}else{
			$transaction_id = $_GET['transactionId'];
		}
		if(!$transaction_id){
			echo '<p>' . __( 'Error: MANGOPAY transaction did not succeed.', 'mangopay' ) . '</p>';
			return false;
		}
   
		/** If we are dealing with a pre-authorization, processing ends here (no payin) **/
		$preauthorization_id = get_post_meta( $order_id, 'preauthorization_id', true );
		if( !empty( $preauthorization_id ) ) {
			return false;
		}
		
		if( !$mp_transaction = $this->mp->get_payin( $transaction_id ) ) {
			return false;
		}
               
        if( !$mp_status = $mp_transaction->Status ) {
            return false;
        }
       
        if( !$mp_amount = $mp_transaction->CreditedFunds->Amount ) {
            return false;
        }
       
        if( !$mp_currency = $mp_transaction->CreditedFunds->Currency ) {
            return false;
        }
       
        if( mangopayWCConfig::DEBUG ) {
            $tr_href = $this->mp->getDBUserUrl( '' ) . 'PayIn/' . $transaction_id;
            $tr_link = '<a target="_mp_db" href="' . $tr_href . '">';
            echo '<p>' . __( 'MANGOPAY transaction Id:', 'mangopay' ) . ' ' . $tr_link . $transaction_id . '</a></p>';
            echo '<p>' . __( 'MANGOPAY transaction status:', 'mangopay' ) . ' ' . $mp_status . '</p>';
            echo '<p>' . __( 'MANGOPAY transaction total amount:', 'mangopay' ) . ' ' . $mp_amount . '</p>';
            echo '<p>' . __( 'MANGOPAY transaction currency:', 'mangopay' ) . ' ' . $mp_currency . '</p>';
            echo '<p>' . __( 'Order total:', 'mangopay' ) . ' ' . $order->get_total() . '</p>';    //Debug
            echo '<p>' . __( 'Order currency:', 'mangopay' ) . ' ' . $order->get_currency() . '</p>';    //Debug
        }
       
        if( 'SUCCEEDED' != $mp_status ) {
            echo '<p>' . __( 'Error: MANGOPAY transaction did not succeed.', 'mangopay' ) . '</p>';
            return false;
        }
       
        if( $order->get_currency() != $mp_currency ) {
            echo '<p>' . __( 'Error: wrong currency.', 'mangopay' ) . '</p>';
            return false;
        }
       
        if( round( $order->get_total() * 100 ) != $mp_amount ) {
            echo '<p>' . __( 'Error: wrong payment amount.', 'mangopay' ) . '</p>';
            return false;
        }
       
        /**
         * Save the MP transaction ID in the WC order metas
         * this needs to be done before calling payment->complete()
         * to handle auto-completed orders such as downloadables and virtual products & bookings
         *
         */
        update_post_meta( $order_id, 'mp_transaction_id', $transaction_id );
        update_post_meta( $order_id, 'mp_success_transaction_id', $transaction_id );
               
        $order->payment_complete();
    }
   
    /**
     * Display bankwire ref at top of thankyou page when new order received
     * (only if payment was bankwire)
     *
     * @param int $order_id
     */
    public function display_bankwire_ref( $order_id ) {
      
        $order = new WC_Order( $order_id ); //not used?
       
        if(
            get_post_meta( $order_id, 'mangopay_payment_type', true ) != 'bank_wire' ||
            !$ref = get_post_meta( $order_id, 'mangopay_payment_ref', true )
        )
            return $order_id;    // Do nothing

        echo '<h2>' . __( 'Information for your bank transfer', 'mangopay' ) . '</h2>';
        echo '<p>' . __( 'To complete your order, please do a bank transfer with the following information, including the bank wire reference.', 'mangopay' ) . '<p>';
        echo '<p>' . __( 'We will process your order once the transfer is received.', 'mangopay' ) . '<p>';
       
        ?>
        <ul class="order_details">
            <li class="mp_amount">
                <?php _e( 'Amount:', 'mangopay' ); ?>
                <strong><?php echo $ref->PaymentDetails->DeclaredDebitedFunds->Amount/100; ?></strong>
                <strong><?php echo $ref->PaymentDetails->DeclaredDebitedFunds->Currency; ?></strong>
            </li>
            <li class="mp_owner">
                <?php _e( 'Bank account owner:', 'mangopay' ); ?>
                <strong><?php echo $ref->PaymentDetails->BankAccount->OwnerName; ?></strong>
            </li>
            <!--<li class="mp_address">
                <?php _e( 'Owner address:', 'mangopay' ); ?>
                <div class="mp_address_block">
                    <strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->AddressLine1; ?></strong><br/>
                    <?php if( $ref->PaymentDetails->BankAccount->OwnerAddress->AddressLine2 ): ?>
                        <strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->AddressLine2; ?></strong><br/>
                    <?php endif; ?>
                    <strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->PostalCode; ?></strong>
                    <strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->City; ?></strong><br/>
                    <?php if( $ref->PaymentDetails->BankAccount->OwnerAddress->Region ): ?>
                        <strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->Region; ?></strong><br/>
                    <?php endif; ?>
                    <strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->Country; ?></strong>
                </div>
            </li>-->
            <li class="mp_iban">
                <?php _e( 'IBAN:', 'mangopay' ); ?>
                <strong><?php echo $ref->PaymentDetails->BankAccount->Details->IBAN; ?></strong>
            </li>
            <li class="mp_bic">
                <?php _e( 'BIC:', 'mangopay' ); ?>
                <strong><?php echo $ref->PaymentDetails->BankAccount->Details->BIC; ?></strong>
            </li>
            <li class="mp_wire_ref">
                <?php _e( 'Wire reference:', 'mangopay' ); ?>
                <strong><?php echo $ref->PaymentDetails->WireReference; ?></strong>
            </li>       
        </ul>
        <?php
    }
   
    /**
     * Loop to gather all wallet transfers that need to be performed in an order batch
     *
     * @param WV_Order object $order
     * @param int $order_id
     * @param int $mp_transaction_id
     * @param int $wp_user_id
     * @param string $mp_currency
     * @return array $transfers_to_return
     *
     */
    public function get_all_wallet_trans( $order, $order_id, $mp_transaction_id, $wp_user_id, $mp_currency ){
		
        /** This will hold the returned list of transfers **/
        $transfers_to_return = array();
		
        if(class_exists('WCV_Vendors')){
           
			/** Check WC-Vendors version and apply only for versions >= 2.0 **/
        	include_once(ABSPATH.'wp-admin/includes/plugin.php');	// Necessary to use get_plugin_data()
        	$data_plugin = get_plugin_data( wcv_plugin_dir_path . '/class-wc-vendors.php' );			
			$test_version = version_compare( $data_plugin['Version'] , '2.0' , '>=' );	
			if($test_version){
				
				/** Up-to-date code for WC-Vendors versions >= 2.0 **/
			
				/** Prepare data about the order **/
				$order_items = array();			
				foreach ($order->get_items() as $item_id => $item_data) {
					$product = $item_data->get_product();
					$product_id = $product->get_id();
					$order_items[$product_id] = array(
						'total'=>$item_data->get_total(),
						'total_tax'=>$item_data->get_total_tax(),
						'item_id'=>$item_id
					);
				}
				
				$order_used = true;
				$order_shipping = $order->get_total_shipping();
				$order_shipping_tax = $order->get_shipping_tax();				
								
				/** Get shipping method to test for flat rate **/
				$shipping_methods = $order->get_shipping_methods();
				foreach( $shipping_methods as $shipping_method ) {
					$method = $shipping_method['method_id'];
					break;
				}
				$flat_rate_mode = false;
				if ( preg_match( '#flat_rate#', $method ) ){
					/** If this order uses flat rate shipping, apply the $item->total_shipping only once **/
					$flat_rate_mode = true;
				}

				/** Get commission data from WC-Vendors **/
				global $wpdb;
				$sql_com = "SELECT * FROM `".$wpdb->prefix."pv_commission` WHERE `order_id` =".$order_id;
				$items = $wpdb->get_results( $sql_com );
								
				if($items && count($items)>0){
					foreach($items as $item){
						
						if(class_exists('WCV_Vendors')){
							if ( ! WCV_Vendors::is_vendor( $item->vendor_id ) ) {
								continue;
							}
						}						

						$total_shipping = 0;
						/** if first passage and there is shipping, we add everything and give to the first vendor **/
						if($order_used && $order_shipping){
							$total_shipping = $order_shipping+$order_shipping_tax;
						}
						
						/**
						 * Mangopay AMOUNT 
						 * item total + item tax + shipping_only_for_first(ORDER shipping + ORDER Shipping TAX)
						 */						
						$mp_amount = $order_items[$item->product_id]['total']+$order_items[$item->product_id]['total_tax']+$total_shipping;

						/** By default we calculate with total shipping **/
						$total_shipping_commission = $item->total_shipping;
						/** Except if flat_rate_mode is ON AND we already passed the first loop ($order_used == false) **/
						if(!$order_used && $flat_rate_mode){
							$total_shipping_commission = 0;
						}
						//$total_vendor = $item->total_due+$total_shipping_commission+$item->tax;
						//$mp_fees = $mp_amount-$total_vendor;
						
						/**
						 * Total vendor to calculate FEES
						 * item DUE to vendor + item total shipping + item tax
						 *
						 * WCV adds the complete tax of the shipping to $item->item_tax 
						 * so we add the shipping tax to the item tax(wooc) for the first line
						 * and for the others only the item tax(wooc)
						 */
						$total_vendor_tax = 0;
						$wcv_taxes_to_user = get_option('wcvendors_vendor_give_taxes');
						if($wcv_taxes_to_user && $wcv_taxes_to_user =="yes"){
							$total_vendor_tax = $order_items[$item->product_id]['total_tax'];
							if($order_used){
								$total_vendor_tax = $order_shipping_tax+$order_items[$item->product_id]['total_tax'];
							}
						}
						
						$total_vendor = $item->total_due+$total_shipping_commission+$total_vendor_tax;
						$mp_fees = $mp_amount-$total_vendor;
						
						/** DEBUG **/
//echo "<pre>", print_r("PV COMISSION total due", 1), "</pre>";
//echo "<pre>", print_r($item->total_due, 1), "</pre>";
//echo "<pre>", print_r("PV COMISSION total shipping", 1), "</pre>";
//echo "<pre>", print_r($total_shipping_commission, 1), "</pre>";
//echo "<pre>", print_r("PV COMISSION total TAX", 1), "</pre>";
//echo "<pre>", print_r($total_vendor_tax, 1), "</pre>";
//echo "<pre>", print_r("PV COMISSION TOTAL VENDOR", 1), "</pre>";
//echo "<pre>", print_r($total_vendor, 1), "</pre>";
//echo "<pre>", print_r("---------------------------------------------------", 1), "</pre>";
//echo "<pre>", print_r("WOOC total item", 1), "</pre>";
//echo "<pre>", print_r($order_items[$item->product_id]['total'], 1), "</pre>";
//echo "<pre>", print_r("WOOc total tax item", 1), "</pre>";
//echo "<pre>", print_r($order_items[$item->product_id]['total_tax'], 1), "</pre>";
//echo "<pre>", print_r("WOOC total shipping with tax", 1), "</pre>";
//echo "<pre>", print_r($total_shipping, 1), "</pre>";
//echo "<pre>", print_r("WOOC total", 1), "</pre>";
//echo "<pre>", print_r($mp_amount, 1), "</pre>";
//echo "<pre>", print_r("---------------------------------------------------", 1), "</pre>";
//echo "<pre>", print_r("mp_fees = ".$mp_amount. " - ".$total_vendor, 1), "</pre>";
//echo "<pre>", print_r($mp_fees, 1), "</pre>";


						$transfer_to_return = array();
						$transfer_to_return['order_id']				= $order_id;
						$transfer_to_return['mp_transaction_id']	= $mp_transaction_id;
						$transfer_to_return['wp_user_id']			= $wp_user_id;
						$transfer_to_return['vendor_id']			= $item->vendor_id;
						$transfer_to_return['mp_amount']			= $mp_amount;
						$transfer_to_return['mp_fees']				= $mp_fees;
						$transfer_to_return['mp_currency']			= $mp_currency;
						$transfer_to_return['item_id']				= $item_id;

						/** To return **/
						$transfers_to_return[] = $transfer_to_return;
						
						/** End first loop **/
						$order_used = false;
							
					} //endforeach
					
				} //endif ($items && count($items)>0)
				
			} else {	//$test_version
				
				/** Legacy code for WC-Vendors versions < 2.0 (not maintained) **/
			
				/** Get due commissions from WC-Vendors **/
				$dues  = WCV_Vendors::get_vendor_dues_from_order( $order, false );
				foreach ( $dues as $vendor_id => $lines ) {
					if( 1 == $vendor_id )
						continue;

					foreach( $lines as $item_id => $details ) {

						$mp_fees    = $dues[1][$item_id]['total'];
						$mp_amount     = $details['total'] + $mp_fees;    // This will be DebitedFunds, so it includes the fees

						$transfer_to_return = array();
						$transfer_to_return['order_id'] = $order_id;
						$transfer_to_return['mp_transaction_id'] = $mp_transaction_id;
						$transfer_to_return['wp_user_id'] = $wp_user_id;
						$transfer_to_return['vendor_id'] = $vendor_id;
						$transfer_to_return['mp_amount'] = $mp_amount;
						$transfer_to_return['mp_fees'] = $mp_fees;
						$transfer_to_return['mp_currency'] = $mp_currency;
						$transfer_to_return['item_id'] = $item_id;

						$transfers_to_return[] = $transfer_to_return;
						
					} //endforeach $lines
					
				} //endforeach $dues
				
			} //endif $test_version
			
		} //endif class_exists('WCV_Vendors')
		
//echo "<pre>", print_r("COMPLETE LIST ------------------------ ", 1), "</pre>";
//echo "<pre>", print_r($transfers_to_return, 1), "</pre>";		
//die("STOP");
        return $transfers_to_return;
    }
	   	
    /**
     * Do wallet transactions when an order gets completed
     *
     * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/transfer.php
     *
     * @param int $order_id
     *
     */
    public function on_order_completed( $order_id ) {
		
        if( $mp_transfers = get_post_meta( $order_id, 'mp_transfers', true ) ){
            return false;    // The wallet transfer has already been done
		}
       
        if( !$mp_transaction_id = get_post_meta( $order_id, 'mp_transaction_id', true ) ){
            return false;
		}
       
        if( $mp_success_transaction_id = get_post_meta( $order_id, 'mp_success_transaction_id', true ) ){
            $mp_transaction_id = $mp_success_transaction_id;
		}
       
		/** If we have pre-authorization, we need to get the payin id for the transfer **/
		if( $preauth_id = get_post_meta( $order_id, 'preauthorization_id', true ) ) {
			
			/** Get data of the pre-authorization transfer from MANGOPAY **/
			$pre_auth_data = $this->mp->get_pre_authorization_data_by_id($preauth_id);
			
			/** If the payin ID exists, use it as a MP transaction ID **/
			if(isset($pre_auth_data->PayInId)){
				$mp_transaction_id = $pre_auth_data->PayInId;
			}
		}
		
        $order = new WC_Order( $order_id );
        $order_data = $order->get_data();
        
        $wp_user_id = $order_data['customer_id'];//$order->customer_user;
        $mp_currency = $order_data['currency'];//$order->order_currency;
       
        $mp_transfers = array();
        $mp_instapays = array();
       
        /*
         * grid
         * array(
         * - $order_id
         * - $mp_transaction_id
         * - $wp_user_id
         * - $vendor_id
         * - $mp_amount
         * - $mp_fees
         * - $mp_currency
         * )
         */
        $list_of_transfers = $this->get_all_wallet_trans(
            $order,                    // WC_Order object
            $order_id,
            $mp_transaction_id,
            $wp_user_id,
            $mp_currency
        );
		
        $list_of_transfers = apply_filters(
            'mangopay_order_complete_list_wallet_transfert',
            $list_of_transfers,
            $order,
            $mp_transaction_id
        );	
		
        /** Perform the wallet transfers **/
        if( count( $list_of_transfers ) > 0 ) {
            foreach( $list_of_transfers as $transfer ){
				
				/** Do not try to perform a wallet transfer when the amount is zero or negative **/
				if( floatval( $transfer['mp_amount'] ) <= 0 || floatval( $transfer['mp_fees'] ) < 0 ){
					continue;
				}

                $transfer_result = $this->mp->wallet_trans(
                    $transfer['order_id'],
                    $transfer['mp_transaction_id'],
                    $transfer['wp_user_id'],
                    $transfer['vendor_id'],
                    $transfer['mp_amount'],
                    $transfer['mp_fees'],
                    $transfer['mp_currency']
				);
                $mp_transfers[] = $transfer_result;		
				
				//$transfer['item_id'] is the product id in the order
				
                /**
                 * WV "instapay" feature: Instantly pay vendors their commission when an order is made
                 * @see WV: wp-plugins/wc-vendors/classes/gateways/PayPal_AdvPayments:L302&L126...
                 *
                 */
                $instapay_success = true;
                if( $this->instapay && 'SUCCEEDED' == $transfer_result->Status) {

                    /** We store a different mp_account_id for production and sandbox environments **/
                    $umeta_key = 'mp_account_id';
                    if( !$this->mp->is_production() )
                        $umeta_key .= '_sandbox';

                    if( $mp_account_id = get_user_meta( $transfer['vendor_id'], $umeta_key, true ) ) {

						$commission_row = $this->mp->get_commission_row_by_orderid_and_wpuser($transfer['order_id'],$transfer['vendor_id']);
						if($commission_row){
							$amount = $this->mp->payout_calcul_amount($commission_row->id);
						}		
						if(!$amount){
							$instapay_success = false; 
						}
						
                        $payout_result = $this->mp->payout(
                            $transfer['vendor_id'],
                            $mp_account_id, //Bank account: PayOut->MeanOfPaymentDetails->BankAccountId
                            $order_id,
                            $transfer['mp_currency'],
                            $amount, //$transfer_result->CreditedFunds->Amount/100,    //$amount
                            0                                                //$fees
                        );
						
                        if(
                            isset( $payout_result->Status ) &&
                            ( 'SUCCEEDED' == $payout_result->Status || 'CREATED' == $payout_result->Status )
                        ) {		
                            $this->set_commission_paid( $commission_row->id );
                        } else {						
                            $instapay_success = false;               
                        }							
                        $mp_instapays[] = $payout_result;

                    } else {													
                        $instapay_success = false;
                        $mp_instapays[] = 'No mp_account_id';
                    }
                } //endif instapay
				
                if( $this->instapay && $instapay_success && class_exists('WCV_Commission') ){
                    WCV_Commission::set_order_commission_paid( $order_id );
                }
            } //endforeach $list_of_transfers
			
        } //endif there are a transfers to do						       
        update_post_meta( $order_id, 'mp_transfers', $mp_transfers );
       
        if( $this->instapay ){								
            update_post_meta( $order_id, 'mp_instapays', $mp_instapays );
        }
    }

    /**
     * Adds "refuse item" button on vendor dashboard order list (NOT USED)
     *
     * @param unknown $output
     * @param unknown $item_meta_o
     */
    public function refuse_item_button( $output, $item_meta_o ) {

        if( !isset( $this->options['per_item_wf'] ) || !$this->options['per_item_wf']=='yes' )
            return $output;

        if( class_exists( 'WC_Vendors' ) ) {
            $vendor_dashboard_page = WC_Vendors::$pv_options->get_option( 'vendor_dashboard_page' );
            if ( is_admin() || !is_page( $vendor_dashboard_page ) )
                return $output;
        }
       
        //echo '<pre>'; var_dump( $this->_current_order );    echo '</pre>';    //Debug
        //echo '<pre>'; var_dump( $item_meta_o->meta );    echo '</pre>';    //Debug
       
        $order_id = $this->_current_order->id;
        $product_id = $item_meta_o->meta['_product_id'][0];
        $url = wp_nonce_url( '?mp_refuse&order_id=' . $order_id . '&product_id=' . $product_id );
        $output .= '<a href="' . $url . '" class="mp_refuse_button">';
        $output .= __( 'Refuse this item', 'mangopay' );
        $output .= '</a>';
        return $output;
    }
	
	/**
	 * 
	 * @param type $order_actions
	 * @param type $order
	 * @return type
	 */
    public function record_current_order( $order_actions, $order ) {
        $this->_current_order = $order;
        return $order_actions;
    }
       
    /**
     * Check if a wp_user_id is a vendor
     *
     * @param int $wp_user_id
     * @return boolean
     *
     * Public because shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function is_vendor( $wp_user_id ) {
        $is_vendor = false;
        $wp_userdata = get_userdata( $wp_user_id );
        $vendor_role = apply_filters( 'mangopay_vendor_role', 'vendor' );		
        if(
			(!empty($wp_userdata->roles) && isset( $wp_userdata->roles[$vendor_role] ) )
			|| (is_array($wp_userdata->roles) && in_array( $vendor_role, $wp_userdata->roles , true )) ||
            user_can( $wp_user_id, $vendor_role )
        ) {
            $is_vendor = true;
        }
		
        return $is_vendor;
    }
   
    /**
     * Payline form template shortcode
     * https://docs.mangopay.com/guide/customising-the-design
     */
    public function payform_shortcode( $html ) {

		$token = '';
		if(isset($_GET['token'])){
			$token = strip_tags($_GET['token']);
		}
		
		if($this->mp->is_production()){
			$js_link = 'https://payment.payline.com/scripts/widget-min.js';
			$css_link = 'https://payment.payline.com/styles/widget-min.css';
		}else{
			$js_link = 'https://homologation-payment.payline.com/scripts/widget-min.js';
			$css_link = 'https://homologation-payment.payline.com/styles/widget-min.css';
		}
		
		$html = '';
		$html.= '<script src="'.$js_link.'"> </script>';
		$html.= '<link href="'.$css_link.'" rel="stylesheet" />'
			. '<script>  
			jQuery(document).on("click","#pl-container-lightbox-close",function(){
				window.history.back();
			});
			</script>';
		$html.= '<div id="PaylineWidget" data-token="'.$token.'"></div>';
		
		return $html;
    }
   
    /**
     * Check that date conforms to expected date format
     * @see: http://stackoverflow.com/questions/19271381/correctly-determine-if-date-string-is-a-valid-date-in-that-format
     *
     * @param string $date
     * @return boolean
     *
     * Public because shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function convertDate( $date, $format=null ) {

        if( !$format )
            $format = $this->supported_format( get_option( 'date_format' ) );
   
        if( preg_match( '/F/', $format ) && function_exists( 'strptime' ) ) {
   
            /** Convert date format to strftime format */
            $format = preg_replace( '/j/', '%d', $format );
            $format = preg_replace( '/F/', '%B', $format );
            $format = preg_replace( '/Y/', '%Y', $format );
            $format = preg_replace( '/,\s*/', ' ', $format );
            $date = preg_replace( '/,\s*/', ' ', $date );
           
            setlocale( LC_TIME, get_locale() );
            do_action('mwc_set_locale_date_validation',get_locale());
           
            $d = strptime( $date, $format );
            if( false === $d )    // Fix problem with accentuated month names on some systems
                $d = strptime( utf8_decode( $date ), $format );
           
            /* Debug *
            echo '<div class="debug" style="background:#fff">';
            echo '<strong>Debug date:</strong><br/>';
            echo 'Original date: ' . $date . '<br/>';
            echo 'Date format: ' . get_option( 'date_format' ) . '<br/>';
            echo 'strftime format: ' . $format . '<br/>';
            echo 'get_locale(): ' . get_locale() . '<br/>';
            echo 'strftime( \'' . $format . '\' ): ' . strftime( $format ) . '<br/>';
            echo 'strptime():<br/><pre>';
            var_dump( $d );
            echo '</pre>';
            echo 'checkdate:<br/>';
            var_dump( checkdate( $d['tm_mon']+1, $d['tm_mday'], 1900+$d['tm_year'] ) );
            echo '</div>';
            /* */
               
            if( !$d )
                return false;
               
            return
            1900+$d['tm_year'] . '-' .
            sprintf( '%02d', $d['tm_mon']+1 ) . '-' .
            sprintf( '%02d', $d['tm_mday'] );
               
        } else if(preg_match( '/S/', $format ) && function_exists( 'strptime' )){
			
			$formated = date_parse_from_format($format,$date);
			if(empty($formated['year']) || empty($formated['month']) || empty($formated['day'])){
				return false;
			}			
			return $formated['year'].'-'.sprintf("%02d", $formated['month']).'-'.sprintf("%02d", $formated['day']);
			
		}else{   
            $d = DateTime::createFromFormat( $format, $date );
   
            if( !$d )
                return false;
   
            return $d->format( 'Y-m-d' );
        }
    }
   
    /**
     * If the date format is not properly supported at system level
     * (if textual dates cannot be translated back and forth using the locale settings),
     * this will replace textual month names with month numbers in the date format
     *
     * @param string $date_format
     * @return string $date_format
     *
     * Public because shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function supported_format( $date_format ) {
        if( date( 'Y-m-d' ) == $this->convertDate( date_i18n( get_option( 'date_format' ), time() ), get_option( 'date_format' ) ) )
            return $date_format;
       
        return preg_replace( '/F/', 'm', $date_format );
    }
   
    /**
     * Checks that date is a valid Gregorian calendar date
     * Uses the yyyy-mm-dd format as input
     *
     * @param string $date    // yyyy-mm-dd
     * @return boolean
     *
     * Public because shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function validateDate( $date ) {
   
        // echo 'validateDate<br/>';    //Debug
        // var_dump( $date );            //Debug
   
        if( !preg_match( '/^(\d{4,4})\-(\d{2,2})\-(\d{2,2})$/', $date, $matches ) )
            return false;
   
        if( !wp_checkdate( $matches[2], $matches[3], $matches[1], $date ) )
            return false;
   
        return true;
    }
   
    /**
     * Sets-up JS initilization parameters for jQ-ui-Datepicker localization
     *
     * @see: http://www.renegadetechconsulting.com/tutorials/jquery-datepicker-and-wordpress-i18n
     *
     * Public because shared with mangopayWCAdmin. TODO: refactor
     *
     */
    public function localize_datepicker() {
        global $wp_locale;
        $aryArgs = array(
                'showButtonPanel'    => true,
                'closeText'         => __( 'Done', 'mangopay' ),
                'currentText'       => __( 'Today', 'mangopay' ),
                'monthNames'        => array_values( $wp_locale->month ),
                'monthNamesShort'   => array_values( $wp_locale->month_abbrev ),
                'monthStatus'       => __( 'Show a different month', 'mangopay' ),
                'dayNames'          => array_values( $wp_locale->weekday ),
                'dayNamesShort'     => array_values( $wp_locale->weekday_abbrev ),
                'dayNamesMin'       => array_values( $wp_locale->weekday_initial ),
                // set the date format to match the WP general date settings
                'dateFormat'        => $this->date_format_php_to_js( $this->supported_format( get_option( 'date_format' ) ) ),
                // get the start of week from WP general setting
                'firstDay'          => get_option( 'start_of_week' ),
                // is Right to left language? default is false
                'isRTL'             => $wp_locale->is_rtl(),
                'changeYear'        => true,
                'yearRange'            => (date('Y')-120) . ':' . date('Y'),
                'defaultDate'        => -( 365 * 29 )
        );
        wp_localize_script( 'jquery-ui-datepicker', 'datepickerL10n', $aryArgs );
    }
   
    /**
     * This tries to convert allowed default WP date formats to what jquery-ui-datepicker expects
     * @see:https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
     *
     * @param string $sFormat
     * @return string
     *
     */
    private function date_format_php_to_js( $sFormat ) {
        switch( $sFormat ) {
            //Predefined WP date formats
            case 'F j, Y':
                return( 'MM dd, yy' );
                break;
            case 'F js, Y':
                return( 'MM dd, yy' );
                break;				
            case 'Y/m/d':
                return( 'yy/mm/dd' );
                break;
            case 'm/d/Y':
                return( 'mm/dd/yy' );
                break;
            case 'd/m/Y':
                return( 'dd/mm/yy' );
                break;
            default:
                $jsFormat = preg_replace( '/F/', 'MM', $sFormat );
				$jsFormat = preg_replace( '/S/', '',   $jsFormat );
                $jsFormat = preg_replace( '/d/', 'dd', $jsFormat );
                $jsFormat = preg_replace( '/j/', 'dd', $jsFormat );
                $jsFormat = preg_replace( '/Y/', 'yy', $jsFormat );
                $jsFormat = preg_replace( '/m/', 'mm', $jsFormat );
				$jsFormat = str_replace('  ', ' ',$jsFormat);				
                return $jsFormat;
        }
    }
   
    /**
     * API key (passphrase) security
     *
     */
    public function encrypt_passphrase( $new_options, $old_options ) {

        if( isset( $new_options['sand_passphrase'] ) && preg_match( '/^\*+$/', $new_options['sand_passphrase'] ) )
            $new_options['sand_passphrase'] = $old_options['sand_passphrase'];
       
        if( isset( $new_options['prod_passphrase'] ) && preg_match( '/^\*+$/', $new_options['prod_passphrase'] ) )
            $new_options['prod_passphrase'] = $old_options['prod_passphrase'];

		if( !function_exists("mcrypt_encrypt") && !function_exists('openssl_encrypt') ) {
			return $new_options;
		}
		
        if( isset( $new_options['sand_passphrase'] ) && $new_options['sand_passphrase'] )
            $new_options['sand_passphrase'] = $this->encrypt( $new_options['sand_passphrase'] );
       
        if( isset( $new_options['sand_passphrase'] ) && $new_options['sand_passphrase']==='' )
            $new_options['sand_passphrase'] = '';
       
        if( isset( $new_options['prod_passphrase'] ) && $new_options['prod_passphrase'] )
            $new_options['prod_passphrase'] = $this->encrypt( $new_options['prod_passphrase'] );
       
        if( isset( $new_options['prod_passphrase'] ) && $new_options['prod_passphrase']==='' )
            $new_options['prod_passphrase'] = '';

        return $new_options;
    }
    public function decrypt_passphrase( $options ) {

    	if( !function_exists("mcrypt_encrypt") && !function_exists('openssl_encrypt') ) {
			return $options;
		}

        if( isset( $options['sand_passphrase'] ) && $options['sand_passphrase'] )
            $options['sand_passphrase'] = $this->decrypt( $options['sand_passphrase'] );
       
        if( isset( $options['prod_passphrase'] ) && $options['prod_passphrase'] )
            $options['prod_passphrase'] = $this->decrypt( $options['prod_passphrase'] );

        return $options;
    }
	private function encrypt( $data ) {

		$keyfile = dirname( $this->mp->get_tmp_dir() ) . '/' . mangopayWCConfig::KEY_FILE_NAME;
		if( !file_exists( $keyfile) ) {
			$key = substr( str_shuffle(MD5(microtime())), 0, 16 );
			$file_content = '<?php header("HTTP/1.0 404 Not Found"); echo "File not found."; exit; //' . $key . ' ?>';
			file_put_contents( $keyfile, $file_content );
		} else {
			$file_content = file_get_contents( $keyfile );
			if( preg_match( '|//(\w+)|', $file_content, $matches ) ) {
				$key = $matches[1];
			} else {
				return $data;
			}
		}

		if(function_exists("openssl_encrypt")) {

			$ivlen = openssl_cipher_iv_length($cipher="AES-128-CBC");
			$iv = openssl_random_pseudo_bytes($ivlen);
			$ciphertext_raw = openssl_encrypt($data, $cipher, $key, $options=OPENSSL_RAW_DATA, $iv);
			$hmac = hash_hmac('sha256', $ciphertext_raw, $key, $as_binary=true);
			$ciphertext = $iv.$hmac.$ciphertext_raw ;

		} else {
			$iv_size = mcrypt_get_iv_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC );
			$iv = mcrypt_create_iv( $iv_size, MCRYPT_RAND );
			$ciphertext = mcrypt_encrypt(
				MCRYPT_RIJNDAEL_128, 
				$key, 
				$data, 
				MCRYPT_MODE_CBC, 
				$iv
			);
			$ciphertext = $iv . $ciphertext;
		}

		$ciphertext_base64 = base64_encode($ciphertext);

		return $ciphertext_base64;
	}
	private function decrypt( $data ) {

		$keyfile = dirname( $this->mp->get_tmp_dir() ) . '/' . mangopayWCConfig::KEY_FILE_NAME;
		if( !file_exists( $keyfile) ) {
			return $data;
		}
		$file_content = file_get_contents( $keyfile );
		if( preg_match( '|//(\w+)|', $file_content, $matches ) ) {
			$key = $matches[1];
		} else {
			return $data;
		}
		$plaintext_dec = '';
		
		if(function_exists("openssl_encrypt")) {
  
			$c = base64_decode($data);
			$ivlen = openssl_cipher_iv_length($cipher="AES-128-CBC");
			$iv = substr($c, 0, $ivlen);
			$hmac = substr($c, $ivlen, $sha2len=32);
			$ciphertext_raw = substr($c, $ivlen+$sha2len);
			$original_plaintext = openssl_decrypt($ciphertext_raw, $cipher, $key, $options=OPENSSL_RAW_DATA, $iv);
			$calcmac = hash_hmac('sha256', $ciphertext_raw, $key, $as_binary=true);
			if (hash_equals($hmac, $calcmac))//PHP 5.6+ timing attack safe comparison
			{
				$plaintext_dec = $original_plaintext;
			}
		} else {

			$ciphertext_dec = base64_decode( $data );   
			$iv_size = mcrypt_get_iv_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC );
			$iv_dec = substr($ciphertext_dec, 0, $iv_size);
			$ciphertext_dec = substr($ciphertext_dec, $iv_size);
			$plaintext_dec = mcrypt_decrypt(
				MCRYPT_RIJNDAEL_128, 
				$key,
				$ciphertext_dec, 
				MCRYPT_MODE_CBC, 
				$iv_dec
			);
			$plaintext_dec = str_replace("\0", "", $plaintext_dec);
		}
		return $plaintext_dec;
	}
   
    /**
     * Record single commission as 'paid' in WV's custom commission table
     * @see /plugins/wc-vendors/classes/class-commission.php
     *
     */
    public function set_commission_paid( $pv_commission_id ) {
        global $wpdb;
   
        $table_name = $wpdb->prefix . mangopayWCConfig::WV_TABLE_NAME;
   
        $query  = "UPDATE `{$table_name}` SET `status` = 'paid' WHERE id=%d";
        $query = $wpdb->prepare( $query, $pv_commission_id );
        $result = $wpdb->query( $query );
   
        return $result;
    }
   
    function mangopay_form_field( $key, $args, $value = null ) {
        $defaults = array(
            'type'              => 'text',
            'label'             => '',
            'description'       => '',
            'placeholder'       => '',
            'maxlength'         => false,
            'required'          => false,
            'autocomplete'      => false,
            'id'                => $key,
            'class'             => array(),
            'label_class'       => array(),
            'input_class'       => array(),
            'return'            => false,
            'options'           => array(),
            'custom_attributes' => array(),
            'validate'          => array(),
            'default'           => '',
            'autofocus'         => '',
            'priority'          => '',
        );
        $args = wp_parse_args( $args, $defaults );
        $args = apply_filters( 'woocommerce_form_field_args', $args, $key, $value );
        if ( $args['required'] ) {
            $args['class'][] = 'validate-required';
            $required = ' <abbr class="required" title="' . esc_attr__( 'required', 'woocommerce' ) . '">*</abbr>';
        } else {
            $required = '';
        }
        if ( is_string( $args['label_class'] ) ) {
            $args['label_class'] = array( $args['label_class'] );
        }
        if ( is_null( $value ) ) {
            $value = $args['default'];
        }
        // Custom attribute handling
        $custom_attributes         = array();
        $args['custom_attributes'] = array_filter( (array) $args['custom_attributes'] );
        if ( $args['maxlength'] ) {
            $args['custom_attributes']['maxlength'] = absint( $args['maxlength'] );
        }
        if ( ! empty( $args['autocomplete'] ) ) {
            $args['custom_attributes']['autocomplete'] = $args['autocomplete'];
        }
        if ( true === $args['autofocus'] ) {
            $args['custom_attributes']['autofocus'] = 'autofocus';
        }
        if ( ! empty( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ) {
            foreach ( $args['custom_attributes'] as $attribute => $attribute_value ) {
                $custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
            }
        }
        if ( ! empty( $args['validate'] ) ) {
            foreach ( $args['validate'] as $validate ) {
                $args['class'][] = 'validate-' . $validate;
            }
        }
        $field           = '';
        $label_id        = $args['id'];
        $sort            = $args['priority'] ? $args['priority'] : '';
        $field_container = '<p class="form-row %1$s" id="%2$s" data-sort="' . esc_attr( $sort ) . '">%3$s</p>';
        switch ( $args['type'] ) {
           
            case 'country_nop' :
                //$key = 'billing_country';
                $id =  esc_attr( $args['id'] );//vendor_account_country vendor_account_region
                //$id = 'billing_country';
                $name = esc_attr( $key );
                //$name = 'billing_country';
                $countries = 'shipping_country' === $key ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();
                if ( 1 === sizeof( $countries ) ) {
                    $field .= '<strong>' . current( array_values( $countries ) ) . '</strong>';
                    $field .= '<input type="hidden" name="' . $name . '" id="' . $id . '" value="' . current( array_keys( $countries ) ) . '" ' . implode( ' ', $custom_attributes ) . ' class="country_to_state" />';
                } else {
                    $field = '<select name="' .$name . '" id="' . $id . '" class="country_to_state country_select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . '>' . '<option value="">' . __(  'Select a country...', 'mangopay'  ) . '</option>';
                    foreach ( $countries as $ckey => $cvalue ) {
                        $field .= '<option value="' . esc_attr( $ckey ) . '" ' . selected( $value, $ckey, false ) . '>' . $cvalue . '</option>';
                    }
                    $field .= '</select>';
                    $field .= '<noscript><input type="submit" name="woocommerce_checkout_update_totals" value="' . esc_attr__( 'Update country', 'woocommerce' ) . '" /></noscript>';
                }
                break;
               
            case 'country' :
                $countries = 'shipping_country' === $key ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();
                if ( 1 === sizeof( $countries ) ) {
                    $field .= '<strong>' . current( array_values( $countries ) ) . '</strong>';
                    $field .= '<input type="hidden" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . current( array_keys( $countries ) ) . '" ' . implode( ' ', $custom_attributes ) . ' class="country_to_state" />';
                } else {
                    $field = '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" class="country_to_state country_select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . '>' . '<option value="">' . __(  'Select a country...', 'mangopay'  ) . '</option>';
                    foreach ( $countries as $ckey => $cvalue ) {
                        $field .= '<option value="' . esc_attr( $ckey ) . '" ' . selected( $value, $ckey, false ) . '>' . $cvalue . '</option>';
                    }
                    $field .= '</select>';
                    $field .= '<noscript><input type="submit" name="woocommerce_checkout_update_totals" value="' . esc_attr__( 'Update country', 'woocommerce' ) . '" /></noscript>';
                }
            break;
               
            case 'state' :
                /* Get Country */
               
                $country_key = 'billing_state' === $key ? 'billing_country' : 'shipping_country';
                $current_cc  = WC()->checkout->get_value( $country_key );
                $id = esc_attr( $args['id'] );
                $name = esc_attr( $key );
                if(isset($args['countrykey'])){
                    if(isset($value)){
                        $current_cc = $value;
                    }else{
                        $current_cc = get_user_meta( $args['userid'], $args['countrykey'] , true );//'vendor_account_country'
                    }
                   
                }
                $states = WC()->countries->get_states( $current_cc );
                               
                if ( is_array( $states ) && empty( $states ) ) {
                    $field_container = '<p class="form-row %1$s" id="%2$s" style="display: none">%3$s</p>';
                    $field .= '<input type="hidden" class="hidden" name="' . $name . '" id="' . $id . '" value="" ' . implode( ' ', $custom_attributes ) . ' placeholder="' . esc_attr( $args['placeholder'] ) . '" />';
                } elseif ( is_array( $states ) ) {
                    $field .= '<select name="' .$name . '" id="' . $id . '" class="state_select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '">
                        <option value="">' . __( 'Select a state&hellip;', 'woocommerce' ) . '</option>';
                    foreach ( $states as $ckey => $cvalue ) {
                        $field .= '<option value="' . esc_attr( $ckey ) . '" ' . selected( $value, $ckey, false ) . '>' . $cvalue . '</option>';
                    }
                    $field .= '</select>';
                } else {
                    $field .= '<input type="text" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( $value ) . '"  placeholder="' . esc_attr( $args['placeholder'] ) . '" name="' . esc_attr( $key ) . '" id="' . $id . '" ' . implode( ' ', $custom_attributes ) . ' />';
                }
                break;
            case 'textarea' :
                $field .= '<textarea name="' . esc_attr( $key ) . '" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '" ' . ( empty( $args['custom_attributes']['rows'] ) ? ' rows="2"' : '' ) . ( empty( $args['custom_attributes']['cols'] ) ? ' cols="5"' : '' ) . implode( ' ', $custom_attributes ) . '>' . esc_textarea( $value ) . '</textarea>';
                break;
            case 'checkbox' :
                $field = '<label class="checkbox ' . implode( ' ', $args['label_class'] ) . '" ' . implode( ' ', $custom_attributes ) . '>
                        <input type="' . esc_attr( $args['type'] ) . '" class="input-checkbox ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" value="1" ' . checked( $value, 1, false ) . ' /> '
                         . $args['label'] . $required . '</label>';
                break;
            case 'password' :
            case 'text' :
            case 'email' :
            case 'tel' :
            case 'number' :
                $field .= '<input type="' . esc_attr( $args['type'] ) . '" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
                break;
            case 'select' :
                $options = $field = '';
                if ( ! empty( $args['options'] ) ) {
                    foreach ( $args['options'] as $option_key => $option_text ) {
                        if ( '' === $option_key ) {
                            // If we have a blank option, select2 needs a placeholder
                            if ( empty( $args['placeholder'] ) ) {
                                $args['placeholder'] = $option_text ? $option_text : __( 'Choose an option', 'woocommerce' );
                            }
                            $custom_attributes[] = 'data-allow_clear="true"';
                        }
                        $options .= '<option value="' . esc_attr( $option_key ) . '" ' . selected( $value, $option_key, false ) . '>' . esc_attr( $option_text ) . '</option>';
                    }
                    $field .= '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" class="select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '">
                            ' . $options . '
                        </select>';
                }
                break;
            case 'radio' :
                $label_id = current( array_keys( $args['options'] ) );
                if ( ! empty( $args['options'] ) ) {
                    foreach ( $args['options'] as $option_key => $option_text ) {
                        $field .= '<input type="radio" class="input-radio ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( $option_key ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_key ) . '"' . checked( $value, $option_key, false ) . ' />';
                        $field .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_key ) . '" class="radio ' . implode( ' ', $args['label_class'] ) . '">' . $option_text . '</label>';
                    }
                }
                break;
        }
        if ( ! empty( $field ) ) {
            $field_html = '';
            if ( $args['label'] && 'checkbox' != $args['type'] ) {
                $field_html .= '<label for="' . esc_attr( $label_id ) . '" class="' . esc_attr( implode( ' ', $args['label_class'] ) ) . '">' . $args['label'] . $required . '</label>';
            }
            $field_html .= $field;
            if ( $args['description'] ) {
                $field_html .= '<span class="description">' . esc_html( $args['description'] ) . '</span>';
            }
            $container_class = esc_attr( implode( ' ', $args['class'] ) );
            $container_id = esc_attr( $args['id'] ) . '_field';
            $after = ! empty( $args['clear'] ) ? '<div class="clear"></div>' : '';
            $field = sprintf( $field_container, $container_class, $container_id, $field_html ) . $after;
        }
        $field = apply_filters( 'woocommerce_form_field_' . $args['type'], $field, $key, $args, $value );
        if ( $args['return'] ) {
            return $field;
        } else {
            echo $field;
        }
    }
   
    public function custom_woocommerce_add_error( $error ) {
        $phrase_to_look_for = __('Zip','wcvendors');
        if(preg_match('#'.$phrase_to_look_for.'#', $error)){
            $error = '';
        }       
        return $error;
    }
	
	/**
	 * Add VAT to shipping fees our own way
	 * (replaces original calculation of WV which turns out wrong with flat rate shipping fees)
	 * 
	 * @see: get_shipping_due() fromm /wc-vendors/classes/class-shipping.php
	 * 
	 * @param	array	$shipping_costs
	 * @param	int		$order_id
	 * @param	array	$product
	 * @param	type	$author (not used)
	 * @param	int		$product_id
	 * @return	array	$shipping_costs | New shipping feed
	 */
	public function add_vat_to_shipping( $shipping_costs, $order_id, $product, $author, $product_id ){
		
		global $woocommerce;
		
		$method			= '';
		$_product		= wc_get_product( $product[ 'product_id' ] );
		$order			= wc_get_order( $order_id ); 

		if ( $_product && $_product->needs_shipping() && !$_product->is_downloadable() ) {

			/** Get Shipping methods **/ 
			$shipping_methods = $order->get_shipping_methods();

			// TODO: Currently this only allows one shipping method per order, this definitely needs changing
			foreach( $shipping_methods as $shipping_method ) {
				$method = $shipping_method['method_id'];
				break;
			}
			
			/** Per Product Shipping **/
			if ( preg_match( '#flat_rate#', $method ) ) {
				
				$shipping_costs['amount'] = $order->get_total_shipping();
				$shipping_costs['tax'] = WCV_Shipping::calculate_shipping_tax( $shipping_costs['amount'], $order ); 
			}
		}
		return $shipping_costs;
	}
	
	/**
	 * If at least one product is "_preautorisation", remove all other payment methods
	 * @global object $woocommerce
	 * @param array $available_gateways
	 * @return array
	 */
	public function remove_payment_methods($available_gateways){
		global $woocommerce;
		$unset = false;
		if(isset($woocommerce->cart->cart_contents)){
			foreach ( $woocommerce->cart->cart_contents as $key => $values ) {

				$product_id = $values['product_id'];
				$preauth = get_post_meta( $product_id, '_preautorisation', true );
				if($preauth === "yes"){
					$unset = true;
				}

			}//end foreach
		}
					
		if ( $unset ){
			/** Remove all methods except **/
			//memorize
			$gateaway = $available_gateways['mangopay'];
			//reset
			$available_gateways = array();
			//set
			$available_gateways['mangopay'] = $gateaway;			
		}
		
		return $available_gateways;		
	}
	
	/**
	 * Woocommerce filter -- Add pre-authorization option in admin for a product
	 * @param array $product_type
	 * @return array
	 */
	public function get_product_type_options_add_preauth( $product_type ) {
		
		/** Do not add this checkbox if card registration is disabled **/
		if( ! ( $wc_settings = get_option( 'woocommerce_mangopay_settings' ) ) ) {
			return $product_type;
		}
		if( empty( $wc_settings['enabled_card_registration'] ) || 'yes' != $wc_settings['enabled_card_registration'] ) {
			return $product_type;
		}
		
		$product_type['preautorisation'] = array(
					'id'            => '_preautorisation',
					'wrapper_class' => 'show_if_simple',
					'label'         => __( 'Pre-authorization', 'mangopay' ),
					'description'   => __( 
						'Products or services that will be subject to a payment pre-authorization (ie deposit). ' .
						'all other payment types will be disabled.', 
						'mangopay'
					),
					'default'       => 'no',
				);		
		return $product_type;
	}	
	
	/**
	 * Save pre-authorization option for a woocommerce product 
	 * (following the virtual/downloadable official exemple)
	 * @param int $post_id
	 * @param array $post
	 * @param bool $update
	 */
	public function product_save_add_preauth_meta( $post_id, $post, $update ) {		
		if(isset($_POST['_preautorisation'])){
			update_post_meta( $post_id, '_preautorisation', "yes");
		}else{
			update_post_meta( $post_id, '_preautorisation', "no");
		}
	}
	
	/**
	 * Get list of orders that have a pre-authorization, by vendor
	 * @param int $vendor_id
	 * @return array
	 */
	public function get_pre_auth_orders_by_vendorid($vendor_id){
		$list_orders = false;
		
		/** Get all products of vendor **/
		$args = array(
			'posts_per_page' => -1,
			'author'    =>  $vendor_id,
			'post_type' => 'product',
			'fields'	=> 'ID'
		);
		$all_products = get_posts( $args );
		
		$list_products = array();
		foreach( $all_products as $product ){
			$list_products[$product->ID] = $product->ID;
		}
		
		/** Get orders limited by products **/
		if(count($list_products)>0){
			$orders = wc_get_orders( array( 'intervention' => 'limitbyproducts', 'list_products' => $list_products ) );
			if(count($orders)>0){
				foreach( $orders as $order ){
					
					/** Get rid of the orders that have more than one vendor **/
					$multivendor = false;
					$author_id = 0;
					foreach ($order->get_items() as $order_item_id => $item_data) {
						$product = $item_data->get_product();
						if(!$product){
							continue;
						}
						
						$product_id = $product->get_id();
						$post_author_id = get_post_field( 'post_author', $product_id );
						if($author_id == 0){
							$author_id = $post_author_id;
						}
						if($post_author_id!=$author_id){
							//more than one
							$multivendor = true;
							break;
						}
					}
					if($multivendor){
						/** Skip this one **/
						continue;
					}
					
					/** Keep only those that are still not captured nor cancelled **/
					$pre_auth_id = get_post_meta($order->get_id(),'preauthorization_id',true);
					if($pre_auth_id){
						
						/** Get data from mangopay **/
						$result = mpAccess::getInstance()->get_preauthorization_by_id($pre_auth_id);
						
						/** If we have data , else ignore **/
						if( !empty( $result['success'] ) ) {
							
							/** Only keep the ones we can capture **/
							if($result['result']->Status == "SUCCEEDED" && $result['result']->PaymentStatus == "WAITING"){
								$list_orders[$order->get_id()] = array('order'=>$order,'data_preauth'=>$result['result']);
							}
						}
					}
				}
			}
		}		
		return $list_orders;
	}
	
	/**
	 * Filter that changes the wc official query to get orders
	 * to filter orders with pre-authorizations
	 * 
	 * @param array $query
	 * @param type $query_vars
	 * @return array
	 */
	public function custom_query_for_orders( $query, $query_vars ) {
		if ( ! empty( $query_vars['intervention'] ) &&  ! empty( $query_vars['list_products'] )) {
						
			$query['include'] = $query_vars['list_products'];
			$query['post_type'] = 'shop_order';
			
			$query['meta_query'][] = array(
				'key'	=> 'preauthorization_id',
				'value' => array(''),
				'compare' => 'NOT IN'
			);
			
			/** Get dates (start end) **/
			if(isset($_POST[ 'start_date' ]) && isset($_POST[ 'end_date' ]) ){
				$query['date_query'] = array(
					array(
						'after'     => $_POST[ 'start_date' ],
						'before'    => $_POST[ 'end_date' ],
						'inclusive' => true,
					)
				);
			}
			/** Get dates (start end), WV Pro version **/
			if(isset($_POST[ '_wcv_order_start_date_input' ]) && isset($_POST[ '_wcv_order_end_date_input' ]) ){
				$query['date_query'] = array(
					array(
						'after'     => $_POST[ '_wcv_order_start_date_input' ],
						'before'    => $_POST[ '_wcv_order_end_date_input' ],
						'inclusive' => true,
					)
				);
			}			
		}
		return $query;
	}
	
	/**
	 * Print the html for the list of orders with pre-authorization 
	 * Code applying to the WC-Vendors Marketplace free plugin (default), 
	 * or to the WC-Vendors Pro plugin, depending on the $pro param:
	 * 
	 * @param bool $pro WV plugin version (true for Pro)
	 */
	public function add_preauth_list_vendor($pro = false){
		
		if($pro == "pro"){
			echo '<table role="grid" class="wcvendors-table wcvendors-table-order wcv-table">';
		}else{
			echo '<h2>';
				echo __( 'Orders with pre-authorization', 'mangopay' );
			echo '</h2>';
			echo '<table class="table table-condensed table-vendor-sales-report">';
		}
		
		echo '<thead>';
			echo '<tr>';
				echo '<th class="product-header">';
					echo __( 'Pre-authorization', 'mangopay' );
				echo '</th>';
			echo '<th class="commission-header">';
				echo __( 'Total', 'wc-vendors' );
			echo '</th>';
			echo '<th class="rate-header">';
				echo __( 'Date', 'wc-vendors' );
			echo '</th>';
			echo '<th class="rate-header">';
				echo __( 'Actions', 'mangopay' );
			echo '</th>';
		echo '</thead>';
		echo '<tbody>';
		
		$user_id = get_current_user_id();		
		$list_preauth_orders = $this->get_pre_auth_orders_by_vendorid($user_id);
		
		if($list_preauth_orders){
			foreach($list_preauth_orders as $order_id=>$data){
				$order = $data['order'];
				$data_preauth = $data['data_preauth'];
				
				$order_date = $order->get_date_created();
				
				echo '<tr id="order-'.$order_id.'" data-order-id="'.$order_id.'">';
					echo '<td>';
						echo $order->get_order_number();
					echo '</td>';
					echo '<td>';
						echo wc_price( $order->get_total() );
					echo '</td>';				
					echo '<td>';
						echo date_i18n( wc_date_format(), strtotime( $order_date ) );
					echo '</td>';
					echo '<td>';
					
						$order = new WC_Order($order_id);
						$mp_user_id = $data_preauth->AuthorId;

						/** This locale is not used right now (optional) **/
						$locale = 'EN';

						/** Capture the pre-authorization by Ajax **/
						echo '<div id="capturebuttondiv_'.$order_id.'" class="capturebuttondiv">';
							echo '<input id="capture_preauth" class="button button-primary capturepreauth" '
								. 'data-PreauthorizationId="'.$data_preauth->Id.'" '
								. 'data-order_id="'.$order_id.'" '
								. 'data-mp_user_id="'.$mp_user_id.'" '
								. 'data-locale="'.$locale.'" '		
								. 'type="button" '
								. 'value="' . __( 'Capture', 'mangopay' ) . '">';
							
							echo '&nbsp;';
							
							/** cancel by ajax the pre authorization **/
							echo '<input id="cancel_preauth_'.$order_id.'" class="button button-primary cancelpreauth" '
								. 'data-PreauthorizationId="'.$data_preauth->Id.'" '
								. 'data-order_id="'.$order_id.'" '
								. 'type="button" '
								. 'value="' . __( 'Cancel', 'mangopay' ) . '">';
						echo '</div>';
					
						echo '<div id="waitingmessage_capture_'.$order_id.'" style="display:none;">';
							echo __("Processing...." ,'mangopay');
						echo '</div>';
						
						echo '<div id="result_capture_captured_'.$order_id.'" style="display:none;">';
							echo __("The pre-authorized payment was captured (ie the deposit was cashed-in).",'mangopay');
						echo '</div>';

						echo '<div id="result_capture_canceled_'.$order_id.'" style="display:none;">';
							echo __("The pre-authorized payment was cancelled (ie the deposit was refunded)." ,'mangopay');
						echo '</div>';
						
					echo '</td>';
				echo '</tr>';
			}
		} else {	//if($list_preauth_orders)
			
			echo '<tr>';
				echo '<td colspan="4" style="text-align:center;">';
					echo __( 'You have no orders during this period.', 'wc-vendors' );
				echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';			
	}
	
	/**
	 * Print the html for the list of orders with pre-authorization 
	 * (code applying to the WC-Vendors Pro plugin)
	 * 
	 */
	public function add_preauth_list_vendor_pro(){
		$this->add_preauth_list_vendor('pro');
	}
	
	/**
	 * Change the shipping value of wcv when we are only on partial pre auth
	 * @param type $shipping_costs
	 * @param type $order_id
	 * @param type $order_item
	 * @param type $author
	 * @param type $product_id
	 * @return type
	 */
	public function partial_preauth_change_shipping_value_wcv($shipping_costs, $order_id, $order_item, $author, $product_id){
		
		/** test if it's a order with partial  **/
		$pre_auth_id = get_post_meta($order_id,'preauthorization_id',true);
		$order = new WC_Order($order_id);
		if($pre_auth_id){
			$who_is_the_first_product = false;
			$multivendor = false;
			$author_id = 0;
			foreach ($order->get_items() as $order_item_id => $item_data) {
					$product = $item_data->get_product();
					$product_id_order = $product->get_id();
					if(false == $who_is_the_first_product){						
						$who_is_the_first_product = $product_id_order;
					}					
					$post_author_id = get_post_field( 'post_author', $product_id_order );
					if($author_id == 0){
						$author_id = $post_author_id;
					}
					if($post_author_id!=$author_id){
						/** More than one **/
						$multivendor = true;
						break;
					}
			}
			
			if(!$multivendor && $who_is_the_first_product==$product_id){
				$shipping_costs['amount'] = 0;
				$shipping_costs['tax'] = 0;
				foreach( $order->get_items( 'shipping' ) as $shipping_id => $shipping_item_obj ){
					$shipping_data = $shipping_item_obj->get_data();
					//$shipping_data_id           = $shipping_data['id'];
					$shipping_costs['amount']	= $shipping_data['total']+$shipping_costs['amount'];
					$shipping_costs['tax']		= $shipping_data['total_tax']+$shipping_costs['tax'];
				}
			}else{
				$shipping_costs['amount'] = 0;
				$shipping_costs['tax'] = 0;
			}
		}
		
		return $shipping_costs;
	}	
	
	/**
	 * Change the option "supports" of woocommerce 
	 * @param type $in_array
	 * @param type $feature
	 * @param type $WC_Payment_Gateway_object
	 * @return type
	 */
	public function mangopay_woocommerce_payment_gateway_supports( $in_array , $feature, $WC_Payment_Gateway_object){
		/* feature named from woocommerce */
		if($feature == 'refunds'){
			/* let the possibility to change  for custom coding */
			$in_array = apply_filters('mangopay_woocommerce_payment_gateway_supports',false, $in_array , $feature, $WC_Payment_Gateway_object);
		}
		return $in_array;
	}	
}
?>