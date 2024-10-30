/** KYC ERROR MESSAGE **/
(function($) {

	$(document).ready(function() {
		if ($.magnificPopup != undefined && $.magnificPopup.instance != null) {
			$.magnificPopup.instance._onFocusIn = function(e) {
				if ($(e.target).hasClass('ui-datepicker-year')) {
					return true;
				}
				$.magnificPopup.proto._onFocusIn.call(this, e);
			};
		}
	});
})(jQuery);

/** KYC tab show/hide form **/
(function($) {

	$(document).ready(function() {

		if ($('#kyc_div_global').attr('id') != undefined && $('.tabs-tab').attr('class') != undefined) {
			$('.tabs-tab').click(function() {
				if ($(this).attr('class') == 'tabs-tab  payment') {
					$('#kyc_div_global').show();
				} else {
					$('#kyc_div_global').hide();
				}
			})
		}
	});
})(jQuery);

/** Preauthorization scripts **/
(function($) {

	$(document).ready(function() {

		$(document).on('click',"#askforconnect", function() {
			if ($(".showlogin").length < 1) {
				return;
			}
			var pixel_top = parseInt($(".showlogin").offset().top) - 100;
			$('html, body').animate({
				scrollTop: pixel_top
			}, 1000);
			$('a[class="showlogin"]').trigger('click');
		});

		/** When option is chosen open/close block **/
		$(document).on('change','input[name="mp_payment_type"]', function() {
			$('#registercardform').hide();
			$('#registercardlist').hide();
			if ($('#askforconnect').length > 0) {
				$('#askforconnect').hide();
			}
		});
		$(document).on('change','#mp_payment_type_registeredcard:checked', function() {

			/** Update first time the cards **/
			update_front_list_cards();

			/** Show **/
			$('#registercardform').show();
			$('#registercardlist').show();
			$('#toggle_addcard').show();

			if ($('#askforconnect').length > 0) {
				$('#askforconnect').show();
			}
		});

		/** Cancel card **/
		$(document).on('click','#cancel_card', function() {
			var id_card = $(this).attr('data_card');
			var data = {
				"action": "delete_card_list_preauth_cards",
				"id_card": id_card
			};
			$.post(ajax_object.ajax_url, data, function(theajaxresponse) {
				if (theajaxresponse == 'success') {

					/** Remove line **/
					$('#line_' + id_card).remove();
				}
			})
				.fail(function() {
					console.log("error javascript cancel_card");
				});
		});


		/** Save card **/
		$(document).on('click','#save_preauth_card_button', function() {

			/** Reset error messages **/
			jQuery('#preauth_error_messages').html('');
			jQuery('#preauth_error_messages').hide();

			/** Hide the form and show the waiting message **/
			$('#registercardform').hide();
			$('#registeredcard_waiting').show();

			/** Get data **/
			var user_id = $('#user_id').val();
			var order_currency = $('#order_currency').val();
			var card_type = $('#registered_card_type').val();

			/** Check if we got user **/
			var let_it_pass = true;
			if (user_id == undefined || user_id == 0 || user_id == '') {
				let_it_pass = false;
			}

			if (let_it_pass == true) {
				/** Data for FIRST step **/
				var data = {
					"action": "preauth_registercard",
					"user_id": user_id,
					"order_currency": order_currency,
					"card_type": card_type
				};

				var date_month = $('#preauth_ccdate_month').val();
				var date_year = $('#preauth_ccdate_year').val();
				var date = date_month + '' + date_year;

				/** data for SECOND STEP **/
				var cardData = {
					"cardNumber": $('#preauth_ccnumber').val(),
					"cardExpirationDate": date,
					"cardCvx": $('#preauth_cccrypto').val(),
					"cardType": $('#registered_card_type').val()
				};

				/** FIRSregistercardlistl **/
				$.post(ajax_object.ajax_url, data, function(theajaxresponse) {
					if (theajaxresponse != false && theajaxresponse != 'false') {

						var response = $.parseJSON(theajaxresponse);
						if (response.error != undefined && response.error.length > 0) {

							/** We have an error **/
							jQuery('#registercardform').show();
							jQuery('#preauth_error_messages').html(response.error);
							jQuery('#preauth_error_messages').show();

						} else {
							/** If success go SECOND step, send data to MANGOPAY **/
							ajax_carddetails_registration(
								response.CardRegistrationId,
								response.CardRegistrationURL,
								response.PreregistrationData,
								response.AccessKey,
								response.UserId,
								cardData);
						}
					}
				})
					.fail(function() {
						console.log("error javascript save_preauth_card");
					});
			}
		});

	});

	function ajax_carddetails_registration(cardRegistrationId, CardRegistrationURL, PreregistrationData, AccessKey, mp_user_id, cardData) {

		/** Ready url **/
		var status_p = $('#status_p').val();
		var url = 'https://api.sandbox.mangopay.com';
		if (status_p == 'p') {
			url = 'https://api.mangopay.com';
		}
		mangoPay.cardRegistration.baseURL = url;

		/** MP user id **/
		mangoPay.cardRegistration.clientId = mp_user_id;

		/** Set data from previous form **/
		mangoPay.cardRegistration.init({
			"cardRegistrationURL": CardRegistrationURL,
			"preregistrationData": PreregistrationData,
			"accessKey": AccessKey,
			"Id": cardRegistrationId,
		});

		/** Register **/
		mangoPay.cardRegistration.registerCard(cardData,
			function(res) {
				/** Update front list of cards **/
				update_front_list_cards();
				jQuery('#registeredcard_waiting').hide();
			},
			function(res) {
				/** We have an error **/
				jQuery('#registercardform').show();
				/** to change translated data look for "translate_error_cards_registration" function **/
				var message = translated_data['base_message'] + translated_data[res.ResultCode];

				jQuery('#preauth_error_messages').html(message);
				jQuery('#preauth_error_messages').show();
				jQuery('#registeredcard_waiting').hide();
				//console.log("Error occured while registering the card: " + "ResultCode: " + res.ResultCode + ", ResultMessage: " + res.ResultMessage);
			}
		);
	}

})(jQuery);

