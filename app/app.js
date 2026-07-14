/**
 * DoughBoss Console — standalone staff app.
 *
 * A static single-page app (host anywhere, e.g. GitHub Pages) that signs in to a
 * DoughBoss WordPress site with an Application Password and drives the
 * doughboss/v1 REST API: Voucher Scan, Vouchers management, and the Order Board.
 * No build step, no framework.
 */
( function () {
	'use strict';

	var NS = 'doughboss/v1';
	var STORE = 'dbconsole';
	var DEFAULT_SITE = 'https://doughboss.com.au';

	var root = document.getElementById( 'app' );
	var state = load();
	state.boardKey = boardKeyFromUrl();
	var pollTimer = null;
	var clearTimer = null;
	var camScanner = null;
	var eventSource = null; // Mercure SSE, when the site advertises it on /config.
	var fieldSeq = 0;

	function boardKeyFromUrl() {
		try { return new URLSearchParams( window.location.search ).get( 'key' ) || ''; }
		catch ( e ) { return ''; }
	}

	/* ---------- storage ---------- */
	function load() {
		try {
			var stored = JSON.parse( localStorage.getItem( STORE ) ) || {};
			// Never restore a reusable Application Password (or server-provided
			// capability/config data) from persistent browser storage. This also
			// removes credentials written by older Console versions on first load.
			var safe = {
				site: typeof stored.site === 'string' ? stored.site : '',
				user: typeof stored.user === 'string' ? stored.user : '',
				tab: typeof stored.tab === 'string' ? stored.tab : ''
			};
			localStorage.setItem( STORE, JSON.stringify( safe ) );
			return safe;
		} catch ( e ) {
			try { localStorage.removeItem( STORE ); } catch ( ignored ) {}
			return {};
		}
	}
	function save() {
		// Preferences only. The Application Password and capabilities live in
		// memory and are cleared by sign-out, reload, tab close or browser restart.
		localStorage.setItem( STORE, JSON.stringify( {
			site: state.site || '',
			user: state.user || '',
			tab: state.tab || ''
		} ) );
	}
	function wipe() { localStorage.removeItem( STORE ); state = { boardKey: boardKeyFromUrl() }; }

	// Single sign-out path: wipe the stored credential and return to the login
	// screen. Used by both the explicit "Sign out" button and the inactivity
	// auto sign-out below — never duplicate this logic elsewhere.
	function signOut( msg ) { wipe(); renderLogin( msg ); }

	/* ---------- dom ---------- */
	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( text !== undefined && text !== null ) { n.textContent = String( text ); }
		return n;
	}
	function money( n ) {
		var c = state.currency || '$';
		return c + ( Math.round( ( Number( n ) || 0 ) * 100 ) / 100 ).toFixed( 2 );
	}
	function stopPoll() {
		if ( pollTimer ) { clearInterval( pollTimer ); pollTimer = null; }
		closeSse();
		stopCamScan();
		netUp();
	}
	function closeSse() {
		if ( eventSource ) {
			try { eventSource.close(); } catch ( e ) {}
			eventSource = null;
		}
	}

	/* ---------- camera QR scan (html5-qrcode) ---------- */
	function stopCamScan() {
		if ( ! camScanner ) { return; }
		var sc = camScanner;
		camScanner = null;
		try {
			sc.stop().then( function () { try { sc.clear(); } catch ( e ) {} } ).catch( function () {} );
		} catch ( e ) {}
	}
	function toast( msg ) {
		var t = el( 'div', 'toast show', msg );
		t.setAttribute( 'role', 'status' );
		t.setAttribute( 'aria-live', 'polite' );
		document.body.appendChild( t );
		setTimeout( function () { t.classList.remove( 'show' ); }, 2600 );
		setTimeout( function () { if ( t.parentNode ) { t.parentNode.removeChild( t ); } }, 3000 );
	}

	/* ---------- network status ---------- */

	// Shown while any poller is failing; cleared on the next successful poll.
	// Styled inline because app.css is shared and this element is app.js-only.
	var netEl = null;
	function netDown() {
		if ( netEl ) { return; }
		netEl = el( 'div', 'net-reconnect', 'Reconnecting…' );
		netEl.setAttribute( 'role', 'status' );
		netEl.setAttribute( 'aria-live', 'polite' );
		netEl.style.cssText = 'position:fixed;bottom:16px;left:16px;background:#b45309;color:#fff;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;z-index:60;box-shadow:0 4px 14px rgba(0,0,0,.35);';
		document.body.appendChild( netEl );
	}
	function netUp() {
		if ( ! netEl ) { return; }
		if ( netEl.parentNode ) { netEl.parentNode.removeChild( netEl ); }
		netEl = null;
	}

	/* ---------- api ---------- */
	function api( path, method, body ) {
		var headers = {
			'Content-Type': 'application/json',
			'Authorization': 'Basic ' + btoa( state.user + ':' + state.pass )
		};
		if ( state.boardKey ) { headers['X-DoughBoss-Board-Key'] = state.boardKey; }
		return fetch( state.site + '/wp-json/' + NS + path, {
			method: method,
			headers: headers,
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, status: res.status, data: data };
			} ).catch( function () { return { ok: res.ok, status: res.status, data: null }; } );
		} );
	}

	/* ---------- realtime (Mercure SSE, optional) ---------- */

	// Fetch the public /config once and cache its (optional) mercure block. The
	// orchestrator adds { enabled, url, topic } to /config when Mercure is on.
	// Defensive: any failure leaves state.mercure unset and the board falls back
	// to polling, exactly as today.
	function ensureConfig( done ) {
		if ( state.mercure !== undefined ) { done(); return; }
		fetch( state.site + '/wp-json/' + NS + '/config' )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				state.mercure = ( data && data.mercure ) ? data.mercure : null;
			} )
			.catch( function () { state.mercure = null; } )
			.then( function () { save(); done(); } );
	}

	// Open an EventSource against the configured Mercure hub and call onMessage on
	// every notification. The poll the caller already started stays running as the
	// fallback. Returns true if an EventSource was opened.
	function connectSse( onMessage ) {
		var m = state.mercure;
		if ( ! m || ! m.enabled || ! m.url || typeof window.EventSource === 'undefined' ) {
			return false;
		}
		var url = m.url + '?topic=' + encodeURIComponent( m.topic );
		// The board topic is publicly readable; EventSource can't send headers, so
		// only append the (URL) authorization param if a subscribe JWT is provided.
		if ( m.subscribe_jwt ) {
			url += '&authorization=' + encodeURIComponent( m.subscribe_jwt );
		}
		try {
			eventSource = new EventSource( url );
		} catch ( e ) {
			return false;
		}
		// Re-fetch authoritative board data — the SSE payload is only a signal.
		eventSource.onmessage = function () { onMessage(); };
		// On error the browser auto-reconnects; the poll keeps the board fresh.
		eventSource.onerror = function () {};
		return true;
	}

	/* ---------- inactivity auto sign-out ---------- */

	// A lost/shared/kiosk device would otherwise hold a live, reusable REST
	// credential indefinitely — sign out automatically after 30 minutes with
	// no user activity. Only armed while signed in (see renderApp/renderLogin).
	var IDLE_LIMIT_MS = 30 * 60 * 1000;
	var idleTimer = null;
	var idleWatching = false;
	var IDLE_EVENTS = [ 'click', 'keydown', 'touchstart' ];

	function idleTimeout() {
		signOut( 'Signed out after 30 minutes of inactivity for security.' );
	}
	// Debounced: each tracked event just restarts the 30-minute countdown
	// rather than reacting on every event (e.g. we deliberately skip mousemove).
	function resetIdleTimer() {
		clearTimeout( idleTimer );
		idleTimer = setTimeout( idleTimeout, IDLE_LIMIT_MS );
	}
	function startIdleWatch() {
		if ( idleWatching ) { return; }
		idleWatching = true;
		IDLE_EVENTS.forEach( function ( evt ) { document.addEventListener( evt, resetIdleTimer, { passive: true } ); } );
		resetIdleTimer();
	}
	function stopIdleWatch() {
		if ( idleWatching ) {
			IDLE_EVENTS.forEach( function ( evt ) { document.removeEventListener( evt, resetIdleTimer, { passive: true } ); } );
		}
		idleWatching = false;
		clearTimeout( idleTimer );
		idleTimer = null;
	}

	/* ---------- login ---------- */
	function renderLogin( err ) {
		stopPoll();
		stopIdleWatch();
		root.innerHTML = '';
		var wrap = el( 'div', 'login' );
		var card = el( 'div', 'login__card' );
		card.appendChild( el( 'div', 'login__brand', 'DoughBoss Console' ) );
		card.appendChild( el( 'p', 'login__sub', 'Staff sign-in' ) );

		var fSite = field( 'Site address', 'url', state.site || DEFAULT_SITE );
		var fUser = field( 'WordPress username', 'text', state.user || '' );
		var fPass = field( 'Application password', 'password', '' );
		fUser.input.setAttribute( 'autocomplete', 'username' );
		fPass.input.setAttribute( 'autocomplete', 'current-password' );

		// A real form so Enter submits from any field and password managers engage.
		var form = el( 'form' );
		form.appendChild( fSite.row );
		form.appendChild( fUser.row );
		form.appendChild( fPass.row );

		var btn = el( 'button', 'login__btn', 'Sign in' );
		btn.setAttribute( 'type', 'submit' );
		form.appendChild( btn );
		card.appendChild( form );
		if ( err ) { card.appendChild( el( 'div', 'login__err', err ) ); }

		var help = el( 'div', 'login__help' );
		help.innerHTML = 'Create an <strong>Application Password</strong> in WordPress: Users → Profile → Application Passwords. Your site and username are remembered on this device; the Application Password is kept only in memory and is cleared when this page reloads or closes.';
		card.appendChild( help );

		function go() {
			var site = ( fSite.input.value || '' ).trim().replace( /\/$/, '' );
			var user = ( fUser.input.value || '' ).trim();
			var pass = ( fPass.input.value || '' ).trim();
			if ( ! site || ! user || ! pass ) { return renderLogin( 'Please fill in every field.' ); }
			try {
				var siteUrl = new URL( site );
				var localDev = siteUrl.hostname === 'localhost' || siteUrl.hostname === '127.0.0.1';
				if ( siteUrl.protocol !== 'https:' && ! localDev ) {
					return renderLogin( 'Use an HTTPS site address so the Application Password is encrypted in transit.' );
				}
				site = siteUrl.origin + siteUrl.pathname.replace( /\/$/, '' );
			} catch ( e ) {
				return renderLogin( 'Enter a valid site address, including https://.' );
			}
			btn.disabled = true; btn.textContent = 'Signing in…';
			state.site = site; state.user = user; state.pass = pass;
			api( '/auth/me', 'GET' ).then( function ( r ) {
				if ( r.ok && r.data && ( r.data.can_redeem || r.data.can_manage || r.data.can_board ) ) {
					state.name = r.data.name || user;
					state.currency = r.data.currency || '$';
					state.caps = { redeem: !! r.data.can_redeem, manage: !! r.data.can_manage, board: !! r.data.can_board };
					save();
					renderApp();
				} else {
					var m = ( r.status === 401 || r.status === 403 ) ? 'Sign-in failed — check the username and application password.'
						: ( r.data && r.data.message ) ? r.data.message : 'Could not connect to the site.';
					state.pass = '';
					renderLogin( m );
				}
			} ).catch( function () {
				state.pass = '';
				renderLogin( 'Could not reach ' + site + '. Check the address and that the site allows this app (CORS).' );
			} );
		}
		form.addEventListener( 'submit', function ( e ) { e.preventDefault(); go(); } );

		wrap.appendChild( card );
		root.appendChild( wrap );
		fUser.input.focus();
	}
	function field( label, type, value ) {
		var row = el( 'div' );
		var id = 'db-console-field-' + ( ++fieldSeq );
		var labelEl = el( 'label', null, label );
		labelEl.setAttribute( 'for', id );
		row.appendChild( labelEl );
		var input = el( 'input' );
		input.id = id;
		input.type = type;
		input.value = value || '';
		row.appendChild( input );
		return { row: row, input: input };
	}

	/* ---------- app shell ---------- */
	function renderApp() {
		stopPoll();
		root.innerHTML = '';

		var bar = el( 'div', 'topbar' );
		bar.appendChild( el( 'div', 'topbar__brand', 'DoughBoss' ) );
		var right = el( 'div', 'topbar__right' );
		right.appendChild( el( 'span', null, state.name || '' ) );
		var out = el( 'button', 'topbar__out', 'Sign out' );
		out.addEventListener( 'click', function () { signOut(); } );
		right.appendChild( out );
		bar.appendChild( right );
		root.appendChild( bar );

		var tabsWrap = el( 'div', 'tabs' );
		var screen = el( 'div', 'screen' );
		var tabs = [];
		if ( state.caps.redeem ) { tabs.push( { key: 'scan', label: 'Voucher Scan', render: scanScreen } ); }
		if ( state.caps.manage ) { tabs.push( { key: 'vouchers', label: 'Vouchers', render: vouchersScreen } ); }
		if ( state.caps.board ) { tabs.push( { key: 'board', label: 'Order Board', render: boardScreen } ); }

		if ( ! tabs.length ) {
			screen.appendChild( el( 'div', 'empty', 'Your account has no DoughBoss screens.' ) );
		}
		var current = tabs.some( function ( t ) { return t.key === state.tab; } ) ? state.tab : ( tabs[0] && tabs[0].key );

		tabs.forEach( function ( t ) {
			var b = el( 'button', 'tab' + ( t.key === current ? ' is-active' : '' ), t.label );
			b.addEventListener( 'click', function () {
				state.tab = t.key; save();
				stopPoll();
				Array.prototype.forEach.call( tabsWrap.children, function ( c ) { c.classList.remove( 'is-active' ); } );
				b.classList.add( 'is-active' );
				screen.innerHTML = '';
				t.render( screen );
			} );
			tabsWrap.appendChild( b );
		} );
		root.appendChild( tabsWrap );
		root.appendChild( screen );

		var active = tabs.filter( function ( t ) { return t.key === current; } )[0];
		if ( active ) { active.render( screen ); }

		startIdleWatch();
	}

	/* ---------- Scan screen ---------- */
	function scanScreen( screen ) {
		var s = {};
		var grid = el( 'div', 'grid2' );

		var c1 = el( 'div', 'card' );
		c1.appendChild( el( 'h2', null, 'Redeem a voucher' ) );
		s.input = el( 'input', 'scan__input' );
		s.input.placeholder = 'DOUGH-XXXXXXXX';
		s.input.setAttribute( 'autocomplete', 'off' );
		c1.appendChild( s.input );

		// Camera QR scan (html5-qrcode, loaded via CDN in index.html). Hidden if the lib is unavailable.
		s.camBtn = el( 'button', 'btn--ghost scan__cam', 'Scan with camera' );
		s.camBtn.setAttribute( 'type', 'button' );
		s.camWrap = el( 'div', 'scan__cam-wrap' );
		s.camView = el( 'div', 'scan__cam-view' );
		s.camView.id = 'db-cam-' + Date.now();
		s.camStop = el( 'button', 'btn--ghost scan__cam-stop', 'Stop' );
		s.camStop.setAttribute( 'type', 'button' );
		s.camWrap.appendChild( s.camView );
		s.camWrap.appendChild( s.camStop );
		c1.appendChild( s.camBtn );
		c1.appendChild( s.camWrap );
		if ( ! window.Html5Qrcode ) { s.camBtn.style.display = 'none'; }

		var row = el( 'div', 'scan__row' );
		s.total = el( 'input', 'scan__total' );
		s.total.type = 'number'; s.total.min = '0'; s.total.step = '0.01';
		s.total.placeholder = 'Order total (only for % / min-spend)';
		s.btn = el( 'button', 'btn', 'Redeem' );
		row.appendChild( s.total ); row.appendChild( s.btn );
		c1.appendChild( row );
		c1.appendChild( el( 'p', 'hint', 'A barcode scanner can type the code then press Enter.' ) );
		s.result = el( 'div', 'result' );
		c1.appendChild( s.result );
		grid.appendChild( c1 );

		var right = el( 'div' );
		s.tiles = el( 'div', 'tiles' ); right.appendChild( s.tiles );
		var c2 = el( 'div', 'card' );
		c2.appendChild( el( 'h2', null, "Today's release" ) );
		s.meters = el( 'div' ); c2.appendChild( s.meters );
		right.appendChild( c2 );
		var c3 = el( 'div', 'card' );
		c3.appendChild( el( 'h2', null, 'Recent vouchers' ) );
		s.feed = el( 'ul', 'list' ); c3.appendChild( s.feed );
		right.appendChild( c3 );
		grid.appendChild( right );
		screen.appendChild( grid );

		var idemCode = '', idemKey = '';
		function showResult( kind, title, sub, amount ) {
			clearTimeout( clearTimer );
			s.result.className = 'result is-' + kind;
			s.result.innerHTML = '';
			s.result.appendChild( el( 'p', 'result__t', title ) );
			if ( amount !== undefined && amount !== null ) { s.result.appendChild( el( 'p', 'result__amt', money( amount ) ) ); }
			if ( sub ) { s.result.appendChild( el( 'p', 'result__s', sub ) ); }
		}
		function focusCode() { try { s.input.focus(); s.input.select(); } catch ( e ) {} }

		function submit() {
			var code = ( s.input.value || '' ).trim();
			s.total.classList.remove( 'is-required' );
			if ( ! code ) { showResult( 'neutral', 'Scan or type a voucher code' ); focusCode(); return; }
			var sub = parseFloat( s.total.value );
			if ( isNaN( sub ) || sub < 0 ) { sub = 0; }
			if ( code !== idemCode || ! idemKey ) { idemCode = code; idemKey = 'con-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2, 8 ); }
			s.btn.disabled = true;
			showResult( 'neutral', 'Checking…' );
			api( '/voucher/scan', 'POST', { code: code, subtotal: sub, idempotency_key: idemKey } ).then( function ( r ) {
				s.btn.disabled = false;
				if ( r.ok && r.data && r.data.redeemed ) {
					showResult( 'ok', 'Redeemed ✓', r.data.code, r.data.amount );
					s.input.value = ''; s.total.value = ''; idemCode = ''; idemKey = '';
					clearTimer = setTimeout( function () { s.result.className = 'result'; focusCode(); }, 7000 );
					activity();
				} else {
					var c = r.data && r.data.code, msg = ( r.data && r.data.message ) || 'Could not redeem this voucher.';
					var title = 'Declined';
					if ( c === 'doughboss_voucher_used' ) { title = 'Already used'; }
					else if ( c === 'doughboss_voucher_min' ) { title = 'Minimum spend not met'; }
					else if ( c === 'doughboss_need_total' ) { title = 'Enter order total'; }
					showResult( 'bad', title, msg );
					if ( c === 'doughboss_need_total' ) { s.total.classList.add( 'is-required' ); try { s.total.focus(); } catch ( e ) {} return; }
				}
				focusCode();
			} ).catch( function () { s.btn.disabled = false; showResult( 'bad', 'Network error', 'Please try again.' ); } );
		}
		s.btn.addEventListener( 'click', submit );
		s.input.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); submit(); } } );
		s.total.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); submit(); } } );

		function startCamScan() {
			if ( ! window.Html5Qrcode || camScanner ) { return; }
			s.camWrap.classList.add( 'is-on' );
			s.camBtn.disabled = true;
			showResult( 'neutral', 'Starting camera…' );
			var scanner = new window.Html5Qrcode( s.camView.id, { verbose: false } );
			camScanner = scanner;
			scanner.start(
				{ facingMode: 'environment' },
				{ fps: 10, qrbox: 240 },
				function ( decoded ) {
					// Reuse the existing redeem flow: fill the code input and submit.
					s.input.value = ( decoded || '' ).trim();
					stopCamScan();
					s.camWrap.classList.remove( 'is-on' );
					s.camBtn.disabled = false;
					submit();
				},
				function () { /* per-frame decode misses — ignore */ }
			).catch( function ( err ) {
				camScanner = null;
				s.camWrap.classList.remove( 'is-on' );
				s.camBtn.disabled = false;
				showResult( 'bad', 'Camera unavailable', ( err && err.message ) ? err.message : 'Allow camera access and try again.' );
			} );
		}
		function endCamScan() {
			stopCamScan();
			s.camWrap.classList.remove( 'is-on' );
			s.camBtn.disabled = false;
			focusCode();
		}
		s.camBtn.addEventListener( 'click', startCamScan );
		s.camStop.addEventListener( 'click', endCamScan );

		function activity() {
			return api( '/voucher/activity', 'GET' ).then( function ( r ) {
				netUp();
				if ( ! r.ok || ! r.data ) { return; }
				if ( r.data.currency ) { state.currency = r.data.currency; }
				var tt = r.data.totals || {};
				s.tiles.innerHTML = '';
				[ [ 'issued', 'Live', 'tile--live' ], [ 'redeemed', 'Redeemed', 'tile--ok' ], [ 'voided', 'Voided', '' ] ].forEach( function ( d ) {
					var t = el( 'div', 'tile ' + d[2] );
					t.appendChild( el( 'div', 'tile__n', tt[ d[0] ] || 0 ) );
					t.appendChild( el( 'div', 'tile__l', d[1] ) );
					s.tiles.appendChild( t );
				} );
				s.meters.innerHTML = '';
				( r.data.campaigns || [] ).forEach( function ( c ) {
					var m = el( 'div', 'meter' ), top = el( 'div', 'meter__top' );
					var name = el( 'div', 'meter__name' );
					name.appendChild( document.createTextNode( ( c.type === 'percent' ? c.value + '% ' : money( c.value ) + ' ' ) ) );
					name.appendChild( el( 'small', null, c.label ) );
					top.appendChild( name );
					var cap = Number( c.cap ) || 0, used = Number( c.pool_used ) || 0;
					top.appendChild( el( 'div', 'meter__c', cap > 0 ? ( used + ' / ' + cap + ' claimed' ) : ( used + ' claimed' ) ) );
					m.appendChild( top );
					var bar = el( 'div', 'bar' ), f = el( 'div', 'bar__f' + ( cap > 0 && used >= cap ? ' is-full' : '' ) );
					f.style.width = ( cap > 0 ? Math.min( 100, Math.round( used / cap * 100 ) ) : 0 ) + '%';
					bar.appendChild( f ); m.appendChild( bar ); s.meters.appendChild( m );
				} );
				s.feed.innerHTML = '';
				var recent = r.data.recent || [];
				if ( ! recent.length ) { s.feed.appendChild( el( 'li', null, '' ) ).appendChild( el( 'div', 'empty', 'No vouchers yet.' ) ); return; }
				recent.forEach( function ( v ) {
					var li = el( 'li' );
					li.appendChild( el( 'span', 'badge badge--' + ( v.status || 'issued' ), v.status || 'issued' ) );
					var meta = el( 'div', 'meta' );
					meta.appendChild( el( 'span', 'mono', v.code ) );
					var bits = [];
					if ( v.campaign ) { bits.push( v.campaign ); }
					if ( v.phone ) { bits.push( v.phone ); }
					bits.push( v.status === 'redeemed' && v.redeemed_at ? ( ( v.channel || 'redeemed' ) + ' · ' + v.redeemed_at ) : ( 'issued ' + ( v.created_at || '' ) ) );
					meta.appendChild( el( 'div', 'sub', bits.join( ' · ' ) ) );
					li.appendChild( meta );
					li.appendChild( el( 'span', 'val', v.type === 'percent' ? v.value + '%' : money( v.value ) ) );
					s.feed.appendChild( li );
				} );
			} ).catch( function () { netDown(); } );
		}
		focusCode();
		activity();
		pollTimer = setInterval( activity, 10000 );
	}

	/* ---------- Vouchers screen ---------- */
	function vouchersScreen( screen ) {
		var make = el( 'div', 'card' );
		make.appendChild( el( 'h2', null, 'Create a voucher' ) );
		var fr = el( 'div', 'formrow' );
		var type = el( 'select' );
		[ [ 'amount', 'Amount ($)' ], [ 'percent', 'Percent (%)' ] ].forEach( function ( o ) {
			var op = el( 'option', null, o[1] ); op.value = o[0]; type.appendChild( op );
		} );
		var value = el( 'input' ); value.type = 'number'; value.min = '0'; value.step = '0.01'; value.placeholder = 'Value';
		var prefix = el( 'input' ); prefix.type = 'text'; prefix.value = 'DOUGH'; prefix.placeholder = 'Prefix';
		var phone = el( 'input' ); phone.type = 'text'; phone.placeholder = 'Customer phone (optional)';
		fr.appendChild( labelled( 'Type', type ) );
		fr.appendChild( labelled( 'Value', value ) );
		fr.appendChild( labelled( 'Code prefix', prefix ) );
		fr.appendChild( labelled( 'Phone', phone ) );
		var cbtn = el( 'button', 'btn', 'Create' ); cbtn.style.minHeight = '40px'; cbtn.style.fontSize = '14px';
		fr.appendChild( cbtn );
		make.appendChild( fr );
		var note = el( 'div', 'sub' ); note.style.marginTop = '10px'; make.appendChild( note );
		screen.appendChild( make );

		var listCard = el( 'div', 'card' );
		listCard.appendChild( el( 'h2', null, 'Recent vouchers' ) );
		var search = el( 'input', 'search' ); search.type = 'search'; search.placeholder = 'Filter by code, status or phone…';
		listCard.appendChild( search );
		var list = el( 'ul', 'list' ); listCard.appendChild( list );
		screen.appendChild( listCard );

		var cache = [];
		cbtn.addEventListener( 'click', function () {
			var v = parseFloat( value.value );
			if ( isNaN( v ) || v <= 0 ) { note.textContent = 'Enter a value greater than zero.'; return; }
			cbtn.disabled = true;
			api( '/voucher/issue', 'POST', { type: type.value, value: v, prefix: prefix.value, scope: 'both', customer_phone: phone.value } ).then( function ( r ) {
				cbtn.disabled = false;
				if ( r.ok && r.data && r.data.code ) { note.textContent = 'Created: ' + r.data.code; value.value = ''; phone.value = ''; refresh(); }
				else { note.textContent = ( r.data && r.data.message ) || 'Could not create the voucher.'; }
			} ).catch( function () { cbtn.disabled = false; note.textContent = 'Network error.'; } );
		} );
		search.addEventListener( 'input', render );

		function render() {
			var q = ( search.value || '' ).trim().toLowerCase();
			list.innerHTML = '';
			var rows = cache.filter( function ( v ) { return ! q || ( ( v.code + ' ' + v.status + ' ' + ( v.phone || '' ) + ' ' + ( v.campaign || '' ) ).toLowerCase().indexOf( q ) > -1 ); } );
			if ( ! rows.length ) { list.appendChild( el( 'li', null, '' ) ).appendChild( el( 'div', 'empty', 'No vouchers.' ) ); return; }
			rows.forEach( function ( v ) {
				var li = el( 'li' );
				li.appendChild( el( 'span', 'badge badge--' + v.status, v.status ) );
				var meta = el( 'div', 'meta' );
				meta.appendChild( el( 'span', 'mono', v.code ) );
				var bits = [];
				if ( v.campaign ) { bits.push( v.campaign ); }
				if ( v.phone ) { bits.push( v.phone ); }
				bits.push( v.status === 'redeemed' && v.redeemed_at ? ( ( v.channel || 'redeemed' ) + ' · ' + v.redeemed_at ) : ( v.created_at || '' ) );
				meta.appendChild( el( 'div', 'sub', bits.join( ' · ' ) ) );
				li.appendChild( meta );
				li.appendChild( el( 'span', 'val', v.type === 'percent' ? v.value + '%' : money( v.value ) ) );
				if ( v.status === 'issued' ) {
					var vb = el( 'button', 'btn--ghost btn--danger', 'Void' ); vb.style.color = '#fff';
					vb.addEventListener( 'click', function () {
						vb.disabled = true;
						api( '/voucher/void', 'POST', { id: v.id } ).then( function ( r ) {
							if ( r.ok && r.data && r.data.voided ) { toast( 'Voided ' + v.code ); refresh(); }
							else { vb.disabled = false; toast( ( r.data && r.data.message ) || 'Could not void.' ); }
						} ).catch( function () { vb.disabled = false; toast( 'Network error.' ); } );
					} );
					li.appendChild( vb );
				}
				list.appendChild( li );
			} );
		}
		function refresh() {
			return api( '/admin/vouchers', 'GET' ).then( function ( r ) {
				netUp();
				if ( r.ok && r.data && r.data.vouchers ) { cache = r.data.vouchers; render(); }
				else if ( r.status === 403 ) { list.innerHTML = ''; list.appendChild( el( 'div', 'empty', 'Owner access required.' ) ); }
			} ).catch( function () { netDown(); } );
		}
		refresh();
		pollTimer = setInterval( refresh, 15000 );
	}
	function labelled( label, input ) {
		var w = el( 'div' );
		if ( ! input.id ) { input.id = 'db-console-field-' + ( ++fieldSeq ); }
		var labelEl = el( 'label', null, label );
		labelEl.setAttribute( 'for', input.id );
		w.appendChild( labelEl );
		w.appendChild( input );
		return w;
	}

	/* ---------- Order board screen ---------- */
	function boardScreen( screen ) {
		var head = el( 'div', 'card' );
		head.appendChild( el( 'h2', null, 'Live orders' ) );
		head.appendChild( el( 'p', 'sub', 'Auto-refreshing every 8 seconds.' ) );
		screen.appendChild( head );
		var wrap = el( 'div', 'orders' );
		screen.appendChild( wrap );

		function act( id, path, body, msg ) {
			return api( '/admin/order/' + id + path, 'POST', body ).then( function ( r ) {
				if ( r.ok ) { toast( msg ); refresh(); } else { toast( ( r.data && r.data.message ) || 'Action failed.' ); }
			} ).catch( function () { toast( 'Network error.' ); } );
		}
		function refresh() {
			return api( '/admin/orders', 'GET' ).then( function ( r ) {
				netUp();
				if ( ! r.ok || ! r.data ) { return; }
				var orders = r.data.data || r.data.orders || [];
				wrap.innerHTML = '';
				if ( ! orders.length ) { wrap.appendChild( el( 'div', 'empty', 'No active orders.' ) ); return; }
				orders.forEach( function ( o ) {
					var isNew = ( o.status === 'new' || o.status === 'pending' || ( o.acknowledged_at === null && ! o.acknowledged ) );
					var card = el( 'div', 'order' + ( isNew ? ' is-new' : '' ) );
					var h = el( 'div', 'order__h' );
					h.appendChild( el( 'span', 'order__no', '#' + ( o.order_number || o.id ) ) );
					h.appendChild( el( 'span', 'badge badge--' + ( o.status || 'new' ), o.status || 'new' ) );
					card.appendChild( h );
					card.appendChild( el( 'div', 'sub', ( o.order_type || '' ) + ' · ' + ( o.customer_name || '' ) + ( o.total ? ' · ' + money( o.total ) : '' ) ) );
					if ( o.items_summary || o.items ) {
						card.appendChild( el( 'div', 'order__items', o.items_summary || ( Array.isArray( o.items ) ? o.items.map( function ( i ) { return ( i.quantity || 1 ) + '× ' + ( i.name || '' ); } ).join( ', ' ) : '' ) ) );
					}
					var actions = el( 'div', 'order__actions' );
					var ack = el( 'button', 'btn--ghost', 'Acknowledge' );
					ack.addEventListener( 'click', function () { act( o.id, '/ack', {}, 'Acknowledged' ); } );
					var acc = el( 'button', 'btn--ghost', 'Accept + ETA' );
					acc.addEventListener( 'click', function () {
						var eta = prompt( 'ETA in minutes?', '20' );
						if ( eta !== null ) { act( o.id, '/accept', { eta: parseInt( eta, 10 ) || 0 }, 'Accepted' ); }
					} );
					actions.appendChild( ack ); actions.appendChild( acc );
					[ 'preparing', 'ready', 'completed' ].forEach( function ( st ) {
						var b = el( 'button', 'btn--ghost', st.charAt( 0 ).toUpperCase() + st.slice( 1 ) );
						b.addEventListener( 'click', function () { act( o.id, '/status', { status: st }, 'Marked ' + st ); } );
						actions.appendChild( b );
					} );
					card.appendChild( actions );
					wrap.appendChild( card );
				} );
			} ).catch( function () { netDown(); } );
		}
		refresh();
		pollTimer = setInterval( refresh, 8000 );

		// Layer instant SSE updates on top of the poll when the site advertises
		// Mercure. Defensive: if unsupported or absent, the poll above is the only
		// path and behaviour is exactly as before.
		ensureConfig( function () { connectSse( refresh ); } );
	}

	/* ---------- boot ---------- */
	if ( state.site && state.user && state.pass && state.caps ) {
		api( '/auth/me', 'GET' ).then( function ( r ) {
			if ( r.ok && r.data ) {
				state.name = r.data.name || state.name;
				state.currency = r.data.currency || state.currency;
				state.caps = { redeem: !! r.data.can_redeem, manage: !! r.data.can_manage, board: !! r.data.can_board };
				save();
				renderApp();
			} else { renderLogin(); }
		} ).catch( function () { renderLogin(); } );
	} else {
		renderLogin();
	}
} )();
