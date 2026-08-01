/* global bumpmintFrontend */
(function ($) {
	'use strict';

	$( document ).on( 'change', '.bumpmint-checkbox', function () {
		var $checkbox = $( this );
		var $card = $checkbox.closest( '.bumpmint-bump-box' );
		var $feedback = $card.find( '.bumpmint-bump-feedback' );
		var shouldAdd = $checkbox.is( ':checked' ) ? 1 : 0;

		$checkbox.prop( 'disabled', true );
		$card.addClass( 'is-loading' );
		$feedback.text( '' );

		$.ajax({
			url: bumpmintFrontend.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'bumpmint_toggle_bump',
				rule_id: $checkbox.data( 'rule-id' ),
				add: shouldAdd,
				nonce: bumpmintFrontend.nonce
			}
		})
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					$checkbox.prop( 'checked', ! shouldAdd );
					$feedback.text( bumpmintFrontend.genericError );
					return;
				}

				$( document.body ).trigger( 'update_checkout' );
			})
			.fail( function ( xhr ) {
				var message = bumpmintFrontend.genericError;
				if (
					xhr.responseJSON &&
					xhr.responseJSON.data &&
					xhr.responseJSON.data.message
				) {
					message = xhr.responseJSON.data.message;
				}

				$checkbox.prop( 'checked', ! shouldAdd );
				$feedback.text( message );
			})
			.always( function () {
				$checkbox.prop( 'disabled', false );
				$card.removeClass( 'is-loading' );
			});
	});

})( jQuery );
