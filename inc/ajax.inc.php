<?php
/**
 * Ajax methods for MANGOPAY WooCommerce Plugin admin
 * 
 */
class mangopayWCAjax {
	
	/** This will store our mpAccess class instance **/
	private $mp;

	/** Ignored items **/
	private $ignored_failed_po		= array();
	private $ignored_refused_kyc	= array();
	
	/**
	 * Class constructor
	 *
	 */
	public function __construct() {
		
		/** Get the stored hidden/ignored items **/
		$this->ignored_failed_po	= get_option( 'mp_ignored_failed_po', array() );
		$this->ignored_refused_kyc	= get_option( 'mp_ignored_refused_kyc', array() );
		
		/** Admin ajax for failed payouts and KYCs dashboard widget **/
		add_action( 'wp_ajax_ignore_mp_failed_po', array( $this, 'ajax_ignore_mp_failed_po' ) );
		//add_action( 'wp_ajax_retry_mp_failed_po', array( $this, 'ajax_retry_mp_failed_po' ) );
		add_action( 'wp_ajax_ignore_mp_refused_kyc', array( $this, 'ajax_ignore_mp_refused_kyc' ) );
		add_action( 'wp_ajax_failed_transaction_widget', array( $this, 'ajax_failed_transaction_widget' ) );
		
		/** Front Ajax calls for pre authorization **/
		add_action( 'wp_ajax_update_list_preauth_cards', array( $this, 'ajax_update_list_preauth_cards' ) );
		add_action( 'wp_ajax_delete_card_list_preauth_cards', array( $this, 'ajax_delete_card_list_preauth_cards' ) );
		add_action( 'wp_ajax_preauth_registercard', array( $this, 'ajax_preauth_registercard' ) );
		add_action( 'wp_ajax_preauth_registercard_update', array( $this, 'ajax_preauth_registercard_update' ) );
		add_action( 'wp_ajax_preauth_capture', array( $this, 'ajax_preauth_capture' ) );
		//add_action( 'wp_ajax_partial_preauth_capture', array( $this, 'ajax_partial_preauth_capture' ) );
		//add_action( 'wp_ajax_preauth_cancel', array( $this, 'ajax_preauth_cancel' ) );
		
		/** UBO **/
		add_action( 'wp_ajax_create_ubo', array( $this, 'ajax_create_ubo' ) );
		add_action( 'wp_ajax_add_ubo_element', array( $this, 'ajax_add_ubo_element' ) );
		add_action( 'wp_ajax_create_ubo_html', array( $this, 'ajax_create_ubo_html' ) );
		add_action( 'wp_ajax_ubo_ask_declaration', array( $this, 'ajax_ubo_ask_declaration' ) );
		
		/** call to check company number **/
		//add_action( 'wp_ajax_check_company_number_patterns', array( $this, 'ajax_check_company_number_patterns' ) );
	}
	
	public function ajax_ubo_ask_declaration(){
		$result = 'error';		
		if(isset($_POST['userid']) && $_POST['userid']!=''
			&& isset($_POST['ubo_declaration_id']) && $_POST['ubo_declaration_id']!=""){
			$userId = $_POST['userid'];
			$uboDeclarationId = $_POST['ubo_declaration_id'];
		}else{
			echo $result;
			wp_die();
		}
		
		try {
			$result = mpAccess::getInstance()->ask_ubo_validation($userId, $uboDeclarationId);
			$result = json_encode($result);
		}catch(MangoPay\Libraries\ResponseException $e) {
//			echo "<pre>", print_r("ResponseException", 1), "</pre>";
//			echo "<pre>", print_r($e, 1), "</pre>";
		} catch(MangoPay\Libraries\Exception $e) {
//			echo "<pre>", print_r("Exception", 1), "</pre>";
//			echo "<pre>", print_r($e, 1), "</pre>";
		}
			
		echo $result;
		wp_die();
	}
	
	public function ajax_create_ubo(){
		$result = array();
		
		try {
			$result = mpAccess::getInstance()->create_ubo_declaration($_POST['userid']);
			//$result = json_encode($result);
		}catch(MangoPay\Libraries\ResponseException $e) {
//			echo "<pre>", print_r("ResponseException", 1), "</pre>";
//			echo "<pre>", print_r($e->GetErrorDetails(), 1), "</pre>";
			$result['error'] = $e->GetErrorDetails()->Message;
		} catch(MangoPay\Libraries\Exception $e) {
//			echo "<pre>", print_r("Exception", 1), "</pre>";
//			echo "<pre>", print_r($e, 1), "</pre>";
			$result['error'] = "Error";
		}	
		
		echo json_encode($result);
		wp_die();
	}
	
