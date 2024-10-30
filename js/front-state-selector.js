(function($) {
        
	$(document).ready(function() {
        
		if ($('#billing_country').length > 0) {
	    				
			/** Initialization **/
			test_state_selector();
			    
			/** Retest when changed **/
//			$('#billing_country').change(function() {
//			    test_state_selector();
//			});
			$('#billing_country').change(function() {
                setTimeout(function(){
                    test_state_selector();
                }, 500);
			});
			
	    }
	});
	
	/**
	 * Will be called upon page init
	 * 
	 */
	function test_state_selector() {

        /* entire block billing_state_field */
        /* billing_state is the input */
        if ($('#billing_state').length > 0) { 
            
            var is_element_select = $('#billing_state').is("select");
            if(is_element_select){
                                
                //test parent field country
                var country_value = $('#billing_country').val();
                if($('#wc_country_state_'+country_value).val()){
                    $('#billing_state_field label').html($('#wc_country_state_'+country_value).val());
                }else{
                    $('#billing_state_field label').html($('#wc_country_state_default').val());
                }
                
                if(country_value && (country_value == "MX" || country_value == "CA" || country_value == "US")){
					$('.billing_state_field_required').show();
                    $('#billing_state_field label').append('&nbsp;<abbr id="state_require_star" class="required" title="required">*</abbr>');
                }else{
                    $('#state_require_star').hide();
					$('.billing_state_field_required').hide();
                }
                
                $('#billing_state_field').show();
                $('.billing_state_field').show();
                
            }else{
                $('#billing_state_field').hide();
                $('.billing_state_field').hide();
            }
            
        }

	}

 
})( jQuery );