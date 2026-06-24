/**
 * DoughBoss — storefront voucher claim widget.
 *
 * Hydrates [data-doughboss-voucher-claim]: the customer picks an offer, enters
 * their mobile, and we POST /voucher/claim to mint a single-use code (the daily
 * cap is enforced server-side). Reuses DoughBossData from the main storefront
 * app. No build step.
 */
( function () {
	'use strict';

	var cfg  = window.DoughBossData || {};
	var i18n = cfg.i18n || {};
	var rest = ( cfg.restUrl || '' ).replace( /\/$/, '' );

	var root = document.querySelector( '[data-doughboss-voucher-claim]' );
	if ( ! root || ! rest ) {
		return;
	}

	var offers   = root.querySelectorAll( '.db-vc-offer' );
	var form     = root.querySelector( '.db-vc-form' );
	var result   = root.querySelector( '.db-vc-result' );
	var selected = '';

	function show( kind, msg ) {
		result.className = 'db-vc-result is-' + kind;
		result.textContent = msg;
	}

	Array.prototype.forEach.call( offers, function ( btn ) {
		btn.addEventListener( 'click', function () {
			selected = btn.getAttribute( 'data-campaign' ) || '';
			Array.prototype.forEach.call( offers, function ( b ) { b.classList.remove( 'is-selected' ); } );
			btn.classList.add( 'is-selected' );
			if ( form ) {
				form.hidden = false;
				result.className = 'db-vc-result';
				result.textContent = '';
				var phone = form.querySelector( 'input[name="phone"]' );
				if ( phone ) {
					try { phone.focus(); } catch ( e ) {}
				}
			}
		} );
	} );

	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! selected ) {
				return;
			}
			var phone = ( form.querySelector( 'input[name="phone"]' ).value || '' ).trim();
			var email = ( form.querySelector( 'input[name="email"]' ).value || '' ).trim();
			if ( ! phone ) {
				show( 'bad', i18n.vNeedPhone || 'Please enter your mobile number.' );
				return;
			}
			var submit = form.querySelector( '.db-vc-submit' );
			if ( submit ) { submit.disabled = true; }
			show( 'pending', i18n.vClaiming || 'Getting your code…' );

			fetch( rest + '/voucher/claim', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
				body: JSON.stringify( { campaign: selected, customer_phone: phone, customer_email: email } )
			} ).then( function ( res ) {
				return res.json().then( function ( data ) { return { ok: res.ok, data: data }; } );
			} ).then( function ( r ) {
				if ( submit ) { submit.disabled = false; }
				if ( r.ok && r.data && r.data.code ) {
					renderCode( r.data.code );
				} else {
					show( 'bad', ( r.data && r.data.message ) || i18n.genericError || 'Something went wrong. Please try again.' );
				}
			} ).catch( function () {
				if ( submit ) { submit.disabled = false; }
				show( 'bad', i18n.genericError || 'Something went wrong. Please try again.' );
			} );
		} );
	}

	function renderCode( code ) {
		result.className = 'db-vc-result is-ok';
		result.innerHTML = '';
		var label = document.createElement( 'div' );
		label.className = 'db-vc-code-label';
		label.textContent = i18n.vYourCode || 'Your code';
		var c = document.createElement( 'div' );
		c.className = 'db-vc-code';
		c.textContent = code;
		var info = document.createElement( 'div' );
		info.className = 'db-vc-info';
		info.textContent = i18n.vUseInfo || 'Show this code at the till, or paste it at checkout. One use only.';
		result.appendChild( label );
		result.appendChild( c );
		result.appendChild( info );
		if ( form ) {
			form.hidden = true;
		}
	}
} )();
