const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const helper = fs.readFileSync(
	path.resolve(__dirname, '..', 'tools', 'doughboss-quarantine-helper', 'doughboss-quarantine-helper.php'),
	'utf8'
);

test('quarantine helper only moves the two known inactive malformed folders', () => {
	assert.match(helper, /DoughBoss-Migration-Gate-1\.0\.0/);
	assert.match(helper, /doughboss-migration-gate-1\.0\.1/);
	assert.match(helper, /get_option\( 'active_plugins'/);
	assert.match(helper, /is_multisite\(\) \? \(array\) get_site_option\( 'active_sitewide_plugins'/);
	assert.match(helper, /wp_normalize_path\( WP_PLUGIN_DIR/);
	assert.match(helper, /@rename\( \$source, \$destination \)/);
	assert.doesNotMatch(helper, /unlink\(|rmdir\(|delete_plugins\(/);
});
