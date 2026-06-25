/**
 * DoughBoss — staff Voucher Scan dashboard.
 *
 * Hydrates #doughboss-scan-app: a big scan/redeem box plus a live tracker
 * (today's campaign release meters, status tiles and the recent voucher feed).
 * Talks to doughboss/v1/voucher/scan (atomic in-store redeem) and
 * /voucher/activity (live state). Designed so a barcode scanner that types the
 * code and presses Enter "just works", and so the voucher dies on first scan.
 *
 * No build step — plain DOM, enqueued with the plugin version.
 */
( function () {
	'use strict';

	var cfg = window.DoughBossScan || {};
	var restUrl = ( cfg.restUrl || '' ).replace( /\/$/, '' );
	var nonce = cfg.nonce || '';
	var currency = cfg.currency || '$';

	var root = document.getElementById( 'doughboss-scan-app' );
	if ( ! root ) {
		return;
	}

	var POLL_MS = 10000;
	var pollTimer = null;
	var clearTimer = null;
	var feedCache = [];
	var els = {};
	// Reuse one key per code until it succeeds so a retry after a dropped
	// connection replays the same redeem rather than risking a double.
	var idemCode = '';
	var idemKey = '';

	// Camera QR scan (html5-qrcode, lazy-loaded from CDN on first use).
	var CAM_CDN = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
	var camScanner = null;
	var camLibPromise = null;

	function money( n ) {
		return currency + ( Math.round( ( Number( n ) || 0 ) * 100 ) / 100 ).toFixed( 2 );
	}

	function make( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		return node;
	}

	function api( path, method, body ) {
		return fetch( restUrl + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, status: res.status, data: data };
			} );
		} );
	}

	/* ---------- shell ---------- */

	function buildShell() {
		root.innerHTML = '';

		var head = make( 'div', 'db-scan__head' );
		var headL = make( 'div' );
		headL.appendChild( make( 'h1', null, 'Voucher Scan' ) );
		headL.appendChild( make( 'p', null, 'Scan or type a voucher code to redeem it at the till. Each code works once.' ) );
		var live = make( 'span', 'db-scan__live' );
		live.appendChild( make( 'span', 'db-scan__dot' ) );
		els.liveText = make( 'span', null, 'Live' );
		live.appendChild( els.liveText );
		head.appendChild( headL );
		head.appendChild( live );
		root.appendChild( head );

		var grid = make( 'div', 'db-scan__grid' );

		/* left: scan card */
		var scanCard = make( 'div', 'db-card' );
		scanCard.appendChild( make( 'h2', null, 'Redeem a voucher' ) );

		els.input = make( 'input', 'db-scan__input' );
		els.input.setAttribute( 'type', 'text' );
		els.input.setAttribute( 'placeholder', 'SNOW-XXXXXXXX' );
		els.input.setAttribute( 'autocomplete', 'off' );
		els.input.setAttribute( 'spellcheck', 'false' );
		els.input.setAttribute( 'aria-label', 'Voucher code' );
		scanCard.appendChild( els.input );

		// Camera QR scan. html5-qrcode is loaded lazily from the CDN on first click
		// (the dashboard does not enqueue it), so no PHP enqueue change is needed.
		els.camBtn = make( 'button', 'db-scan__cam', 'Scan with camera' );
		els.camBtn.setAttribute( 'type', 'button' );
		els.camWrap = make( 'div', 'db-scan__cam-wrap' );
		els.camView = make( 'div', 'db-scan__cam-view' );
		els.camView.id = 'db-scan-cam-' + Date.now();
		els.camStop = make( 'button', 'db-scan__cam-stop', 'Stop' );
		els.camStop.setAttribute( 'type', 'button' );
		els.camWrap.appendChild( els.camView );
		els.camWrap.appendChild( els.camStop );
		scanCard.appendChild( els.camBtn );
		scanCard.appendChild( els.camWrap );

		var row = make( 'div', 'db-scan__row' );
		els.total = make( 'input', 'db-scan__total' );
		els.total.setAttribute( 'type', 'number' );
		els.total.setAttribute( 'min', '0' );
		els.total.setAttribute( 'step', '0.01' );
		els.total.setAttribute( 'placeholder', 'Order total (only for % / min-spend)' );
		els.total.setAttribute( 'aria-label', 'Order total' );
		els.btn = make( 'button', 'db-scan__btn', 'Redeem' );
		els.btn.setAttribute( 'type', 'button' );
		row.appendChild( els.total );
		row.appendChild( els.btn );
		scanCard.appendChild( row );

		scanCard.appendChild( make( 'p', 'db-scan__hint', 'Tip: a barcode scanner can type the code and press Enter. Enter the order total to cap a discount to the order value.' ) );

		els.result = make( 'div', 'db-scan__result' );
		scanCard.appendChild( els.result );

		grid.appendChild( scanCard );

		/* right: tracker */
		var trackCol = make( 'div' );

		els.tiles = make( 'div', 'db-tiles' );
		trackCol.appendChild( els.tiles );

		var campCard = make( 'div', 'db-card' );
		campCard.style.marginBottom = '18px';
		campCard.appendChild( make( 'h2', null, "Today's release" ) );
		els.meters = make( 'div' );
		campCard.appendChild( els.meters );
		trackCol.appendChild( campCard );

		var feedCard = make( 'div', 'db-card' );
		feedCard.appendChild( make( 'h2', null, 'Recent vouchers' ) );
		els.search = make( 'input', 'db-feed__search' );
		els.search.setAttribute( 'type', 'search' );
		els.search.setAttribute( 'placeholder', 'Filter by code, status or phone…' );
		feedCard.appendChild( els.search );
		els.feed = make( 'ul', 'db-feed' );
		feedCard.appendChild( els.feed );
		trackCol.appendChild( feedCard );

		grid.appendChild( trackCol );
		root.appendChild( grid );

		bind();
	}

	/* ---------- interactions ---------- */

	function bind() {
		els.btn.addEventListener( 'click', submitScan );
		els.input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				submitScan();
			}
		} );
		els.total.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				submitScan();
			}
		} );
		els.search.addEventListener( 'input', renderFeed );
		els.camBtn.addEventListener( 'click', startCamScan );
		els.camStop.addEventListener( 'click', endCamScan );
		focusInput();
	}

	/* ---------- camera QR scan ---------- */

	// Inject the html5-qrcode UMD script once; resolve when window.Html5Qrcode is ready.
	function loadCamLib() {
		if ( window.Html5Qrcode ) {
			return Promise.resolve( true );
		}
		if ( camLibPromise ) {
			return camLibPromise;
		}
		camLibPromise = new Promise( function ( resolve, reject ) {
			var s = document.createElement( 'script' );
			s.src = CAM_CDN;
			s.async = true;
			s.onload = function () {
				if ( window.Html5Qrcode ) {
					resolve( true );
				} else {
					reject( new Error( 'Scanner library failed to initialise.' ) );
				}
			};
			s.onerror = function () {
				camLibPromise = null;
				reject( new Error( 'Could not load the scanner library.' ) );
			};
			document.head.appendChild( s );
		} );
		return camLibPromise;
	}

	function stopCamScan() {
		if ( ! camScanner ) {
			return;
		}
		var sc = camScanner;
		camScanner = null;
		try {
			sc.stop().then( function () { try { sc.clear(); } catch ( e ) {} } ).catch( function () {} );
		} catch ( e ) {}
	}

	function startCamScan() {
		if ( camScanner ) {
			return;
		}
		els.camBtn.disabled = true;
		showResult( 'neutral', 'Starting camera…' );
		loadCamLib().then( function () {
			els.camWrap.classList.add( 'is-on' );
			var scanner = new window.Html5Qrcode( els.camView.id, { verbose: false } );
			camScanner = scanner;
			return scanner.start(
				{ facingMode: 'environment' },
				{ fps: 10, qrbox: 240 },
				function ( decoded ) {
					// Reuse the existing redeem flow: fill the input and submit.
					els.input.value = ( decoded || '' ).trim();
					stopCamScan();
					els.camWrap.classList.remove( 'is-on' );
					els.camBtn.disabled = false;
					submitScan();
				},
				function () { /* per-frame decode misses — ignore */ }
			);
		} ).catch( function ( err ) {
			camScanner = null;
			els.camWrap.classList.remove( 'is-on' );
			els.camBtn.disabled = false;
			showResult( 'bad', 'Camera unavailable', ( err && err.message ) ? err.message : 'Allow camera access and try again.' );
		} );
	}

	function endCamScan() {
		stopCamScan();
		els.camWrap.classList.remove( 'is-on' );
		els.camBtn.disabled = false;
		focusInput();
	}

	function focusInput() {
		try {
			els.input.focus();
			els.input.select();
		} catch ( e ) {}
	}

	function showResult( kind, title, sub, amount ) {
		clearTimeout( clearTimer );
		els.result.className = 'db-scan__result is-' + kind;
		els.result.innerHTML = '';
		els.result.appendChild( make( 'p', 'db-scan__result-title', title ) );
		if ( amount !== undefined && amount !== null ) {
			els.result.appendChild( make( 'p', 'db-scan__result-amt', money( amount ) ) );
		}
		if ( sub ) {
			els.result.appendChild( make( 'p', 'db-scan__result-sub', sub ) );
		}
		try { els.result.scrollIntoView( { block: 'nearest' } ); } catch ( e ) {}
	}

	function submitScan() {
		var code = ( els.input.value || '' ).trim();
		els.total.classList.remove( 'is-required' );
		if ( ! code ) {
			showResult( 'neutral', 'Scan or type a voucher code' );
			focusInput();
			return;
		}
		var subtotal = parseFloat( els.total.value );
		if ( isNaN( subtotal ) || subtotal < 0 ) {
			subtotal = 0;
		}
		if ( code !== idemCode || ! idemKey ) {
			idemCode = code;
			idemKey  = 'scan-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2, 8 );
		}
		els.btn.disabled = true;
		showResult( 'neutral', 'Checking…' );

		api( '/voucher/scan', 'POST', {
			code: code,
			subtotal: subtotal,
			idempotency_key: idemKey
		} ).then( function ( r ) {
			els.btn.disabled = false;
			if ( r.ok && r.data && r.data.redeemed ) {
				showResult( 'ok', 'Redeemed ✓', r.data.code, r.data.amount );
				els.input.value = '';
				els.total.value = '';
				idemCode = '';
				idemKey = '';
				clearTimer = setTimeout( function () { els.result.className = 'db-scan__result'; focusInput(); }, 7000 );
				refresh();
			} else {
				var msg = ( r.data && r.data.message ) ? r.data.message : 'Could not redeem this voucher.';
				var code2 = r.data && r.data.code;
				var title = 'Declined';
				if ( 'doughboss_voucher_used' === code2 ) {
					title = 'Already used';
				} else if ( 'doughboss_voucher_min' === code2 ) {
					title = 'Minimum spend not met';
				} else if ( 'doughboss_need_total' === code2 ) {
					title = 'Enter order total';
				}
				showResult( 'bad', title, msg );
				if ( 'doughboss_need_total' === code2 ) {
					els.total.classList.add( 'is-required' );
					try { els.total.focus(); } catch ( e ) {}
					return;
				}
			}
			focusInput();
		} ).catch( function () {
			els.btn.disabled = false;
			showResult( 'bad', 'Network error', 'Please try again.' );
			focusInput();
		} );
	}

	/* ---------- live tracker ---------- */

	function refresh() {
		return api( '/voucher/activity', 'GET' ).then( function ( r ) {
			if ( ! r.ok || ! r.data ) {
				if ( els.liveText ) {
					els.liveText.textContent = 'Reconnecting…';
				}
				return;
			}
			if ( els.liveText ) {
				els.liveText.textContent = 'Live';
			}
			if ( r.data.currency ) {
				currency = r.data.currency;
			}
			renderTiles( r.data.totals || {} );
			renderMeters( r.data.campaigns || [] );
			feedCache = r.data.recent || [];
			renderFeed();
		} ).catch( function () {} );
	}

	function renderTiles( totals ) {
		els.tiles.innerHTML = '';
		var defs = [
			{ k: 'issued', l: 'Live (issued)', cls: 'db-tile--live' },
			{ k: 'redeemed', l: 'Redeemed', cls: 'db-tile--ok' },
			{ k: 'voided', l: 'Voided', cls: '' }
		];
		defs.forEach( function ( d ) {
			var t = make( 'div', 'db-tile ' + d.cls );
			t.appendChild( make( 'div', 'db-tile__n', totals[ d.k ] || 0 ) );
			t.appendChild( make( 'div', 'db-tile__l', d.l ) );
			els.tiles.appendChild( t );
		} );
	}

	function renderMeters( campaigns ) {
		els.meters.innerHTML = '';
		if ( ! campaigns.length ) {
			els.meters.appendChild( make( 'div', 'db-empty', 'No campaigns configured.' ) );
			return;
		}
		campaigns.forEach( function ( c ) {
			var meter = make( 'div', 'db-meter' );
			var top = make( 'div', 'db-meter__top' );
			var name = make( 'div', 'db-meter__name' );
			name.appendChild( document.createTextNode(
				( 'percent' === c.type ? c.value + '% ' : money( c.value ) + ' ' )
			) );
			var sm = make( 'small', null, c.label + ( c.shared ? ' · shared pool' : '' ) );
			name.appendChild( sm );
			top.appendChild( name );

			var cap = Number( c.cap ) || 0;
			var used = Number( c.pool_used ) || 0;
			var countText = make( 'div', 'db-meter__count' );
			if ( cap > 0 ) {
				var b = make( 'b', null, used );
				countText.appendChild( b );
				countText.appendChild( document.createTextNode( ' / ' + cap + ' claimed' ) );
			} else {
				countText.appendChild( make( 'b', null, used ) );
				countText.appendChild( document.createTextNode( ' claimed' ) );
			}
			top.appendChild( countText );
			meter.appendChild( top );

			var bar = make( 'div', 'db-bar' );
			var pct = cap > 0 ? Math.min( 100, Math.round( ( used / cap ) * 100 ) ) : 0;
			var fill = make( 'div', 'db-bar__fill' + ( cap > 0 && used >= cap ? ' is-full' : '' ) );
			fill.style.width = pct + '%';
			bar.appendChild( fill );
			meter.appendChild( bar );

			els.meters.appendChild( meter );
		} );
	}

	function renderFeed() {
		var q = ( els.search.value || '' ).trim().toLowerCase();
		els.feed.innerHTML = '';
		var rows = feedCache.filter( function ( v ) {
			if ( ! q ) {
				return true;
			}
			return ( ( v.code || '' ) + ' ' + ( v.status || '' ) + ' ' + ( v.phone || '' ) + ' ' + ( v.campaign || '' ) ).toLowerCase().indexOf( q ) > -1;
		} );
		if ( ! rows.length ) {
			els.feed.appendChild( make( 'li', null, '' ) ).appendChild( make( 'div', 'db-empty', 'No vouchers yet.' ) );
			return;
		}
		rows.forEach( function ( v ) {
			var li = document.createElement( 'li' );

			var meta = make( 'div', 'db-feed__meta' );
			meta.appendChild( make( 'span', 'db-feed__code', v.code ) );
			var sub = make( 'div', 'db-feed__sub' );
			var bits = [];
			if ( v.campaign ) {
				bits.push( v.campaign );
			}
			if ( v.phone ) {
				bits.push( v.phone );
			}
			if ( 'redeemed' === v.status && v.redeemed_at ) {
				bits.push( ( v.channel || 'redeemed' ) + ' · ' + v.redeemed_at );
			} else if ( v.created_at ) {
				bits.push( 'issued ' + v.created_at );
			}
			sub.textContent = bits.join( ' · ' );
			meta.appendChild( sub );

			var badge = make( 'span', 'db-badge db-badge--' + ( v.status || 'issued' ), v.status || 'issued' );
			var val = make( 'span', 'db-feed__val', ( 'percent' === v.type ? v.value + '%' : money( v.value ) ) );

			li.appendChild( badge );
			li.appendChild( meta );
			li.appendChild( val );
			els.feed.appendChild( li );
		} );
	}

	/* ---------- boot ---------- */

	function startPoll() {
		stopPoll();
		pollTimer = setInterval( refresh, POLL_MS );
	}

	function stopPoll() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	buildShell();
	refresh();
	startPoll();
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			stopPoll();
			endCamScan();
		} else {
			refresh();
			startPoll();
		}
	} );
} )();
