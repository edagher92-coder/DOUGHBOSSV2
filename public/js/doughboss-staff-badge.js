( function () {
	'use strict';

	function normaliseScan( value ) {
		try {
			var url = new URL( String( value || '' ).trim(), window.location.origin );
			if ( url.origin !== window.location.origin || url.pathname.replace( /\/+$/, '' ) !== '/staff-clock' ) {
				return '';
			}
			var fragment = url.hash.match( /^#staff-badge=([A-Za-z0-9_-]{40,120})$/ );
			if ( fragment ) {
				return fragment[ 1 ];
			}
			return /^[A-Za-z0-9_-]{40,120}$/.test( url.searchParams.get( 'staff_badge' ) || '' ) ? url.searchParams.get( 'staff_badge' ) : '';
		} catch ( e ) {
			return '';
		}
	}

	function postBadge( action, token ) {
		if ( ! action || ! token ) {
			return false;
		}
		var form = document.createElement( 'form' );
		form.method = 'post';
		form.action = action;
		[ [ 'action', 'doughboss_staff_badge_scan' ], [ 'staff_badge', token ] ].forEach( function ( field ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = field[ 0 ];
			input.value = field[ 1 ];
			form.appendChild( input );
		} );
		document.body.appendChild( form );
		form.submit();
		return true;
	}

	var exchange = document.querySelector( '[data-badge-scan-action]' );
	var action = exchange ? exchange.getAttribute( 'data-badge-scan-action' ) || '' : '';
	var scan = document.getElementById( 'db-badge-scan' );
	if ( scan ) {
		var submitScan = function () {
			return postBadge( action, normaliseScan( scan.value ) );
		};
		scan.addEventListener( 'change', submitScan );
		scan.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && submitScan() ) {
				event.preventDefault();
			}
		} );
		if ( ! window.location.hash ) {
			window.setTimeout( function () { try { scan.focus(); } catch ( e ) {} }, 80 );
		}
	}

	// Badge fragments must replace any surviving kiosk identity even when the
	// current page is already showing another employee's PIN or action screen.
	function exchangeFragment() {
		var token = normaliseScan( window.location.href );
		if ( ! window.location.hash || ! token ) {
			return false;
		}
		window.history.replaceState( null, '', window.location.pathname + window.location.search );
		return postBadge( action, token );
	}
	exchangeFragment();
	window.addEventListener( 'hashchange', exchangeFragment );

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