	public function ajax_add_ubo_element(){	
		$result = 'nogo';
				
		if(
		$_POST['ubo_mp_id'] != ''
		&& $_POST['ubo_declaration_id'] != ''
		&& $_POST['FirstName'] != ''
		&& $_POST['LastName'] != ''
		&& $_POST['AddressLine1'] != ''		
		&& $_POST['City'] != ''
		&& $_POST['Region'] != ''
		&& $_POST['PostalCode'] != ''
		&& $_POST['ubo_Country_select'] != ''
		&& $_POST['ubo_NationalityCountry_select'] != ''
		&& $_POST['ubo_datetimestamp'] != ''
		&& $_POST['BirthplaceCity'] != ''
		&& $_POST['ubo_BirthplaceCountry_select'] != ''
			){
			
			/** create UBO object **/
			$ubo = new \MangoPay\Ubo();
			$ubo->FirstName = $_POST['FirstName'];
			$ubo->LastName = $_POST['LastName'];
			$ubo->Address = new \MangoPay\Address();
			$ubo->Address->AddressLine1 = $_POST['AddressLine1'];
			$ubo->Address->AddressLine2 = $_POST['AddressLine2'];
			$ubo->Address->City = $_POST['City'];
			$ubo->Address->Region = $_POST['Region'];
			$ubo->Address->PostalCode = $_POST['PostalCode'];
			$ubo->Address->Country = $_POST['ubo_Country_select'];
			$ubo->Nationality = $_POST['ubo_NationalityCountry_select'];
			$ubo->Birthday = (int) $_POST['ubo_datetimestamp']; //accept only 9 digits
			$ubo->Birthplace = new \MangoPay\Birthplace();
			$ubo->Birthplace->City = $_POST['BirthplaceCity'];
			$ubo->Birthplace->Country = $_POST['ubo_BirthplaceCountry_select'];
						
			try {
				if(isset($_POST['ubo_element_id']) && $_POST['ubo_element_id']!=''){
					$ubo->Id = $_POST['ubo_element_id'];
					$result = mpAccess::getInstance()->update_ubo_element($_POST['ubo_mp_id'],$_POST['ubo_declaration_id'],$ubo);
				}else{
					$result = mpAccess::getInstance()->create_ubo_element($_POST['ubo_mp_id'],$_POST['ubo_declaration_id'],$ubo);
				}				
				$result = json_encode($result);
			} catch(MangoPay\Libraries\ResponseException $e) {
//				echo "<pre>", print_r("ResponseException", 1), "</pre>";
//				echo "<pre>", print_r($e, 1), "</pre>";
			} catch(MangoPay\Libraries\Exception $e) {
//				echo "<pre>", print_r("Exception", 1), "</pre>";
//				echo "<pre>", print_r($e, 1), "</pre>";
			}
			
		}
		
		echo $result;
		wp_die();
	}	
	
