/*global wc_country_select_params */
jQuery( function( $ ) {

	// wc_country_select_params is required to continue, ensure the object exists
	if ( typeof wc_country_select_params === 'undefined' ) {
		return false;
	}

	function getEnhancedSelectFormatString() {
		return {
			'language': {
				errorLoading: function() {
					// Workaround for https://github.com/select2/select2/issues/4355 instead of i18n_ajax_error.
					return wc_country_select_params.i18n_searching;
				},
				inputTooLong: function( args ) {
					var overChars = args.input.length - args.maximum;

					if ( 1 === overChars ) {
						return wc_country_select_params.i18n_input_too_long_1;
					}

					return wc_country_select_params.i18n_input_too_long_n.replace( '%qty%', overChars );
				},
				inputTooShort: function( args ) {
					var remainingChars = args.minimum - args.input.length;

					if ( 1 === remainingChars ) {
						return wc_country_select_params.i18n_input_too_short_1;
					}

					return wc_country_select_params.i18n_input_too_short_n.replace( '%qty%', remainingChars );
				},
				loadingMore: function() {
					return wc_country_select_params.i18n_load_more;
				},
				maximumSelected: function( args ) {
					if ( args.maximum === 1 ) {
						return wc_country_select_params.i18n_selection_too_long_1;
					}

					return wc_country_select_params.i18n_selection_too_long_n.replace( '%qty%', args.maximum );
				},
				noResults: function() {
					return wc_country_select_params.i18n_no_matches;
				},
				searching: function() {
					return wc_country_select_params.i18n_searching;
				}
			}
		};
	}

	// Select2 Enhancement if it exists	
	if ( $().select2 && $('#vendor_account_country').length<1) {
		var wc_country_select_select2 = function() {
			$( 'select.country_select:visible, select.state_select:visible' ).each( function() {
				var select2_args = $.extend({
					placeholderOption: 'first',
					width: '100%'
				}, getEnhancedSelectFormatString() );
				$( this ).select2( select2_args );
				// Maintain focus after select https://github.com/select2/select2/issues/4384
				$( this ).on( 'select2:select', function() {
					$( this ).focus();
				} );				
			});			
		};
		wc_country_select_select2();

		$( document.body ).bind( 'country_to_state_changed', function() {
			wc_country_select_select2();
		});
	}

	/* State/Country select boxes */
	var states_json = wc_country_select_params.countries.replace( /&quot;/g, '"' ),
		states = $.parseJSON( states_json );


    function select_the_target_element(the_parent_element){
        var data_to_return = {};
        data_to_return.need_to_hide = true;
        var who_is_the_select = $( the_parent_element ).attr('id');

        if(who_is_the_select == 'vendor_account_country' && $('#vendor_account_region').length>0){
            data_to_return.the_element_to_return = $('#vendor_account_region');
            data_to_return.need_to_hide = false;
            data_to_return.value_at_load = $('#vendor_account_region').val();
            data_to_return.field_type = $('#vendor_account_region').attr('type');
        }

        if(who_is_the_select == 'billing_country' && $('#billing_state').length>0){
            data_to_return.the_element_to_return = $('#billing_state');
        }            

        return data_to_return;
    }
	$( document.body ).on( 'change', 'select.country_to_state, input.country_to_state', function() {
		
		// Grab wrapping element to target only stateboxes in same 'group'
		var $wrapper    = $( this ).closest('.woocommerce-billing-fields, .woocommerce-shipping-fields, .woocommerce-shipping-calculator');

		if ( ! $wrapper.length ) {
			$wrapper = $( this ).closest('.form-row').parent();
		}

        var data_selected_element = select_the_target_element(this);
				
        the_target_element = data_selected_element.the_element_to_return;
        if(!the_target_element){
            the_target_element = $wrapper.find('#shipping_state, #calc_shipping_state' );
        }
        
		var country     = $( this ).val(),
			$statebox   = the_target_element,
			$parent     = $statebox.parent(),
			input_name  = $statebox.attr( 'name' ),
			input_id    = $statebox.attr( 'id' ),
			value       = $statebox.val(),
			placeholder = $statebox.attr( 'placeholder' ) || $statebox.attr( 'data-placeholder' ) || '';

		//reset label
		$("#mangopay_vendor_account_region_labelrequire").hide();
				
		if ( states[ country ] ) {
			if ( $.isEmptyObject( states[ country ] ) ) {		
                if(data_selected_element.need_to_hide){
                    $statebox.parent().hide().find( '.select2-container' ).remove();
                    $statebox.replaceWith( '<input type="hidden" class="hidden" name="' + input_name + '" id="' + input_id + '" value="" placeholder="' + placeholder + '" />' );
					$('#billing_state_field').hide();
                }else{                    
                    if(data_selected_element.value_at_load == undefined || data_selected_element.field_type != 'text'){
                        data_selected_element.value_at_load = '';
                    }                    
                    //get the first child
                    $('#mangopay_vendor_account_country_td').empty();
                    $('#mangopay_vendor_account_country_td').html( '<input type="text" class="input-text" name="' + input_name + '" id="' + input_id + '" value="'+data_selected_element.value_at_load+'" placeholder="' + placeholder + '" />' );
                }

				$( document.body ).trigger( 'country_to_state_changed', [ country, $wrapper ] );

			} else {					
				var options = '',
					state = states[ country ];

				for( var index in state ) {
					if ( state.hasOwnProperty( index ) ) {
						options = options + '<option value="' + index + '">' + state[ index ] + '</option>';
					}
				}

				$statebox.parent().show();

				if ( $statebox.is( 'input' ) ) {
					// Change for select
					$statebox.replaceWith( '<select name="' + input_name + '" id="' + input_id + '" class="state_select" data-placeholder="' + placeholder + '"></select>' );
					//$statebox = $wrapper.find( '#billing_state, #shipping_state, #calc_shipping_state' );
                    
                    var the_target_element = '';
                    //the_target_element = select_the_target_element(this);
                    var data_selected_element = select_the_target_element(this);
                    the_target_element = data_selected_element.the_element_to_return;
                    if(!the_target_element){
                        the_target_element = $wrapper.find('#shipping_state, #calc_shipping_state' );
                    }
                    $statebox = the_target_element;
					$('#billing_state_field').show();
				}

				$statebox.html( '<option value="">' + wc_country_select_params.i18n_select_state_text + '</option>' + options );
				$statebox.val( value ).change();

				$( document.body ).trigger( 'country_to_state_changed', [country, $wrapper ] );
				
				//is vendor_account_region mandatory? add require in front
				var country_val = $("#vendor_account_country").val();
				if(country_val && (country_val == "MX" || country_val == "CA" || country_val == "US")){
					$("#mangopay_vendor_account_region_labelrequire").show();
				}
			}
		} else {
						
			if ( $statebox.is( 'select' ) ) {
				$parent.show().find( '.select2-container' ).remove();
				$statebox.replaceWith( '<input type="text" class="input-text" name="' + input_name + '" id="' + input_id + '" placeholder="' + placeholder + '" />' );
				$( document.body ).trigger( 'country_to_state_changed', [country, $wrapper ] );

			} else if ( $statebox.is( 'input[type="hidden"]' ) ) {
				$parent.show().find( '.select2-container' ).remove();
				$statebox.replaceWith( '<input type="text" class="input-text" name="' + input_name + '" id="' + input_id + '" placeholder="' + placeholder + '" />' );
				$('#billing_state_field').show();
				$( document.body ).trigger( 'country_to_state_changed', [country, $wrapper ] );

			}
		}			

		$( document.body ).trigger( 'country_to_state_changing', [country, $wrapper ] );

	});

	$(function() {
		$( ':input.country_to_state' ).change();
	});

});