/** Update_list_preauth_cards **/
function update_front_list_cards() {

	jQuery('#registercardlist').show();

	var user_id = jQuery('#user_id').val();
	var data = {
		"action": "update_list_preauth_cards",
		"user_id": user_id,
	};
	jQuery.post(ajax_object.ajax_url, data, function(htmltoreturn) {

		/** add html to page **/
		jQuery('#registercardlist').html(htmltoreturn);
		/** look for data to know if we print or not the form and button of add card **/
		setTimeout(function() {
			if (parseInt(jQuery('#atleastonecard').length) >= 1 && parseInt(jQuery('#atleastonecard').val()) == 1) {
				jQuery("#registercardform").hide()
				jQuery("#toggle_addcard").show()
			} else {
				jQuery("#toggle_addcard").hide()
			}
		}, 500);
	});
}

/**
 * Need to be out of anonymous function to be accessible for mangopay-kit.js
 * @param {type} Id
 * @param {type} RegistrationData
 * @returns {undefined}
 */
function register_update_php(Id, RegistrationData) {

	var data = {
		"action": "preauth_registercard_update",
		"id_card": Id,
		"RegistrationData": RegistrationData,
	};
	jQuery.post(ajax_object.ajax_url, data, function(theajaxresponseupdate) {
		update_front_list_cards();
		jQuery('#preauth_ccnickname').val('');
		jQuery('#preauth_ccnumber').val('');
		jQuery('#preauth_ccdate').val('');
		jQuery('#preauth_cccrypto').val('');
		jQuery('#registeredcard_waiting').hide();
		jQuery('#registercardform').show();
	});

}

/** Fonction to show/hide the credit card form with the "Add a card" button **/
(function($) {
	$(document).ready(function() {
		$(document).on("click","#toggle_addcard", function() {
			$("#registercardform").toggle();
		});
	});
})(jQuery);

/** Front-end dashboard of WC-Vendors **/
(function($) {
	$(document).ready(function() {
		/** Button to capture the order **/
		$(document).on("click","[id^=capture_preauth]", function() {

			var order_id = $(this).attr('data-order_id');
			$("#capturebuttondiv_" + order_id).hide();
			$("#waitingmessage_capture_" + order_id).show();

			var data = {
				"action": "preauth_capture",
				"PreauthorizationId": $(this).attr('data-PreauthorizationId'),
				"order_id": $(this).attr('data-order_id'),
				"mp_user_id": $(this).attr('data-mp_user_id'),
				"locale": $(this).attr('data-locale'),
			};
			jQuery.post(ajax_object.ajax_url, data, function(theajaxresponseupdate) {
				$("#waitingmessage_capture_" + order_id).hide();
				$("#result_capture_captured_" + order_id).show();
			});

		});
		/** Button to cancel the order **/
		$(document).on("click","[id^=cancel_preauth]", function() {
			var order_id = $(this).attr('data-order_id');
			$("#capturebuttondiv_" + order_id).hide();
			$("#waitingmessage_capture_" + order_id).show();

			var data = {
				"action": "preauth_cancel",
				"PreauthorizationId": $(this).attr('data-PreauthorizationId'),
				"order_id": $(this).attr('data-order_id'),
			};
			jQuery.post(ajax_object.ajax_url, data, function(theajaxresponseupdate) {
				$("#waitingmessage_capture_" + order_id).hide();
				$("#result_capture_canceled_" + order_id).show();
			});
		});
		/** company test number by ajax **/
//		$("#compagny_number").keyup(function() {
//			/** do an ajax call to test the number at least at 6 letters **/
//			if($(this).val().length>5){
//				var data = {
//					"action": "check_company_number_patterns",
//					"companynumber": $(this).val(),
//				};
//				jQuery.post(ajax_object.ajax_url, data, function(theajaxresponse) {
//					console.log("return ajax");
//					console.log(theajaxresponse);
//				});
//			}			
//		});
	});
})(jQuery);

