/* global DoughBossSquareDashboard */
/**
 * Owner-only Square dashboard presentation.
 *
 * The REST endpoint owns all data selection. This client never persists demo
 * state, calls Square, or treats demo data as a fallback for a failed status
 * request.
 */
( function () {
	'use strict';

	var config = window.DoughBossSquareDashboard || {};
	var root = document.getElementById( 'doughboss-square-dashboard' );

	if ( ! root || typeof config.restUrl !== 'string' || typeof config.nonce !== 'string' || typeof window.fetch !== 'function' ) {
		return;
	}

	var previewButton = root.querySelector( '[data-square-preview]' );
	var statusButton = root.querySelector( '[data-square-status-button]' );
	var locationSelect = root.querySelector( '[data-square-location]' );
	var status = root.querySelector( '[data-square-status]' );
	var content = root.querySelector( '[data-square-content]' );
	var activeMode = 'status';
	var validLocations = [ 'all', 'revesby', 'bankstown', 'roselands' ];

	function isObject( value ) {
		return value !== null && typeof value === 'object' && ! Array.isArray( value );
	}

	function isSafeInteger( value ) {
		return typeof value === 'number' && Number.isSafeInteger( value );
	}

	function isNonEmptyString( value ) {
		return typeof value === 'string' && value.trim().length > 0;
	}

	function create( tagName, className, text ) {
		var element = document.createElement( tagName );
		if ( className ) {
			element.className = className;
		}
		if ( typeof text === 'string' ) {
			element.textContent = text;
		}
		return element;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function formatMoney( minor, currency ) {
		try {
			return new Intl.NumberFormat( 'en-AU', {
				style: 'currency',
				currency: currency,
				minimumFractionDigits: 2,
				maximumFractionDigits: 2
			} ).format( minor / 100 );
		} catch ( error ) {
			return '$' + ( minor / 100 ).toFixed( 2 );
		}
	}

	function validateBase( payload ) {
		if ( ! isObject( payload ) || payload.schema_version !== 1 || ! isObject( payload.connection ) || ! isObject( payload.staff ) || ! isObject( payload.planned_location ) || ! Array.isArray( payload.locations ) ) {
			return false;
		}

		if ( ! [ 'square', 'synthetic' ].includes( payload.source ) || ! [ 'not_connected', 'demo' ].includes( payload.data_state ) || ! [ 'not_configured', 'configured_unverified' ].includes( payload.connection.state ) || ! [ 'test', 'live' ].includes( payload.connection.environment ) || typeof payload.connection.credentials_present !== 'boolean' || payload.connection.provider_verified !== false || payload.staff.managed_by !== 'square' || payload.staff.attendance_available !== false || payload.planned_location.label !== 'Planned location' || payload.planned_location.state !== 'private_unmapped' ) {
			return false;
		}

		return payload.locations.every( function ( location ) {
			return isObject( location ) && isNonEmptyString( location.id ) && isNonEmptyString( location.name ) && location.state === 'unmapped';
		} );
	}

	function validateDemo( payload ) {
		if ( ! validateBase( payload ) || payload.source !== 'synthetic' || payload.data_state !== 'demo' || payload.demo !== true || ! validLocations.includes( payload.selected_location ) || payload.currency !== 'AUD' || ! isObject( payload.summary ) || ! Array.isArray( payload.location_summaries ) || ! Array.isArray( payload.sales_series ) || ! Array.isArray( payload.orders ) ) {
			return false;
		}

		var moneyFields = [ 'gross_sales_minor', 'refunds_minor', 'net_sales_minor', 'orders', 'average_order_minor' ];
		if ( ! moneyFields.every( function ( key ) { return isSafeInteger( payload.summary[ key ] ); } ) ) {
			return false;
		}

		return payload.location_summaries.every( function ( location ) {
			return isObject( location ) && isNonEmptyString( location.id ) && isNonEmptyString( location.name ) && moneyFields.every( function ( key ) { return isSafeInteger( location[ key ] ); } );
		} ) && payload.sales_series.every( function ( point ) {
			return isObject( point ) && isNonEmptyString( point.label ) && isSafeInteger( point.gross_sales_minor );
		} ) && payload.orders.every( function ( order ) {
			return isObject( order ) && isNonEmptyString( order.id ) && isNonEmptyString( order.location_id ) && isNonEmptyString( order.location_name ) && isNonEmptyString( order.customer ) && isNonEmptyString( order.status ) && isSafeInteger( order.amount_minor ) && Array.isArray( order.items ) && order.items.every( function ( item ) {
				return isObject( item ) && isNonEmptyString( item.name ) && isSafeInteger( item.quantity ) && isSafeInteger( item.unit_price_minor );
			} );
		} );
	}

	function addCard( parent, label, value, note ) {
		var card = create( 'div', 'doughboss-square-dashboard__metric' );
		card.appendChild( create( 'p', 'doughboss-square-dashboard__metric-label', label ) );
		card.appendChild( create( 'p', 'doughboss-square-dashboard__metric-value', value ) );
		if ( note ) {
			card.appendChild( create( 'p', 'doughboss-square-dashboard__metric-note', note ) );
		}
		parent.appendChild( card );
	}

	function buildOperationalCards( payload ) {
		var grid = create( 'div', 'doughboss-square-dashboard__grid' );
		var connection = create( 'section', 'doughboss-square-dashboard__panel' );
		connection.appendChild( create( 'h3', '', 'Connection status' ) );
		connection.appendChild( create( 'p', 'doughboss-square-dashboard__state', payload.connection.state === 'not_configured' ? 'Not configured' : 'Configured, not yet verified' ) );
		connection.appendChild( create( 'p', 'description', payload.connection.credentials_present ? 'Credentials are present. Square has not been verified from this dashboard.' : 'No Square credentials are configured.' ) );
		connection.appendChild( create( 'p', 'description', 'Environment: ' + payload.connection.environment + '. Connected Square figures will be available after verification. Demo figures are synthetic.' ) );
		grid.appendChild( connection );

		var staff = create( 'section', 'doughboss-square-dashboard__panel' );
		staff.appendChild( create( 'h3', '', 'Team and attendance' ) );
		staff.appendChild( create( 'p', 'doughboss-square-dashboard__state', 'Team and attendance managed in Square' ) );
		staff.appendChild( create( 'p', 'description', 'Clock controls are intentionally not part of this DoughBoss dashboard.' ) );
		var squareLink = create( 'a', 'button', 'Open Square Dashboard' );
		squareLink.href = 'https://dashboard.squareup.com/';
		squareLink.target = '_blank';
		squareLink.rel = 'noopener noreferrer';
		staff.appendChild( squareLink );
		grid.appendChild( staff );

		var planned = create( 'section', 'doughboss-square-dashboard__panel doughboss-square-dashboard__panel--private' );
		planned.appendChild( create( 'h3', '', 'Planned location' ) );
		planned.appendChild( create( 'p', 'doughboss-square-dashboard__state', 'Unannounced — no Square mapping' ) );
		planned.appendChild( create( 'p', 'description', 'Internal planning only. No name, address, public listing, or totals are shown.' ) );
		grid.appendChild( planned );

		return grid;
	}

	function renderStatus( payload ) {
		clear( content );
		content.appendChild( buildOperationalCards( payload ) );
	}

	function renderDemo( payload ) {
		clear( content );
		var banner = create( 'p', 'doughboss-square-dashboard__demo-label', 'Demo data — synthetic preview only. This is not Square data and is not stored.' );
		content.appendChild( banner );
		var metrics = create( 'div', 'doughboss-square-dashboard__metrics' );
		addCard( metrics, 'Gross sales', formatMoney( payload.summary.gross_sales_minor, payload.currency ) );
		addCard( metrics, 'Refunds', formatMoney( payload.summary.refunds_minor, payload.currency ) );
		addCard( metrics, 'Net sales', formatMoney( payload.summary.net_sales_minor, payload.currency ) );
		addCard( metrics, 'Orders', String( payload.summary.orders ) );
		addCard( metrics, 'Average order', formatMoney( payload.summary.average_order_minor, payload.currency ) );
		content.appendChild( metrics );
		content.appendChild( buildOperationalCards( payload ) );

		var grid = create( 'div', 'doughboss-square-dashboard__grid' );
		var locationPanel = create( 'section', 'doughboss-square-dashboard__panel' );
		locationPanel.appendChild( create( 'h3', '', 'Demo location summaries' ) );
		var locations = create( 'ul', 'doughboss-square-dashboard__list' );
		payload.location_summaries.forEach( function ( location ) {
			locations.appendChild( create( 'li', '', location.name + ': ' + formatMoney( location.net_sales_minor, payload.currency ) + ' net · ' + String( location.orders ) + ' orders' ) );
		} );
		locationPanel.appendChild( locations );
		grid.appendChild( locationPanel );

		var salesPanel = create( 'section', 'doughboss-square-dashboard__panel' );
		salesPanel.appendChild( create( 'h3', '', 'Demo sales series' ) );
		var sales = create( 'ul', 'doughboss-square-dashboard__sales-bars' );
		var maximumGross = payload.sales_series.reduce( function ( maximum, point ) {
			return Math.max( maximum, Math.max( 0, point.gross_sales_minor ) );
		}, 0 );
		payload.sales_series.forEach( function ( point ) {
			var item = create( 'li', 'doughboss-square-dashboard__sales-bar' );
			var gross = Math.max( 0, point.gross_sales_minor );
			var percentage = maximumGross > 0 ? Math.round( ( gross / maximumGross ) * 100 ) : 0;
			item.appendChild( create( 'span', 'doughboss-square-dashboard__sales-label', point.label ) );
			item.appendChild( create( 'span', 'doughboss-square-dashboard__sales-value', formatMoney( point.gross_sales_minor, payload.currency ) ) );
			var track = create( 'span', 'doughboss-square-dashboard__sales-track' );
			track.setAttribute( 'aria-hidden', 'true' );
			var fill = create( 'span', 'doughboss-square-dashboard__sales-fill' );
			fill.style.width = String( percentage ) + '%';
			track.appendChild( fill );
			item.appendChild( track );
			sales.appendChild( item );
		} );
		salesPanel.appendChild( sales );
		grid.appendChild( salesPanel );
		content.appendChild( grid );

		var ordersPanel = create( 'section', 'doughboss-square-dashboard__panel' );
		ordersPanel.appendChild( create( 'h3', '', 'Demo orders' ) );
		var orders = create( 'ul', 'doughboss-square-dashboard__orders' );
		payload.orders.forEach( function ( order ) {
			var item = create( 'li' );
			item.appendChild( create( 'strong', '', order.id + ' · ' + formatMoney( order.amount_minor, payload.currency ) ) );
			item.appendChild( create( 'span', '', order.location_name + ' · ' + order.customer + ' · ' + order.status ) );
			item.appendChild( create( 'span', '', order.items.map( function ( product ) { return String( product.quantity ) + ' × ' + product.name + ' (' + formatMoney( product.unit_price_minor, payload.currency ) + ')'; } ).join( ', ' ) ) );
			orders.appendChild( item );
		} );
		ordersPanel.appendChild( orders );
		content.appendChild( ordersPanel );
	}

	function setControls( mode, isLoading ) {
		if ( previewButton ) {
			previewButton.disabled = isLoading;
		}
		if ( statusButton ) {
			statusButton.hidden = mode !== 'demo';
			statusButton.disabled = isLoading;
		}
		locationSelect.disabled = isLoading || mode !== 'demo';
	}

	function setLoading( message ) {
		status.textContent = message;
		content.setAttribute( 'aria-busy', 'true' );
		setControls( activeMode, true );
	}

	function setError( message ) {
		clear( content );
		content.removeAttribute( 'aria-busy' );
		status.textContent = message;
		var retry = create( 'button', 'button', 'Retry Square status' );
		retry.type = 'button';
		retry.addEventListener( 'click', function () { load( activeMode, locationSelect.value ); } );
		content.appendChild( retry );
		setControls( activeMode, false );
	}

	function load( mode, location ) {
		activeMode = mode;
		setLoading( mode === 'demo' ? 'Loading isolated demo data…' : 'Loading Square connection status…' );
		var endpoint = config.restUrl.replace( /\/$/, '' ) + '/admin/square-dashboard?mode=' + encodeURIComponent( mode ) + '&location=' + encodeURIComponent( location );
		window.fetch( endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' }
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Square dashboard request failed.' );
			}
			return response.json();
		} ).then( function ( payload ) {
			if ( mode === 'demo' ? ! validateDemo( payload ) || payload.selected_location !== location : ! validateBase( payload ) || payload.source !== 'square' || payload.data_state !== 'not_connected' ) {
				throw new Error( 'Square dashboard response was invalid.' );
			}
			content.removeAttribute( 'aria-busy' );
			if ( mode === 'demo' ) {
				locationSelect.value = payload.selected_location;
				renderDemo( payload );
				status.textContent = 'Showing isolated demo data for ' + payload.selected_location + '.';
			} else {
				renderStatus( payload );
				status.textContent = 'Square connection status loaded.';
			}
			setControls( mode, false );
		} ).catch( function () {
			setError( 'Square dashboard could not be loaded. Demo data has not been substituted.' );
		} );
	}

	if ( previewButton ) {
		previewButton.addEventListener( 'click', function () { load( 'demo', locationSelect.value ); } );
	}
	if ( statusButton ) {
		statusButton.addEventListener( 'click', function () { load( 'status', 'all' ); } );
	}
	locationSelect.addEventListener( 'change', function () {
		if ( activeMode === 'demo' && validLocations.includes( locationSelect.value ) ) {
			load( 'demo', locationSelect.value );
		}
	} );

	load( 'status', 'all' );
}() );
