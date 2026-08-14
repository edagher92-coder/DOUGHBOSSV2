( function () {
	'use strict';

	function normaliseScan( value ) {
		try {
			var url = new URL( String( value || '' ).trim(), window.location.origin );
			if ( url.origin !== window.location.origin || url.pathname.replace( /\/+$/, '' ) !== '/staff-clock' ) {
				return '';
			}
			return url.searchParams.get( 'staff_badge' ) ? url.toString() : '';
		} catch ( e ) {
			return '';
		}
	}

	var scan = document.getElementById( 'db-badge-scan' );
	if ( scan ) {
		var submitScan = function () {
			var destination = normaliseScan( scan.value );
			if ( destination ) {
				window.location.assign( destination );
				return true;
			}
			return false;
		};
		scan.addEventListener( 'change', submitScan );
		scan.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && submitScan() ) {
				event.preventDefault();
			}
		} );
		window.setTimeout( function () { try { scan.focus(); } catch ( e ) {} }, 80 );
	}

	document.querySelectorAll( '.db-timeclock-keypad' ).forEach( function ( keypad ) {
		var target = document.getElementById( keypad.getAttribute( 'data-target' ) || '' );
		if ( ! target ) {
			return;
		}
		keypad.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( 'button[data-key]' );
			if ( ! button ) {
				return;
			}
			var key = String( button.getAttribute( 'data-key' ) || '' );
			if ( 'clear' === key ) {
				target.value = '';
			} else if ( 'back' === key ) {
				target.value = target.value.slice( 0, -1 );
			} else if ( /^\d$/.test( key ) && target.value.length < 8 ) {
				target.value += key;
			}
			target.focus();
		} );
	} );
} )();
