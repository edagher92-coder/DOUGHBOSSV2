(function (root) {
	'use strict';

	function object(value) { return value && typeof value === 'object' && !Array.isArray(value); }
	function clone(value) {
		if (Array.isArray(value)) { return value.map(clone); }
		if (!object(value)) { return value; }
		var out = {};
		Object.keys(value).forEach(function (key) { out[key] = clone(value[key]); });
		return out;
	}
	function merge(base, override) {
		var out = clone(base || {});
		Object.keys(override || {}).forEach(function (key) {
			out[key] = object(out[key]) && object(override[key]) ? merge(out[key], override[key]) : clone(override[key]);
		});
		return out;
	}
	function create(profile) { return merge(root.DB_DEMO_BASE_CONFIG || {}, profile || root.DB_DEMO_PROFILE || {}); }
	function validate(config) {
		var errors = [];
		if (config.schemaVersion !== 1) { errors.push('schemaVersion must be 1'); }
		if (!config.profileId || config.profileId === 'unconfigured') { errors.push('profileId is required'); }
		if (!config.brand || !config.brand.name) { errors.push('brand.name is required'); }
		if (!config.region || !config.region.locale || !config.region.currency || !config.region.timezone) { errors.push('region locale, currency and timezone are required'); }
		var locations = (config.locations || []).filter(function (location) { return location.active !== false; });
		if (!locations.length) { errors.push('at least one active location is required'); }
		var ids = {};
		locations.forEach(function (location) {
			if (!location.id || ids[location.id]) { errors.push('location ids must be present and unique'); }
			ids[location.id] = true;
			var enabled = Object.keys(location.fulfilment || {}).filter(function (key) { return location.fulfilment[key] && location.fulfilment[key].enabled; });
			if (!enabled.length) { errors.push('location ' + location.id + ' needs an enabled fulfilment method'); }
			enabled.forEach(function (key) { if (!config.workflows[key]) { errors.push('workflow missing for ' + key); } });
		});
		if (!ids[config.defaultLocationId]) { errors.push('defaultLocationId must reference an active location'); }
		var payments = config.payments || {};
		if (payments.enabled && (!payments.selectedProvider || (payments.allowedProviders || []).indexOf(payments.selectedProvider) === -1)) {
			errors.push('enabled payments need an allowed selectedProvider');
		}
		if (errors.length) { throw new Error('Invalid demo profile: ' + errors.join('; ')); }
		return config;
	}

	function buildRuntime(selectedConfig) {
		var config = validate(selectedConfig);
		function translations(locale) {
			var all = root.DB_DEMO_TRANSLATIONS || {};
			return all[locale] || all[config.region.fallbackLanguage] || {};
		}
		function t(key) { var dict = translations(config.region.language); return Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key; }
		function formatMoney(value, options) {
			return new Intl.NumberFormat(config.region.locale, merge({ style: 'currency', currency: config.region.currency }, options || {})).format(Number(value || 0));
		}
		function formatDateTime(value, options) {
			return new Intl.DateTimeFormat(config.region.locale, merge({ timeZone: config.region.timezone, dateStyle: 'medium', timeStyle: 'short' }, options || {})).format(new Date(value));
		}
		function activeLocations() { return config.locations.filter(function (location) { return location.active !== false; }); }
		function getLocation(id) { return activeLocations().filter(function (location) { return location.id === id; })[0] || null; }
		function enabledFulfilments(locationId) {
			var location = getLocation(locationId);
			return location ? Object.keys(location.fulfilment || {}).filter(function (key) { return location.fulfilment[key] && location.fulfilment[key].enabled; }) : [];
		}
		function workflow(key) { return config.workflows[key] || null; }
		function state(order) { var flow = workflow(order.fulfilment); return flow && flow.states[order.status] ? flow.states[order.status] : null; }
		function isLive(order) { var current = state(order); return order.channel === 'online' && !!current && !current.terminal; }
		function storageKey(name) { return 'db_demo_' + config.profileId + '_' + name; }
		return {
			config: config,
			create: create,
			createRuntime: function (profile) { return buildRuntime(create(profile)); },
			validate: validate,
			merge: merge,
			t: t,
			formatMoney: formatMoney,
			formatDateTime: formatDateTime,
			activeLocations: activeLocations,
			getLocation: getLocation,
			enabledFulfilments: enabledFulfilments,
			workflow: workflow,
			state: state,
			isLive: isLive,
			storageKey: storageKey
		};
	}

	root.DBDemo = buildRuntime(create());
}(typeof globalThis !== 'undefined' ? globalThis : this));
