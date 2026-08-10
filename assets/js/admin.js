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
		});

		frame.open();
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

	$( document ).on( 'change', '#bumpmint-discount-enabled', function () {
		updateDiscountFields( $( this ).closest( 'form' ) );
	});

	$( function () {
		$( '.bumpmint-rule-form' ).each( function () {
			var $form = $( this );
			updateConditionFields( $form );
			updateProductTriggerFields( $form );
			updateDiscountFields( $form );
			initializeHelpTips( $form );
		});
	});

})( jQuery );
