/* global wp, bumpmintAdmin */
(function ($) {
	'use strict';

	function openMediaFrame( $form ) {
		var frame = wp.media({
			title: bumpmintAdmin.selectImageTitle,
			button: { text: bumpmintAdmin.useImageText },
			multiple: false
		});

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var previewUrl = ( attachment.sizes && attachment.sizes.thumbnail )
				? attachment.sizes.thumbnail.url
				: attachment.url;

			$form.find( '.bumpmint-image-id' ).val( attachment.id );
			$form.find( '.bumpmint-image-preview' ).attr( 'src', previewUrl ).show();
			$form.find( '.bumpmint-remove-image' ).show();
			triggerOfferContentChange( $form );
		});

		frame.open();
	}

	function triggerOfferContentChange( $form ) {
		var form = $form && $form.length ? $form[ 0 ] : null;

		if ( ! form ) {
			return;
		}

		if ( typeof window.CustomEvent === 'function' ) {
			form.dispatchEvent( new window.CustomEvent( 'bumpmint:offer-content-change', { bubbles: true } ) );
		} else {
			$( form ).trigger( 'bumpmint:offer-content-change' );
		}
	}

	function updateConditionFields( $form ) {
		var selectedType = $form.find( '#bumpmint-condition-type' ).val();

		$form.find( '.bumpmint-condition-fields' ).each( function () {
			var $row = $( this );
			var supportedTypes = String( $row.data( 'condition-types' ) || '' ).split( /\s+/ );
			var isVisible = supportedTypes.indexOf( selectedType ) !== -1;

			$row.toggle( isVisible );
			$row.find( ':input' ).prop( 'disabled', ! isVisible );
		});
	}

	function updateProductTriggerFields( $form ) {
		var isProductCondition = 'product' === $form.find( '#bumpmint-condition-type' ).val();
		var categoriesEnabled  = $form.find( '#bumpmint-condition-category-enabled' ).is( ':checked' );
		var hasProducts        = $form.find( '#bumpmint-condition-product option:selected' ).length > 0;
		var $categoryRow       = $form.find( '.bumpmint-category-select-row' );
		var $categorySelect    = $form.find( '#bumpmint-condition-category' );
		var $matchRow          = $form.find( '#bumpmint-condition-match' ).closest( '.bumpmint-condition-fields' );

		$categoryRow.toggle( isProductCondition && categoriesEnabled );
		$categorySelect.prop( 'disabled', !( isProductCondition && categoriesEnabled ) );
		$matchRow.toggle( isProductCondition && hasProducts );
		$matchRow.find( ':input' ).prop( 'disabled', !( isProductCondition && hasProducts ) );
	}

	function updateCustomerFields( $form ) {
		var targetsRoles = 'roles' === $form.find( '#bumpmint-customer-audience' ).val();
		var $rolesRow    = $form.find( '.bumpmint-customer-roles-row' );

		$rolesRow.toggle( targetsRoles );
		$rolesRow.find( ':input' ).prop( 'disabled', ! targetsRoles );
	}

	function initializeCouponSearch( $form ) {
		if ( typeof $.fn.selectWoo !== 'function' ) {
			return;
		}

		$form.find( '.bumpmint-coupon-search' ).each( function () {
			var $field = $( this );

			if ( $field.data( 'bumpmintCouponSearchInitialized' ) ) {
				return;
			}

			$field.selectWoo({
				ajax: {
					url: bumpmintAdmin.ajaxUrl,
					dataType: 'json',
					delay: 250,
					cache: true,
					data: function ( params ) {
						return {
							action: 'bumpmint_search_coupons',
							nonce: bumpmintAdmin.couponSearchNonce,
							term: params.term || '',
							page: params.page || 1
						};
					},
					processResults: function ( response ) {
						var data = response && response.success && response.data ? response.data : {};

						return {
							results: Array.isArray( data.results ) ? data.results : [],
							pagination: data.pagination || { more: false }
						};
					}
				},
				allowClear: true,
				minimumInputLength: 0,
				placeholder: $field.data( 'placeholder' ) || '',
				width: 'resolve'
			});
			$field.data( 'bumpmintCouponSearchInitialized', true );
		});
	}

	function updateCouponFields( $form ) {
		var targetsSpecificCoupons = 'specific' === $form.find( '#bumpmint-coupon-mode' ).val();
		var $couponFields          = $form.find( '.bumpmint-coupon-fields' );

		$couponFields.toggle( targetsSpecificCoupons );
		$couponFields.find( ':input' ).prop( 'disabled', ! targetsSpecificCoupons );
	}

	function updateLocationFields( $form ) {
		var targetsLocations = 'specific' === $form.find( '#bumpmint-location-mode' ).val();
		var $locationFields  = $form.find( '.bumpmint-location-fields' );
		var $statesRow       = $form.find( '.bumpmint-location-states-row' );
		var $statesSelect    = $form.find( '#bumpmint-location-states' );
		var hasStates        = $statesSelect.find( 'option' ).length > 0;

		$locationFields.toggle( targetsLocations );
		$locationFields.find( ':input' ).prop( 'disabled', ! targetsLocations );
		$statesRow.toggle( targetsLocations && hasStates );
		$statesSelect.prop( 'disabled', !( targetsLocations && hasStates ) );
	}

	function loadLocationStates( $form ) {
		var $countries = $form.find( '#bumpmint-location-countries' );
		var $states = $form.find( '#bumpmint-location-states' );
		var $feedback = $form.find( '.bumpmint-location-state-feedback' );
		var countryCodes = $countries.val() || [];
		var selectedStates = $states.val() || [];
		var previousRequest = $form.data( 'bumpmintLocationRequest' );
		var request;

		if ( previousRequest && previousRequest.readyState !== 4 ) {
			previousRequest.abort();
		}

		$feedback.text( '' );
		if ( ! countryCodes.length ) {
			$states.empty().trigger( 'change' );
			updateLocationFields( $form );
			return;
		}

		$states.prop( 'disabled', true );
		request = $.ajax({
			url: bumpmintAdmin.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'bumpmint_get_location_states',
				nonce: bumpmintAdmin.locationNonce,
				country_codes: countryCodes
			}
		});
		$form.data( 'bumpmintLocationRequest', request );

		request.done( function ( response ) {
			var states;

			if ( $form.data( 'bumpmintLocationRequest' ) !== request ) {
				return;
			}

			if ( ! response || ! response.success || ! response.data || ! Array.isArray( response.data.states ) ) {
				$feedback.text( bumpmintAdmin.locationLoadError || '' );
				return;
			}
			states = response.data.states;

			$states.empty();
			states.forEach( function ( state ) {
				var isSelected = selectedStates.indexOf( state.value ) !== -1;
				$( '<option />' )
					.val( state.value )
					.text( state.label )
					.prop( 'selected', isSelected )
					.appendTo( $states );
			});
			$states.trigger( 'change' );
		});

		request.fail( function ( xhr, status ) {
			if ( 'abort' !== status && $form.data( 'bumpmintLocationRequest' ) === request ) {
				$feedback.text( bumpmintAdmin.locationLoadError || '' );
			}
		});

		request.always( function () {
			if ( $form.data( 'bumpmintLocationRequest' ) === request ) {
				updateLocationFields( $form );
			}
		});
	}

	function updateScheduleFields( $form ) {
		var scheduleType = $form.find( '#bumpmint-schedule-type' ).val();
		var showDates    = 'dates' === scheduleType;
		var showWeekdays = 'weekdays' === scheduleType;
		var showTimes    = $form.find( '#bumpmint-schedule-time-enabled' ).is( ':checked' );
		var $dateRow     = $form.find( '.bumpmint-schedule-dates-row' );
		var $weekdayRow  = $form.find( '.bumpmint-schedule-weekdays-row' );
		var $timeRow     = $form.find( '.bumpmint-schedule-time-row' );

		$dateRow.toggle( showDates );
		$dateRow.find( ':input' ).prop( 'disabled', ! showDates );
		$weekdayRow.toggle( showWeekdays );
		$weekdayRow.find( ':input' ).prop( 'disabled', ! showWeekdays );
		$timeRow.toggle( showTimes );
		$timeRow.find( ':input' ).prop( 'disabled', ! showTimes );
	}

	function updateDiscountFields( $form ) {
		var enabled = $form.find( '#bumpmint-discount-enabled' ).is( ':checked' );
		$form.find( '.bumpmint-discount-fields' ).toggle( enabled );
		$form.find( '.bumpmint-discount-fields :input' ).prop( 'disabled', ! enabled );
	}

	function initializeHelpTips( $form ) {
		if ( typeof $.fn.tipTip !== 'function' ) {
			return;
		}

		$form.find( '.woocommerce-help-tip' ).tipTip({
			attribute: 'data-tip',
			fadeIn: 50,
			fadeOut: 50,
			delay: 200
		});
	}

	$( document ).on( 'click', '.bumpmint-select-image', function ( event ) {
		event.preventDefault();
		openMediaFrame( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'click', '.bumpmint-remove-image', function ( event ) {
		event.preventDefault();
		var $form = $( this ).closest( 'form' );
		$form.find( '.bumpmint-image-id' ).val( '' );
		$form.find( '.bumpmint-image-preview' ).attr( 'src', '' ).hide();
		$( this ).hide();
		triggerOfferContentChange( $form );
	});

	$( document ).on( 'click', '.bumpmint-delete-link', function ( event ) {
		if ( ! window.confirm( bumpmintAdmin.confirmDelete ) ) {
			event.preventDefault();
		}
	});

	$( document ).on( 'change', '#bumpmint-condition-type', function () {
		var $form = $( this ).closest( 'form' );
		updateConditionFields( $form );
		updateProductTriggerFields( $form );
	});

	$( document ).on( 'change', '#bumpmint-condition-category-enabled, #bumpmint-condition-product', function () {
		updateProductTriggerFields( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'change', '#bumpmint-customer-audience', function () {
		updateCustomerFields( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'change', '#bumpmint-location-mode', function () {
		updateLocationFields( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'change', '#bumpmint-location-countries', function () {
		loadLocationStates( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'change', '#bumpmint-coupon-mode', function () {
		updateCouponFields( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'change', '#bumpmint-discount-enabled', function () {
		updateDiscountFields( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'change', '#bumpmint-schedule-type, #bumpmint-schedule-time-enabled', function () {
		updateScheduleFields( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'click', '.bumpmint-add-schedule-date', function () {
		var $form = $( this ).closest( 'form' );
		var $rows = $form.find( '.bumpmint-schedule-date-row' );
		var maxDates = Number( bumpmintAdmin.maxScheduleDates || 366 );

		if ( $rows.length >= maxDates ) {
			return;
		}

		var $newRow = $rows.first().clone();
		$newRow.find( 'input' ).val( '' ).prop( 'disabled', false );
		$newRow.find( 'button' ).prop( 'disabled', false );
		$newRow.appendTo( $form.find( '.bumpmint-schedule-dates' ) );
		$newRow.find( 'input' ).trigger( 'focus' );
	});

	$( document ).on( 'click', '.bumpmint-remove-schedule-date', function () {
		var $row = $( this ).closest( '.bumpmint-schedule-date-row' );
		var $rows = $row.closest( '.bumpmint-schedule-dates' ).find( '.bumpmint-schedule-date-row' );

		if ( $rows.length > 1 ) {
			$row.remove();
			return;
		}

		$row.find( 'input' ).val( '' ).trigger( 'focus' );
	});

	$( function () {
		$( '.bumpmint-rule-form' ).each( function () {
			var $form = $( this );
			initializeCouponSearch( $form );
			updateConditionFields( $form );
			updateProductTriggerFields( $form );
			updateCustomerFields( $form );
			updateLocationFields( $form );
			updateCouponFields( $form );
			updateScheduleFields( $form );
			updateDiscountFields( $form );
			initializeHelpTips( $form );
		});
	});

})( jQuery );
