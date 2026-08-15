/**
 * DoughBoss Console â€” standalone staff app.
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
	// auto sign-out below â€” never duplicate this logic elsewhere.
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
		netEl = el( 'div', 'net-reconnect', 'Reconnectingâ€¦' );
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
		// Re-fetch authoritative board data â€” the SSE payload is only a signal.
		eventSource.onmessage = function () { onMessage(); };
		// On error the browser auto-reconnects; the poll keeps the board fresh.
		eventSource.onerror = function () {};
		return true;
	}

	/* ---------- inactivity auto sign-out ---------- */

	// A lost/shared/kiosk device would otherwise hold a live, reusable REST
	// credential indefinitely â€” sign out automatically after 30 minutes with
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
		help.innerHTML = 'Create an <strong>Application Password</strong> in WordPress: Users â†’ Profile â†’ Application Passwords. Your site and username are remembered on this device; the Application Password is kept only in memory and is cleared when this page reloads or closes.';
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
			btn.disabled = true; btn.textContent = 'Signing inâ€¦';
			state.site = site; state.user = user; state.pass = pass;
			api( '/auth/me', 'GET' ).then( function ( r ) {
				if ( r.ok && r.data && ( r.data.can_redeem || r.data.can_manage || r.data.can_board ) ) {
					state.name = r.data.name || user;
					state.currency = r.data.currency || '$';
					state.caps = { redeem: !! r.data.can_redeem, manage: !! r.data.can_manage, board: !! r.data.can_board };
					save();
					renderApp();
				} else {
					var m = ( r.status === 401 || r.status === 403 ) ? 'Sign-in failed â€” check the username and application password.'
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
		s.receipt = el( 'input', 'scan__receipt' );
		s.receipt.placeholder = 'POS/till receipt no. (required)';
		s.btn = el×^}¶‰ËkºwµçyÑ•Èœ€ôôô”¹­•ä€¤ì”¹ÁÉ•Ù•¹Ñ•™…Õ±Ğ ¤ìÍÕ‰µ¥Ğ ¤ìôô€¤ì($%Ì¹Ñ½Ñ…°¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €­•å‘½İ¸œ°™Õ¹Ñ¥½¸€ ”€¤ì¥˜€ €¹Ñ•Èœ€ôôô”¹­•ä€¤ì”¹ÁÉ•Ù•¹Ñ•™…Õ±Ğ ¤ìÍÕ‰µ¥Ğ ¤ìôô€¤ì(($%™Õ¹Ñ¥½¸ÍÑ…ÉÑ…µM…¸ ¤ì($$%¥˜€ €„İ¥¹‘½Ü¹!Ñµ°ÕEÉ½‘”ñğ…µM…¹¹•È€¤ìÉ•ÑÕÉ¸ìô($$%Ì¹…µ]É…À¹±…ÍÍ1¥ÍĞ¹…‘ €¥Ìµ½¸œ€¤ì($$%Ì¹…µ	Ñ¸¹‘¥Í…‰±•€ôÑÉÕ”ì($$%Í¡½İI•ÍÕ±Ğ €¹•ÕÑÉ…°œ°€MÑ…ÉÑ¥¹œ…µ•É‡Š˜œ€¤ì($$%Ù…ÈÍ…¹¹•È€ô¹•Üİ¥¹‘½Ü¹!Ñµ°ÕEÉ½‘” Ì¹…µY¥•Ü¹¥°ìÙ•É‰½Í”è™…±Í”ô€¤ì($$%…µM…¹¹•È€ôÍ…¹¹•Èì($$%Í…¹¹•È¹ÍÑ…ÉĞ ($$$%ì™…¥¹5½‘”è€•¹Ù¥É½¹µ•¹Ğœô°($$$%ì™ÁÌè€ÄÀ°ÅÉ‰½àè€ÈĞÀô°($$$%™Õ¹Ñ¥½¸€ ‘•½‘•€¤ì($$$$$¼¼I•ÕÍ”Ñ¡”•á¥ÍÑ¥¹œÉ•‘••´™±½Üè™¥±°Ñ¡”½‘”¥¹ÁÕĞ…¹ÍÕ‰µ¥Ğ¸($$$$%Ì¹¥¹ÁÕĞ¹Ù…±Õ”€ô€ ‘•½‘•ñğ€œœ€¤¹ÑÉ¥´ ¤ì($$$$%ÍÑ½Á…µM…¸ ¤ì($$$$%Ì¹…µ]É…À¹±…ÍÍ1¥ÍĞ¹É•µ½Ù” €¥Ìµ½¸œ€¤ì($$$$%Ì¹…µ	Ñ¸¹‘¥Í…‰±•€ô™…±Í”ì($$$$%ÍÕ‰µ¥Ğ ¤ì($$$%ô°($$$%™Õ¹Ñ¥½¸€ ¤ì€¼¨Á•Èµ™É…µ”‘•½‘”µ¥ÍÍ•ÌƒŠP¥¹½É”€¨¼ô($$$¤¹…Ñ  ™Õ¹Ñ¥½¸€ •ÉÈ€¤ì($$$%…µM…¹¹•È€ô¹Õ±°ì($$$%Ì¹…µ]É…À¹±…ÍÍ1¥ÍĞ¹É•µ½Ù” €¥Ìµ½¸œ€¤ì($$$%Ì¹…µ	Ñ¸¹‘¥Í…‰±•€ô™…±Í”ì($$$%Í¡½İI•ÍÕ±Ğ €‰…œ°€…µ•É„Õ¹…Ù…¥±…‰±”œ°€ •ÉÈ€˜˜•ÉÈ¹µ•ÍÍ…”€¤€ü•ÉÈ¹µ•ÍÍ…”€è€±±½Ü…µ•É„…•ÍÌ…¹ÑÉä……¥¸¸œ€¤ì($$%ô€¤ì($%ô($%™Õ¹Ñ¥½¸•¹‘…µM…¸ ¤ì($$%ÍÑ½Á…µM…¸ ¤ì($$%Ì¹…µ]É…À¹±…ÍÍ1¥ÍĞ¹É•µ½Ù” €¥Ìµ½¸œ€¤ì($$%Ì¹…µ	Ñ¸¹‘¥Í…‰±•€ô™…±Í”ì($$%™½ÕÍ½‘” ¤ì($%ô($%Ì¹…µ	Ñ¸¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €±¥¬œ°ÍÑ…ÉÑ…µM…¸€¤ì($%Ì¹…µMÑ½À¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €±¥¬œ°•¹‘…µM…¸€¤ì(($%™Õ¹Ñ¥½¸…Ñ¥Ù¥Ñä ¤ì($$%É•ÑÕÉ¸…Á¤ €œ½Ù½Õ¡•È½…Ñ¥Ù¥Ñäœ°€Pœ€¤¹Ñ¡•¸ ™Õ¹Ñ¥½¸€ È€¤ì($$$%¹•ÑUÀ ¤ì($$$%¥˜€ €„È¹½¬ñğ€„È¹‘…Ñ„€¤ìÉ•ÑÕÉ¸ìô($$$%¥˜€ È¹‘…Ñ„¹ÕÉÉ•¹ä€¤ìÍÑ…Ñ”¹ÕÉÉ•¹ä€ôÈ¹‘…Ñ„¹ÕÉÉ•¹äìô($$$%Ù…ÈÑĞ€ôÈ¹‘…Ñ„¹Ñ½Ñ…±Ìñğíôì($$$%Ì¹Ñ¥±•Ì¹¥¹¹•É!Q50€ô€œœì($$$%ll€¥ÍÍÕ•œ°€1¥Ù”œ°€Ñ¥±”´µ±¥Ù”œt°l€É•‘••µ•œ°€I•‘••µ•œ°€Ñ¥±”´µ½¬œt°l€Ù½¥‘•œ°€Y½¥‘•œ°€œœtt¹™½É…  ™Õ¹Ñ¥½¸€ €¤ì($$$$%Ù…ÈĞ€ô•° €‘¥Øœ°€Ñ¥±”€œ€¬‘lÉt€¤ì($$$$%Ğ¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€Ñ¥±•}}¸œ°ÑÑl‘lÁttñğ€À€¤€¤ì($$$$%Ğ¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€Ñ¥±•}}°œ°‘lÅt€¤€¤ì($$$$%Ì¹Ñ¥±•Ì¹…ÁÁ•¹‘¡¥± Ğ€¤ì($$$%ô€¤ì($$$%Ì¹µ•Ñ•ÉÌ¹¥¹¹•É!Q50€ô€œœì($$$$ È¹‘…Ñ„¹…µÁ…¥¹Ìñğmt€¤¹™½É…  ™Õ¹Ñ¥½¸€ Œ€¤ì($$$$%Ù…È´€ô•° €‘¥Øœ°€µ•Ñ•Èœ€¤°Ñ½À€ô•° €‘¥Øœ°€µ•Ñ•É}}Ñ½Àœ€¤ì($$$$%Ù…È¹…µ”€ô•° €‘¥Øœ°€µ•Ñ•É}}¹…µ”œ€¤ì($$$$%¹…µ”¹…ÁÁ•¹‘¡¥± ‘½Õµ•¹Ğ¹É•…Ñ•Q•áÑ9½‘” € Œ¹ÑåÁ”€ôôô€Á•É•¹Ğœ€üŒ¹Ù…±Õ”€¬€œ”€œ€èµ½¹•ä Œ¹Ù…±Õ”€¤€¬€œ€œ€¤€¤€¤ì($$$$%¹…µ”¹…ÁÁ•¹‘¡¥± •° €Íµ…±°œ°¹Õ±°°Œ¹±…‰•°€¤€¤ì($$$$%Ñ½À¹…ÁÁ•¹‘¡¥± ¹…µ”€¤ì($$$$%Ù…È…À€ô9Õµ‰•È Œ¹…À€¤ñğ€À°ÕÍ•€ô9Õµ‰•È Œ¹Á½½±}ÕÍ•€¤ñğ€Àì($$$$%Ñ½À¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€µ•Ñ•É}}Œœ°…À€ø€À€ü€ ÕÍ•€¬€œ€¼€œ€¬…À€¬€œ±…¥µ•œ€¤€è€ ÕÍ•€¬€œ±…¥µ•œ€¤€¤€¤ì($$$$%´¹…ÁÁ•¹‘¡¥± Ñ½À€¤ì($$$$%Ù…È‰…È€ô•° €‘¥Øœ°€‰…Èœ€¤°˜€ô•° €‘¥Øœ°€‰…É}}˜œ€¬€ …À€ø€À€˜˜ÕÍ•€øô…À€ü€œ¥Ìµ™Õ±°œ€è€œœ€¤€¤ì($$$$%˜¹ÍÑå±”¹İ¥‘Ñ €ô€ …À€ø€À€ü5…Ñ ¹µ¥¸ €ÄÀÀ°5…Ñ ¹É½Õ¹ ÕÍ•€¼…À€¨€ÄÀÀ€¤€¤€è€À€¤€¬€œ”œì($$$$%‰…È¹…ÁÁ•¹‘¡¥± ˜€¤ì´¹…ÁÁ•¹‘¡¥± ‰…È€¤ìÌ¹µ•Ñ•ÉÌ¹…ÁÁ•¹‘¡¥± ´€¤ì($$$%ô€¤ì($$$%Ì¹™••¹¥¹¹•É!Q50€ô€œœì($$$%Ù…ÈÉ••¹Ğ€ôÈ¹‘…Ñ„¹É••¹Ğñğmtì($$$%¥˜€ €„É••¹Ğ¹±•¹Ñ €¤ìÌ¹™••¹…ÁÁ•¹‘¡¥± •° €±¤œ°¹Õ±°°€œœ€¤€¤¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€•µÁÑäœ°€9¼Ù½Õ¡•ÉÌå•Ğ¸œ€¤€¤ìÉ•ÑÕÉ¸ìô($$$%É••¹Ğ¹™½É…  ™Õ¹Ñ¥½¸€ Ø€¤ì($$$$%Ù…È±¤€ô•° €±¤œ€¤ì($$$$%±¤¹…ÁÁ•¹‘¡¥± •° €ÍÁ…¸œ°€‰…‘”‰…‘”´´œ€¬€ Ø¹ÍÑ…ÑÕÌñğ€¥ÍÍÕ•œ€¤°Ø¹ÍÑ…ÑÕÌñğ€¥ÍÍÕ•œ€¤€¤ì($$$$%Ù…Èµ•Ñ„€ô•° €‘¥Øœ°€µ•Ñ„œ€¤ì($$$$%µ•Ñ„¹…ÁÁ•¹‘¡¥± •° €ÍÁ…¸œ°€µ½¹¼œ°Ø¹½‘”€¤€¤ì($$$$%Ù…È‰¥ÑÌ€ômtì($$$$%¥˜€ Ø¹…µÁ…¥¸€¤ì‰¥ÑÌ¹ÁÕÍ  Ø¹…µÁ…¥¸€¤ìô($$$$%¥˜€ Ø¹Á¡½¹”€¤ì‰¥ÑÌ¹ÁÕÍ  Ø¹Á¡½¹”€¤ìô($$$$%‰¥ÑÌ¹ÁÕÍ  Ø¹ÍÑ…ÑÕÌ€ôôô€É•‘••µ•œ€˜˜Ø¹É•‘••µ•‘}…Ğ€ü€ € Ø¹¡…¹¹•°ñğ€É•‘••µ•œ€¤€¬€œƒ
Ü€œ€¬Ø¹É•‘••µ•‘}…Ğ€¤€è€ €¥ÍÍÕ•€œ€¬€ Ø¹É•…Ñ•‘}…Ğñğ€œœ€¤€¤€¤ì($$$$%µ•Ñ„¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€ÍÕˆœ°‰¥ÑÌ¹©½¥¸ €œƒ
Ü€œ€¤€¤€¤ì($$$$%±¤¹…ÁÁ•¹‘¡¥± µ•Ñ„€¤ì($$$$%±¤¹…ÁÁ•¹‘¡¥± •° €ÍÁ…¸œ°€Ù…°œ°Ø¹ÑåÁ”€ôôô€Á•É•¹Ğœ€üØ¹Ù…±Õ”€¬€œ”œ€èµ½¹•ä Ø¹Ù…±Õ”€¤€¤€¤ì($$$$%Ì¹™••¹…ÁÁ•¹‘¡¥± ±¤€¤ì($$$%ô€¤ì($$%ô€¤¹…Ñ  ™Õ¹Ñ¥½¸€ ¤ì¹•Ñ½İ¸ ¤ìô€¤ì($%ô($%™½ÕÍ½‘” ¤ì($%…Ñ¥Ù¥Ñä ¤ì($%Á½±±Q¥µ•È€ôÍ•Ñ%¹Ñ•ÉÙ…° …Ñ¥Ù¥Ñä°€ÄÀÀÀÀ€¤ì(%ô(($¼¨€´´´´´´´´´´Y½Õ¡•ÉÌÍÉ••¸€´´´´´´´´´´€¨¼(%™Õ¹Ñ¥½¸Ù½Õ¡•ÉÍMÉ••¸ ÍÉ••¸€¤ì($%Ù…Èµ…­”€ô•° €‘¥Øœ°€…Éœ€¤ì($%µ…­”¹…ÁÁ•¹‘¡¥± •° € Èœ°¹Õ±°°€É•…Ñ”„Ù½Õ¡•Èœ€¤€¤ì($%Ù…È™È€ô•° €‘¥Øœ°€™½ÉµÉ½Üœ€¤ì($%Ù…ÈÑåÁ”€ô•° €Í•±•Ğœ€¤ì($%ll€…µ½Õ¹Ğœ°€µ½Õ¹Ğ€ ¤œt°l€Á•É•¹Ğœ°€A•É•¹Ğ€ ”¤œtt¹™½É…  ™Õ¹Ñ¥½¸€ ¼€¤ì($$%Ù…È½À€ô•° €½ÁÑ¥½¸œ°¹Õ±°°½lÅt€¤ì½À¹Ù…±Õ”€ô½lÁtìÑåÁ”¹…ÁÁ•¹‘¡¥± ½À€¤ì($%ô€¤ì($%Ù…ÈÙ…±Õ”€ô•° €¥¹ÁÕĞœ€¤ìÙ…±Õ”¹ÑåÁ”€ô€¹Õµ‰•ÈœìÙ…±Õ”¹µ¥¸€ô€œÀ¸ÀÄœìÙ…±Õ”¹µ…à€ô€œÔœìÙ…±Õ”¹ÍÑ•À€ô€œÀ¸ÀÄœìÙ…±Õ”¹Á±…•¡½±‘•È€ô€Y…±Õ”€¡µ…à€Ô¤œì($%Ù…Èµ¥¹MÁ•¹€ô•° €¥¹ÁÕĞœ€¤ìµ¥¹MÁ•¹¹ÑåÁ”€ô€¹Õµ‰•Èœìµ¥¹MÁ•¹¹µ¥¸€ô€œÌœìµ¥¹MÁ•¹¹ÍÑ•À€ô€œÀ¸ÀÄœìµ¥¹MÁ•¹¹Ù…±Õ”€ô€œÌœìµ¥¹MÁ•¹¹Á±…•¡½±‘•È€ô€5¥¹¥µÕ´ÍÁ•¹œì($%Ù…ÈÁÉ•™¥à€ô•° €¥¹ÁÕĞœ€¤ìÁÉ•™¥à¹ÑåÁ”€ô€Ñ•áĞœìÁÉ•™¥à¹Ù…±Õ”€ô€=U œìÁÉ•™¥à¹Á±…•¡½±‘•È€ô€AÉ•™¥àœì($%Ù…ÈÁ¡½¹”€ô•° €¥¹ÁÕĞœ€¤ìÁ¡½¹”¹ÑåÁ”€ô€Ñ•áĞœìÁ¡½¹”¹Á±…•¡½±‘•È€ô€ÕÍÑ½µ•ÈÁ¡½¹”€¡½ÁÑ¥½¹…°¤œì($%™È¹…ÁÁ•¹‘¡¥± ±…‰•±±• €QåÁ”œ°ÑåÁ”€¤€¤ì($%™È¹…ÁÁ•¹‘¡¥± ±…‰•±±• €Y…±Õ”œ°Ù…±Õ”€¤€¤ì($%™È¹…ÁÁ•¹‘¡¥± ±…‰•±±• €5¥¹¥µÕ´ÍÁ•¹œ°µ¥¹MÁ•¹€¤€¤ì($%™È¹…ÁÁ•¹‘¡¥± ±…‰•±±• €½‘”ÁÉ•™¥àœ°ÁÉ•™¥à€¤€¤ì($%™È¹…ÁÁ•¹‘¡¥± ±…‰•±±• €A¡½¹”œ°Á¡½¹”€¤€¤ì($%Ù…È‰Ñ¸€ô•° €‰ÕÑÑ½¸œ°€‰Ñ¸œ°€É•…Ñ”œ€¤ì‰Ñ¸¹ÍÑå±”¹µ¥¹!•¥¡Ğ€ô€œĞÁÁàœì‰Ñ¸¹ÍÑå±”¹™½¹ÑM¥é”€ô€œÄÑÁàœì($%™È¹…ÁÁ•¹‘¡¥± ‰Ñ¸€¤ì($%µ…­”¹…ÁÁ•¹‘¡¥± ™È€¤ì($%Ù…È¹½Ñ”€ô•° €‘¥Øœ°€ÍÕˆœ°€AÉ½µ½Ñ¥½¹…°Ù½Õ¡•ÉÌ…É”…ÁÁ•…Ğ€Ô…¹É•ÅÕ¥É”…Ğ±•…ÍĞ„€Ì‰…Í­•Ğ¸œ€¤ì¹½Ñ”¹ÍÑå±”¹µ…É¥¹Q½À€ô€œÄÁÁàœìµ…­”¹…ÁÁ•¹‘¡¥± ¹½Ñ”€¤ì($%ÍÉ••¸¹…ÁÁ•¹‘¡¥± µ…­”€¤ì(($%Ù…È±¥ÍÑ…É€ô•° €‘¥Øœ°€…Éœ€¤ì($%±¥ÍÑ…É¹…ÁÁ•¹‘¡¥± •° € Èœ°¹Õ±°°€I••¹ĞÙ½Õ¡•ÉÌœ€¤€¤ì($%Ù…ÈÍ•…É €ô•° €¥¹ÁÕĞœ°€Í•…É œ€¤ìÍ•…É ¹ÑåÁ”€ô€Í•…É œìÍ•…É ¹Á±…•¡½±‘•È€ô€¥±Ñ•È‰ä½‘”°ÍÑ…ÑÕÌ½ÈÁ¡½¹—Š˜œì($%±¥ÍÑ…É¹…ÁÁ•¹‘¡¥± Í•…É €¤ì($%Ù…È±¥ÍĞ€ô•° €Õ°œ°€±¥ÍĞœ€¤ì±¥ÍÑ…É¹…ÁÁ•¹‘¡¥± ±¥ÍĞ€¤ì($%ÍÉ••¸¹…ÁÁ•¹‘¡¥± ±¥ÍÑ…É€¤ì(($%Ù…È…¡”€ômtì($%‰Ñ¸¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €±¥¬œ°™Õ¹Ñ¥½¸€ ¤ì($$%Ù…ÈØ€ôÁ…ÉÍ•±½…Ğ Ù…±Õ”¹Ù…±Õ”€¤ì($$%Ù…Èµ¥¹¥µÕ´€ôÁ…ÉÍ•±½…Ğ µ¥¹MÁ•¹¹Ù…±Õ”€¤ì($$%¥˜€ ¥Í9…8 Ø€¤ñğØ€ğô€ÀñğØ€ø€Ô€¤ì¹½Ñ”¹Ñ•áÑ½¹Ñ•¹Ğ€ô€¹Ñ•È„Ù…±Õ”™É½´€À¸ÀÄÑ¼€Ô¸ÀÀ¸œìÉ•ÑÕÉ¸ìô($$%¥˜€ ¥Í9…8 µ¥¹¥µÕ´€¤ñğµ¥¹¥µÕ´€ğ€Ì€¤ì¹½Ñ”¹Ñ•áÑ½¹Ñ•¹Ğ€ô€5¥¹¥µÕ´ÍÁ•¹µÕÍĞ‰”…Ğ±•…ÍĞ€Ì¸ÀÀ¸œìÉ•ÑÕÉ¸ìô($$%‰Ñ¸¹‘¥Í…‰±•€ôÑÉÕ”ì($$%…Á¤ €œ½Ù½Õ¡•È½¥ÍÍÕ”œ°€A=MPœ°ìÑåÁ”èÑåÁ”¹Ù…±Õ”°Ù…±Õ”èØ°µ¥¹}ÍÁ•¹èµ¥¹¥µÕ´°ÁÉ•™¥àèÁÉ•™¥à¹Ù…±Õ”°Í½Á”è€‰½Ñ œ°ÕÍÑ½µ•É}Á¡½¹”èÁ¡½¹”¹Ù…±Õ”ô€¤¹Ñ¡•¸ ™Õ¹Ñ¥½¸€ È€¤ì($$$%‰Ñ¸¹‘¥Í…‰±•€ô™…±Í”ì($$$%¥˜€ È¹½¬€˜˜È¹‘…Ñ„€˜˜È¹‘…Ñ„¹½‘”€¤ì¹½Ñ”¹Ñ•áÑ½¹Ñ•¹Ğ€ô€É•…Ñ•è€œ€¬È¹‘…Ñ„¹½‘”ìÙ…±Õ”¹Ù…±Õ”€ô€œœìÁ¡½¹”¹Ù…±Õ”€ô€œœìÉ•™É•Í  ¤ìô($$$%•±Í”ì¹½Ñ”¹Ñ•áÑ½¹Ñ•¹Ğ€ô€ È¹‘…Ñ„€˜˜È¹‘…Ñ„¹µ•ÍÍ…”€¤ñğ€½Õ±¹½ĞÉ•…Ñ”Ñ¡”Ù½Õ¡•È¸œìô($$%ô€¤¹…Ñ  ™Õ¹Ñ¥½¸€ ¤ì‰Ñ¸¹‘¥Í…‰±•€ô™…±Í”ì¹½Ñ”¹Ñ•áÑ½¹Ñ•¹Ğ€ô€9•Ñİ½É¬•ÉÉ½È¸œìô€¤ì($%ô€¤ì($%Í•…É ¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €¥¹ÁÕĞœ°É•¹‘•È€¤ì(($%™Õ¹Ñ¥½¸É•¹‘•È ¤ì($$%Ù…ÈÄ€ô€ Í•…É ¹Ù…±Õ”ñğ€œœ€¤¹ÑÉ¥´ ¤¹Ñ½1½İ•É…Í” ¤ì($$%±¥ÍĞ¹¥¹¹•É!Q50€ô€œœì($$%Ù…ÈÉ½İÌ€ô…¡”¹™¥±Ñ•È ™Õ¹Ñ¥½¸€ Ø€¤ìÉ•ÑÕÉ¸€„Äñğ€ € Ø¹½‘”€¬€œ€œ€¬Ø¹ÍÑ…ÑÕÌ€¬€œ€œ€¬€ Ø¹Á¡½¹”ñğ€œœ€¤€¬€œ€œ€¬€ Ø¹…µÁ…¥¸ñğ€œœ€¤€¤¹Ñ½1½İ•É…Í” ¤¹¥¹‘•á=˜ Ä€¤€ø€´Ä€¤ìô€¤ì($$%¥˜€ €„É½İÌ¹±•¹Ñ €¤ì±¥ÍĞ¹…ÁÁ•¹‘¡¥± •° €±¤œ°¹Õ±°°€œœ€¤€¤¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€•µÁÑäœ°€9¼Ù½Õ¡•ÉÌ¸œ€¤€¤ìÉ•ÑÕÉ¸ìô($$%É½İÌ¹™½É…  ™Õ¹Ñ¥½¸€ Ø€¤ì($$$%Ù…È±¤€ô•° €±¤œ€¤ì($$$%±¤¹…ÁÁ•¹‘¡¥± •° €ÍÁ…¸œ°€‰…‘”‰…‘”´´œ€¬Ø¹ÍÑ…ÑÕÌ°Ø¹ÍÑ…ÑÕÌ€¤€¤ì($$$%Ù…Èµ•Ñ„€ô•° €‘¥Øœ°€µ•Ñ„œ€¤ì($$$%µ•Ñ„¹…ÁÁ•¹‘¡¥± •° €ÍÁ…¸œ°€µ½¹¼œ°Ø¹½‘”€¤€¤ì($$$%Ù…È‰¥ÑÌ€ômtì($$$%¥˜€ Ø¹…µÁ…¥¸€¤ì‰¥ÑÌ¹ÁÕÍ  Ø¹…µÁ…¥¸€¤ìô($$$%¥˜€ Ø¹Á¡½¹”€¤ì‰¥ÑÌ¹ÁÕÍ  Ø¹Á¡½¹”€¤ìô($$$%‰¥ÑÌ¹ÁÕÍ  Ø¹ÍÑ…ÑÕÌ€ôôô€É•‘••µ•œ€˜˜Ø¹É•‘••µ•‘}…Ğ€ü€ € Ø¹¡…¹¹•°ñğ€É•‘••µ•œ€¤€¬€œƒ
Ü€œ€¬Ø¹É•‘••µ•‘}…Ğ€¤€è€ Ø¹É•…Ñ•‘}…Ğñğ€œœ€¤€¤ì($$$%µ•Ñ„¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€ÍÕˆœ°‰¥ÑÌ¹©½¥¸ €œƒ
Ü€œ€¤€¤€¤ì($$$%±¤¹…ÁÁ•¹‘¡¥± µ•Ñ„€¤ì($$$%±¤¹…ÁÁ•¹‘¡¥± •° €ÍÁ…¸œ°€Ù…°œ°Ø¹ÑåÁ”€ôôô€Á•É•¹Ğœ€üØ¹Ù…±Õ”€¬€œ”œ€èµ½¹•ä Ø¹Ù…±Õ”€¤€¤€¤ì($$$%¥˜€ Ø¹ÍÑ…ÑÕÌ€ôôô€¥ÍÍÕ•œ€¤ì($$$$%Ù…ÈÙˆ€ô•° €‰ÕÑÑ½¸œ°€‰Ñ¸´µ¡½ÍĞ‰Ñ¸´µ‘…¹•Èœ°€Y½¥œ€¤ìÙˆ¹ÍÑå±”¹½±½È€ô€œ™™˜œì($$$$%Ùˆ¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €±¥¬œ°™Õ¹Ñ¥½¸€ ¤ì($$$$$%Ù…ÈÉ•…Í½¸€ôİ¥¹‘½Ü¹ÁÉ½µÁĞ €I•…Í½¸™½ÈÙ½¥€¡É•ÅÕ¥É•¤èœ€¤ì($$$$$%¥˜€ €„É•…Í½¸ñğÉ•…Í½¸¹ÑÉ¥´ ¤¹±•¹Ñ €ğ€Ô€¤ìÑ½…ÍĞ €Í¡½ÉĞÙ½¥É•…Í½¸¥ÌÉ•ÅÕ¥É•¸œ€¤ìÉ•ÑÕÉ¸ìô($$$$$%Ùˆ¹‘¥Í…‰±•€ôÑÉÕ”ì($$$$$%…Á¤ €œ½Ù½Õ¡•È½Ù½¥œ°€A=MPœ°ì¥èØ¹¥°É•…Í½¸èÉ•…Í½¸¹ÑÉ¥´ ¤ô€¤¹Ñ¡•¸ ™Õ¹Ñ¥½¸€ È€¤ì($$$$$$%¥˜€ È¹½¬€˜˜È¹‘…Ñ„€˜˜È¹‘…Ñ„¹Ù½¥‘•€¤ìÑ½…ÍĞ €Y½¥‘•€œ€¬Ø¹½‘”€¤ìÉ•™É•Í  ¤ìô($$$$$$%•±Í”ìÙˆ¹‘¥Í…‰±•€ô™…±Í”ìÑ½…ÍĞ € È¹‘…Ñ„€˜˜È¹‘…Ñ„¹µ•ÍÍ…”€¤ñğ€½Õ±¹½ĞÙ½¥¸œ€¤ìô($$$$$%ô€¤¹…Ñ  ™Õ¹Ñ¥½¸€ ¤ìÙˆ¹‘¥Í…‰±•€ô™…±Í”ìÑ½…ÍĞ €9•Ñİ½É¬•ÉÉ½È¸œ€¤ìô€¤ì($$$$%ô€¤ì($$$$%±¤¹…ÁÁ•¹‘¡¥± Ùˆ€¤ì($$$%ô•±Í”¥˜€ Ø¹ÍÑ…ÑÕÌ€ôôô€É•‘••µ•œ€˜˜Ø¹¡…¹¹•°€ôôô€¥¹ÍÑ½É”œ€¤ì($$$$%Ù…ÈÉˆ€ô•° €‰ÕÑÑ½¸œ°€‰Ñ¸´µ¡½ÍĞœ°€I•Ù•ÉÍ”Ñ¥±°Í…¸œ€¤ì($$$$%Éˆ¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €±¥¬œ°™Õ¹Ñ¥½¸€ ¤ì($$$$$%Ù…ÈÉ•…Í½¸€ôİ¥¹‘½Ü¹ÁÉ½µÁĞ €I•…Í½¸™½ÈÑ¥±°½ÉÉ•Ñ¥½¸€¡É•ÅÕ¥É•¤èœ€¤ì($$$$$%¥˜€ €„É•…Í½¸ñğÉ•…Í½¸¹ÑÉ¥´ ¤¹±•¹Ñ €ğ€Ô€¤ìÑ½…ÍĞ €Í¡½ÉĞ½ÉÉ•Ñ¥½¸É•…Í½¸¥ÌÉ•ÅÕ¥É•¸œ€¤ìÉ•ÑÕÉ¸ìô($$$$$%Éˆ¹‘¥Í…‰±•€ôÑÉÕ”ì($$$$$%…Á¤ €œ½Ù½Õ¡•È½É•Ù•ÉÍ”œ°€A=MPœ°ì¥èØ¹¥°É•…Í½¸èÉ•…Í½¸¹ÑÉ¥´ ¤ô€¤¹Ñ¡•¸ ™Õ¹Ñ¥½¸€ È€¤ì($$$$$$%¥˜€ È¹½¬€˜˜È¹‘…Ñ„€˜˜È¹‘…Ñ„¹É•Ù•ÉÍ•€¤ìÑ½…ÍĞ €Y½Õ¡•ÈÉ”µ½Á•¹•…¹…Õ‘¥Ñ•è€œ€¬Ø¹½‘”€¤ìÉ•™É•Í  ¤ìô($$$$$$%•±Í”ìÉˆ¹‘¥Í…‰±•€ô™…±Í”ìÑ½…ÍĞ € È¹‘…Ñ„€˜˜È¹‘…Ñ„¹µ•ÍÍ…”€¤ñğ€½Õ±¹½ĞÉ•Ù•ÉÍ”Ñ¡¥ÌÍ…¸¸œ€¤ìô($$$$$%ô€¤¹…Ñ  ™Õ¹Ñ¥½¸€ ¤ìÉˆ¹‘¥Í…‰±•€ô™…±Í”ìÑ½…ÍĞ €9•Ñİ½É¬•ÉÉ½È¸œ€¤ìô€¤ì($$$$%ô€¤ì($$$$%±¤¹…ÁÁ•¹‘¡¥± Éˆ€¤ì($$$%ô($$$%±¥ÍĞ¹…ÁÁ•¹‘¡¥± ±¤€¤ì($$%ô€¤ì($%ô($%™Õ¹Ñ¥½¸É•™É•Í  ¤ì($$%É•ÑÕÉ¸…Á¤ €œ½…‘µ¥¸½Ù½Õ¡•ÉÌœ°€Pœ€¤¹Ñ¡•¸ ™Õ¹Ñ¥½¸€ È€¤ì($$$%¹•ÑUÀ ¤ì($$$%¥˜€ È¹½¬€˜˜È¹‘…Ñ„€˜˜È¹‘…Ñ„¹Ù½Õ¡•ÉÌ€¤ì…¡”€ôÈ¹‘…Ñ„¹Ù½Õ¡•ÉÌìÉ•¹‘•È ¤ìô($$$%•±Í”¥˜€ È¹ÍÑ…ÑÕÌ€ôôô€ĞÀÌ€¤ì±¥ÍĞ¹¥¹¹•É!Q50€ô€œœì±¥ÍĞ¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€•µÁÑäœ°€=İ¹•È…•ÍÌÉ•ÅÕ¥É•¸œ€¤€¤ìô($$%ô€¤¹…Ñ  ™Õ¹Ñ¥½¸€ ¤ì¹•Ñ½İ¸ ¤ìô€¤ì($%ô($%É•™É•Í  ¤ì($%Á½±±Q¥µ•È€ôÍ•Ñ%¹Ñ•ÉÙ…° É•™É•Í °€ÄÔÀÀÀ€¤ì(%ô(%™Õ¹Ñ¥½¸±…‰•±±• ±…‰•°°¥¹ÁÕĞ€¤ì($%Ù…ÈÜ€ô•° €‘¥Øœ€¤ì($%¥˜€ €„¥¹ÁÕĞ¹¥€¤ì¥¹ÁÕĞ¹¥€ô€‘ˆµ½¹Í½±”µ™¥•±´œ€¬€ €¬­™¥•±‘M•Ä€¤ìô($%Ù…È±…‰•±°€ô•° €±…‰•°œ°¹Õ±°°±…‰•°€¤ì($%±…‰•±°¹Í•ÑÑÑÉ¥‰ÕÑ” €™½Èœ°¥¹ÁÕĞ¹¥€¤ì($%Ü¹…ÁÁ•¹‘¡¥± ±…‰•±°€¤ì($%Ü¹…ÁÁ•¹‘¡¥± ¥¹ÁÕĞ€¤ì($%É•ÑÕÉ¸Üì(%ô(($¼¨€´´´´´´´´´´=É‘•È‰½…ÉÍÉ••¸€´´´´´´´´´´€¨¼(%™Õ¹Ñ¥½¸‰½…É‘MÉ••¸ ÍÉ••¸€¤ì($%Ù…È¡•…€ô•° €‘¥Øœ°€…Éœ€¤ì($%¡•…¹…ÁÁ•¹‘¡¥± •° € Èœ°¹Õ±°°€1¥Ù”½É‘•ÉÌœ€¤€¤ì($%¡•…¹…ÁÁ•¹‘¡¥± •° €Àœ°€ÍÕˆœ°€ÕÑ¼µÉ•™É•Í¡¥¹œ•Ù•Éä€àÍ•½¹‘Ì¸œ€¤€¤ì($%ÍÉ••¸¹…ÁÁ•¹‘¡¥± ¡•…€¤ì($%Ù…ÈİÉ…À€ô•° €‘¥Øœ°€½É‘•ÉÌœ€¤ì($%ÍÉ••¸¹…ÁÁ•¹‘¡¥± İÉ…À€¤ì(($%™Õ¹Ñ¥½¸•Ù•¹Ñ-•ä ¼°Ñ…É•Ğ€¤ì($$%É•ÑÕÉ¸l€½¹Í½±”œ°¼¹¥°¼¹Ù•ÉÍ¥½¸°Ñ…É•Ğ°…Ñ”¹¹½Ü ¤°5…Ñ ¹É…¹‘½´ ¤¹Ñ½MÑÉ¥¹œ €ÌØ€¤¹Í±¥” €È€¤t¹©½¥¸ €œèœ€¤ì($%ô($%™Õ¹Ñ¥½¸…Ğ ¼°Á…Ñ °‰½‘ä°µÍœ€¤ì($$%‰½‘ä€ô‰½‘äñğíôì($$%¥˜€ Á…Ñ €„ôô€œ½…¬œ€¤ì($$$%‰½‘ä¹•áÁ•Ñ•‘}Ù•ÉÍ¥½¸€ô¼¹Ù•ÉÍ¥½¸ì($$$%‰½‘ä¹•Ù•¹Ñ}­•ä€ô•Ù•¹Ñ-•ä ¼°‰½‘ä¹ÍÑ…ÑÕÌñğ€½¹™¥Éµ•œ€¤ì($$%ô($$%É•ÑÕÉ¸…Á¤ €œ½…‘µ¥¸½½É‘•È¼œ€¬¼¹¥€¬Á…Ñ °€A=MPœ°‰½‘ä€¤¹Ñ¡•¸ ™Õ¹Ñ¥½¸€ È€¤ì($$$%¥˜€ È¹½¬€¤ìÑ½…ÍĞ µÍœ€¤ìÉ•™É•Í  ¤ìô•±Í”ìÑ½…ÍĞ € È¹‘…Ñ„€˜˜È¹‘…Ñ„¹µ•ÍÍ…”€¤ñğ€Ñ¥½¸™…¥±•¸œ€¤ìô($$%ô€¤¹…Ñ  ™Õ¹Ñ¥½¸€ ¤ìÑ½…ÍĞ €9•Ñİ½É¬•ÉÉ½È¸œ€¤ìô€¤ì($%ô($%™Õ¹Ñ¥½¸É•™É•Í  ¤ì($$%É•ÑÕÉ¸…Á¤ €œ½…‘µ¥¸½½É‘•ÉÌœ°€Pœ€¤¹Ñ¡•¸ ™Õ¹Ñ¥½¸€ È€¤ì($$$%¹•ÑUÀ ¤ì($$$%¥˜€ €„È¹½¬ñğ€„È¹‘…Ñ„€¤ìÉ•ÑÕÉ¸ìô($$$%Ù…È½É‘•ÉÌ€ôÈ¹‘…Ñ„¹‘…Ñ„ñğÈ¹‘…Ñ„¹½É‘•ÉÌñğmtì($$$%İÉ…À¹¥¹¹•É!Q50€ô€œœì($$$%¥˜€ €„½É‘•ÉÌ¹±•¹Ñ €¤ìİÉ…À¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€•µÁÑäœ°€9¼…Ñ¥Ù”½É‘•ÉÌ¸œ€¤€¤ìÉ•ÑÕÉ¸ìô($$$%½É‘•ÉÌ¹™½É…  ™Õ¹Ñ¥½¸€ ¼€¤ì($$$$%Ù…È¥Í9•Ü€ô€ ¼¹ÍÑ…ÑÕÌ€ôôô€¹•Üœñğ¼¹ÍÑ…ÑÕÌ€ôôô€Á•¹‘¥¹œœñğ€ ¼¹…­¹½İ±•‘•‘}…Ğ€ôôô¹Õ±°€˜˜€„¼¹…­¹½İ±•‘•€¤€¤ì($$$$%Ù…È…É€ô•° €‘¥Øœ°€½É‘•Èœ€¬€ ¥Í9•Ü€ü€œ¥Ìµ¹•Üœ€è€œœ€¤€¤ì($$$$%Ù…È €ô•° €‘¥Øœ°€½É‘•É}} œ€¤ì($$$$% ¹…ÁÁ•¹‘¡¥± •° €ÍÁ…¸œ°€½É‘•É}}¹¼œ°€œŒœ€¬€ ¼¹½É‘•É}¹Õµ‰•Èñğ¼¹¥€¤€¤€¤ì($$$$% ¹…ÁÁ•¹‘¡¥± •° €ÍÁ…¸œ°€‰…‘”‰…‘”´´œ€¬€ ¼¹ÍÑ…ÑÕÌñğ€¹•Üœ€¤°¼¹ÍÑ…ÑÕÍ}±…‰•°ñğ¼¹ÍÑ…ÑÕÌñğ€¹•Üœ€¤€¤ì($$$$%…É¹…ÁÁ•¹‘¡¥±  €¤ì($$$$%…É¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€ÍÕˆœ°€ ¼¹½É‘•É}ÑåÁ”ñğ€œœ€¤€¬€œƒ
Ü€œ€¬€ ¼¹ÕÍÑ½µ•É}¹…µ”ñğ€œœ€¤€¬€ ¼¹Ñ½Ñ…°€ü€œƒ
Ü€œ€¬µ½¹•ä ¼¹Ñ½Ñ…°€¤€è€œœ€¤€¤€¤ì($$$$%¥˜€ ¼¹¥Ñ•µÍ}ÍÕµµ…Éäñğ¼¹¥Ñ•µÌ€¤ì($$$$$%…É¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€½É‘•É}}¥Ñ•µÌœ°¼¹¥Ñ•µÍ}ÍÕµµ…Éäñğ€ ÉÉ…ä¹¥ÍÉÉ…ä ¼¹¥Ñ•µÌ€¤€ü¼¹¥Ñ•µÌ¹µ…À ™Õ¹Ñ¥½¸€ ¤€¤ìÉ•ÑÕÉ¸€ ¤¹ÅÕ…¹Ñ¥Ñäñğ€Ä€¤€¬€Ÿ\€œ€¬€ ¤¹¹…µ”ñğ€œœ€¤ìô€¤¹©½¥¸ €œ°€œ€¤€è€œœ€¤€¤€¤ì($$$$%ô($$$$%¥˜€ ¼¹Ñ¥µ¥¹}±…‰•°€¤ì…É¹…ÁÁ•¹‘¡¥± •° €‘¥Øœ°€ÍÕˆœ°¼¹Ñ¥µ¥¹}±…‰•°€¤€¤ìô($$$$%Ù…È…Ñ¥½¹Ì€ô•° €‘¥Øœ°€½É‘•É}}…Ñ¥½¹Ìœ€¤ì($$$$%¥˜€ ¼¹ÍÑ…ÑÕÌ€ôôô€Á•¹‘¥¹œœ€¤ì($$$$$%Ù…È…¬€ô•° €‰ÕÑÑ½¸œ°€‰Ñ¸´µ¡½ÍĞœ°€­¹½İ±•‘”œ€¤ì($$$$$%…¬¹Í•ÑÑÑÉ¥‰ÕÑ” €…É¥„µ±…‰•°œ°€­¹½İ±•‘”½É‘•È€œ€¬¼¹½É‘•É}¹Õµ‰•È€¤ì($$$$$%…¬¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €±¥¬œ°™Õ¹Ñ¥½¸€ ¤ì…Ğ ¼°€œ½…¬œ°íô°€­¹½İ±•‘•œ€¤ìô€¤ì($$$$$%…Ñ¥½¹Ì¹…ÁÁ•¹‘¡¥± …¬€¤ì($$$$$%l€ÄÀ°€ÄÔ°€ÈÀ°€ÌÀt¹™½É…  ™Õ¹Ñ¥½¸€ •Ñ„€¤ì($$$$$$%Ù…È…•ÁÑ	ÕÑÑ½¸€ô•° €‰ÕÑÑ½¸œ°€‰Ñ¸´µ¡½ÍĞœ°€•ÁĞ€œ€¬•Ñ„€¬€´œ€¤ì($$$$$$%…•ÁÑ	ÕÑÑ½¸¹Í•ÑÑÑÉ¥‰ÕÑ” €…É¥„µ±…‰•°œ°€•ÁĞ½É‘•È€œ€¬¼¹½É‘•É}¹Õµ‰•È€¬€œ°É•…‘ä¥¸€œ€¬•Ñ„€¬€œµ¥¹ÕÑ•Ìœ€¤ì($$$$$$%…•ÁÑ	ÕÑÑ½¸¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €±¥¬œ°™Õ¹Ñ¥½¸€ ¤ì…Ğ ¼°€œ½…•ÁĞœ°ì•Ñ„è•Ñ„ô°€=É‘•È…•ÁÑ•œ€¤ìô€¤ì($$$$$$%…Ñ¥½¹Ì¹…ÁÁ•¹‘¡¥± …•ÁÑ	ÕÑÑ½¸€¤ì($$$$$%ô€¤ì($$$$%ô•±Í”ì($$$$$$ ¼¹…±±½İ•‘}¹•áÑ}ÍÑ…ÑÕÍ•Ìñğmt€¤¹™¥±Ñ•È ™Õ¹Ñ¥½¸€ ÍĞ€¤ìÉ•ÑÕÉ¸ÍĞ€„ôô€…¹•±±•œìô€¤¹™½É…  ™Õ¹Ñ¥½¸€ ÍĞ€¤ì($$$$$$%Ù…ÈÑ•áĞ€ô€ ¼¹ÍÑ…ÑÕÍ}±…‰•°€˜˜ÍĞ€ôôô€ÁÉ•Á…É¥¹œœ€¤€ü€MÑ…ÉĞ½½­¥¹œœ€è€ ÍĞ€ôôô€½µÁ±•Ñ•œ€ü€½µÁ±•Ñ”œ€èÍĞ¹É•Á±…” €½|½œ°€œ€œ€¤€¤ì($$$$$$%Ù…Èˆ€ô•° €‰ÕÑÑ½¸œ°€‰Ñ¸´µ¡½ÍĞœ°Ñ•áĞ¹¡…ÉĞ €À€¤¹Ñ½UÁÁ•É…Í” ¤€¬Ñ•áĞ¹Í±¥” €Ä€¤€¤ì($$$$$$%ˆ¹Í•ÑÑÑÉ¥‰ÕÑ” €…É¥„µ±…‰•°œ°Ñ•áĞ€¬€œ½É‘•È€œ€¬¼¹½É‘•É}¹Õµ‰•È€¤ì($$$$$$%ˆ¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È €±¥¬œ°™Õ¹Ñ¥½¸€ ¤ì…Ğ ¼°€œ½ÍÑ…ÑÕÌœ°ìÍÑ…ÑÕÌèÍĞô°€=É‘•ÈÕÁ‘…Ñ•œ€¤ìô€¤ì($$$$$$%…Ñ¥½¹Ì¹…ÁÁ•¹‘¡¥± ˆ€¤ì($$$$$%ô€¤ì($$$$%ô($$$$%…É¹…ÁÁ•¹‘¡¥± …Ñ¥½¹Ì€¤ì($$$$%İÉ…À¹…ÁÁ•¹‘¡¥± …É€¤ì($$$%ô€¤ì($$%ô€¤¹…Ñ  ™Õ¹Ñ¥½¸€ ¤ì¹•Ñ½İ¸ ¤ìô€¤ì($%ô($%É•™É•Í  ¤ì($%Á½±±Q¥µ•È€ôÍ•Ñ%¹Ñ•ÉÙ…° É•™É•Í °€àÀÀÀ€¤ì(($$¼¼1…å•È¥¹ÍÑ…¹ĞMMÕÁ‘…Ñ•Ì½¸Ñ½À½˜Ñ¡”Á½±°İ¡•¸Ñ¡”Í¥Ñ”…‘Ù•ÉÑ¥Í•Ì($$¼¼5•ÉÕÉ”¸•™•¹Í¥Ù”è¥˜Õ¹ÍÕÁÁ½ÉÑ•½È…‰Í•¹Ğ°Ñ¡”Á½±°…‰½Ù”¥ÌÑ¡”½¹±ä($$¼¼Á…Ñ …¹‰•¡…Ù¥½ÕÈ¥Ì•á…Ñ±ä…Ì‰•™½É”¸($%•¹ÍÕÉ•½¹™¥œ ™Õ¹Ñ¥½¸€ ¤ì½¹¹•ÑMÍ” É•™É•Í €¤ìô€¤ì(%ô(($¼¨€´´´´´´´´´´‰½½Ğ€´´´´´´´´´´€¨¼(%¥˜€ ÍÑ…Ñ”¹Í¥Ñ”€˜˜ÍÑ…Ñ”¹ÕÍ•È€˜˜ÍÑ…Ñ”¹Á…ÍÌ€˜˜ÍÑ…Ñ”¹…ÁÌ€¤ì($%…Á¤ €œ½…ÕÑ ½µ”œ°€Pœ€¤¹Ñ¡•¸ ™Õ¹Ñ¥½¸€ È€¤ì($$%¥˜€ È¹½¬€˜˜È¹‘…Ñ„€¤ì($$$%ÍÑ…Ñ”¹¹…µ”€ôÈ¹‘…Ñ„¹¹…µ”ñğÍÑ…Ñ”¹¹…µ”ì($$$%ÍÑ…Ñ”¹ÕÉÉ•¹ä€ôÈ¹‘…Ñ„¹ÕÉÉ•¹äñğÍÑ…Ñ”¹ÕÉÉ•¹äì($$$%ÍÑ…Ñ”¹…ÁÌ€ôìÉ•‘••´è€„„È¹‘…Ñ„¹…¹}É•‘••´°µ…¹…”è€„„È¹‘…Ñ„¹…¹}µ…¹…”°‰½…Éè€„„È¹‘…Ñ„¹…¹}‰½…Éôì($$$%Í…Ù” ¤ì($$$%É•¹‘•ÉÁÀ ¤ì($$%ô•±Í”ìÉ•¹‘•É1½¥¸ ¤ìô($%ô€¤¹…Ñ  ™Õ¹Ñ¥½¸€ ¤ìÉ•¹‘•É1½¥¸ ¤ìô€¤ì(%ô•±Í”ì($%É•¹‘•É1½¥¸ ¤ì(%ô)ô€¤ ¤ì(