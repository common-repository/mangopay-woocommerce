(function($) {

	var url = window.location.href;

	$(document).ready(function() {
		if (url.indexOf('wcv-vendor-shopsettings') > 0) {

			$('<form id="form_kyc" action="" method="post" enctype="multipart/form-data" class="kyc_form kyc_form wcv-form wcv-formvalidator" style="margin-top:20px;"></form>').appendTo($('#wpbody-content'));
			$('#kyc_div_global').appendTo($('#form_kyc'));
			$('#kyc_div_global').show();
		}
	});
})(jQuery);

/** Pre-authorizations **/
(function($) {

	$(document).ready(function() {
		
//		$("[id^=preauth_items_amount]").live("focus", function() {
//			test_amount_input($(this).attr('id'));
//		});
//		$("[id^=preauth_shipping_amount]").live("focus", function() {
//			test_amount_input($(this).attr('id'));
//		});		
//
//		$("[id^=preauth_items_amount]").live("change", function() {
//			test_amount_input($(this).attr('id'));
//		});
//		$("[id^=preauth_shipping_amount]").live("change", function() {
//			test_amount_input($(this).attr('id'));
//		});
//
//		$("#applypartialcapture").live("click", function() {			
//			if($("#applypartialcapture").is(':checked')){
//				$("#partial_capture_preauth_submit").prop('disabled', false);
//				$("#cancel_preauth").prop('disabled', false);
//			}else{
//				$("#partial_capture_preauth_submit").prop('disabled', true);				
//				$("#cancel_preauth").prop('disabled', true);
//			}
//		});

		$('#capture_preauth_button').click(function() {			
			$('#applycompletecapture').val(true);
			$('#post').submit();
		});		

		/** Cancel pre-authorization by ajax **/
		$('#cancel_preauth').click(function() {			
			$('#cancelcapture').val(true);
			$('#post').submit();
		});
	});

//	function test_amount_input(key) {
//		
//		$("#applypartialcapture").prop('checked', false);
//		$("#partial_capture_preauth_submit").prop('disabled', true);
//		$("#cancel_preauth").prop('disabled', true);
//
//		/** Replace the "," with "." to make it a float **/
//		var value_changed = parseFloat($("#" + key).val().replace(',', '.'));
//
//		/** Forced change **/
//		$("#" + key).val(value_changed);
//
//		/** Get high limit **/
//		var value_origin = parseFloat($("#" + key).attr('data-limit'));
//
//		/** If it is not a number, forcefully-restore the original number **/
//		if (isNaN(value_changed) || value_changed == undefined || !jQuery.isNumeric(value_changed)) {
//			$("#" + key).val(value_origin);
//		}
//
//		/** If the value is too high or too low, restore original **/
//		if (value_changed > value_origin || value_changed < 0) {
//			$("#" + key).val(value_origin);
//		}
//
//		/** ITEMS change VAT line if needed **/
//		var part_id = $("#" + key).attr('data-idproduct');
//		var items_tva_amount_origin = parseFloat($("#preauth_items_tva_amount_" + part_id).attr('data-limit'));
//		var item_value_changed = parseFloat($("#preauth_items_amount_" + part_id).val());
//		var item_value_origin = parseFloat($("#preauth_items_amount_" + part_id).attr('data-limit'));
//		var new_items_tva = 0;
//		if (item_value_origin != 0 && item_value_changed != 0) {
//			var perc_item = (item_value_changed * 100) / item_value_origin;
//			new_items_tva = (items_tva_amount_origin * perc_item) / 100;
//		}
//		$("#preauth_items_tva_amount_" + part_id).html(new_items_tva);
//
//		/** SHIPPING change VAT line if needed **/
//		var part_id = $("#" + key).attr('data-idshipping');
//		var shipping_tva_amount_origin = parseFloat($("#preauth_shipping_tva_amount_" + part_id).attr('data-limit'));
//		var shipping_value_changed = parseFloat($("#preauth_shipping_amount_" + part_id).val());
//		var shipping_value_origin = parseFloat($("#preauth_shipping_amount_" + part_id).attr('data-limit'));
//		var new_shipping_tva = 0;
//		if (shipping_value_origin != 0 && shipping_value_changed != 0) {
//			var perc_shiping = (shipping_value_changed * 100) / shipping_value_origin;
//			new_shipping_tva = (shipping_tva_amount_origin * perc_shiping) / 100;
//		}
//		$("#preauth_shipping_tva_amount_" + part_id).html(new_shipping_tva);
//
//		/** Update total **/
//		//var total = new_items_tva+new_shipping_tva+item_value_changed+shipping_value_changed;
//		var total = 0;
//		$.each($("[id^=preauth_items_amount]"), function(index, element) {
//			total = parseFloat($(element).val()) + total;
//		});
//		$.each($("[id^=preauth_items_tva_amount]"), function(index, element) {
//			total = parseFloat($(element).html()) + total;
//		});
//		$.each($("[id^=preauth_shipping_amount]"), function(index, element) {
//			total = parseFloat($(element).val()) + total;
//		});
//		$.each($("[id^=preauth_shipping_tva_amount]"), function(index, element) {
//			total = parseFloat($(element).html()) + total;
//		});
//
//		$('#preauth_total_amount').html(total.toFixed(2));
//	}

//	function change_preauth_text(status_returned) {
//
//		$('#result_capture_waiting').hide();
//		$('#result_capture_captured').hide();
//		$('#result_capture_canceled').hide();
//		$('#waitingmessage_capture').hide();
//
//		if (status_returned == "WAITING") {
//			$('#result_capture_waiting').show();
//			$('#capture_preauth').show();
//			$('#cancel_preauth').show();
//		}
//		if (status_returned == "VALIDATED") {
//			$('#result_capture_captured').show();
//		}
//		if (status_returned == "CANCELED") {
//			$('#result_capture_canceled').show();
//		}
//
//		try {
//			var status_returned_obj = JSON.parse(status_returned);
//			if (status_returned_obj.message != undefined && status_returned_obj.message == "VALIDATED") {
//				$('#result_capture_captured').show();
//			}
//		} catch (e) {
//			return false;
//		}
//	}
})(jQuery);