/** UBO SCRIPTS **/
(function($) {
	
	var text_button_show_details;
	var text_button_hide_details;
		
	function reset_error_messages_ubo(){
		$('#ubo_list_errors').hide();
		$('#FirstName_error').hide();
		$('#LastName_error').hide();
		$('#AddressLine1_error').hide();
		$('#City_error').hide();
		$('#Region_error').hide();
		$('#PostalCode_error').hide();
		$('#ubo_Country_select_error').hide();
		$('#ubo_NationalityCountry_select_error').hide();
		$('#Birthday_error').hide();
		$('#BirthplaceCity_error').hide();
		$('#ubo_BirthplaceCountry_select_error').hide();
		
		$('#ubo_create_error_div').html('');
		$('#ubo_create_error_div').hide();
	}
	
	function show_add_button(){
		var ubo_declaration_id = $('#ubo_declaration_id').val();
		var ubo_count = $('#ubo_declaration_ubo_count_'+ubo_declaration_id).val();
		if(ubo_count<4){
			$('#ubo_add_button').show();
		}
	}
	
	function send_ubo(){
		
		/** reset error validation messages **/
		reset_error_messages_ubo();

		/** test all fields of ubo element **/
		var send_ubo = true;
		if($('#FirstName').val() == ""){
			send_ubo = false;
			$('#FirstName_error').show();
		}
		if($('#LastName').val() == ""){
			send_ubo = false;
			$('#LastName_error').show();
		}
		if($('#AddressLine1').val() == ""){
			send_ubo = false;
			$('#AddressLine1_error').show();
		}
		if($('#City').val() == ""){
			send_ubo = false;
			$('#City_error').show();
		}
		if($('#Region').val() == ""){
			send_ubo = false;
			$('#Region_error').show();
		}
		if($('#PostalCode').val() == ""){
			send_ubo = false;
			$('#PostalCode_error').show();
		}
		if($('#ubo_Country_select').val() == ""){
			send_ubo = false;
			$('#ubo_Country_select_error').show();
		}
		if($('#ubo_NationalityCountry_select').val() == ""){
			send_ubo = false;
			$('#ubo_NationalityCountry_select_error').show();
		}
		if($('#Birthday').val() == ""){
			send_ubo = false;
			$('#Birthday_error').show();
		}
		if($('#BirthplaceCity').val() == ""){
			send_ubo = false;
			$('#BirthplaceCity_error').show();
		}
		if($('#ubo_BirthplaceCountry_select').val() == ""){
			send_ubo = false;
			$('#ubo_BirthplaceCountry_select_error').show();
		}

		if(send_ubo){
			/** ajax to add ubo element **/
			$('#form_add_ubo_element').hide();
			show_add_button();
			$('#ubo_askvalidation_button').show();
			
			$('#loading_ubo_element').show();
			$('html, body').animate({
			   scrollTop: $("#loading_ubo_element").offset().top
			}, 500);

			if($('#ubo_datetimestamp').val() != ""){
				var time_php_format = $('#ubo_datetimestamp').val()/1000;
			}

			var data = {
				"action": "add_ubo_element",
				"ubo_mp_id": $('#ubo_mp_id').val(),
				"ubo_declaration_id": $('#ubo_declaration_id').val(),
				"FirstName": $('#FirstName').val(),
				"LastName": $('#LastName').val(),
				"AddressLine1": $('#AddressLine1').val(),
				"AddressLine2": $('#AddressLine2').val(),
				"City": $('#City').val(),
				"Region": $('#Region').val(),
				"PostalCode": $('#PostalCode').val(),
				"ubo_Country_select": $('#ubo_Country_select').val(),
				"ubo_NationalityCountry_select": $('#ubo_NationalityCountry_select').val(),
				"ubo_datetimestamp": time_php_format,
				"BirthplaceCity": $('#BirthplaceCity').val(),
				"ubo_BirthplaceCountry_select": $('#ubo_BirthplaceCountry_select').val(),
				"ubo_element_id": $('#ubo_element_id').val()
			};

			$.post(ajax_object.ajax_url, data, function(theajaxresponse) {
				
				if(theajaxresponse!='nogo'){
					var data = {
						"action": "create_ubo_html",
						"existing_account_id": $('#ubo_mp_id').val(),
					};
					$.post(ajax_object.ajax_url, data, function(theajaxhtml) {
						$('#loading_ubo_element').hide();
						$('#ubo_data').html(theajaxhtml);
						var ubo_declaration_id = $('#ubo_declaration_id').val();
						
						if($("#show_ubo_elements_button_"+ubo_declaration_id) == text_button_show_details){
							$("#show_ubo_elements_button_"+ubo_declaration_id).val(text_button_hide_details);
						}else{
							$("#show_ubo_elements_button_"+ubo_declaration_id).val(text_button_show_details);
						}
						
						$("#tr_ubo_"+ubo_declaration_id).toggle();
						$('html, body').animate({
							scrollTop: $("#tr_ubo_"+ubo_declaration_id).offset().top
						}, 500);							
					});

				}else{
					//TODO message failed
					console.log("FAILED");
					$('#loading_ubo_element').hide();
				}
			});
		}else{
			/** show errors **/
			$('#ubo_list_errors').show();
			$('html, body').animate({
			   scrollTop: $("#ubo_list_errors").offset().top
			}, 1000);
		}
	}
	$(document).ready(function() {
		
		/** init texts **/
		setTimeout(function(){		
			text_button_show_details = $('#showbutton_text').val();
			text_button_hide_details = $('#hidebutton_text').val();
		},2000);
				
		/** Button to capture the order **/
		$(document).on("click","#ubo_create_declaration_button", function() {
			$('#loading_ubo_declaration').show();
			var data = {
				"action": "create_ubo",
				"userid": $('#ubo_mp_id').val(),
			};
			$.post(ajax_object.ajax_url, data, function(theajaxresponse) {
				
				//console.log(theajaxresponse.error);
				var result = $.parseJSON(theajaxresponse);
				if(typeof result.error=="undefined"){
					var data = {
						"action": "create_ubo_html",
						"existing_account_id": $('#ubo_mp_id').val(),
					};
					$.post(ajax_object.ajax_url, data, function(theajaxhtml) {
						$('#ubo_data').html(theajaxhtml);
						$('#loading_ubo_declaration').hide();
					});
				}else{
					$('#loading_ubo_declaration').hide(result.error);
					$('#ubo_create_error_div').html(translated_data[result.error]);
					$('#ubo_create_error_div').show();
				}

			});
		});
		
		/** button to ask validation for UBO **/
		$(document).on('click','#ubo_askvalidation_button',function(){
			$('#loading_ubo_validation').show();
			var data = {
				"action": "ubo_ask_declaration",
				"userid": $('#ubo_mp_id').val(),
				"ubo_declaration_id": $('#ubo_declaration_id').val()
			};
			$.post(ajax_object.ajax_url, data, function(theajaxresponse) {
				
				if(theajaxresponse!="error"){
					var data = {
						"action": "create_ubo_html",
						"existing_account_id": $('#ubo_mp_id').val(),
					};
					$.post(ajax_object.ajax_url, data, function(theajaxhtml) {
						$('#ubo_data').html(theajaxhtml);
						$('#loading_ubo_declaration').hide();
					});
				}else{
					$('#loading_ubo_declaration').hide();
					
					if(typeof translated_data[result.error] != undefined){
						$('#ubo_create_error_div').html(translated_data[result.error]);
					}else{
						$('#ubo_create_error_div').html(result.error);
					}
					$('#ubo_create_error_div').show();
				}

			});
		});
		
		/** create for first load the ubo interface **/
		if($('#ubo_data').length>0){
			var data = {
				"action": "create_ubo_html",
				"existing_account_id": $('#ubo_data').attr('data-mpid'),
			};
			$.post(ajax_object.ajax_url, data, function(theajaxresponse) {
				$('#ubo_data').html(theajaxresponse);
			});
		}
		
		/** button that open/close the list of UBO elements **/
		$(document).on('click',"[id^='show_ubo_elements_button_']",function(){
			if($(this).val() == text_button_show_details){
				$(this).val(text_button_hide_details);
			}else{
				$(this).val(text_button_show_details);
			}
			$('#tr_ubo_'+$(this).attr("data-id")).toggle();
		});
		
		/** show html form for UBO element **/
		$(document).on("click","#ubo_add_button", function() {
			
			//reset form
			$('#ubo_element_id').val('');			
			$('#FirstName').val('');
			$('#LastName').val('');
			$('#AddressLine1').val('');
			$('#AddressLine2').val('');
			$('#City').val('');
			$('#Region').val('');
			$('#PostalCode').val('');
			$('#BirthplaceCity').val('');
			//selector country
			$('#ubo_Country_select').val('');
			$('#ubo_NationalityCountry_select').val('');
			$('#ubo_BirthplaceCountry_select').val('');			
			//date						
			$('#Birthday').val('');
			$('#ubo_datetimestamp').val('');
			
			//show form
			$('#form_add_ubo_element').show();
			$('#ubo_add_button').hide();
			$('#ubo_askvalidation_button').hide();
			$('#add_button_ubo_element').show();
			$('#update_button_ubo_element').hide();
			//field re init
			$("#Birthday").datepicker(datepickerL10n);
			$("#ubo_dateFormat").val(datepickerL10n.dateFormat);
		});
		
		/** field date listener to get timestamp **/
		$(document).on('change',"#Birthday",function(){
			$("#Birthday").datepicker(datepickerL10n);
			/** get the date and convert it to timestamp **/
			var currentDate = $( "#Birthday" ).datepicker( "getDate" );
			var dateyDate = new Date(currentDate);				
			var ms = dateyDate.valueOf();
			/** to get the good date depending of the timezone **/
			ms = ms - (60000 * dateyDate.getTimezoneOffset());			
			/** set up the variable to send after **/
			$('#ubo_datetimestamp').val(ms);			
		});
		
		$(document).on('click','[id^="uboelementbutton_"]',function(){
			
			reset_error_messages_ubo();
			
			var jsondata = $(this).attr('data-uboelement');
			jsondata = jsondata.substr(1).slice(0, -1);
			var ubo_data = $.parseJSON(jsondata);
			
			$('#ubo_element_id').val(ubo_data.Id);
			
			$('#FirstName').val(ubo_data.FirstName);
			$('#LastName').val(ubo_data.LastName);
			$('#AddressLine1').val(ubo_data.Address.AddressLine1);
			$('#AddressLine2').val(ubo_data.Address.AddressLine2);
			$('#City').val(ubo_data.Address.City);
			$('#Region').val(ubo_data.Address.Region);
			$('#PostalCode').val(ubo_data.Address.PostalCode);
			$('#BirthplaceCity').val(ubo_data.Birthplace.City);
			//selector country
			$('#ubo_Country_select').val(ubo_data.Address.Country);
			$('#ubo_NationalityCountry_select').val(ubo_data.Nationality);
			$('#ubo_BirthplaceCountry_select').val(ubo_data.Birthplace.Country);			
			//date			
			var dateyDate = new Date(ubo_data.Birthday*1000);
			var new_date = datepickerL10n.dateFormat;
			new_date = new_date.replace('MM',dateyDate.getMonth()+1);
			new_date = new_date.replace('mm',dateyDate.getMonth()+1);
			new_date = new_date.replace('dd',dateyDate.getDate());
			new_date = new_date.replace('yy',dateyDate.getFullYear());			
			$('#Birthday').val(new_date);
			$('#ubo_datetimestamp').val(ubo_data.Birthday*1000);
			
			//reinit calendar
			$("#Birthday").datepicker(datepickerL10n);
			
			$('#add_button_ubo_element').hide();
			$('#ubo_askvalidation_button').hide();
			$('#ubo_add_button').hide();
			$('#update_button_ubo_element').show();
			
			//show form
			$('#form_add_ubo_element').show();
			
		});
		
		/** test the ubo element and  **/
		$(document).on("click",'#add_button_ubo_element', function() {
			send_ubo();			
		});
		$(document).on("click",'#update_button_ubo_element', function() {
			send_ubo();			
		});
		$(document).on("click",'#cancel_button_ubo_element', function() {
			$('#form_add_ubo_element').hide();
			show_add_button();
			$('#ubo_askvalidation_button').show();
			
			$('html, body').animate({
			   scrollTop: $("#ubo_data").offset().top
			}, 500);
			
		});	
	});
	
	
})(jQuery);