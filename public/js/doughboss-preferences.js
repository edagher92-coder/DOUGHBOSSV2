/** Optional device preferences only. Cart, table and payment recovery are not managed here. */
(function () {
	'use strict';
	if (window.DoughBossPreferences) { return; }

	function createPreferences(storageProvider, clock, changed) {
		var consentKey = 'doughboss_preferences_v1';
		var shopKey = 'doughboss_location';
		var detailsKey = 'doughboss_remember_details';
		var day = 24 * 60 * 60 * 1000;
		var consentTtl = 180 * day;
		var detailsTtl = 30 * day;
		var fields = ['customer_name', 'customer_email', 'customer_phone'];
		var record = null;
		var pageShop = 0;
		function storage() { try { return storageProvider(); } catch (ignore) { return null; } }
		function remove(key) { try { var s = storage(); if (s) { s.removeItem(key); } } catch (ignore) {} }
		function read(key) { try { var s = storage(); return s ? s.getItem(key) : null; } catch (ignore) { return null; } }
		function write(key, value) { try { var s = storage(); if (!s) { return false; } s.setItem(key, value); return true; } catch (ignore) { return false; } }
		function clearOptional() { [shopKey, detailsKey, 'db-hero-motion'].forEach(remove); }
		function validLifetime(value, ttl) {
			var now = clock();
			return !!(value && value.version === 1 && Number.isFinite(value.savedAt) && Number.isFinite(value.expiresAt)
				&& value.savedAt > 0 && value.savedAt <= now && value.expiresAt > now
				&& value.expiresAt > value.savedAt && value.expiresAt - value.savedAt <= ttl);
		}
		try { record = JSON.parse(read(consentKey) || 'null'); } catch (ignore) {}
		if (!validLifetime(record, consentTtl) || typeof record.preferences !== 'boolean') {
			record = null;
			remove(consentKey);
			// Remove legacy optional values without reading or reusing their contents.
			clearOptional();
		} else if (!record.preferences) { clearOptional(); }
		function consent() {
			if (record && !validLifetime(record, consentTtl)) {
				record = null;
				remove(consentKey);
				clearOptional();
				if (changed) { changed({ decided: false, preferences: false }); }
			}
			return { decided: !!record, preferences: !!(record && record.preferences) };
		}
		function allowed() { return consent().preferences; }
		function choose(enabled) {
			if (typeof enabled !== 'boolean') { return false; }
			var now = clock();
			record = { version: 1, savedAt: now, expiresAt: now + consentTtl, preferences: enabled };
			if (!enabled) { clearOptional(); }
			var persisted = write(consentKey, JSON.stringify(record));
			if (enabled && pageShop) { persisted = write(shopKey, String(pageShop)) && persisted; }
			if (changed) { changed({ decided: true, preferences: enabled, persisted: persisted }); }
			return persisted;
		}
		function shopId(value) {
			if (typeof value !== 'number' && (typeof value !== 'string' || !/^[1-9][0-9]{0,9}$/.test(value))) { return 0; }
			var id = Number(value);
			return Number.isSafeInteger(id) && id > 0 && id <= 2147483647 ? id : 0;
		}
		function readShop() {
			var mayRead = allowed();
			if (!pageShop && mayRead) { pageShop = shopId(read(shopKey)); }
			return pageShop;
		}
		function writeShop(value) {
			var id = shopId(value);
			if (!id) { return false; }
			// A transactional page choice is usable even when optional storage is off.
			pageShop = id;
			return allowed() ? write(shopKey, String(id)) : false;
		}
		function cleanDetails(values) {
			var result = {};
			var any = false;
			fields.forEach(function (key) {
				result[key] = values && typeof values[key] === 'string' ? values[key].trim().slice(0, 200) : '';
				if (result[key]) { any = true; }
			});
			return any ? result : null;
		}
		function readDetails() {
			if (!allowed()) { return null; }
			var saved = null;
			try { saved = JSON.parse(read(detailsKey) || 'null'); } catch (ignore) {}
			if (!validLifetime(saved, detailsTtl) || !saved.details || typeof saved.details !== 'object' || Array.isArray(saved.details)) {
				remove(detailsKey);
				return null;
			}
			return cleanDetails(saved.details);
		}
		function writeDetails(values) {
			if (!allowed()) { return false; }
			var details = cleanDetails(values);
			if (!details) { remove(detailsKey); return false; }
			var now = clock();
			return write(detailsKey, JSON.stringify({ version: 1, savedAt: now, expiresAt: now + detailsTtl, details: details }));
		}
		function reload() {
			var next = null;
			try { next = JSON.parse(read(consentKey) || 'null'); } catch (ignore) {}
			record = validLifetime(next, consentTtl) && typeof next.preferences === 'boolean' ? next : null;
			if (!record || !record.preferences) { clearOptional(); }
			if (changed) { changed(consent()); }
		}
		return { allowed: allowed, getConsent: consent, choose: choose, readShop: readShop, writeShop: writeShop,
			readDetails: readDetails, writeDetails: writeDetails, clearDetails: function () { remove(detailsKey); }, reload: reload };
	}

	var panel = null;
	var preferenceBox = null;
	var management = null;
	var manage = null;
	var entry = null;
	var status = null;
	var returnFocus = null;
	function syncMarketing() {
		// Allowing device preferences never enables analytics or advertising.
		var denied = { measurement: false, advertising: false, version: 'preferences-v1' };
		if (window.DoughBossMarketing && typeof window.DoughBossMarketing.setConsent === 'function') {
			window.DoughBossMarketing.setConsent(denied);
		}
		document.dispatchEvent(new CustomEvent('doughboss:consent', { detail: denied }));
	}
	var preferences = createPreferences(function () { return window.localStorage; }, Date.now, function (next) {
		syncMarketing();
		if (preferenceBox) { preferenceBox.checked = next.preferences; }
		if (panel && !next.decided) { panel.hidden = false; }
		document.dispatchEvent(new CustomEvent('doughboss:preferences-changed', { detail: next }));
	});
	function close() {
		if (!returnFocus && panel && panel.contains(document.activeElement)) { returnFocus = entry; }
		if (panel) { panel.hidden = true; }
		if (entry) { entry.setAttribute('aria-expanded', 'false'); }
		if (returnFocus && typeof returnFocus.focus === 'function') { returnFocus.focus(); }
		returnFocus = null;
	}
	function open() {
		if (!panel) { return; }
		returnFocus = document.activeElement;
		preferenceBox.checked = preferences.allowed();
		panel.hidden = false;
		management.hidden = false;
		manage.setAttribute('aria-expanded', 'true');
		entry.setAttribute('aria-expanded', 'true');
		panel.querySelector('h2').focus();
	}
	function save(enabled) {
		var persisted = preferences.choose(enabled);
		status.textContent = persisted ? 'Your privacy preferences have been saved.' : 'Preferences apply on this page only. Your browser did not allow them to be saved.';
		close();
	}
	function node(tag, text, parent) {
		var element = document.createElement(tag);
		if (text) { element.textContent = text; }
		if (parent) { parent.appendChild(element); }
		return element;
	}
	function button(text, callback, parent) {
		var element = node('button', text, parent);
		element.type = 'button';
		element.addEventListener('click', callback);
		return element;
	}
	function boot() {
		if (!document.body || document.getElementById('db-device-preferences')) { return; }
		var config = window.DoughBossPreferencesConfig || {};
		panel = node('section', '', document.body);
		panel.id = 'db-device-preferences';
		panel.className = 'db-preferences-panel';
		panel.setAttribute('aria-labelledby', 'db-preferences-title');
		var heading = node('h2', 'Your privacy choices', panel);
		heading.id = 'db-preferences-title';
		heading.tabIndex = -1;
		node('p', 'Essential cookies keep your basket, table session and payment confirmation working. Optional preferences can remember your shop on this device for up to 180 days. Analytics and advertising stay off.', panel);
		var links = node('p', '', panel);
		[['Privacy policy', config.privacyUrl || '/privacy-policy/'], ['Terms', config.termsUrl || '/terms-conditions/']].forEach(function (item) {
			var link = node('a', item[0], links);
			link.href = item[1];
		});
		management = node('div', '', panel);
		management.id = 'db-preferences-management';
		management.hidden = true;
		var label = node('label', '', management);
		preferenceBox = node('input', '', label);
		preferenceBox.type = 'checkbox';
		preferenceBox.checked = preferences.allowed();
		node('span', 'Allow optional device preferences. Remembering contact details still needs its separate checkout opt-in.', label);
		button('Save choices', function () { save(preferenceBox.checked); }, management);
		var actions = node('div', '', panel);
		actions.className = 'db-preferences-actions';
		button('Reject optional', function () { save(false); }, actions);
		button('Allow preferences', function () { save(true); }, actions);
		manage = button('Manage', function () {
			management.hidden = !management.hidden;
			manage.setAttribute('aria-expanded', String(!management.hidden));
			if (!management.hidden) { preferenceBox.focus(); }
		}, actions);
		manage.setAttribute('aria-controls', management.id);
		manage.setAttribute('aria-expanded', 'false');
		button('Close', close, actions);
		panel.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); close(); } });
		var footer = document.querySelector('footer') || document.body;
		entry = button('Privacy preferences', open, footer);
		entry.className = 'db-preferences-entry';
		entry.setAttribute('aria-controls', panel.id);
		entry.setAttribute('aria-expanded', 'false');
		status = node('span', '', footer);
		status.className = 'db-preferences-status';
		status.setAttribute('role', 'status');
		panel.hidden = preferences.getConsent().decided;
		entry.setAttribute('aria-expanded', String(!panel.hidden));
		syncMarketing();
	}
	window.DoughBossPreferences = { allowed: preferences.allowed, getConsent: preferences.getConsent, readShop: preferences.readShop,
		writeShop: preferences.writeShop, readDetails: preferences.readDetails, writeDetails: preferences.writeDetails,
		clearDetails: preferences.clearDetails, open: open, syncMarketing: syncMarketing };
	window.addEventListener('storage', function (event) { if (event.key === 'doughboss_preferences_v1' || event.key === null) { preferences.reload(); } });
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
}());