(function($) {
	$(document).ready(function() {
				
		if(translate_object.require_translation!= undefined || translate_object.require_translation!=''){
			require_translation = ' (required)';
		}		
		var text_to_include = ' <span class="description required">'+require_translation+'</span>';
		$('label[for=first_name],label[for=last_name],label[for=billing_country]').append(text_to_include);
		
		if(typeof datepickerL10n != "undefined" && datepickerL10n!=""){
			$('input.calendar').datepicker(datepickerL10n);
		}

		if( 'business'==$('#user_mp_status').val() )
			$('.hide_business_type').show();
	});
	$('#user_mp_status').on('change',function(e){
		if( 'business'==$('#user_mp_status').val() ) {
			$('.hide_business_type').show();
		} else {
			$('.hide_business_type').hide();
		}
	});
})( jQuery );

/** handle close notice **/
(function($) {
	$(document).ready(function() {
		//class added to the message, special to find this click
		$('.findforclosing').on('click',function(e){
			//cookie to not print warning anymore
			document.cookie = "findforclosing=donotprint;";
		});
	});
})( jQuery );

/** handle admin list of users **/
(function($) {
	$(document).ready(function() {
		//class added to the message, special to find this click
		$('.openusermangopaydetails').on('click',function(e){
			let idtarget = jQuery(this).attr('data-id');
			//close all
			jQuery('.trid_'+idtarget).toggle();
			
			if(jQuery('.arrow_'+idtarget).hasClass('arrowtableusersleft')){
				jQuery('.arrow_'+idtarget).removeClass('arrowtableusersleft');
				jQuery('.arrow_'+idtarget).addClass('arrowtableusersdown');
			}else{
				jQuery('.arrow_'+idtarget).removeClass('arrowtableusersdown');
				jQuery('.arrow_'+idtarget).addClass('arrowtableusersleft');
			}
		});
	});
})( jQuery );