	public function ajax_create_ubo_html(){
		
		if(!isset($_POST['existing_account_id'])){
			echo __('No account found','mangopay');
			wp_die();
		}
		$existing_account_id = $_POST['existing_account_id'];
		
//		try {
//			$all_ubo_declarations = mpAccess::getInstance()->get_all_ubo_declarations($existing_account_id,null,'DESC');			
//		} catch (Exception $exc) {
//			echo $exc->getTraceAsString();
//		}

		try {
			//sort
			$sorting_o = new \MangoPay\Sorting();
			$sorting_o->AddField('CreationDate', 'ASC');	
			//pagination
			$pagination_o = new \MangoPay\Pagination(1,99);
			//get all
			$all_ubo_declarations = mpAccess::getInstance()->get_all_ubo_declarations($existing_account_id,$pagination_o,$sorting_o);
		}catch(MangoPay\Libraries\ResponseException $e) {
//			echo "<pre>", print_r("ResponseException", 1), "</pre>";
//			echo "<pre>", print_r($e->GetErrorDetails(), 1), "</pre>";
		} catch(MangoPay\Libraries\Exception $e) {
//			echo "<pre>", print_r("Exception", 1), "</pre>";
//			echo "<pre>", print_r($e, 1), "</pre>";
		}		
		
		/** translations **/
		$mangopay_key_text = array(
			'REFUSED'=>__('Refused','mangopay'),
			'VALIDATION_ASKED'=>__('Validation asked','mangopay'),
			'CREATED'=>__('Created','mangopay'),
			'VALIDATED'=>__('Validated','mangopay'),
			'INCOMPLETE'=>__('Incomplete','mangopay'),
			'WRONG_UBO_INFORMATION'=>__('Wrong UBO information','mangopay'),
			'MISSING_UBO'=>__('Missing UBO','mangopay'),
			'UBO_IDENTITY_NEEDED'=>__('UBO identity needed','mangopay'),
			'DOCUMENTS_NEEDED'=>__('Documents needed','mangopay'),
			'SHAREHOLDERS_DECLARATION_NEEDED'=>__('Shareholders declaration needed','mangopay'),
			'ORGANIZATION_CHART_NEEDED'=>__('Organization chart needed','mangopay'),
			'SPECIFIC_CASE'=>__('Specific case','mangopay'),
			'DECLARATION_DO_NOT_MATCH_UBO_INFORMATION'=>__('Declaration do not match UBO information','mangopay')
		);
		
		$html.= '<input type="hidden" id="showbutton_text" value="'.__('Show details','mangopay').'">';
		$html.= '<input type="hidden" id="hidebutton_text" value="'.__('Hide details','mangopay').'">';
		
		/** print all ubo declaration and ubo elements **/
		$ubo_print_create_button = true;
		if(count($all_ubo_declarations)>0){
			$html.= '<table class="ubo_table_list_declarations">';
				$html.= '<thead class="ubo_thead_list_declarations">';
					$html.= '<tr>';
						$html.= '<td>';
							$html.= __('Date','mangopay');
						$html.= '</td>';
						$html.= '<td>';
							$html.= __('ID','mangopay');
						$html.= '</td>';
						$html.= '<td>';
							$html.= __('Status','mangopay');
						$html.= '</td>';
						$html.= '<td>';
							$html.= __('Details','mangopay');
						$html.= '</td>';							
					$html.= '</tr>';
				$html.= '</thead>';

			$wp_date_format = get_option('date_format');
			$created_ubo_id = false;
			$created_ubo_button = false;
			$at_least_one_ubo = false;
			foreach($all_ubo_declarations as $ubo_declaration){
								
				/** if at least one has a different status than refused, we do not let the user ask for another declaration**/
				if($ubo_declaration->Status != 'REFUSED'
					&& $ubo_declaration->Status != 'VALIDATED'){
					$ubo_print_create_button = false;
				}
				/** if one of this status we add the button  **/
				if($ubo_declaration->Status == 'CREATED'
					|| $ubo_declaration->Status == "INCOMPLETE"){
					$created_ubo_id = $ubo_declaration->Id;
					$created_ubo_button = true;
					if(count($ubo_declaration->Ubos)>=4){
						$created_ubo_button = false;
					}
					if(count($ubo_declaration->Ubos)>=1){
						$at_least_one_ubo = true;
					}
				}

				$html.= '<tr>';
					$html.= '<td class="date_ubodeclaration_td">';						
						$html.= date_i18n($wp_date_format,$ubo_declaration->CreationDate);
					$html.= '</td>';
					$html.= '<td>';
						$html.= $ubo_declaration->Id;
					$html.= '</td>';
					$html.= '<td>';
						if(isset($mangopay_key_text[$ubo_declaration->Status])){
							$html.= $mangopay_key_text[$ubo_declaration->Status];
						}else{
							$html.= __($ubo_declaration->Status,'mangopay');
						}
						if($ubo_declaration->Status == 'REFUSED'){
							$html.= '<br><span class="ubo_refused_status_Reason">';
							if(isset($mangopay_key_text[$ubo_declaration->Reason])){
								$html.= $mangopay_key_text[$ubo_declaration->Reason];
							}else{
								$html.= __($ubo_declaration->Reason,'mangopay');
							}
							$html.= '</span>';
						}
						if($ubo_declaration->Status == 'INCOMPLETE'){
							$html.= '<br><span class="ubo_incomplete_status_Reason">';
							if(isset($mangopay_key_text[$ubo_declaration->Reason])){
								$html.= $mangopay_key_text[$ubo_declaration->Reason];
							}else{
								$html.= __($ubo_declaration->Reason,'mangopay');
							}
							$html.= '</span>';
						}
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input '
							. 'id="show_ubo_elements_button_'.$ubo_declaration->Id.'" '
							. 'type="button" '
							. 'data-id="'.$ubo_declaration->Id.'" '
							. 'value="'.__('Show details','mangopay').'">';
						
						$html.= '<input type="hidden" '
							. 'id="ubo_declaration_ubo_count_'.$ubo_declaration->Id.'" '
							. 'value="'.count($ubo_declaration->Ubos).'">';
					$html.= '</td>';
				$html.= '</tr>';
				/** ubo list elements **/
				if(count($ubo_declaration->Ubos)>0){
					$html.= '<tr class="ubo_tr_list_elements" id="tr_ubo_'.$ubo_declaration->Id.'" style="display:none;">';
						$html.= '<td colspan="4">';
							/** list all ubo elements **/							
								$html.= '<table class="ubo_table_list_elements">';
									$html.= '<thead class="ubo_thead_list_elements">';
										$html.= '<tr>';
											$html.= '<td>';
												$html.= __('ID','mangopay');
											$html.= '</td>';
											$html.= '<td>';
												$html.= __('First name','mangopay');
											$html.= '</td>';
											$html.= '<td>';
												$html.= __('Last name','mangopay');
											$html.= '</td>';
											$html.= '<td>';
												$html.= __('Birthday','mangopay');
											$html.= '</td>';	
											$html.= '<td>';
												$html.= __('Edit','mangopay');
											$html.= '</td>';
										$html.= '</tr>';
									$html.= '</thead>';
								foreach($ubo_declaration->Ubos as $ubo_element){
									$html.= '<tr>';
										$html.= '<td>';
											$html.= $ubo_element->Id;
										$html.= '</td>';
										$html.= '<td>';
											$html.= $ubo_element->FirstName;
										$html.= '</td>';
										$html.= '<td>';
											$html.= $ubo_element->LastName;
										$html.= '</td>';
										$html.= '<td>';
											$html.= date_i18n($wp_date_format,$ubo_element->Birthday);
										$html.= '</td>';
										$html.= '<td>';
											/** check status of the declaration **/
											if($ubo_declaration->Status == 'CREATED'
												|| $ubo_declaration->Status == "INCOMPLETE"){
												$html.= "<input type='button' "
													. "id='uboelementbutton_".$ubo_element->Id."' "
													. "data-uboelementid='".$ubo_element->Id."' "
													. "data-uboelement='\"".json_encode($ubo_element)."\"' "
													. "value='".__('Edit','mangopay')."'>";
											}
										$html.= '</td>';
									$html.= '</tr>';
								}
								$html.= '</table>';
						$html.= '</td>';
					$html.= '</tr>';
				}
			}
			$html.= '</table>';
		}
		
		$html.= '<div id="ubo_create_error_div" class="ubo_create_errormessage" style="display:none;"></div>';

		/** button to add an UBO declaration can only be one (except if there is one refused) **/
		if($ubo_print_create_button){
			$html.= '<div id="ubo_create_div" class="ubo_create_div">';
				$html.= '<input type="button" '
					. 'class="btn btn-inverse btn-small" '
					. 'style="float:none;" '
					. 'id="ubo_create_declaration_button" '
					. 'value="'.__('Create an UBO declaration','mangopay').'">';
			$html.= '</div>';
			$html.= '<div id="loading_ubo_declaration" style="display:none;">'.__('Declaring UBO ...','mangopay').'</div>';
		}

		$html.= '<div class="ubo_buttons_div">';
		if($created_ubo_button!=false){				
			$html.= '<div id="ubo_create_div" class="ubo_add_div">';
				$html.= '<input type="button" '
					. 'class="btn btn-inverse btn-small" '
					. 'style="float:none;" '
					. 'id="ubo_add_button" '
					. 'value="'.__('Add UBO','mangopay').'">';
			$html.= '</div>';
		}
		/** can't ask for validation if there is not at leadt one UBO **/
		if($at_least_one_ubo){
			$html.= '<div id="ubo_askvalidation_div" class="ubo_add_div">';
				$html.= '<input type="button" '
					. 'class="btn btn-inverse btn-small" '
					. 'style="float:none;" '
					. 'id="ubo_askvalidation_button" '
					. 'value="'.__('Ask validation','mangopay').'">';
			$html.= '</div>';
		}
		$html.= '<div id="loading_ubo_validation" style="display:none;">'.__('Sending validation for UBO ...','mangopay').'</div>';

		$html.= '<div>';

		/** actual mpid **/
		$html.= '<input type="hidden" id="ubo_mp_id" value="'.$existing_account_id.'">';
		/** UBO declaration id (the declaration contains all the ubos) **/
		$html.= '<input type="hidden" id="ubo_declaration_id" value="'.$created_ubo_id.'">'; //false if no UBO declaration
		/** UBO id (the UBO are called UBO_elements to distinct them from ubo declarations)  **/
		$html.= '<input type="hidden" id="ubo_element_id" value="">'; //will be fill by javascript			

		/** FORM add UBO element **/
		$countries_obj = new WC_Countries();
		$countries = $countries_obj->__get('countries');

		//
		$html.= '<div id="ubo_list_errors" class="woocommerce" style="display:none;">';
			$html.= '<ul id="ubo_element_errors" class="woocommerce-error" role="alert">';
				$list_fields = array(
					'FirstName'=>'First name',
					'LastName'=>'Last name',
					'AddressLine1'=>'Address 1',
					'City'=>'City',
					'Region'=>'Region',
					'PostalCode'=>'Postal code',
					'ubo_Country_select'=>'Country',
					'ubo_NationalityCountry_select'=>'Nationality',
					'Birthday'=>'Birthday',
					'BirthplaceCity'=>'Birthplace City',
					'ubo_BirthplaceCountry_select'=>'Birthplace Country'
				);
				foreach($list_fields as $fieldkey=>$fieldvalue){
				$html.= '<li id="'.$fieldkey.'_error" style="display:none;">'
					. '<strong>'.__('Error:','mangopay').'</strong> '
					. ''.__($fieldvalue,'mangopay').' '.__('is required!','mangopay').''
					. '</li>';
				}

			$html.= '</ul>';
		$html.= '</div>';

		$html.= '<div id="loading_ubo_element" style="display:none;">'.__('Sending UBO ...','mangopay').'</div>';

		$html.= '<table class="ubo_table_form" id="form_add_ubo_element" style="display:none;">';
			$html.= '<thead class="ubo_thead_form">';
				$html.= '<tr>';
					$html.= '<td>';
						$html.= __('Add UBO form','mangopay');
					$html.= '</td>';
				$html.= '</tr>';
			$html.= '</thead>';
			$html.= '<tbody class="ubo_tbody_form">';
				/** FISTNAME **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'First name', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="FirstName" id="FirstName" value="" class="regular-text" />';
					$html.= '</td>';						
				$html.= '</tr>';
				/** LASTNAME **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Last name', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="LastName" id="LastName" value="" class="regular-text" />';
					$html.= '</td>';						
				$html.= '</tr>';
				/** AddressLine1 **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Address 1', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="AddressLine1" id="AddressLine1" value="" class="regular-text" />';
					$html.= '</td>';						
				$html.= '</tr>';
				/** AddressLine2 **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Address 2', 'mangopay' );
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="AddressLine2" id="AddressLine2" value="" class="regular-text" />';
					$html.= '</td>';						
				$html.= '</tr>';
				/** City **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'City', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="City" id="City" value="" class="regular-text" />';
					$html.= '</td>';						
				$html.= '</tr>';						
				/** Region **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Region', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="Region" id="Region" value="" class="regular-text" />';
					$html.= '</td>';						
				$html.= '</tr>';
				/** PostalCode **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Postal code', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="PostalCode" id="PostalCode" value="" class="regular-text" />';
					$html.= '</td>';						
				$html.= '</tr>';
				/** Country **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Country', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<select '
							. 'class="ubo_Country_select js_field-country" '
							. 'name="ubo_Country_select" '
							. 'id="ubo_Country_select">';
						$html.= '<option value="">'.__( 'Select a country...', 'mangopay' ).'</option>';
						foreach ($countries as $key => $value){
							$html.= '<option value="'.$key.'">'.$value.'</option>';
						}
						$html.= '</select>';
					$html.= '</td>';						
				$html.= '</tr>';
				/** Nationality **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Nationality', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<select '
							. 'class="ubo_NationalityCountry_select js_field-country" '
							. 'name="ubo_NationalityCountry_select" '
							. 'id="ubo_NationalityCountry_select">';
						$html.= '<option value="">'.__( 'Select a country...', 'mangopay' ).'</option>';
						foreach ($countries as $key => $value){
							$html.= '<option value="'.$key.'">'.$value.'</option>';
						}
						$html.= '</select>';
					$html.= '</td>';						
				$html.= '</tr>';
				/** Birthday **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Birthday', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="Birthday" id="Birthday" value="" class="regular-text calendar" />';
						/** date in timastamp format  **/
						$html.= '<input type="hidden" id="ubo_datetimestamp" value="">'; //will be fill by javascript	
					$html.= '</td>';						
				$html.= '</tr>';
				/** Birthplace City **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Birthplace city', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<input type="text" name="BirthplaceCity" id="BirthplaceCity" value="" class="regular-text" />';
					$html.= '</td>';						
				$html.= '</tr>';
				/** BirthplaceCountry **/ 
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<label for="vendor_account_name">';
							$html.= __( 'Birthplace country', 'mangopay' );
							$html.= '<span class="description required">';
								$html.= __( '(required)', 'mangopay' );
							$html.= '</span>';
						$html.= '</label>';
					$html.= '</td>';
					$html.= '<td>';
						$html.= '<select '
							. 'class="ubo_BirthplaceCountry_select js_field-country" '
							. 'name="ubo_BirthplaceCountry_select" '
							. 'id="ubo_BirthplaceCountry_select">';
						$html.= '<option value="">'.__( 'Select a country...', 'mangopay' ).'</option>';
						foreach ($countries as $key => $value){
							$html.= '<option value="'.$key.'">'.$value.'</option>';
						}
						$html.= '</select>';
					$html.= '</td>';						
				$html.= '</tr>';
				$html.= '<tr>';
					$html.= '<td>';
						$html.= '<input '
							. 'class="btn btn-inverse btn-small" '
							. 'type="button" '
							. 'id="add_button_ubo_element" '
							. 'value="'.__('Send UBO','mangopay').'" >';
						$html.= '<input '
							. 'class="btn btn-inverse btn-small" '
							. 'style="display:none;" '
							. 'type="button" '
							. 'id="update_button_ubo_element" '
							. 'value="'.__('Update UBO','mangopay').'" >';
						$html.= '&nbsp;<input '
							. 'class="btn btn-inverse btn-small" '
							. 'type="button" '
							. 'id="cancel_button_ubo_element" '
							. 'value="'.__('Cancel','mangopay').'" >';
					$html.= '</td>';						
				$html.= '</tr>';					

			$html.= '</tbody>';
		$html.= '</table>';
				
		echo $html;
		wp_die();
	}
	
