'use strict';

const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const helper = fs.readFileSync(path.join(root, 'tools', 'doughboss-site-repair-2341', 'doughboss-site-repair-2341.php'), 'utf8');

test('site repair pins both immutable release packages', function () {
	assert.match(helper, /v2\.34\.1-rc\.1\/doughboss\.zip/);
	assert.match(helper, /PLUGIN_BYTES = 2475049/);
	assert.match(helper, /75680c4c9b1275525c31805c373c3ee8c7576854fd5cb9449424a53313587811/);
	assert.match(helper, /v2\.34\.1-rc\.1\/doughboss-final\.zip/);
	assert.match(helper, /THEME_BYTES = 24982/);
	assert.match(helper, /e5a2553668dfd094c590a6619f576816e461b9cba8934f63253264826e1f5daf/);
});

test('site repair preserves both current folders and restores them as a pair', function () {
	assert.match(helper, /doughboss-backup-/);
	assert.match(helper, /doughboss-final-backup-/);
	assert.match(helper, /restore_pair/);
	assert.match(helper, /Post-install verification failed/);
	assert.match(helper, /is_plugin_active\( self::PLUGIN_BASENAME \)/);
	assert.match(helper, /self::THEME_SLUG === \$theme_slug/);
});

test('site repair does not mutate commerce or WordPress data', function () {
	assert.doesNotMatch(helper, /update_option|delete_option|wpdb->|wp_delete_post|wp_delete_user|uninstall_plugin/);
	assert.match(helper, /does not change content, users, orders, payment settings, credentials, or the database/);
});
