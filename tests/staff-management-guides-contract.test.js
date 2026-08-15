'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const plugin = fs.readFileSync(path.join(root, 'doughboss.php'), 'utf8');
const core = fs.readFileSync(path.join(root, 'includes', 'class-doughboss.php'), 'utf8');
const portals = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-portals.php'), 'utf8');
const guides = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-guides.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public', 'css', 'doughboss-guides.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'public', 'js', 'doughboss-guides.js'), 'utf8');

assert.match(plugin, /Version:\s+2\.40\.0/);
assert.match(plugin, /DOUGHBOSS_VERSION', '2\.40\.0/);
assert.match(core, /class-doughboss-guides\.php/);
assert.match(portals, /\^staff-guide\/\?\$/);
assert.match(portals, /\^management-guide\/\?\$/);
assert.match(portals, /render_guide\( 'management' \)/);
assert.match(portals, /render_guide\( 'staff' \)/);
assert.match(portals, /'management' === \$audience && ! current_user_can\( 'manage_doughboss' \)/);
assert.match(portals, /noindex,nofollow,noarchive/);
assert.match(guides, /class DoughBoss_Guides/);
assert.match(guides, /Scan, enter PIN, then clock in/);
assert.match(guides, /Worked time subtracts only breaks that were actually recorded/);
assert.match(guides, /home_url\( '\/staff-clock\/' \)/);
assert.match(guides, /admin_url\( 'admin\.php\?page=doughboss-staff-badges' \)/);
assert.match(guides, /never place payment keys, passwords or staff PINs/);
assert.match(css, /prefers-reduced-motion/);
assert.match(css, /min-height:44px/);
assert.match(js, /localStorage/);
assert.match(js, /navigator\.clipboard/);

console.log('PASS: staff and management guides are unlisted, role-gated where required, mobile-touch-friendly and preserve the QR/PIN attendance safety rules');
