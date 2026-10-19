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
	var catalogueButton = root.querySelector( '[data-square-catalogue-read]' );
	var sourceButton = root.querySelector( '[data-square-source-read]' );
	var locationsButton = root.querySelector( '[data-square-locations-read]' );
	var prepareButton = root.querySelector( '[data-square-prepare-review]' );
	var catalogueSelect = root.querySelector( '[data-square-catalogue-shop]' );
	var status = root.querySelector( '[data-square-status]' );
	var content = root.querySelector( '[data-square-content]' );
	var activeMode = 'status';
	var requestSerial = 0;
	var catalogueMaxTopLevel = 2048;
	var catalogueMaxOccurrences = 4096;
	var catalogueMaxPages = 5;
	var validLocations = [ 'all', 'revesby', 'bankstown', 'roselands' ];
	var reviewContext = null;
	var draft = null;
	var draftBlocked = false;
	var storageKey = 'doughboss-square-preparation-v1';
	var reviewClass = 'doughboss-square-dashboard__review';
	var capabilityStates = {
		payments_refunds: 'not_attested', gift_card_balance_redemption: 'not_attested',
		gift_card_purchase_delivery: 'not_implemented', gift_card_split_checkout: 'disabled',
		loyalty: 'not_implemented', loyalty_subscription: 'not_attested', catalogue_inventory: 'not_implemented',
		timecards: 'not_implemented', staff_permissions_passcodes_payroll: 'dashboard_only',
		team_sales_tip_activity_reports: 'unsupported', reporting_costing: 'not_implemented'
	};

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

	function exactKeys( value, keys ) {
		return isObject( value ) && Object.keys( value ).length === keys.length && keys.every( function ( key ) {
			return Object.prototype.hasOwnProperty.call( value, key );
		} );
	}

	function validDatePin( value ) {
		if ( typeof value !== 'string' || ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) { return false; }
		var year = Number( value.slice( 0, 4 ) );
		var month = Number( value.slice( 5, 7 ) );
		var day = Number( value.slice( 8, 10 ) );
		var leap = year % 4 === 0 && ( year % 100 !== 0 || year % 400 === 0 );
		var days = [ 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		return year > 0 && month >= 1 && month <= 12 && day >= 1 && day <= days[ month - 1 ];
	}

	function validObservationTimestamp( value, canonicalUtc ) {
		if ( typeof value !== 'string' ) { return false; }
		var match = value.match( /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/ );
		if ( ! match || ( canonicalUtc && match[ 8 ] !== 'Z' ) ) { return false; }
		var year = Number( match[ 1 ] ); var month = Number( match[ 2 ] ); var day = Number( match[ 3 ] );
		var hour = Number( match[ 4 ] ); var minute = Number( match[ 5 ] ); var second = Number( match[ 6 ] );
		var leap = year % 4 === 0 && ( year % 100 !== 0 || year % 400 === 0 ); var days = [ 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		if ( year < 1 || month < 1 || month > 12 || day < 1 || day > days[ month - 1 ] || hour > 23 || minute > 59 || second > 59 ) { return false; }
		if ( match[ 8 ] !== 'Z' && ( Number( match[ 8 ].slice( 1, 3 ) ) > 23 || Number( match[ 8 ].slice( 4, 6 ) ) > 59 ) ) { return false; }
		return true;
	}

	function validObservationId( value ) {
		return typeof value === 'string' && /^[A-Za-z0-9:_-]{1,192}$/.test( value );
	}

	function validConfiguredLocationId( value ) {
		return typeof value === 'string' && /^[A-Za-z0-9_-]{4,64}$/.test( value );
	}

	function validObservationText( value ) {
		if ( typeof value !== 'string' || value.length === 0 || value.length > 200 ) { return false; }
		for ( var index = 0; index < value.length; index++ ) {
			var code = value.charCodeAt( index );
			if ( code >= 0xD800 && code <= 0xDBFF ) { if ( index + 1 >= value.length || value.charCodeAt( index + 1 ) < 0xDC00 || value.charCodeAt( index + 1 ) > 0xDFFF ) { return false; } index++; }
			else if ( code >= 0xDC00 && code <= 0xDFFF ) { return false; }
		}
		try {
			return typeof TextEncoder !== 'function' || new TextEncoder().encode( value ).length <= 1024;
		} catch ( error ) { return value.length <= 200; }
	}

	function validateLocationObservation( payload ) {
		var topKeys = [ 'schema_version', 'source', 'data_state', 'observed_at', 'application_id', 'merchant_id', 'environment', 'api_version', 'credential_scopes', 'credential_expires_at', 'provider_locations', 'website_shops', 'limitations', 'provider_writes_allowed', 'mapping_approved', 'dispatch_allowed' ];
		var locationKeys = [ 'id', 'name', 'merchant_id', 'status', 'currency', 'country', 'capabilities_present', 'capabilities' ];
		var shopKeys = [ 'slug', 'native_location_id', 'configured_location_id', 'presence' ];
		var limitationKeys = [ 'merchant_ownership', 'store_access', 'settlement', 'payment_enablement', 'website_availability' ];
		if ( ! exactKeys( payload, topKeys ) || payload.schema_version !== 1 || payload.source !== 'square' || payload.data_state !== 'location_observation' || ! validObservationTimestamp( payload.observed_at, true ) || ! validObservationId( payload.application_id ) || ! validObservationId( payload.merchant_id ) || ! [ 'test', 'live' ].includes( payload.environment ) || ! validDatePin( payload.api_version ) || ! Array.isArray( payload.credential_scopes ) || payload.credential_scopes.length > 128 || ! payload.credential_scopes.every( function ( scope, index, scopes ) { return typeof scope === 'string' && /^[A-Z][A-Z0-9_]{0,127}$/.test( scope ) && scopes.indexOf( scope ) === index && ( index === 0 || scopes[ index - 1 ] < scope ); } ) || ! payload.credential_scopes.includes( 'MERCHANT_PROFILE_READ' ) || typeof payload.credential_expires_at !== 'string' || ( payload.credential_expires_at !== '' && ! validObservationTimestamp( payload.credential_expires_at, false ) ) || ! Array.isArray( payload.provider_locations ) || payload.provider_locations.length > 128 || payload.provider_writes_allowed !== false || payload.mapping_approved !== false || payload.dispatch_allowed !== false || ! exactKeys( payload.limitations, limitationKeys ) ) { return false; }
		if ( ! limitationKeys.every( function ( key ) { return payload.limitations[ key ] === ( key === 'merchant_ownership' ? 'unapproved' : 'unverified' ); } ) ) { return false; }
		var providerIds = [];
		if ( ! payload.provider_locations.every( function ( location ) {
			if ( ! exactKeys( location, locationKeys ) || ! validObservationId( location.id ) || providerIds.includes( location.id ) || ( location.name !== null && ! validObservationText( location.name ) ) || location.merchant_id !== payload.merchant_id || ! [ 'ACTIVE', 'INACTIVE' ].includes( location.status ) || typeof location.currency !== 'string' || ! /^[A-Z]{3}$/.test( location.currency ) || ( location.country !== null && ( typeof location.country !== 'string' || ! /^[A-Z]{2}$/.test( location.country ) ) ) || typeof location.capabilities_present !== 'boolean' || ! Array.isArray( location.capabilities ) || location.capabilities.length > 32 || ! location.capabilities.every( function ( capability, index, capabilities ) { return typeof capability === 'string' && /^[A-Z][A-Z0-9_]{0,63}$/.test( capability ) && capabilities.indexOf( capability ) === index && ( index === 0 || capabilities[ index - 1 ] < capability ); } ) ) { return false; }
			providerIds.push( location.id );
			return location.capabilities_present || location.capabilities.length === 0;
		} ) ) { return false; }
		if ( ! Array.isArray( payload.website_shops ) || payload.website_shops.length !== 3 ) { return false; }
		var expectedSlugs = [ 'revesby', 'bankstown', 'roselands' ];
		return payload.website_shops.every( function ( shop, index ) {
			if ( ! exactKeys( shop, shopKeys ) || shop.slug !== expectedSlugs[ index ] || ! isSafeInteger( shop.native_location_id ) || shop.native_location_id <= 0 || ( shop.configured_location_id !== null && ! validConfiguredLocationId( shop.configured_location_id ) ) || ! [ 'unmapped', 'configured_id_observed', 'configured_id_not_observed' ].includes( shop.presence ) ) { return false; }
			var observed = shop.configured_location_id !== null && providerIds.includes( shop.configured_location_id );
			if ( shop.native_location_id !== null && payload.website_shops.some( function ( other, otherIndex ) { return otherIndex !== index && other.native_location_id === shop.native_location_id; } ) || shop.configured_location_id !== null && payload.website_shops.some( function ( other, otherIndex ) { return otherIndex !== index && other.configured_location_id === shop.configured_location_id; } ) ) { return false; }
			return shop.presence === ( shop.configured_location_id === null ? 'unmapped' : ( observed ? 'configured_id_observed' : 'configured_id_not_observed' ) );
		} );
	}

	function renderLocationObservation( payload ) {
		var panel = create( 'section', 'doughboss-square-dashboard__panel' );
		panel.appendChild( create( 'h3', '', 'Square location observation' ) );
		panel.appendChild( create( 'p', '', 'Observed ' + payload.observed_at + ' (' + payload.environment + '). API pin: ' + payload.api_version + '. Application: ' + payload.application_id + '. Merchant: ' + payload.merchant_id + '.' ) );
		panel.appendChild( create( 'p', '', 'Credential scopes: ' + payload.credential_scopes.join( ', ' ) + '. Expiry: ' + ( payload.credential_expires_at || 'never expires' ) + '.' ) );
		panel.appendChild( create( 'p', '', 'Merchant ownership: ' + payload.limitations.merchant_ownership + '. Store staff access: ' + payload.limitations.store_access + '. Settlement: ' + payload.limitations.settlement + '. Payment enablement: ' + payload.limitations.payment_enablement + '. Website availability: ' + payload.limitations.website_availability + '. None of these observations are approval or operational acceptance.' ) );
		var shops = create( 'ul', 'doughboss-square-dashboard__list' );
		payload.website_shops.forEach( function ( shop ) {
			var observed = payload.provider_locations.find( function ( location ) { return location.id === shop.configured_location_id; } );
			var detail = shop.slug + ': ' + shop.presence + ' (native ID ' + shop.native_location_id + ( shop.configured_location_id ? ', configured ID ' + shop.configured_location_id : '' ) + ')';
			if ( observed ) { detail += '; provider ' + observed.status + ', ' + ( observed.capabilities_present ? 'capabilities ' + ( observed.capabilities.join( ', ' ) || 'none listed' ) : 'capabilities unobserved' ); }
			shops.appendChild( create( 'li', '', detail ) );
		} );
		panel.appendChild( shops );
		var providers = create( 'ul', 'doughboss-square-dashboard__list' );
		payload.provider_locations.forEach( function ( location ) { providers.appendChild( create( 'li', '', 'Provider ' + location.id + ': ' + ( location.name === null ? 'name unobserved' : location.name ) + ' · ' + location.status + ' · ' + location.currency + ' · country ' + ( location.country || 'unobserved' ) + ' · ' + ( location.capabilities_present ? 'capabilities ' + ( location.capabilities.join( ', ' ) || 'none listed' ) : 'capabilities unobserved' ) ) ); } );
		panel.appendChild( create( 'h4', '', 'All provider locations observed' ) ); panel.appendChild( providers );
		panel.appendChild( create( 'p', 'description', 'Provider capabilities are provider-reported only; they do not approve payment or operational readiness. No name matching or synthetic fallback was used.' ) );
		clear( content ); content.appendChild( panel );
	}

	function validateReadiness( readiness ) {
		if ( ! exactKeys( readiness, [ 'schema_version', 'state', 'merchant_verification', 'location_verification', 'permissions', 'account_eligibility', 'api', 'sync_state', 'last_successful_sync', 'capabilities' ] ) || readiness.schema_version !== 1 || readiness.state !== 'not_attested' || readiness.merchant_verification !== 'not_attested' || readiness.location_verification !== 'not_attested' || readiness.permissions !== 'not_attested' || readiness.account_eligibility !== 'not_attested' || readiness.sync_state !== 'not_recorded' || readiness.last_successful_sync !== null ) {
			return false;
		}
		var api = readiness.api;
		if ( ! exactKeys( api, [ 'configured_pin', 'timecards_minimum', 'timecards_version_state' ] ) || typeof api.configured_pin !== 'string' || ! /^\d{4}-\d{2}-\d{2}$/.test( api.configured_pin ) || api.timecards_minimum !== '2025-05-21' ) { return false; }
		var versionState = ! validDatePin( api.configured_pin ) ? 'invalid_pin' : ( api.configured_pin < api.timecards_minimum ? 'below_minimum' : 'meets_minimum' );
		return api.timecards_version_state === versionState && exactKeys( readiness.capabilities, Object.keys( capabilityStates ) ) && Object.keys( capabilityStates ).every( function ( key ) {
			return readiness.capabilities[ key ] === capabilityStates[ key ];
		} );
	}

	function validateBase( payload ) {
		if ( ! isObject( payload ) || payload.schema_version !== 1 || ! isObject( payload.connection ) || ! isObject( payload.staff ) || ! isObject( payload.planned_location ) || ! Array.isArray( payload.locations ) ) {
			return false;
		}

		if ( ! [ 'square', 'synthetic' ].includes( payload.source ) || ! [ 'not_connected', 'demo' ].includes( payload.data_state ) || ! [ 'not_configured', 'configured_unverified' ].includes( payload.connection.state ) || ! [ 'test', 'live' ].includes( payload.connection.environment ) || typeof payload.connection.credentials_present !== 'boolean' || payload.connection.provider_verified !== false || payload.staff.managed_by !== 'square' || payload.staff.attendance_available !== false || payload.planned_location.label !== 'Planned location' || payload.planned_location.state !== 'private_unmapped' ) {
			return false;
		}

		return validateReadiness( payload.readiness ) && payload.locations.every( function ( location ) {
			return isObject( location ) && isNonEmptyString( location.id ) && isNonEmptyString( location.name ) && [ 'unmapped', 'configured_unverified' ].includes( location.state );
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

	function buildReadinessCard( readiness ) {
		var card = create( 'section', 'doughboss-square-dashboard__panel' );
		card.appendChild( create( 'h3', '', 'Setup readiness — not provider-attested' ) );
		card.appendChild( create( 'p', 'description', 'Saved credentials are configuration only. Merchant identity, location ownership, API permissions and Australian/account eligibility have not been verified.' ) );
		var versionNote = readiness.api.timecards_version_state === 'below_minimum' ? 'Below the Timecards minimum.' : ( readiness.api.timecards_version_state === 'invalid_pin' ? 'Invalid configured date pin.' : 'Version floor met only; Timecards integration and permissions are still unverified.' );
		card.appendChild( create( 'p', 'description', 'Configured Square API pin: ' + readiness.api.configured_pin + '. Timecards minimum: ' + readiness.api.timecards_minimum + '. ' + versionNote + ' No API pin has been changed.' ) );
		card.appendChild( create( 'p', 'description', 'Last successful Square sync: not recorded. No live totals or zero-value stand-ins are available.' ) );
		var labels = {
			payments_refunds: 'Payments/refunds — implemented locally; provider acceptance not attested.',
			gift_card_balance_redemption: 'Gift-card balance/redemption — implemented locally; provider acceptance not attested.',
			gift_card_purchase_delivery: 'Gift-card purchase/delivery — not implemented here.',
			gift_card_split_checkout: 'Gift-card-plus-card split checkout — disabled; no activation from this dashboard.',
			loyalty: 'Square Loyalty integration — not implemented. Existing DoughBoss rewards are a separate local system.',
			loyalty_subscription: 'Square Loyalty subscription — active subscription not verified.',
			catalogue_inventory: 'Square catalogue/inventory sync — not implemented here; recipe/ingredient costing is not inferred from stock.',
			timecards: 'Square Timecards — not implemented here. The existing local clock is separate, not evidence of a Square sync.',
			staff_permissions_passcodes_payroll: 'Staff permissions/passcodes and payroll enrolment — Square Dashboard-only; Australian/account availability not attested.',
			team_sales_tip_activity_reports: 'Direct Team API sales/tip/activity report generation — unsupported; provider Dashboard or separately validated derived reporting is required.',
			reporting_costing: 'Square reporting/costing reconciliation — not implemented here. Local reports and demo totals are not provider records.'
		};
		var list = create( 'ul', 'doughboss-square-dashboard__list' );
		Object.keys( labels ).forEach( function ( key ) { list.appendChild( create( 'li', '', labels[ key ] ) ); } );
		card.appendChild( list );
		return card;
	}

	function buildOperationalCards( payload ) {
		var grid = create( 'div', 'doughboss-square-dashboard__grid' );
		var connection = create( 'section', 'doughboss-square-dashboard__panel' );
		connection.appendChild( create( 'h3', '', 'Connection status' ) );
		connection.appendChild( create( 'p', 'doughboss-square-dashboard__state', payload.connection.state === 'not_configured' ? 'Not configured' : 'Configured, not yet verified' ) );
		connection.appendChild( create( 'p', 'description', payload.connection.credentials_present ? 'Credentials are present. Square has not been verified from this dashboard.' : 'Square credentials are missing or incomplete.' ) );
		connection.appendChild( create( 'p', 'description', 'Environment: ' + payload.connection.environment + '. Connected Square figures will be available after verification. Demo figures are synthetic.' ) );
		grid.appendChild( connection );
		grid.appendChild( buildReadinessCard( payload.readiness ) );

		var mappings = create( 'section', 'doughboss-square-dashboard__panel' );
		mappings.appendChild( create( 'h3', '', 'Shop mapping preparation' ) );
		var shopList = create( 'ul', 'doughboss-square-dashboard__list' );
		payload.locations.forEach( function ( location ) {
			shopList.appendChild( create( 'li', '', location.name + ': ' + ( location.state === 'configured_unverified' ? 'Mapped, not verified' : 'Unmapped' ) ) );
		} );
		mappings.appendChild( shopList );
		mappings.appendChild( create( 'p', 'description', 'Saved mappings do not connect Square or authorize payment. Checkout routing and the approval-to-kitchen lifecycle still require acceptance.' ) );
		grid.appendChild( mappings );

		var staff = create( 'section', 'doughboss-square-dashboard__panel' );
		staff.appendChild( create( 'h3', '', 'Team and attendance' ) );
		staff.appendChild( create( 'p', 'doughboss-square-dashboard__state', 'Square attendance integration not implemented' ) );
		staff.appendChild( create( 'p', 'description', 'Square is the intended staff/timecard authority. No clock controls or attested Square attendance records are provided by this dashboard.' ) );
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

	function validateCatalogue( payload, shop ) {
		var types = [ 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER', 'IMAGE', 'TAX', 'CATEGORY' ];
		if ( ! isObject( payload ) || payload.schema_version !== 1 || payload.source !== 'square' || payload.data_state !== 'catalogue_observed'
			|| ! [ 'test', 'live' ].includes( payload.environment ) || typeof payload.observed_at !== 'string' || ! /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test( payload.observed_at )
			|| ! isObject( payload.shop ) || payload.shop.slug !== shop || ! isSafeInteger( payload.shop.id ) || payload.shop.id < 1
			|| ! isObject( payload.location ) || ! isNonEmptyString( payload.location.id ) || ! [ 'ACTIVE', 'INACTIVE' ].includes( payload.location.status ) || ! /^[A-Z]{3}$/.test( payload.location.currency )
			|| ! isNonEmptyString( payload.location.merchant_id ) || typeof payload.location.capabilities_present !== 'boolean' || ! Array.isArray( payload.location.capabilities ) || ! payload.location.capabilities.every( isNonEmptyString )
			|| ! exactKeys( payload.reconciliation, [ 'merchant_identity', 'catalogue', 'pricing', 'inventory' ] ) || payload.reconciliation.merchant_identity !== 'observed_owner_reconciliation_required' || payload.reconciliation.catalogue !== 'current_listing_only' || payload.reconciliation.pricing !== 'base_price_and_raw_overrides_only' || payload.reconciliation.inventory !== 'not_observed'
			|| ! isObject( payload.catalogue ) || ! isSafeInteger( payload.catalogue.pages ) || payload.catalogue.pages < 1 || payload.catalogue.pages > catalogueMaxPages || ! Array.isArray( payload.catalogue.objects ) || payload.catalogue.objects.length > catalogueMaxTopLevel
			|| payload.catalogue.object_count !== payload.catalogue.objects.length || ! Array.isArray( payload.catalogue.types ) || payload.catalogue.types.join( ',' ) !== types.join( ',' ) || ! exactKeys( payload.catalogue.counts, types ) ) { return false; }
		var projectedCount = 0;
		var identities = Object.create( null );
		function validMoney( money ) { return isObject( money ) && isSafeInteger( money.amount ) && money.amount >= 0 && money.currency === payload.location.currency; }
		function validObject( object, nested ) {
			if ( ++projectedCount > catalogueMaxOccurrences || ! isObject( object ) || ! isNonEmptyString( object.id ) || ! types.includes( object.type ) || ( object.version !== null && ( ! isSafeInteger( object.version ) || object.version < 0 ) )
				|| ( object.updated_at !== null && typeof object.updated_at !== 'string' ) || ( object.is_deleted !== null && typeof object.is_deleted !== 'boolean' ) || ! isObject( object.presence ) || ! isObject( object.data ) || ! Array.isArray( object.embedded_objects ) || ( nested && object.embedded_objects.length > 0 ) ) { return false; }
			if ( Object.prototype.hasOwnProperty.call( object.data, 'name' ) && typeof object.data.name !== 'string' ) { return false; }
			if ( Object.prototype.hasOwnProperty.call( object.data, 'sku' ) && ( object.type !== 'ITEM_VARIATION' || ! validSku( object.data.sku ) ) ) { return false; }
			if ( Object.prototype.hasOwnProperty.call( object.data, 'selection_type' ) && ( object.type !== 'MODIFIER_LIST' || ! [ 'SINGLE', 'MULTIPLE' ].includes( object.data.selection_type ) ) ) { return false; }
			if ( Object.prototype.hasOwnProperty.call( object.data, 'ordinal' ) && ( object.type !== 'MODIFIER' || ! isSafeInteger( object.data.ordinal ) || object.data.ordinal < -2147483648 || object.data.ordinal > 2147483647 ) ) { return false; }
			if ( object.type === 'TAX' ) {
				if ( Object.prototype.hasOwnProperty.call( object.data, 'percentage' ) && ( typeof object.data.percentage !== 'string' || object.data.percentage.length < 1 || object.data.percentage.length > 32 || ! /^[0-9]+(?:\.[0-9]+)?$/.test( object.data.percentage ) || /[^0-9.]/.test( object.data.percentage ) ) ) { return false; }
				if ( Object.prototype.hasOwnProperty.call( object.data, 'calculation_phase' ) && ! [ 'TAX_SUBTOTAL_PHASE', 'TAX_TOTAL_PHASE' ].includes( object.data.calculation_phase ) ) { return false; }
				if ( Object.prototype.hasOwnProperty.call( object.data, 'inclusion_type' ) && ! [ 'ADDITIVE', 'INCLUSIVE' ].includes( object.data.inclusion_type ) ) { return false; }
				if ( ! [ 'enabled', 'applies_to_custom_amounts' ].every( function ( fact ) { return ! Object.prototype.hasOwnProperty.call( object.data, fact ) || typeof object.data[ fact ] === 'boolean'; } ) ) { return false; }
				if ( Object.prototype.hasOwnProperty.call( object.data, 'applies_to_product_set_id' ) && object.data.applies_to_product_set_id !== null && ( typeof object.data.applies_to_product_set_id !== 'string' || object.data.applies_to_product_set_id.length < 1 || object.data.applies_to_product_set_id.length > 192 || /[^A-Za-z0-9:_-]/.test( object.data.applies_to_product_set_id ) ) ) { return false; }
			}
			if ( Object.prototype.hasOwnProperty.call( object.data, 'price_money' ) && ! validMoney( object.data.price_money ) ) { return false; }
			if ( Object.prototype.hasOwnProperty.call( object.data, 'location_overrides' ) && ( ! Array.isArray( object.data.location_overrides ) || ! object.data.location_overrides.every( function ( override ) { return isObject( override ) && isNonEmptyString( override.location_id ) && ( ! Object.prototype.hasOwnProperty.call( override, 'price_money' ) || validMoney( override.price_money ) ); } ) ) ) { return false; }
			var facts = JSON.stringify( object );
			if ( Object.prototype.hasOwnProperty.call( identities, object.id ) && identities[ object.id ] !== facts ) { return false; }
			identities[ object.id ] = facts;
			return object.embedded_objects.every( function ( child ) { return validObject( child, true ); } );
		}
		var counts = {};
		types.forEach( function ( type ) { counts[ type ] = 0; } );
		var ids = Object.create( null );
		return payload.catalogue.objects.every( function ( object ) {
			if ( ! validObject( object, false ) || ids[ object.id ] ) { return false; }
			ids[ object.id ] = true; ++counts[ object.type ]; return true;
		} ) && types.every( function ( type ) { return isSafeInteger( payload.catalogue.counts[ type ] ) && counts[ type ] === payload.catalogue.counts[ type ]; } );
	}

	function renderCatalogue( payload ) {
		clear( content );
		var panel = create( 'section', 'doughboss-square-dashboard__panel' );
		panel.appendChild( create( 'h3', '', 'Current Square catalogue observation — ' + payload.shop.slug ) );
		panel.appendChild( create( 'p', '', 'Observed at ' + payload.observed_at + ' (' + payload.environment + '). Exact location: ' + payload.location.id + ' · ' + payload.location.status + ' · ' + payload.location.currency + '.' ) );
		panel.appendChild( create( 'p', '', 'Observed merchant identity: ' + payload.location.merchant_id + '. Awaiting owner reconciliation; this does not verify the correct business mapping.' ) );
		panel.appendChild( create( 'p', '', 'Read capability evidence: ' + ( payload.location.capabilities_present ? payload.location.capabilities.join( ', ' ) || 'None listed' : 'Not supplied' ) + '. No broader account eligibility is attested.' ) );
		panel.appendChild( create( 'p', '', String( payload.catalogue.counts.ITEM ) + ' items; ' + String( payload.catalogue.object_count ) + ' top-level objects across ' + String( payload.catalogue.pages ) + ' complete listing page(s).' ) );
		panel.appendChild( create( 'p', 'description', 'Current merchant-wide listing only, observed using this shop mapping. Deleted objects are not covered. Location presence and overrides remain unresolved; shown prices are base-price evidence, not computed shop prices or stock. Inventory was not read. No import or sync was performed.' ) );
		var list = create( 'ul', 'doughboss-square-dashboard__list' );
		payload.catalogue.objects.filter( function ( object ) { return object.type === 'ITEM'; } ).forEach( function ( item ) {
			var row = create( 'li', '', ( item.data.name || item.id ) + ' [' + item.id + ']' );
			var variations = item.embedded_objects.filter( function ( child ) { return child.type === 'ITEM_VARIATION'; } );
			if ( ! variations.length ) { variations = payload.catalogue.objects.filter( function ( object ) { return object.type === 'ITEM_VARIATION' && object.data.item_id === item.id; } ); }
			if ( ! variations.length ) { row.appendChild( create( 'p', '', 'No variation base price observed; not a zero-price item.' ) ); }
			variations.forEach( function ( variation ) {
				row.appendChild( create( 'p', '', ( variation.data.name || variation.id ) + ': ' + ( variation.data.price_money ? formatMoney( variation.data.price_money.amount, variation.data.price_money.currency ) + ' base-price evidence' : 'No fixed base price observed' ) ) );
				if ( variation.data.location_overrides ) { row.appendChild( create( 'p', '', 'Unresolved variation location overrides: ' + JSON.stringify( variation.data.location_overrides ) ) ); }
			} );
			row.appendChild( create( 'p', '', 'Raw presence evidence (unresolved): ' + JSON.stringify( item.presence ) ) );
			row.appendChild( create( 'p', '', 'Retained references/override evidence: ' + JSON.stringify( item.data ) ) );
			list.appendChild( row );
		} );
		panel.appendChild( list );
		if ( payload.source_comparison ) { renderSourceComparison( payload.source_comparison ); }
		var taxes = payload.catalogue.objects.filter( function ( object ) { return object.type === 'TAX'; } );
		if ( taxes.length ) {
			panel.appendChild( create( 'h4', '', 'Observed tax facts — not calculated or reconciled' ) );
			panel.appendChild( create( 'p', 'description', 'Only explicitly supplied catalogue facts are shown. Missing facts are unobserved; no defaults or effective tax applicability are inferred. Product-set applicability is retained as a beta reference, not resolved. An explicit null means no product-set ID was declared; it does not establish unrestricted tax applicability.' ) );
			var taxList = create( 'ul', 'doughboss-square-dashboard__list' );
			taxes.forEach( function ( tax ) {
				var taxRow = create( 'li', '', ( tax.data.name || tax.id ) + ' [' + tax.id + ']' );
				[ 'percentage', 'calculation_phase', 'inclusion_type', 'enabled', 'applies_to_custom_amounts', 'applies_to_product_set_id' ].forEach( function ( fact ) {
					taxRow.appendChild( create( 'p', '', fact + ': ' + ( Object.prototype.hasOwnProperty.call( tax.data, fact ) ? String( tax.data[ fact ] ) : 'Unobserved' ) ) );
				} );
				taxList.appendChild( taxRow );
			} );
			panel.appendChild( taxList );
		}
		content.appendChild( panel );
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

	function validateSource( payload ) {
		if ( ! isObject( payload ) || payload.source !== 'wordpress_menu' || payload.algorithm !== 'doughboss-menu-utf8-v1'
			|| payload.global_availability_only !== true || payload.provider_writes_allowed !== false
			|| typeof payload.source_fingerprint !== 'string' || ! /^[a-f0-9]{64}$/.test( payload.source_fingerprint )
			|| ! Array.isArray( payload.items ) || payload.items.length < 1 || payload.items.length >= 200 ) { return false; }
		var ids = Object.create( null );
		function text( value, max, required ) { return typeof value === 'string' && value.length <= max && ( ! required || value.trim().length > 0 ); }
		function cents( value ) { return isSafeInteger( value ) && value >= 0; }
		if ( ! payload.items.every( function ( item ) {
			if ( ! isObject( item ) || ! isSafeInteger( item.website_item_id ) || item.website_item_id < 1 || ids[ item.website_item_id ]
				|| ! text( item.name, 200, true ) || ! text( item.description, 5000, false ) || ! text( item.category, 200, true ) || ! text( item.type, 200, true )
				|| ! cents( item.base_price_minor ) || typeof item.image_url !== 'string' || typeof item.public_availability !== 'boolean' || item.global_availability_only !== true
				|| ! Array.isArray( item.dietary ) || ! item.dietary.every( function ( flag ) { return text( flag, 200, true ); } )
				|| ! Array.isArray( item.modifier_groups ) ) { return false; }
			ids[ item.website_item_id ] = true;
			var groups = Object.create( null );
			return item.modifier_groups.every( function ( group ) {
				if ( ! isObject( group ) || ! text( group.source_id, 200, true ) || ! /^[a-z][a-z0-9_]*$/.test( group.source_id )
					|| group.id !== 'item_' + item.website_item_id + '_option_' + group.source_id || groups[ group.id ] || ! text( group.label, 200, true )
					|| ! [ 'radio', 'check' ].includes( group.type ) || ! Array.isArray( group.choices ) || ! group.choices.length ) { return false; }
				groups[ group.id ] = true;
				var choices = Object.create( null );
				var defaults = 0;
				return group.choices.every( function ( choice ) {
					if ( ! isObject( choice ) || ! text( choice.slug, 200, true ) || ! /^[a-z][a-z0-9_-]*$/.test( choice.slug ) || choice.id !== group.id + '_choice_' + choice.slug || choices[ choice.id ]
						|| ! text( choice.label, 200, true ) || ! cents( choice.price_delta_minor ) || typeof choice.default !== 'boolean' ) { return false; }
					choices[ choice.id ] = true;
					if ( choice.default ) { ++defaults; }
					return true;
				} ) && ( group.type !== 'radio' || defaults === 1 );
			} );
		} ) ) { return false; }
		return true;
	}

	function sortedUnique( values, validator ) {
		if ( ! Array.isArray( values ) || ! values.every( validator ) ) { return false; }
		for ( var index = 1; index < values.length; ++index ) {
			if ( values[ index - 1 ] >= values[ index ] ) { return false; }
		}
		return true;
	}

	function reasonCodes( values ) { return sortedUnique( values, function ( value ) { return typeof value === 'string' && /^[a-z][a-z0-9_]*$/.test( value ) && value.length <= 191; } ); }
	function nonnegative( value ) { return isSafeInteger( value ) && value >= 0; }
	function facet( value, states ) { return isObject( value ) && exactKeys( value, states ) && states.every( function ( key ) { return nonnegative( value[ key ] ); } ); }

	function validateSourceComparison( comparison ) {
		var limitationKeys = [ 'source_display_order', 'tax', 'shop_effective_pricing', 'inventory', 'channels', 'merchant_ownership' ];
		var summaryKeys = [ 'source_items', 'lineage', 'name', 'base_price', 'modifiers', 'source_modifier_groups', 'linked_modifier_sets' ];
		if ( ! isObject( comparison ) || ! exactKeys( comparison, [ 'schema_version', 'state', 'lineage_policy', 'source_algorithm', 'source_fingerprint', 'provider_writes_allowed', 'mapping_approved', 'dispatch_allowed', 'limitations', 'summary', 'rows' ] )
			|| comparison.schema_version !== 1 || comparison.state !== 'candidate_comparison' || comparison.lineage_policy !== 'unapproved_exact_sku' || comparison.source_algorithm !== 'doughboss-menu-utf8-v1' || ! hash( comparison.source_fingerprint )
			|| comparison.provider_writes_allowed !== false || comparison.mapping_approved !== false || comparison.dispatch_allowed !== false || ! exactKeys( comparison.limitations, limitationKeys )
			|| comparison.limitations.source_display_order !== 'unobserved' || comparison.limitations.tax !== 'unobserved' || comparison.limitations.shop_effective_pricing !== 'unobserved' || comparison.limitations.inventory !== 'unobserved' || comparison.limitations.channels !== 'unobserved' || comparison.limitations.merchant_ownership !== 'unapproved'
			|| ! exactKeys( comparison.summary, summaryKeys ) || ! Array.isArray( comparison.rows ) ) { return false; }
		var summary = comparison.summary;
		if ( ! nonnegative( summary.source_items ) || summary.source_items < 1 || summary.source_items > 199
			|| ! facet( summary.lineage, [ 'candidate', 'conflict', 'unobserved' ] ) || ! facet( summary.name, [ 'matches', 'conflict', 'unobserved' ] ) || ! facet( summary.base_price, [ 'matches', 'conflict', 'unobserved' ] ) || ! facet( summary.modifiers, [ 'matches', 'conflict', 'unobserved' ] ) || ! nonnegative( summary.source_modifier_groups ) || ! nonnegative( summary.linked_modifier_sets )
			|| comparison.rows.length !== summary.source_items ) { return false; }
		var ids = Object.create( null ); var linked = Object.create( null ); var tallies = { lineage: { candidate: 0, conflict: 0, unobserved: 0 }, name: { matches: 0, conflict: 0, unobserved: 0 }, base_price: { matches: 0, conflict: 0, unobserved: 0 }, modifiers: { matches: 0, conflict: 0, unobserved: 0 } }; var groups = 0;
		var validRow = function ( row, index ) {
			if ( ! isObject( row ) || ! exactKeys( row, [ 'website_item_id', 'source_name', 'expected_sku', 'lineage', 'name', 'base_price', 'modifiers' ] ) || ! positive( row.website_item_id ) || ids[ row.website_item_id ] || ( index > 0 && comparison.rows[ index - 1 ].website_item_id >= row.website_item_id )
				|| ! comparisonSourceName( row.source_name ) || row.expected_sku !== 'DB-WP-' + String( row.website_item_id ) ) { return false; }
			ids[ row.website_item_id ] = true;
			var lineage = row.lineage; var name = row.name; var price = row.base_price; var modifiers = row.modifiers;
			if ( ! isObject( lineage ) || ! exactKeys( lineage, [ 'state', 'candidate_variation_ids', 'item_id', 'reasons' ] ) || ! [ 'candidate', 'conflict', 'unobserved' ].includes( lineage.state ) || ! sortedUnique( lineage.candidate_variation_ids, comparisonId ) || ( lineage.item_id !== null && ! comparisonId( lineage.item_id ) ) || ! reasonCodes( lineage.reasons ) ) { return false; }
			if ( lineage.state === 'candidate' && ( lineage.candidate_variation_ids.length !== 1 || lineage.item_id === null ) ) { return false; }
			if ( ! isObject( name ) || ! exactKeys( name, [ 'state', 'source', 'observed', 'reasons' ] ) || ! [ 'matches', 'conflict', 'unobserved' ].includes( name.state ) || name.source !== row.source_name || ! comparisonObservedName( name.observed ) || ! reasonCodes( name.reasons ) ) { return false; }
			if ( ! isObject( price ) || ! exactKeys( price, [ 'state', 'source_minor', 'observed_minor', 'observed_currency', 'reasons' ] ) || ! [ 'matches', 'conflict', 'unobserved' ].includes( price.state ) || ! nonnegative( price.source_minor ) || ( price.observed_minor !== null && ! nonnegative( price.observed_minor ) ) || ( price.observed_currency !== null && ( typeof price.observed_currency !== 'string' || ! /^[A-Z]{3}$/.test( price.observed_currency ) ) ) || ! reasonCodes( price.reasons ) ) { return false; }
			if ( ! isObject( modifiers ) || ! exactKeys( modifiers, [ 'state', 'source_group_count', 'linked_set_ids', 'compared_group_count', 'missing_facts', 'reasons' ] ) || ! [ 'matches', 'conflict', 'unobserved' ].includes( modifiers.state ) || ! nonnegative( modifiers.source_group_count ) || ! sortedUnique( modifiers.linked_set_ids, comparisonId ) || ! nonnegative( modifiers.compared_group_count ) || modifiers.compared_group_count > modifiers.source_group_count || ! reasonCodes( modifiers.missing_facts ) || ! reasonCodes( modifiers.reasons ) ) { return false; }
			if ( lineage.state !== 'candidate' && ( name.state === 'matches' || price.state === 'matches' || modifiers.state === 'matches' ) ) { return false; }
			if ( name.state === 'matches' && name.observed !== name.source ) { return false; }
			if ( price.state === 'matches' && ( price.observed_minor !== price.source_minor || price.observed_currency !== 'AUD' ) ) { return false; }
			if ( modifiers.state === 'matches' && ( modifiers.compared_group_count !== modifiers.source_group_count || modifiers.missing_facts.length || modifiers.reasons.length ) ) { return false; }
			tallies.lineage[ lineage.state ] += 1; tallies.name[ name.state ] += 1; tallies.base_price[ price.state ] += 1; tallies.modifiers[ modifiers.state ] += 1; groups += modifiers.source_group_count;
			if ( lineage.state === 'candidate' ) { modifiers.linked_set_ids.forEach( function ( id ) { linked[ id ] = true; } ); }
			return true;
		};
		if ( ! comparison.rows.every( validRow ) || summary.source_modifier_groups !== groups || summary.linked_modifier_sets !== Object.keys( linked ).length ) { return false; }
		return [ 'lineage', 'name', 'base_price', 'modifiers' ].every( function ( key ) { return Object.keys( tallies[ key ] ).every( function ( state ) { return summary[ key ][ state ] === tallies[ key ][ state]; } ); } );
	}

	function renderSource( payload ) {
		clear( content );
		content.appendChild( create( 'h3', '', 'Website menu source — not imported' ) );
		content.appendChild( create( 'p', 'description', payload.items.length + ' website items. Global website availability is not shop-specific availability. Revesby, Bankstown and Roselands each require explicit review. Photo references and dietary labels are source records, not independently verified claims.' ) );
		content.appendChild( create( 'p', 'description', 'Source fingerprint (server reported): ' + payload.source_fingerprint ) );
		function amount( minor ) { return '$' + Math.floor( minor / 100 ) + '.' + String( minor % 100 ).padStart( 2, '0' ) + ' AUD'; }
		payload.items.forEach( function ( item ) {
			var card = create( 'details', 'doughboss-square-dashboard__card' );
			card.appendChild( create( 'summary', '', item.name + ' — ' + amount( item.base_price_minor ) + ' · ' + item.category ) );
			card.appendChild( create( 'p', '', 'Website item #' + item.website_item_id + ' · ' + item.type + ' · Website availability: ' + ( item.public_availability ? 'available' : 'unavailable' ) ) );
			card.appendChild( create( 'p', '', item.description ) );
			card.appendChild( create( 'p', '', 'Photo: ' + ( item.image_url ? 'reference present; visual verification required' : 'no reference' ) + '. Dietary labels: ' + ( item.dietary.join( ', ' ) || 'none recorded' ) + '.' ) );
			item.modifier_groups.forEach( function ( group ) {
				card.appendChild( create( 'h4', '', group.label + ' (' + group.type + ')' ) );
				var list = create( 'ul', 'doughboss-square-dashboard__list' );
				group.choices.forEach( function ( choice ) { list.appendChild( create( 'li', '', choice.label + ' · +' + amount( choice.price_delta_minor ) + ( choice.default ? ' · Default' : '' ) ) ); } );
				card.appendChild( list );
			} );
			if ( ! item.modifier_groups.length ) { card.appendChild( create( 'p', '', 'No modifier groups recorded.' ) ); }
			content.appendChild( card );
		} );
	}

	function renderSourceComparison( comparison ) {
		var panel = create( 'section', 'doughboss-square-dashboard__panel' );
		panel.appendChild( create( 'h3', '', 'Candidate comparison — unapproved' ) );
		panel.appendChild( create( 'p', 'description', 'Read-only exact-SKU candidates. No mapping approval, provider write, dispatch, sync, or readiness claim is available.' ) );
		panel.appendChild( create( 'p', '', 'Items: ' + comparison.summary.source_items + ' · lineage candidates/conflicts/unobserved: ' + comparison.summary.lineage.candidate + '/' + comparison.summary.lineage.conflict + '/' + comparison.summary.lineage.unobserved + ' · name matches: ' + comparison.summary.name.matches + ' · base-price matches: ' + comparison.summary.base_price.matches + ' · modifier matches: ' + comparison.summary.modifiers.matches ) );
		panel.appendChild( create( 'p', 'description', 'Unobserved limitations: source display order, tax, shop-effective pricing, inventory, channels, and merchant ownership.' ) );
		var table = create( 'table', 'doughboss-square-dashboard__table' ); var head = create( 'thead' ); var header = create( 'tr' );
		[ 'Source item', 'Candidate IDs', 'Name', 'Base price', 'Options' ].forEach( function ( label ) { header.appendChild( create( 'th', '', label ) ); } ); head.appendChild( header ); table.appendChild( head );
		var body = create( 'tbody' ); comparison.rows.forEach( function ( row ) { var tr = create( 'tr' ); var candidate = row.lineage.candidate_variation_ids.length ? row.lineage.candidate_variation_ids.join( ', ' ) : 'None observed';
			tr.appendChild( create( 'td', '', row.source_name + ' · ' + row.expected_sku ) ); tr.appendChild( create( 'td', '', candidate ) ); tr.appendChild( create( 'td', '', row.name.state ) ); tr.appendChild( create( 'td', '', row.base_price.state ) ); tr.appendChild( create( 'td', '', row.modifiers.state ) ); body.appendChild( tr ); } ); table.appendChild( body ); panel.appendChild( table ); content.appendChild( panel );
	}

	function positive( value ) { return isSafeInteger( value ) && value > 0; }
	function hash( value ) { return typeof value === 'string' && /^[a-f0-9]{64}$/.test( value ); }
	function validUtf8Bytes( value, maximum ) { if ( typeof value !== 'string' ) { return false; } try { return encodeURIComponent( value ).replace( /%[0-9A-F]{2}/gi, 'x' ).length <= maximum; } catch ( error ) { return false; } }
	function validSku( value ) { return validUtf8Bytes( value, 4194304 ); }
	function providerId( value ) { return typeof value === 'string' && /^[A-Za-z0-9_-]{1,191}$/.test( value ); }
	function comparisonId( value ) { return typeof value === 'string' && /^[A-Za-z0-9:_-]{1,192}$/.test( value ); }
	function comparisonSourceName( value ) { return typeof value === 'string' && value.length > 0 && value.length <= 200 && validUtf8Bytes( value, 4194304 ); }
	function comparisonObservedName( value ) { return value === null || validUtf8Bytes( value, 1024 ); }
	function nullableName( value ) {
		return value === null || ( isNonEmptyString( value ) && ! /\p{C}/u.test( value ) && encodeURIComponent( value ).replace( /%[0-9A-F]{2}/g, 'x' ).length <= 200 );
	}
	function safeImageUrl( value ) {
		// Evidence is opened only by an explicit click, never fetched or embedded.
		if ( typeof value !== 'string' || value.length > 2048 || /[^\x21-\x7e]|[\\?#@]|%(?:0[0-9a-f]|1[0-9a-f]|7f|25|2f|5c|3a|40|23|3f)/i.test( value ) ) { return false; }
		var match = /^https:\/\/([a-z0-9.-]+)(?::443)?(\/[^?#]*)?$/i.exec( value );
		if ( ! match || /%(?![0-9a-f]{2})/i.test( value ) ) { return false; }
		var labels = match[1].toLowerCase().split( '.' );
		return labels.length >= 2 && match[1].length <= 253 && /^[a-z]{2,63}$/.test( labels[ labels.length - 1 ] )
			&& ! /(?:^|\.)(?:localhost|local|internal|lan|home|test|invalid|example|onion)$/.test( match[1].toLowerCase() )
			&& labels.every( function ( label ) { return /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test( label ); } );
	}
	function compatibleTax( tax ) {
		return tax.enabled === true && tax.product_set_observed === true && tax.applies_to_product_set_id === null
			&& typeof tax.percentage === 'string' && /^[0-9]{1,3}(?:\.[0-9]{1,4})?$/.test( tax.percentage ) && Number( tax.percentage ) <= 100
			&& [ 'INCLUSIVE', 'ADDITIVE' ].includes( tax.inclusion_type ) && [ 'TAX_SUBTOTAL_PHASE', 'TAX_TOTAL_PHASE' ].includes( tax.calculation_phase )
			&& typeof tax.applies_to_custom_amounts === 'boolean';
	}
	function validateTarget( target, selected, references ) {
		if ( ! exactKeys( target, [ 'merchant_id', 'environment', 'application_id', 'api_version', 'shops', 'mapping_policy', 'existing_references' ] )
			|| ! providerId( target.merchant_id ) || ! providerId( target.application_id ) || ! [ 'test', 'live' ].includes( target.environment )
			|| target.api_version !== '2025-01-23' || target.mapping_policy !== 'create_only' || ! Array.isArray( target.shops ) || target.shops.length < 1 || target.shops.length > 3
			|| ! Array.isArray( target.existing_references ) || target.existing_references.length > 1000 || ( ! references && target.existing_references.length ) ) { return false; }
		var ids = [], locations = [], slugs = [];
		if ( ! target.shops.every( function ( shop ) {
			if ( ! exactKeys( shop, [ 'id', 'slug', 'square_location_id' ] ) || ! positive( shop.id ) || ! validLocations.slice( 1 ).includes( shop.slug ) || ! providerId( shop.square_location_id )
				|| ids.includes( shop.id ) || locations.includes( shop.square_location_id ) || slugs.includes( shop.slug ) ) { return false; }
			ids.push( shop.id ); locations.push( shop.square_location_id ); slugs.push( shop.slug ); return true;
		} ) || slugs.join( ',' ) !== validLocations.slice( 1 ).filter( function ( slug ) { return slugs.includes( slug ); } ).join( ',' ) || ( selected && slugs.join( ',' ) !== selected.join( ',' ) ) ) { return false; }
		var seen = [];
		return target.existing_references.every( function ( ref ) {
			if ( ! exactKeys( ref, [ 'id', 'type', 'version' ] ) || ! providerId( ref.id ) || ! [ 'TAX', 'IMAGE' ].includes( ref.type ) || ! positive( ref.version ) || seen.includes( ref.id ) ) { return false; }
			seen.push( ref.id ); return true;
		} );
	}
	function validateReviewContext( context, selected ) {
		if ( ! exactKeys( context, [ 'schema_version', 'operation_key', 'source', 'target', 'tax_candidates', 'image_candidates', 'dispatch_allowed' ] ) || context.schema_version !== 1 || ! hash( context.operation_key ) || context.dispatch_allowed !== false
			|| ! exactKeys( context.source, [ 'algorithm', 'source_fingerprint', 'items', 'source', 'global_availability_only', 'provider_writes_allowed' ] ) || ! validateSource( context.source )
			|| ! validateTarget( context.target, selected, false ) || ! Array.isArray( context.tax_candidates ) || ! Array.isArray( context.image_candidates ) || context.tax_candidates.length + context.image_candidates.length > 1000 ) { return false; }
		var ids = [];
		function identity( candidate, type ) {
			if ( ! providerId( candidate.id ) || candidate.type !== type || ! positive( candidate.version ) || ! nullableName( candidate.name ) || ids.includes( candidate.id ) ) { return false; }
			ids.push( candidate.id ); return true;
		}
		return context.tax_candidates.every( function ( tax ) {
			return exactKeys( tax, [ 'id', 'type', 'version', 'name', 'percentage', 'calculation_phase', 'inclusion_type', 'enabled', 'applies_to_custom_amounts', 'applies_to_product_set_id', 'product_set_observed', 'policy_compatible' ] ) && identity( tax, 'TAX' )
				&& ( tax.percentage === null || ( typeof tax.percentage === 'string' && tax.percentage.length <= 32 && /^[0-9]+(?:\.[0-9]+)?$/.test( tax.percentage ) ) )
				&& ( tax.calculation_phase === null || [ 'TAX_SUBTOTAL_PHASE', 'TAX_TOTAL_PHASE' ].includes( tax.calculation_phase ) ) && ( tax.inclusion_type === null || [ 'INCLUSIVE', 'ADDITIVE' ].includes( tax.inclusion_type ) )
				&& ( tax.enabled === null || typeof tax.enabled === 'boolean' ) && ( tax.applies_to_custom_amounts === null || typeof tax.applies_to_custom_amounts === 'boolean' )
				&& ( tax.applies_to_product_set_id === null || providerId( tax.applies_to_product_set_id ) ) && typeof tax.product_set_observed === 'boolean'
				&& typeof tax.policy_compatible === 'boolean' && tax.policy_compatible === compatibleTax( tax );
		} ) && context.image_candidates.every( function ( image ) {
			return exactKeys( image, [ 'id', 'type', 'version', 'name', 'url', 'evidence_available', 'evidence_source', 'authenticity_verified', 'licence_verified' ] ) && identity( image, 'IMAGE' )
				&& ( image.url === null || safeImageUrl( image.url ) ) && typeof image.evidence_available === 'boolean'
				&& image.evidence_available === ( isNonEmptyString( image.name ) && safeImageUrl( image.url ) )
				&& image.evidence_source === 'provider_reported' && image.authenticity_verified === false && image.licence_verified === false;
		} );
	}
	function amountCents( minor ) { return '$' + Math.floor( minor / 100 ) + '.' + String( minor % 100 ).padStart( 2, '0' ) + ' AUD (' + minor + ' cents)'; }
	function button( parent, label, action ) {
		var control = create( 'button', 'button', label ); control.type = 'button'; control.addEventListener( 'click', action ); parent.appendChild( control ); return control;
	}
	function field( parent, label, control, key ) {
		var wrapper = create( 'label', reviewClass + '-field', label );
		control.setAttribute( 'data-square-review-control', key ); wrapper.appendChild( control ); parent.appendChild( wrapper ); return control;
	}
	function acknowledgement( parent, label, key ) {
		var input = create( 'input' ); input.type = 'checkbox'; input.checked = false; return field( parent, label, input, key );
	}
	function selectField( parent, label, options, key ) {
		var select = create( 'select' ); select.value = '';
		[ [ '', 'Choose explicitly…' ] ].concat( options ).forEach( function ( option ) { var node = create( 'option', '', option[1] ); node.value = option[0]; select.appendChild( node ); } );
		return field( parent, label, select, key );
	}
	function reviewFailure( message, retry ) {
		clear( content ); content.removeAttribute( 'aria-busy' ); status.textContent = message;
		if ( retry ) { button( content, 'Retry same review selection', retry ); }
		setControls( activeMode, false );
	}
	function beginReview() {
		if ( draft || draftBlocked ) { showRecovery(); return; }
		if ( activeMode === 'demo' ) { return; }
		++requestSerial; activeMode = 'review'; reviewContext = null; clear( content ); content.removeAttribute( 'aria-busy' );
		var panel = create( 'section', reviewClass ); panel.appendChild( create( 'h3', '', 'Choose shops for owner catalogue review' ) );
		panel.appendChild( create( 'p', '', 'Select one to three exact shops. Missing mappings fail closed; there is no default shop. Reading context does not approve or import anything.' ) );
		var choices = validLocations.slice( 1 ).map( function ( slug ) { return { slug: slug, input: acknowledgement( panel, slug, 'shop-' + slug ) }; } );
		button( panel, 'Read review context for selected shops', function () {
			var selected = choices.filter( function ( choice ) { return choice.input.checked === true; } ).map( function ( choice ) { return choice.slug; } );
			if ( ! selected.length ) { status.textContent = 'Select at least one shop explicitly.'; return; }
			readReviewContext( selected );
		} ); content.appendChild( panel ); status.textContent = 'Owner review — shop selection required.'; setControls( activeMode, false );
	}
	function readReviewContext( selected ) {
		if ( draft || draftBlocked ) { showRecovery(); return; }
		var serial = ++requestSerial; activeMode = 'review'; reviewContext = null; clear( content ); setLoading( 'Reading complete current owner review context…' );
		window.fetch( config.restUrl.replace( /\/$/, '' ) + '/admin/square-catalogue-review-context?shops=' + encodeURIComponent( selected.join( ',' ) ), {
			method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' }
		} ).then( function ( response ) { if ( ! response.ok ) { throw new Error( 'Context unavailable.' ); } return response.json(); } ).then( function ( context ) {
			if ( serial !== requestSerial ) { return; }
			if ( ! validateReviewContext( context, selected ) ) { throw new Error( 'Invalid context.' ); }
			reviewContext = context; renderReview( context ); content.removeAttribute( 'aria-busy' ); status.textContent = 'Review every item, shop availability, tax and photo choice before preparation.'; setControls( activeMode, false );
		} ).catch( function () { if ( serial === requestSerial ) { reviewFailure( 'Review context could not be read safely. No partial context, fallback mapping or demo has been shown.', function () { readReviewContext( selected ); } ); } } );
	}
	function renderReview( context ) {
		clear( content ); var panel = create( 'section', reviewClass );
		panel.appendChild( create( 'h3', '', 'Owner catalogue review — preparation only' ) );
		panel.appendChild( create( 'p', reviewClass + '-summary', 'Merchant ' + context.target.merchant_id + ' · ' + context.target.environment + ' · Application ' + context.target.application_id + ' · API ' + context.target.api_version + ' · create-only' ) );
		context.target.shops.forEach( function ( shop ) { panel.appendChild( create( 'p', '', shop.slug + ' → Square location ' + shop.square_location_id + ' (website shop #' + shop.id + ')' ) ); } );
		panel.appendChild( create( 'p', '', 'Source fingerprint (server reported, not browser-proven): ' + context.source.source_fingerprint ) );
		panel.appendChild( create( 'p', '', 'Tax/image candidates are filtered provider-reported review evidence, not the full inventory or proof of authenticity, licence or applicability. No image is loaded automatically. Global website availability does not determine shop availability.' ) );
		var taxSection = create( 'section', reviewClass + '-section' ); taxSection.appendChild( create( 'h4', '', 'Tax facts and applicability' ) );
		context.tax_candidates.forEach( function ( tax ) { taxSection.appendChild( create( 'p', '', ( tax.name || 'Name unobserved' ) + ' [' + tax.id + '] version ' + tax.version + ' · percentage ' + tax.percentage + ' · ' + tax.inclusion_type + ' · ' + tax.calculation_phase + ' · enabled ' + tax.enabled + ' · custom amounts ' + tax.applies_to_custom_amounts + ' · product-set ' + ( tax.product_set_observed ? String( tax.applies_to_product_set_id ) : 'unobserved' ) + ' · ' + ( tax.policy_compatible ? 'compatible candidate; owner applicability required' : 'not selectable' ) ) ); } );
		var taxChoice = selectField( taxSection, 'Tax for all reviewed items', context.tax_candidates.filter( function ( tax ) { return tax.policy_compatible; } ).map( function ( tax ) { return [ tax.id, ( tax.name || tax.id ) + ' · ' + tax.percentage + '% · ' + tax.inclusion_type ]; } ), 'tax' );
		var taxAck = acknowledgement( taxSection, 'I reviewed these tax facts and confirm this tax applies to every selected item and shop. A null product-set alone does not establish applicability.', 'tax-ack' );
		panel.appendChild( taxSection );
		var imageSection = create( 'section', reviewClass + '-section' ); imageSection.appendChild( create( 'h4', '', 'Photo evidence — explicit links only' ) );
		context.image_candidates.forEach( function ( image ) {
			var row = create( 'p', reviewClass + '-evidence', ( image.name || 'Name unobserved' ) + ' [' + image.id + '] version ' + image.version + ' · authenticity and licence not verified. ' );
			if ( image.evidence_available ) { var link = create( 'a', '', 'Open provider-reported image evidence' ); link.href = image.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; row.appendChild( link ); }
			else { row.appendChild( create( 'span', '', 'No usable image evidence; cannot select.' ) ); } imageSection.appendChild( row );
		} ); panel.appendChild( imageSection );
		var itemControls = context.source.items.map( function ( item ) {
			var card = create( 'section', reviewClass + '-item' ); card.appendChild( create( 'h4', '', item.name + ' — ' + amountCents( item.base_price_minor ) ) );
			card.appendChild( create( 'p', '', 'Item #' + item.website_item_id + ' · exact category: ' + item.category + ' · ' + item.type + ' · global website availability: ' + item.public_availability ) );
			card.appendChild( create( 'p', '', item.description ) ); card.appendChild( create( 'p', '', 'Dietary source labels: ' + ( item.dietary.join( ', ' ) || 'none' ) + '. Website photo reference: ' + ( item.image_url ? 'present, not verified' : 'none' ) ) );
			item.modifier_groups.forEach( function ( group ) {
				card.appendChild( create( 'h5', '', group.label + ' (' + group.type + ')' ) ); group.choices.forEach( function ( choice ) { card.appendChild( create( 'p', '', choice.label + ' · +' + amountCents( choice.price_delta_minor ) + ' · default: ' + choice.default ) ); } );
			} ); if ( ! item.modifier_groups.length ) { card.appendChild( create( 'p', '', 'No modifier groups recorded.' ) ); }
			var availability = context.target.shops.map( function ( shop ) { return { slug: shop.slug, control: selectField( card, 'Availability at ' + shop.slug, [ [ 'true', 'Available' ], [ 'false', 'Unavailable' ] ], 'availability-' + item.website_item_id + '-' + shop.slug ) }; } );
			var photo = selectField( card, 'Photo policy', [ [ 'omit', 'Omit photo' ], [ 'exact', 'Exact item photo' ], [ 'representative_category', 'Representative category photo (labelled)' ] ], 'photo-' + item.website_item_id );
			var image = selectField( card, 'Approved image (required only for exact or representative photo)', context.image_candidates.filter( function ( candidate ) { return candidate.evidence_available; } ).map( function ( candidate ) { return [ candidate.id, candidate.name + ' [' + candidate.id + ']' ]; } ), 'image-' + item.website_item_id );
			var label = create( 'input' ); label.type = 'text'; label.maxLength = 200; label.value = ''; field( card, 'Visible representative photo label (exact category: ' + item.category + ')', label, 'label-' + item.website_item_id );
			var ack = acknowledgement( card, 'I approve this photo decision; for a selected image I reviewed the evidence, rights and item/category suitability.', 'photo-ack-' + item.website_item_id );
			panel.appendChild( card ); return { item: item, availability: availability, photo: photo, image: image, label: label, ack: ack };
		} );
		var finalAck = acknowledgement( panel, 'I reviewed the exact item prices, modifiers/defaults, merchant/location mappings and every explicit shop decision. Prepare only; no Square import, dispatch or payment activation is authorized.', 'final-ack' );
		button( panel, 'Prepare reviewed catalogue — no import', function () {
			if ( activeMode !== 'review' || reviewContext !== context || draft || draftBlocked ) { return; }
			var chosenTax = context.tax_candidates.find( function ( tax ) { return tax.id === taxChoice.value && tax.policy_compatible; } );
			if ( ! chosenTax || taxAck.checked !== true || finalAck.checked !== true ) { status.textContent = 'Choose a compatible tax and explicitly confirm tax applicability and the complete review.'; return; }
			var availability = [], photos = [], used = [ { id: chosenTax.id, type: 'TAX', version: chosenTax.version } ];
			var complete = itemControls.every( function ( row ) {
				var shops = {}; if ( ! row.availability.every( function ( choice ) { if ( ! [ 'true', 'false' ].includes( choice.control.value ) ) { return false; } shops[ choice.slug ] = choice.control.value === 'true'; return true; } ) || row.ack.checked !== true || ! [ 'omit', 'exact', 'representative_category' ].includes( row.photo.value ) ) { return false; }
				var photo = { website_item_id: row.item.website_item_id, approved: true, policy: row.photo.value };
				if ( photo.policy !== 'omit' ) {
					var image = context.image_candidates.find( function ( candidate ) { return candidate.id === row.image.value && candidate.evidence_available; } ); if ( ! image ) { return false; }
					photo.square_image_id = image.id; if ( ! used.some( function ( ref ) { return ref.id === image.id; } ) ) { used.push( { id: image.id, type: 'IMAGE', version: image.version } ); }
				}
				if ( photo.policy === 'representative_category' ) { if ( ! isNonEmptyString( row.label.value ) || row.label.value.length > 200 || ! /[^\p{Z}\p{C}\s]/u.test( row.label.value ) ) { return false; } photo.category = row.item.category; photo.label = row.label.value; }
				availability.push( { website_item_id: row.item.website_item_id, shops: shops } ); photos.push( photo ); return true;
			} ); if ( ! complete ) { status.textContent = 'Every item needs explicit availability for every selected shop and an approved photo policy. Selected photos need usable evidence; representative photos need a visible label.'; return; }
			used.sort( function ( left, right ) { return left.id < right.id ? -1 : left.id > right.id ? 1 : 0; } );
			var target = JSON.parse( JSON.stringify( context.target ) ); target.existing_references = used;
			var policy = { schema_version: 1, source_fingerprint_algorithm: context.source.algorithm, source_fingerprint: context.source.source_fingerprint, expected_item_ids: context.source.items.map( function ( item ) { return item.website_item_id; } ), availability: availability, photos: photos,
				tax: { approved: true, enabled: true, square_tax_id: chosenTax.id, version: chosenTax.version, percentage: chosenTax.percentage, inclusion_type: chosenTax.inclusion_type, calculation_phase: chosenTax.calculation_phase, applies_to_custom_amounts: chosenTax.applies_to_custom_amounts, applies_to_product_set_id: null }, category_policy: 'create_from_source' };
			draft = { operation_key: context.operation_key, body: JSON.stringify( { operation_key: context.operation_key, target: target, policy: policy } ), selection: { shops: context.target.shops.map( function ( shop ) { return shop.slug; } ), actor_id: config.actorId, site: config.restUrl }, phase: 'submitting' };
			if ( ! validateDraft( draft ) || ! saveDraft() ) { draftBlocked = true; showRecovery(); return; }
			submitFrozenDraft();
		} ); content.appendChild( panel );
	}

	function validateDraft( value ) {
		if ( ! exactKeys( value, [ 'operation_key', 'body', 'selection', 'phase' ] ) || ! hash( value.operation_key ) || typeof value.body !== 'string' || value.body.length > 1048576
			|| ! [ 'submitting', 'uncertain', 'prepared', 'not_observed', 'status_only' ].includes( value.phase ) || ! exactKeys( value.selection, [ 'shops', 'actor_id', 'site' ] )
			|| ! positive( value.selection.actor_id ) || typeof value.selection.site !== 'string' || ! Array.isArray( value.selection.shops ) ) { return false; }
		try {
			var body = JSON.parse( value.body ); var policy = body.policy;
			if ( ! exactKeys( body, [ 'operation_key', 'target', 'policy' ] ) || body.operation_key !== value.operation_key || ! validateTarget( body.target, value.selection.shops, true )
				|| ! exactKeys( policy, [ 'schema_version', 'source_fingerprint_algorithm', 'source_fingerprint', 'expected_item_ids', 'availability', 'photos', 'tax', 'category_policy' ] ) || policy.schema_version !== 1
				|| policy.source_fingerprint_algorithm !== 'doughboss-menu-utf8-v1' || ! hash( policy.source_fingerprint ) || policy.category_policy !== 'create_from_source'
				|| ! Array.isArray( policy.expected_item_ids ) || policy.expected_item_ids.length < 1 || policy.expected_item_ids.length >= 200 || ! policy.expected_item_ids.every( positive )
				|| new Set( policy.expected_item_ids ).size !== policy.expected_item_ids.length || ! Array.isArray( policy.availability ) || policy.availability.length !== policy.expected_item_ids.length
				|| ! Array.isArray( policy.photos ) || policy.photos.length !== policy.expected_item_ids.length ) { return false; }
			var availabilityIds = [], photoIds = [], refs = body.target.existing_references, used = [];
			function reference( id, type ) { var ref = refs.find( function ( candidate ) { return candidate.id === id && candidate.type === type; } ); if ( ref && ! used.includes( id ) ) { used.push( id ); } return ref; }
			if ( ! policy.availability.every( function ( row ) {
				if ( ! exactKeys( row, [ 'website_item_id', 'shops' ] ) || ! policy.expected_item_ids.includes( row.website_item_id ) || availabilityIds.includes( row.website_item_id ) || ! exactKeys( row.shops, value.selection.shops ) || ! value.selection.shops.every( function ( slug ) { return typeof row.shops[ slug ] === 'boolean'; } ) ) { return false; }
				availabilityIds.push( row.website_item_id ); return true;
			} ) || ! policy.photos.every( function ( photo ) {
				var keys = [ 'website_item_id', 'approved', 'policy' ]; if ( photo.policy !== 'omit' ) { keys.push( 'square_image_id' ); } if ( photo.policy === 'representative_category' ) { keys.push( 'category', 'label' ); }
				if ( ! exactKeys( photo, keys ) || ! policy.expected_item_ids.includes( photo.website_item_id ) || photoIds.includes( photo.website_item_id ) || photo.approved !== true || ! [ 'omit', 'exact', 'representative_category' ].includes( photo.policy )
					|| ( photo.policy !== 'omit' && ! reference( photo.square_image_id, 'IMAGE' ) ) || ( photo.policy === 'representative_category' && ( ! isNonEmptyString( photo.category ) || photo.category.length > 200 || ! isNonEmptyString( photo.label ) || photo.label.length > 200 || ! /[^\p{Z}\p{C}\s]/u.test( photo.label ) ) ) ) { return false; }
				photoIds.push( photo.website_item_id ); return true;
			} ) ) { return false; }
			var tax = policy.tax;
			if ( ! exactKeys( tax, [ 'approved', 'enabled', 'square_tax_id', 'version', 'percentage', 'inclusion_type', 'calculation_phase', 'applies_to_custom_amounts', 'applies_to_product_set_id' ] ) || tax.approved !== true || ! positive( tax.version )
				|| ! compatibleTax( Object.assign( {}, tax, { product_set_observed: true } ) ) ) { return false; }
			var taxRef = reference( tax.square_tax_id, 'TAX' ); return !! taxRef && taxRef.version === tax.version && used.length === refs.length;
		} catch ( error ) { return false; }
	}
	function sameOwnerDraft() { return !! draft && positive( config.actorId ) && draft.selection.actor_id === config.actorId && draft.selection.site === config.restUrl; }
	function saveDraft() {
		try { window.sessionStorage.setItem( storageKey, JSON.stringify( draft ) ); return true; } catch ( error ) { return false; }
	}
	function restoreDraft() {
		try {
			var stored = window.sessionStorage.getItem( storageKey ); if ( stored === null ) { return; }
			var parsed = JSON.parse( stored ); if ( ! validateDraft( parsed ) ) { draftBlocked = true; return; }
			draft = parsed;
			if ( ! sameOwnerDraft() ) { draftBlocked = true; }
		} catch ( error ) { draftBlocked = true; }
	}
	function timestamp( value ) {
		return typeof value === 'string' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test( value ) && validDatePin( value.slice( 0, 10 ) ) && Number( value.slice( 11, 13 ) ) < 24 && Number( value.slice( 14, 16 ) ) < 60 && Number( value.slice( 17, 19 ) ) < 60;
	}
	function validateOperationStatus( payload ) {
		return exactKeys( payload, [ 'schema_version', 'operation_key', 'state', 'version', 'approved_at', 'updated_at', 'source_hash', 'target_hash', 'request_hash', 'receipt_present', 'verified_at', 'preparation_replay_available', 'same_key_replay_required', 'dispatch_allowed', 'new_operation_key_allowed' ] )
			&& payload.schema_version === 1 && payload.operation_key === draft.operation_key && [ 'prepared', 'dispatching', 'held', 'verified' ].includes( payload.state ) && positive( payload.version )
			&& timestamp( payload.approved_at ) && timestamp( payload.updated_at ) && hash( payload.source_hash ) && hash( payload.target_hash ) && hash( payload.request_hash )
			&& typeof payload.receipt_present === 'boolean' && ( payload.verified_at === null || timestamp( payload.verified_at ) )
			&& payload.preparation_replay_available === ( payload.state === 'prepared' ) && payload.same_key_replay_required === ( payload.state === 'prepared' ) && payload.dispatch_allowed === false && payload.new_operation_key_allowed === false;
	}
	function validatePreparation( payload ) {
		if ( ! exactKeys( payload, [ 'operation_key', 'state', 'version', 'approved_at', 'source_hash', 'target_hash', 'request_hash', 'counts', 'replayed', 'dispatch_allowed' ] ) || payload.operation_key !== draft.operation_key || payload.state !== 'prepared' || ! positive( payload.version )
			|| ! timestamp( payload.approved_at ) || ! hash( payload.source_hash ) || ! hash( payload.target_hash ) || ! hash( payload.request_hash ) || typeof payload.replayed !== 'boolean' || payload.dispatch_allowed !== false
			|| ! exactKeys( payload.counts, [ 'unique_objects', 'top_level_objects', 'by_type' ] ) || ! positive( payload.counts.unique_objects ) || ! positive( payload.counts.top_level_objects )
			|| ! exactKeys( payload.counts.by_type, [ 'ITEM', 'ITEM_VARIATION', 'MODIFIER_LIST', 'MODIFIER', 'CATEGORY' ] ) ) { return false; }
		var total = 0; return Object.keys( payload.counts.by_type ).every( function ( type ) { var count = payload.counts.by_type[ type ]; total += count; return isSafeInteger( count ) && count >= 0; } ) && total === payload.counts.unique_objects;
	}
	function showPrepared( payload ) {
		clear( content ); content.removeAttribute( 'aria-busy' );
		content.appendChild( create( 'h3', '', 'Prepared—not imported.' ) ); content.appendChild( create( 'p', '', 'Operation ' + payload.operation_key + ' · version ' + payload.version + ' · approved at ' + payload.approved_at + ' UTC.' ) );
		[ 'source_hash', 'target_hash', 'request_hash' ].forEach( function ( key ) { content.appendChild( create( 'p', reviewClass + '-summary', key + ': ' + payload[ key ] ) ); } );
		content.appendChild( create( 'p', '', 'These are server-reported source, target and compiled-request hashes, not a durable hash of the raw browser POST body. No automatic POST replay, import, dispatch or payment activation will occur. A fresh operation key remains blocked.' ) );
		button( content, 'Check same operation status', recoverStatus ); status.textContent = 'Prepared—not imported. Stop here; dispatch is not authorized.'; setControls( activeMode, false );
	}
	function showRecovery( retryAllowed ) {
		++requestSerial; activeMode = 'recovery'; reviewContext = null; clear( content ); content.removeAttribute( 'aria-busy' );
		content.appendChild( create( 'h3', '', 'Preparation recovery — no new operation' ) );
		if ( draftBlocked ) {
			status.textContent = 'Stored preparation cannot be trusted or safely persisted in this owner/site session. No request was retried and no new key is allowed.';
			content.appendChild( create( 'p', '', 'The existing intent has not been discarded. Restore the original owner/session or obtain authorized operation reconciliation. Stored source, target and policy are not displayed.' ) );
			if ( draft ) { button( content, 'Check existing operation status only', recoverStatus ); }
		} else {
			status.textContent = retryAllowed ? 'Operation not observed. This does not prove the earlier POST failed; only an explicit same-key, exact-body retry is available.' : 'Preparation outcome unresolved. Check the same operation status; no automatic retry or replacement key.';
			content.appendChild( create( 'p', '', 'Frozen request retained for recovery. The policy cannot be edited, and source/target from session storage are not presented as trusted current data.' ) );
			button( content, 'Check same operation status', recoverStatus );
			if ( retryAllowed && sameOwnerDraft() ) { button( content, 'Retry exact frozen preparation with the same key', function () { if ( draft.phase === 'not_observed' && ! draftBlocked && sameOwnerDraft() ) { submitFrozenDraft(); } } ); }
		}
		setControls( activeMode, false );
	}
	function recoverStatus() {
		if ( ! draft ) { showRecovery(); return; }
		var serial = ++requestSerial; activeMode = 'recovery'; clear( content ); setLoading( 'Checking the same operation status… No preparation POST will be sent.' );
		var timer = null; var completed = false;
		if ( typeof window.setTimeout === 'function' ) { timer = window.setTimeout( function () {
			if ( ! completed && serial === requestSerial ) { showRecovery(); status.textContent = 'Operation status timed out. Outcome remains uncertain; retry status only. No preparation POST or new key.'; }
		}, 30000 ); }
		window.fetch( config.restUrl.replace( /\/$/, '' ) + '/admin/square-catalogue-operation-status?operation_key=' + encodeURIComponent( draft.operation_key ), {
			method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' }
		} ).then( function ( response ) { return response.json().then( function ( payload ) { return { status: response.status, ok: response.ok, payload: payload }; } ); } ).then( function ( result ) {
			if ( serial !== requestSerial ) { return; }
			if ( result.status === 404 && exactKeys( result.payload, [ 'code', 'same_key_replay_required', 'new_operation_key_allowed', 'dispatch_allowed' ] ) && result.payload.code === 'operation_not_observed' && result.payload.same_key_replay_required === true && result.payload.new_operation_key_allowed === false && result.payload.dispatch_allowed === false ) {
				draft.phase = 'not_observed'; saveDraft(); showRecovery( ! draftBlocked && sameOwnerDraft() ); return;
			}
			if ( ! result.ok || ! validateOperationStatus( result.payload ) ) { throw new Error( 'Status uncertain.' ); }
			draft.phase = result.payload.state === 'prepared' ? 'prepared' : 'status_only'; saveDraft();
			if ( result.payload.state === 'prepared' ) { showPrepared( result.payload ); }
			else { showRecovery(); status.textContent = 'Operation state: ' + result.payload.state + '. Status-only reconciliation required; no preparation retry, dispatch or new operation.'; }
		} ).catch( function () { if ( serial === requestSerial ) { showRecovery(); status.textContent = 'Operation status unavailable or invalid. Outcome remains uncertain; retry status only. No new key or preparation POST.'; } } ).then( function () {
			completed = true; if ( timer !== null && typeof window.clearTimeout === 'function' ) { window.clearTimeout( timer ); }
		} );
	}
	function submitFrozenDraft() {
		if ( draftBlocked || ! sameOwnerDraft() || ! validateDraft( draft ) ) { draftBlocked = true; showRecovery(); return; }
		draft.phase = 'submitting'; if ( ! saveDraft() ) { draftBlocked = true; showRecovery(); return; }
		var serial = ++requestSerial; activeMode = 'recovery'; reviewContext = null; clear( content ); setLoading( 'Preparing the frozen reviewed catalogue… No import or dispatch is authorized.' );
		// Persist before fetch. A failed second write cannot erase the already frozen intent.
		var timer = null; var completed = false;
		if ( typeof window.setTimeout === 'function' ) { timer = window.setTimeout( function () { if ( ! completed && serial === requestSerial ) { draft.phase = 'uncertain'; saveDraft(); showRecovery(); } }, 30000 ); }
		window.fetch( config.restUrl.replace( /\/$/, '' ) + '/admin/square-catalogue-prepare', {
			method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: draft.body
		} ).then( function ( response ) { if ( ! response.ok ) { throw new Error( 'Preparation uncertain.' ); } return response.json(); } ).then( function ( payload ) {
			if ( serial !== requestSerial ) { return; }
			if ( ! validatePreparation( payload ) ) { throw new Error( 'Invalid receipt.' ); }
			draft.phase = 'prepared'; saveDraft(); showPrepared( payload );
		} ).catch( function () { if ( serial === requestSerial ) { draft.phase = 'uncertain'; saveDraft(); showRecovery(); } } ).then( function () { completed = true; if ( timer !== null && typeof window.clearTimeout === 'function' ) { window.clearTimeout( timer ); } } );
	}

	function readSource() {
		if ( draft || draftBlocked ) { showRecovery(); return; }
		if ( activeMode === 'demo' ) { return; }
		var serial = ++requestSerial;
		activeMode = 'source';
		clear( content );
		setLoading( 'Reading the website menu source…' );
		window.fetch( config.restUrl.replace( /\/$/, '' ) + '/admin/square-catalogue-source', {
			method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' }
		} ).then( function ( response ) { if ( ! response.ok ) { throw new Error( 'Source unavailable.' ); } return response.json(); } ).then( function ( payload ) {
			if ( serial !== requestSerial ) { return; }
			if ( ! validateSource( payload ) ) { throw new Error( 'Invalid source.' ); }
			renderSource( payload );
			content.removeAttribute( 'aria-busy' );
			status.textContent = 'Website menu source read. No Square import or approval has occurred.';
			setControls( activeMode, false );
		} ).catch( function () {
			if ( serial !== requestSerial ) { return; }
			setError( 'Website menu source could not be read safely. No partial menu or demo fallback has been shown.' );
		} );
	}

	function readLocations() {
		if ( draft || draftBlocked || activeMode !== 'status' || ( locationsButton && locationsButton.disabled ) || content.getAttribute( 'aria-busy' ) === 'true' || ! locationsButton ) { return; }
		var serial = ++requestSerial;
		var completed = false;
		var timer = null;
		activeMode = 'locations';
		clear( content );
		setLoading( 'Reading Square locations…' );
		var finish = function () { if ( timer !== null && typeof window.clearTimeout === 'function' ) { window.clearTimeout( timer ); timer = null; } };
		if ( typeof window.setTimeout === 'function' ) {
			timer = window.setTimeout( function () {
				if ( completed || serial !== requestSerial ) { return; }
				completed = true;
				++requestSerial;
				finish();
				setError( 'Square locations timed out. No partial observation or fallback has been shown.' );
			}, 45000 );
		}
		window.fetch( config.restUrl.replace( /\/$/, '' ) + '/admin/square-locations', {
			method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' }
		} ).then( function ( response ) { if ( ! response.ok ) { throw new Error( 'Locations unavailable.' ); } return response.json(); } ).then( function ( payload ) {
			if ( completed || serial !== requestSerial ) { return; }
			if ( ! validateLocationObservation( payload ) ) { throw new Error( 'Invalid location observation.' ); }
			completed = true; finish(); renderLocationObservation( payload ); content.removeAttribute( 'aria-busy' ); status.textContent = 'Square locations observed; owner verification remains required.'; setControls( activeMode, false );
		} ).catch( function () {
			if ( completed || serial !== requestSerial ) { return; }
			completed = true; finish(); setError( 'Square locations could not be observed safely. No partial observation or fallback has been shown.' );
		} );
	}

	function setControls( mode, isLoading ) {
		var locked = !! draft || draftBlocked;
		if ( previewButton ) {
			previewButton.disabled = isLoading || locked;
		}
		if ( statusButton ) {
			statusButton.hidden = mode === 'status';
			statusButton.disabled = isLoading || locked;
		}
		locationSelect.disabled = isLoading || locked || mode !== 'demo';
		if ( catalogueButton ) { catalogueButton.disabled = isLoading || locked || mode === 'demo'; }
		if ( sourceButton ) { sourceButton.disabled = isLoading || locked || mode === 'demo'; }
		if ( locationsButton ) { locationsButton.disabled = isLoading || locked || mode === 'demo' || mode !== 'status'; }
		if ( catalogueSelect ) { catalogueSelect.disabled = isLoading || locked || mode === 'demo'; }
		if ( prepareButton ) { prepareButton.disabled = isLoading || mode === 'demo'; }
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
		var errorSerial = requestSerial; var errorMode = activeMode;
		var retry = create( 'button', 'button', activeMode === 'source' ? 'Retry website menu source' : activeMode === 'catalogue' ? 'Retry Square catalogue' : activeMode === 'locations' ? 'Retry Square locations' : 'Retry Square status' );
		retry.type = 'button';
		retry.addEventListener( 'click', function () { if ( requestSerial !== errorSerial || activeMode !== errorMode ) { return; } if ( errorMode === 'source' ) { readSource(); } else if ( errorMode === 'catalogue' ) { readCatalogue(); } else if ( errorMode === 'locations' ) { activeMode = 'status'; setControls( activeMode, false ); readLocations(); } else { load( errorMode, locationSelect.value ); } } );
		content.appendChild( retry );
		setControls( activeMode, false );
	}

	function load( mode, location ) {
		if ( draft || draftBlocked ) { showRecovery(); return; }
		var serial = ++requestSerial;
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
			if ( serial !== requestSerial ) { return; }
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
			if ( serial !== requestSerial ) { return; }
			setError( 'Square dashboard could not be loaded. Demo data has not been substituted.' );
		} );
	}

	function readCatalogue() {
		if ( draft || draftBlocked ) { showRecovery(); return; }
		if ( ! catalogueSelect || ! validLocations.slice( 1 ).includes( catalogueSelect.value ) || activeMode === 'demo' ) { return; }
		var shop = catalogueSelect.value;
		var serial = ++requestSerial;
		activeMode = 'catalogue';
		clear( content );
		setLoading( 'Reading Square catalogue for ' + shop + '… No partial observation is shown.' );
		window.fetch( config.restUrl.replace( /\/$/, '' ) + '/admin/square-catalogue?location=' + encodeURIComponent( shop ), {
			method: 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, 'Accept': 'application/json' }
		} ).then( function ( response ) { if ( ! response.ok ) { throw new Error( 'Catalogue unavailable.' ); } return response.json(); } ).then( function ( payload ) {
			if ( serial !== requestSerial || catalogueSelect.value !== shop ) { return; }
			if ( ! validateCatalogue( payload, shop ) || ( Object.prototype.hasOwnProperty.call( payload, 'source_comparison' ) && ! validateSourceComparison( payload.source_comparison ) ) ) { throw new Error( 'Invalid catalogue observation.' ); }
			content.removeAttribute( 'aria-busy' );
			renderCatalogue( payload );
			status.textContent = 'Square catalogue observed for ' + shop + '; owner reconciliation remains required.';
			setControls( activeMode, false );
		} ).catch( function () {
			if ( serial !== requestSerial ) { return; }
			setError( 'Square catalogue could not be observed safely. No partial catalogue or synthetic fallback has been shown. Explicitly retry after checking the selected shop and read permissions.' );
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
	if ( catalogueButton ) { catalogueButton.addEventListener( 'click', readCatalogue ); }
	if ( sourceButton ) { sourceButton.addEventListener( 'click', readSource ); }
	if ( locationsButton ) { locationsButton.addEventListener( 'click', readLocations ); }
	if ( prepareButton ) { prepareButton.hidden = false; prepareButton.addEventListener( 'click', beginReview ); }
	if ( catalogueSelect ) {
		catalogueSelect.addEventListener( 'change', function () {
			if ( activeMode !== 'catalogue' ) { return; }
			++requestSerial;
			clear( content );
			content.removeAttribute( 'aria-busy' );
			status.textContent = 'Catalogue not read for ' + catalogueSelect.value + '. Click Read Square catalogue to observe this shop.';
			setControls( activeMode, false );
		} );
	}

	if ( typeof window.addEventListener === 'function' ) {
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( draft && draft.phase === 'submitting' ) { draft.phase = 'uncertain'; saveDraft(); event.preventDefault(); event.returnValue = ''; }
		} );
	}
	restoreDraft();
	if ( draft ) { recoverStatus(); } else if ( draftBlocked ) { showRecovery(); } else { load( 'status', 'all' ); }
}() );