	/**
	 * check if the number given is a valid company number
	 * on success returns "found"
	 * on failure returns "nopattern"
	 * @_POST_param companynumber
	 */
//	public function ajax_check_company_number_patterns(){
//		if(isset($_POST['companynumber'])){
//			echo mpAccess::getInstance()->check_company_number_patterns( $_POST['companynumber'] );			
//		}else{
//			echo 'nocountry';
//		}
//		wp_die();		
//	}
	
	/**
	 * Cancel pre-authorization
	 * @_POST_param Pre-authorizationId	== MP pre-auth id
	 * @_POST_param order_id 			== WC order id
	 * 
	 */	
//	public function ajax_preauth_cancel(){
//		$PreauthorizationId = $_POST['PreauthorizationId'];
//		$order_id			= $_POST['order_id'];
//		$tag = 'WC Order #' . $order_id;
//		$result = mpAccess::getInstance()->cancel_preathorization_by_id($PreauthorizationId,$order_id,$tag);
//
//		if(isset($result['success']) && isset($result['result']->PaymentStatus)){
//			
//			/** Change the order status **/
//			$order = new WC_Order($order_id);
//			$order->update_status('cancelled');
//			
//			echo "CANCELED";
//		}else{
//			//TODO: handle the error message
//		}		
//		wp_die();
//	}
	
