const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const settings = read('includes/class-doughboss-settings.php');
const admin = read('admin/class-doughboss-admin.php');
const rest = read('includes/class-doughboss-rest-controller.php');
const shortcodes = read('includes/class-doughboss-shortcodes.php');
const css = read('public/css/doughboss-catering.css');
const demo = read('demo/index.html');

test('official catering contacts are configurable and safe by default', () => {
	assert.match(settings, /'catering_email'\s*=>\s*'catering@doughboss\.com\.au'/);
	assert.match(settings, /'catering_phone'\s*=>\s*'0422487487'/);
	assert.match(admin, /sanitize_email\( \$input\['catering_email'\] \)/);
	assert.match(admin, /preg_replace\( '\/\[\^0-9\+\]\//);
});

test('catering notifications use the dedicated inbox', () => {
	assert.match(rest, /DoughBoss_Settings::catering_email\(\)/);
	assert.match(rest, /wp_mail\( \$catering_email, \$subject, \$body \)/);
});

test('catering shortcode includes contact, how-to and Q&A content', () => {
	assert.match(shortcodes, /mailto:/);
	assert.match(shortcodes, /tel:/);
	assert.match(shortcodes, /Catering online ordering is coming soon/);
	assert.doesNotMatch(shortcodes, /data-doughboss-catering/);
	assert.match(shortcodes, /A fresh spread in three steps/);
	assert.match(shortcodes, /Good to know before you order/);
	assert.match(shortcodes, /cannot promise an allergen-free environment/);
});

test('demo catering launch state uses the dedicated contacts', () => {
	assert.match(demo, /mailto:catering@doughboss\.com\.au/);
	assert.match(demo, /tel:\+61422487487/);
	assert.match(demo, /Catering online ordering is coming soon/);
});

test('catering contact and guide collapse to one column on mobile', () => {
	assert.match(css, /\.dbc-contact-actions/);
	assert.match(css, /\.dbc-how-grid/);
	assert.match(css, /@media \(max-width: 540px\)[\s\S]*grid-template-columns: 1fr/);
});
