'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const core = fs.readFileSync(path.join(root, 'includes', 'class-doughboss.php'), 'utf8');
const seo = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-seo.php'), 'utf8');
const assets = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-assets.php'), 'utf8');
const storefront = fs.readFileSync(path.join(root, 'public', 'js', 'doughboss.js'), 'utf8');

assert.match(core, /require_once \$dir \. 'class-doughboss-seo\.php'/, 'core loads SEO fallback');
assert.match(core, /new DoughBoss_SEO\(\) \)->init\(\)/, 'core initializes SEO fallback');
assert.match(seo, /DoughBoss_Locations::all\( true \)/, 'schema uses active WordPress locations');
assert.match(seo, /DoughBoss_Locations::weekly_hours/, 'schema uses live location hours');
assert.match(seo, /dedicated_seo_plugin_active/, 'fallback yields to a dedicated SEO plugin');
assert.doesNotMatch(seo, /github\.io|edagher92-coder/, 'WordPress metadata never hard-codes the demo domain');
assert.match(seo, /doughboss-social-card\.jpg/, 'WordPress uses the 1200x630 share card');

assert.match(assets, /DOUGHBOSS_MARKETING_ENABLED/, 'marketing requires an explicit enable flag');
assert.match(assets, /DOUGHBOSS_META_PIXEL_ID/, 'Meta ID is configuration-only');
assert.match(assets, /DOUGHBOSS_TIKTOK_PIXEL_ID/, 'TikTok ID is configuration-only');
assert.match(assets, /'adpilotServerReady'\s*=>\s*false/, 'AdPilot browser delivery remains disabled');
assert.doesNotMatch(assets, /ADPILOT_(?:SECRET|TOKEN|KEY)/, 'no AdPilot credential reaches browser configuration');

for (const event of ['add_to_cart', 'begin_checkout', 'purchase', 'generate_lead']) {
	assert.ok(storefront.includes(`trackCommerce('${event}'`), `storefront emits ${event}`);
}
assert.ok(storefront.indexOf("trackCommerce('purchase'") > storefront.indexOf("request('/checkout'"), 'purchase is emitted only in the successful checkout path');

console.log('WordPress SEO/marketing contract: live-data schema, plugin coexistence, config gates, and commerce hooks passed.');