	/**
	 * Do a partial capture of the pre-authorized payment
	 * (and re-calculate all order amounts accordingly)
	 * 
	 */
//	public function ajax_partial_preauth_capture(){		
//		$mp_user_id			= $_POST['mp_user_id'];
//		$PreauthorizationId = $_POST['PreauthorizationId'];
//		$order_id			= $_POST['order_id'];
//		$locale				= $_POST['locale'];
//		
//		$order = new WC_Order($order_id);
//
//		/** UPDATE ORDER **/
//		$list_order_elements = $_POST['preauth_items'];
//		foreach( $order->get_items() as $item_id => $item ){
//			
//			/** If we have the item in the list **/
//			if(isset($list_order_elements[$item_id])){
//				
//				/** Set the new price **/
//				$item->set_subtotal( $list_order_elements[$item_id] ); 
//				$item->set_total( $list_order_elements[$item_id] );
//				
//				/** Make new taxes calculations **/
//				$item->calculate_taxes();
//				$item->save(); // Save line item data
//			}
//		}
//		/** Make the calculations  for the order **/
//		$order->calculate_totals();
//		$order->save();
//				
//		/** Get shipping **/		
//		$list_order_shipping_elements = $_POST['preauth_shipping'];
//		foreach( $order->get_items( 'shipping' ) as $shipping_id => $shipping_item_obj ){
//			$shipping_data = $shipping_item_obj->get_data();
//			$shipping_data_id = $shipping_data['id'];
//			
//			if(isset($list_order_shipping_elements[$shipping_data_id])){
//				$shipping_item_obj->set_total(floatval($list_order_shipping_elements[$shipping_data_id]));
//				$shipping_item_obj->calculate_taxes();
//				$shipping_item_obj->save();
//			}
//		}
//		$order->calculate_shipping();		
//		$order->calculate_taxes();
//		$order->calculate_totals();
//		$order->save();
//		
//		/** Capture the parts of the pre-authorization **/
//		$result = mpAccess::getInstance()->capture_pre_authorization_by_id($order_id,$mp_user_id,$locale,$PreauthorizationId); //,$part_capture
//		if(isset($result->Status) && $result->Status == "SUCCEEDED"){
//			
//			/** Change the order status **/
//			$order = new WC_Order($order_id);
//			$order->update_status('processing');
//			echo json_encode(array('result'=>'success','message'=>'VALIDATED'));
//		}else{
//			if($result['success'] == false){
//				echo json_encode(array('result'=>'failed','message'=>$result['message']));
//			}
//		}
//		wp_die();
//	}
	
	/**
	 * Capture the complete amount of the pre-authorization
	 * 
	 * @_POST_param mp_user_id 	MP user id
	 * @_POST_param PreauthorizationId 	MP pre auth id
	 * @_POST_param order_id 	WC order id
	 * @_POST_param locale 	WP language
	 */
	public function ajax_preauth_capture(){
		
		$mp_user_id			= $_POST['mp_user_id'];
		$PreauthorizationId = $_POST['PreauthorizationId'];
		$order_id			= $_POST['order_id'];
		$locale				= $_POST['locale'];
						
		$result = mpAccess::getInstance()->capture_pre_authorization_by_id($order_id,$mp_user_id,$locale,$PreauthorizationId);
		
		if(isset($result->Status) && $result->Status == "SUCCEEDED"){
			
			/** Change the order status **/
			$order = new WC_Order($order_id);
			$order->update_status('processing');
			
			echo "VALIDATED";
		}else{
			//TODO: handle the error message
		}
		wp_die();
	}
	
