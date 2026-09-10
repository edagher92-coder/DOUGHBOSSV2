'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const seo = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-seo.php'), 'utf8');
const theme = fs.readFileSync(path.join(root, 'themes', 'doughboss-final', 'functions.php'), 'utf8');

assert.match(seo, /is_page\( 'catering' \)/, 'catering template receives dedicated metadata');
assert.match(seo, /is_page\( array\( 'menu', 'order' \) \)/, 'menu and order templates receive menu metadata');
assert.match(seo, /is_page\( 'locations' \)/, 'locations template receives local bakery metadata');
assert.match(seo, /is_page\( 'vouchers' \)/, 'voucher template receives promotion metadata');
assert.match(seo, /add_filter\( 'robots_txt'/, 'WordPress virtual robots.txt has a no-duplicate sitemap fallback');
assert.match(seo, /home_url\( '\/wp-sitemap\.xml' \)/, 'robots fallback points to the native WordPress sitemap');
assert.match(theme, /doughboss_seo_relevant_page/, 'template-rendered pages opt into DoughBoss metadata');

console.log('WordPress theme SEO contract passed.');
