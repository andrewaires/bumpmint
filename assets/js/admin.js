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
		updateConditionFields( $( this ).closest( 'form' ) );
	});

	$( document ).on( 'change', '#bumpmint-discount-enabled', function () {
		updateDiscountFields( $( this ).closest( 'form' ) );
	});

	$( function () {
		$( '.bumpmint-rule-form' ).each( function () {
			var $form = $( this );
			updateConditionFields( $form );
			updateDiscountFields( $form );
			initializeHelpTips( $form );
		});
	});

})( jQuery );