	/**
	 * First step, create a registration record for a card
	 * 
	 * @_POST_param user_id 	MP user id
	 * @_POST_param currency 	(woocommerce)
	 * @_POST_param card_type 	(supported by MANGOPAY)
	 * @_POST_param preauth_ccnickname
	 * @json_return data for next step
	 */
	public function ajax_preauth_registercard(){
		
		$user_id		= $_POST['user_id'];
		$order_currency = $_POST['order_currency'];
		$card_type		= $_POST['card_type'];
		$nickname		= $_POST['preauth_ccnickname'];
		
		$post_data = false;
		$result = mpAccess::getInstance()->register_card( $user_id, $order_currency, $card_type, $nickname );
		
		if( !empty( $result['success'] ) ){
			$createdCardRegister = $result['result'];
			$post_data = array(
				'CardRegistrationId'	=> $createdCardRegister->Id,
				'PreregistrationData'	=> $createdCardRegister->PreregistrationData,
				'AccessKey'				=> $createdCardRegister->AccessKey,
				'CardRegistrationURL'	=> $createdCardRegister->CardRegistrationURL,
				'UserId'				=> $createdCardRegister->UserId
			);

		} else {
			/** Handle bad response **/
			$post_data = array(
				"error"=> __( 'Card not saved, error:', 'mangopay' ) . ' ' .
					__( $result['message'], 'mangopay' )
			);			
		}
		
		echo json_encode( $post_data );
		exit;
	}
	
	/**
	 * Third step, after saving card data, update the card registration record
	 * 
	 * @_POST_param card id
	 * @_POST_param registration data (return from previous call)
	 * @json_return result
	 */
	public function ajax_preauth_registercard_update(){
		
		$id_card = $_POST['id_card'];
		$RegistrationData = $_POST['RegistrationData'];
		
		$result = mpAccess::getInstance()->update_register_card( $id_card, $RegistrationData );

		echo json_encode($result);
		exit;
	}
		
	/**
	 * Ajax call to deactivate card
	 * @_POST_param id_card
	 * @json_return string failed/success
	 */
	public function ajax_delete_card_list_preauth_cards(){

		$id_card = $_POST['id_card'];
		$result = mpAccess::getInstance()->deactivate_card($id_card);
		$html = 'failed';
		if(isset($result['success']) && $result['success']){
			$html = "success";
		} else {
			$html = "failure";
		}
				
		echo $html;
		exit;
	}
	
	/**
	 * Display list/choice of registered cards
	 * 
	 */
	public function ajax_update_list_preauth_cards(){

		$html = '<div class="list_preauth_cards_block">';
			
			$list_cards = mpAccess::getInstance()->get_all_user_cards($_POST['user_id']);
			
//			echo "<pre>", print_r("list_cards", 1), "</pre>";	//Debug
//			echo "<pre>", print_r($list_cards, 1), "</pre>";	//Debug
			
			if( !empty( $list_cards ) && is_array( $list_cards ) ) {

				$html .= '<input type="hidden" id="atleastonecard" value="1">';
			
				$html .= '<div class="list_preauth_cards_title"><label style="font-weight: bold;">'.__('Use a stored card:', 'mangopay').'</label></div>';
				
				foreach($list_cards as $lc){
					
					$html .= '<div class="preauth_card_div" id="line_'.$lc->Id.'">';
					
						/** Selection **/
						$html .= '<div class="bouton-carte">';
							$html .= '<input type="radio" name="registered_card_selected" value="'.$lc->Id.'">';
						$html .= '</div>';
					
						/** Label **/
						$html .= '<div class="type-de-carte">';
							$html .= '<label>';
								$html .= $lc->CardProvider;
							$html .= '</label>';
						$html.= '</div>';
						
						/** Card number alias */
						$html .= '<div class="numero-de-carte">';
							$html .= '<label>';
								$html .= $lc->Alias;
							$html .= '</label>';					
						$html.= '</div>';
						
						/** Expiration date **/
						$html .= '<div class="date-d-expiration">';
							$html .= '<label>';
								$html .= substr_replace($lc->ExpirationDate,'/20',-2, 0);

							$html .= '</label>';					
						$html.= '</div>';
						
						/** Deactivate button **/
						$html .= '<div class="supprimer-carte">';
							$html .= '<button type="button" id="cancel_card" data_card="'.$lc->Id.'">';
								$html .= '<img class="bin_preauth_cards" src="'.plugins_url( '/img/Bin-512.png', dirname( __FILE__ ) ).'">';
							$html .= '</button>';
						$html .= '</div>';

						$html .= '</div>';
				}
				
			} else {
				$html .= '<div class="list_preauth_cards_title"><label style="font-weight: bold;">' . 
					__('You did not register a card yet. You can add one below.','mangopay') .
					'</label></div>';
			}
			
		$html .= '</div>';
		
		/** Return the list of cards **/
		echo $html;
		exit;
	}

	
	/**
	 * Stores a failed payout resource ID as ignored
	 * 
	 */
	public function ajax_ignore_mp_failed_po() {
		if ( !current_user_can( 'manage_options' ) )
			return;
		
		$this->ajax_head();
		$response = null;
		$ressource_id = null;
		
		if( !empty( $_POST['id'] ) )
			$ressource_id = $_POST['id'];
		
		if( $ressource_id && !in_array( $ressource_id, $this->ignored_failed_po ) ) {
			$this->ignored_failed_po[] = $ressource_id;
			$response = update_option( 'mp_ignored_failed_po', $this->ignored_failed_po );
		}
		
		echo json_encode( $response );
		exit;
	}
	
