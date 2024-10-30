(function($) {
   var first_time_vendor_change = true;
	$(document).ready(function() {
        
       $("#vendor_account_country").on( 'change',function(){
            if(first_time_vendor_change){
                first_time_vendor_change = false;
            }else{
                $("#vendor_account_region").val('');    
            }
			
			//is vendor_account_region mandatory? add require in front
			var country_val = $("#vendor_account_country").val();
			if(country_val && (country_val == "MX" || country_val == "CA" || country_val == "US")){
				$("#mangopay_vendor_account_region_labelrequire").show();
			}else{
				$("#mangopay_vendor_account_region_labelrequire").hide();
			}
       });
       
    });
    
})( jQuery );    
