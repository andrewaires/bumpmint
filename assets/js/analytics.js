/* global bumpmintAnalyticsAdmin */
( function () {
	'use strict';

	function toggleCustomDates() {
		var range = document.getElementById( 'bumpmint-analytics-range' );
		var fields = document.querySelectorAll( '.bumpmint-custom-date' );
		var isCustom = range && range.value === 'custom';

		Array.prototype.forEach.call( fields, function ( field ) {
			var input = field.querySelector( 'input' );
			field.hidden = ! isCustom;
			if ( input ) {
				input.disabled = ! isCustom;
			}
		} );
	}

	function initialize() {
		var range = document.getElementById( 'bumpmint-analytics-range' );
		var resetForm = document.querySelector( '.bumpmint-reset-form' );

		if ( range ) {
			range.addEventListener( 'change', toggleCustomDates );
			toggleCustomDates();
		}

		if ( resetForm ) {
			resetForm.addEventListener( 'submit', function ( event ) {
				var message = window.bumpmintAnalyticsAdmin && bumpmintAnalyticsAdmin.confirmReset
					? bumpmintAnalyticsAdmin.confirmReset
					: 'Permanently delete all BumpMint Analytics data?';
				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initialize );
	} else {
		initialize();
	}
}() );