	/**
	 * Stores a refused KYC doc resource ID as ignored
	 *
	 */
	public function ajax_ignore_mp_refused_kyc() {
		if ( !current_user_can( 'manage_options' ) )
			return;
		
		$this->ajax_head();
		$response = null;
		$ressource_id = null;
		
		if( !empty( $_POST['id'] ) )
			$ressource_id = $_POST['id'];
		
		if( $ressource_id && !in_array( $ressource_id, $this->ignored_refused_kyc ) ) {
			$this->ignored_refused_kyc[] = $ressource_id;
			$response = update_option( 'mp_ignored_refused_kyc', $this->ignored_refused_kyc );
		}
		
		echo json_encode( $response );
		exit;
	}

	/**
	 * Our admin dashboard widget
	 * for displaying failed payout transactions and refused KYC docs
	 *
	 */
	public function ajax_failed_transaction_widget() {
		$this->mp = mpAccess::getInstance();
		
		if ( !current_user_can( 'manage_options' ) ) {
			return;
		}
		$mp_failed = $this->mp->get_failed_payouts();

		$ignored_failed_po = get_option( 'mp_ignored_failed_po', array() );
		if( !empty( $mp_failed['failed_payouts']) ) {
			foreach( $mp_failed['failed_payouts'] as $key => $failed_payout ) {
				if( in_array( $failed_payout->ResourceId, $ignored_failed_po ) ) {
					unset( $mp_failed['failed_payouts'][$key] );
				}
			}
		}

		echo '<h3>' . __( 'Failed payouts', 'mangopay' ) . '</h3>';

		if( empty( $mp_failed['failed_payouts']) ) {
			echo '<p><em>' . __( 'No failed payout', 'mangopay' ) . '</em></p>';
		} else {
			echo '<ul>';
			foreach( $mp_failed['failed_payouts'] as $failed_payout ) {

				if( !$payout_a = get_transient( 'mp_failed_po_' . $failed_payout->ResourceId ) ) {
					$payout = $this->mp->get_payout( $failed_payout->ResourceId );
					if( $payout && is_object( $payout) ) {
						$due_ids = array();
						if( preg_match( '/WC Order #(\d+)/', $payout->Tag, $matches ) ) {
							$order_id = $matches[1];	
							global $wpdb;
							$table_name = $wpdb->prefix . mangopayWCConfig::WV_TABLE_NAME;
							$query = "
								SELECT id
								FROM `{$table_name}`
								WHERE order_id = %d;
							";	//AND status='due'; <- in fact the payout may have been refused afterwards
							$query		= $wpdb->prepare( $query, $order_id );
							$due_ids	= $wpdb->get_col( $query );
						}
						$payout_a = array(
							'payout'	=> $payout,
							'due_ids'		=> $due_ids
						);
						set_transient(
							'mp_failed_po_' . $failed_payout->ResourceId,
							$payout_a,
							60*60*24
						);
					}
				}
				$payout = $payout_a['payout'];

				echo '<li class="mp_failed_po_' . $failed_payout->ResourceId . '">';
				echo date_i18n( get_option( 'date_format' ), $failed_payout->Date ) . '<br/>';
				echo $failed_payout->EventType . ' ';

				$tag = preg_replace(
					'/WC Order #(\d+)/',
					"<a href=\"post.php?post=$1&action=edit\">$0</a>",
					$payout->Tag
				);

				echo $tag . ' ';

				/*
				/wp-admin/admin.php?page=pv_admin_commissions
				&_wpnonce=ebe5c12143
				&_wp_http_referer=%2Fwp-admin%2Fadmin.php%3Fpage%3Dpv_admin_commissions
				&action=-1
				&m=0
				&com_status
				&paged=1
				&id%5B0%5D=35&id%5B1%5D=34
				&action2=mp_payout
				*/

				$retry_payout_url = 'admin.php?page=pv_admin_commissions';
				$retry_payout_url = wp_nonce_url( $retry_payout_url );
                $retry_payout_url .= '&action=mp_payout';

				foreach( $payout_a['due_ids'] as $id ){
					$retry_payout_url .= '&id[]=' . $id;
				}
				$retry_payout_url .= '&mp_initial_transaction=' . $failed_payout->ResourceId;

				echo '<a class="ignore_mp_failed_po" data-id="' . $failed_payout->ResourceId . '" href="#">[' . __( 'Ignore', 'mangopay' ) . ']</a> ';
				echo '<a class="retry_mp_failed_po" href="' . $retry_payout_url . '">[' . __( 'Retry', 'mangopay' ) . ']</a> ';
				//echo '<a class="retry_mp_failed_po" data-id="' . $failed_payout->ResourceId . '" href="#">[' . __( 'Retry', 'mangopay' ) . ']</a> ';
				//var_dump( $failed_payout );	//Debug
				//var_dump( $payout );			//Debug

				echo '</li>';
			}
			echo '</ul>';
		} //end if empty( $mp_failed['failed_payouts'])

		$ignored_refused_kyc = get_option( 'mp_ignored_refused_kyc', array() );
		if( !empty( $mp_failed['refused_kycs']) ) {
			foreach( $mp_failed['refused_kycs'] as $key => $refused_kyc ) {
				if( in_array( $refused_kyc->ResourceId, $ignored_refused_kyc ) ) {
					unset( $mp_failed['refused_kycs'][$key] );
				}
			}
		}

		echo '<hr><h3>' . __( 'Refused KYC documents', 'mangopay' ) . '</h3>';
		if( empty( $mp_failed['refused_kycs']) ) {
			echo '<p><em>' . __( 'No refused KYC document', 'mangopay' ) . '</em></p>';
		} else {
			echo '<ul>';
			foreach( $mp_failed['refused_kycs'] as $refused_kyc ) {
				if( !$kyc_doc_a = get_transient( 'mp_refused_kyc_' . $refused_kyc->ResourceId ) ) {
					$kyc_doc = $this->mp->get_kyc( $refused_kyc->ResourceId );
						
					/** We store a different mp_user_id for production and sandbox environments **/
					$umeta_key = 'mp_user_id';
					if( !$this->mp->is_production() ) {
						$umeta_key .= '_sandbox';
					}
					$wp_user_id = 0;
					$wp_users = get_users( array(
						'meta_key'		=> $umeta_key,
						'meta_value'	=> $kyc_doc->UserId
					));
					if( $wp_users && is_array( $wp_users) ) {
						$wp_user = $wp_users[0];
					}
					if( $kyc_doc && is_object( $kyc_doc) ) {
						$kyc_doc_a = array(
							'kyc_doc'	=> $kyc_doc,
							'wp_user'	=> $wp_user
						);
						set_transient(
							'mp_refused_kyc_' . $refused_kyc->ResourceId,
							$kyc_doc_a,
							60*60*24
						);
					}
				}
				$kyc_doc = $kyc_doc_a['kyc_doc'];

				echo '<li class="mp_refused_kyc_' . $refused_kyc->ResourceId . '">';

				echo date_i18n( get_option( 'date_format' ), $refused_kyc->Date ) . '<br/>';
				echo $refused_kyc->EventType . ' ';
				echo $kyc_doc->Type . ' ';
				echo $kyc_doc->Status . ', ';
				echo $kyc_doc->RefusedReasonType . ' ';

				if( $wp_user_id = $kyc_doc_a['wp_user'] ) {
					echo __( 'For WP user:', 'mangopay' ) . ' ';
					echo '<a href="user-edit.php?user_id=' . $kyc_doc_a['wp_user']->ID . '">';
					echo $kyc_doc_a['wp_user']->user_login . ' ';
					echo '(' . $kyc_doc_a['wp_user']->display_name . ')';
					echo '</a> ';
				} else {
					echo __( 'For MP user:', 'mangopay' ) . ' ';
					echo $kyc_doc->UserId . ' ';
				}

				$upload_url = $this->mp->getDBUploadKYCUrl( $kyc_doc->UserId );
				echo '<a class="ignore_mp_refused_kyc" data-id="' . $refused_kyc->ResourceId . '" href="#">[' . __( 'Ignore', 'mangopay' ) . ']</a> ';
				echo '<a class="retry_mp_refused_kyc" target="_mp_db" href="' . $upload_url . '">[' . __( 'Upload another document', 'mangopay' ) . ']</a> ';

				//var_dump( $refused_kyc );		//Debug
				//var_dump( $kyc_doc_a );		//Debug

				echo '</li>';
			}
			echo '</ul>';
		}
		?>
		<script>
		(function($) {
			$(document).ready(function() {
				//console.log('document ready...');	//Debug
				$('.ignore_mp_failed_po').on( 'click', function( e ){
					e.preventDefault();
					//console.log('clicked ignore_mp_failed_po!');		//Debug
					//console.log(e);				//Debug
					//console.log(this);			//Debug
					//console.log(this.dataset.id);	//Debug
					resource_id = this.dataset.id;
					$.post( ajaxurl, {
						action: 'ignore_mp_failed_po',
						id: resource_id
					}, function( data ) {
						//console.log( data );		//Debug
						if( true === data ) {
							class_id = 'li.mp_failed_po_' + resource_id;
							//console.log( 'hiding: ' + class_id );	//Debug
							$(class_id).hide('slow');
						}
					}).done(function() {
						//console.log( "Ajax ignore_mp_failed_po success" );	//Debug
					}).fail(function() {
						console.log( "Ajax ignore_mp_failed_po error" );	//Debug
					}).always(function() {
						//console.log( "Ajax ignore_mp_failed_po finished" );	//Debug
					});
				});
				/* UNUSED
				$('.retry_mp_failed_po').on( 'click', function( e ){
					e.preventDefault();
					//console.log('clicked retry_mp_failed_po!');		//Debug
					//console.log(e);				//Debug
					//console.log(this);			//Debug
					//console.log(this.dataset.id);	//Debug
					resource_id = this.dataset.id;
					$.post( ajaxurl, {
						action: 'retry_mp_failed_po',
						id: resource_id
					}, function( data ) {
						console.log( data );		//Debug
						if( true === data ) {
							class_id = 'li.mp_failed_po_' + resource_id;
							//console.log( 'hiding: ' + class_id );	//Debug
							$(class_id).hide('slow');
						}
					}).done(function() {
						//console.log( "Ajax retry_mp_failed_po success" );	//Debug
					}).fail(function() {
						console.log( "Ajax retry_mp_failed_po error" );	//Debug
					}).always(function() {
						//console.log( "Ajax retry_mp_failed_po finished" );	//Debug
					});
				});
				*/
				$('.ignore_mp_refused_kyc').on( 'click', function( e ){
					e.preventDefault();
					//console.log('clicked ignore_mp_refused_kyc!');		//Debug
					//console.log(e);				//Debug
					//console.log(this);			//Debug
					//console.log(this.dataset.id);	//Debug
					resource_id = this.dataset.id;
					$.post( ajaxurl, {
						action: 'ignore_mp_refused_kyc',
						id: resource_id
					}, function( data ) {
						//console.log( data );		//Debug
						if( true === data ) {
							class_id = 'li.mp_refused_kyc_' + resource_id;
							//console.log( 'hiding: ' + class_id );	//Debug
							$(class_id).hide('slow');
						}
					}).done(function() {
						//console.log( "Ajax ignore_mp_refused_kyc success" );	//Debug
					}).fail(function() {
						console.log( "Ajax ignore_mp_refused_kyc error" );	//Debug
					}).always(function() {
						//console.log( "Ajax ignore_mp_refused_kyc finished" );	//Debug
					});
				});
			});
		})( jQuery );
		</script>
		<?php
		wp_die();   
	}
	
	private function ajax_head() {
		session_write_close();
		header( "Content-Type: application/json" );
	}
	
}
new mangopayWCAjax();
?>