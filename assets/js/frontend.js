/* global bumpmintFrontend */
(function ($) {
	'use strict';

	var viewedRules = {};
	var pendingViews = {};
	var viewTimer = null;
	var viewObserver = null;

	function sendPendingViews() {
		var ruleIds = Object.keys( pendingViews );
		var offset;
		pendingViews = {};
		viewTimer = null;

		if ( ! ruleIds.length || ! bumpmintFrontend.analyticsAction || ! bumpmintFrontend.analyticsNonce ) {
			return;
		}

		for ( offset = 0; offset < ruleIds.length; offset += 20 ) {
			$.ajax( {
				url: bumpmintFrontend.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: bumpmintFrontend.analyticsAction,
					rule_ids: ruleIds.slice( offset, offset + 20 ),
					nonce: bumpmintFrontend.analyticsNonce
				}
			} );
		}
	}

	function queueView( ruleId ) {
		if ( ! ruleId || viewedRules[ ruleId ] ) {
			return;
		}

		viewedRules[ ruleId ] = true;
		pendingViews[ ruleId ] = true;

		if ( ! viewTimer ) {
			viewTimer = window.setTimeout( sendPendingViews, 250 );
		}
	}

	function observeOfferViews() {
		var cards = document.querySelectorAll( '.bumpmint-bump-box[data-bumpmint-rule-id]' );

		if ( viewObserver ) {
			viewObserver.disconnect();
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			Array.prototype.forEach.call( cards, function ( card ) {
				queueView( card.getAttribute( 'data-bumpmint-rule-id' ) );
			} );
			return;
		}

		viewObserver = new window.IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting && entry.intersectionRatio >= 0.35 ) {
					queueView( entry.target.getAttribute( 'data-bumpmint-rule-id' ) );
					viewObserver.unobserve( entry.target );
				}
			} );
		}, { threshold: [ 0.35 ] } );

		Array.prototype.forEach.call( cards, function ( card ) {
			var ruleId = card.getAttribute( 'data-bumpmint-rule-id' );
			if ( ruleId && ! viewedRules[ ruleId ] ) {
				viewObserver.observe( card );
			}
		} );
	}

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
				product_id: $checkbox.data( 'product-id' ),
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

	$( observeOfferViews );
	$( document.body ).on( 'updated_checkout', observeOfferViews );

})( jQuery );
