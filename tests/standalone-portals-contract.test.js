'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const php = fs.readFileSync(path.join(root, 'includes', 'class-doughboss-portals.php'), 'utf8');
const core = fs.readFileSync(path.join(root, 'includes', 'class-doughboss.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'public', 'css', 'doughboss-portals.css'), 'utf8');
const boardCss = fs.readFileSync(path.join(root, 'public', 'css', 'doughboss-orderboard.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'public', 'js', 'doughboss-portals.js'), 'utf8');

function readPublicTheme(dir) {
	return fs.readdirSync(dir).map((name) => {
		const target = path.join(dir, name);
		if (fs.statSync(target).isDirectory()) { return readPublicTheme(target); }
		return /\.(?:php|js|css)$/.test(name) ? fs.readFileSync(target, 'utf8') : '';
	}).join('\n');
}

const publicTheme = readPublicTheme(path.join(root, 'themes', 'doughboss-final'));

assert.match(core, /class-doughboss-portals\.php/);
assert.match(core, /new DoughBoss_Portals\(\)/);
assert.match(php, /\^kitchen\/\?\$/);
assert.match(php, /\^catering-kitchen\/\?\$/);
assert.match(php, /\^management\/\?\$/);
assert.match(php, /auth_redirect\(\)/);
assert.ok(php.indexOf('$this->portal_headers();') < php.indexOf('auth_redirect();'), 'security headers must be sent before an unauthenticated redirect');
assert.match(php, /manage_doughboss_kds/);
assert.match(php, /manage_doughboss/);
assert.match(php, /DoughBoss_Staff_Scope::current_location_id\(\)/);
assert.match(php, /verify_board_access_key/);
assert.match(php, /wp_create_nonce\( 'wp_rest' \)/);
assert.match(php, /noindex,nofollow,noarchive/);
assert.match(php, /X-Robots-Tag: noindex, nofollow, noarchive/);
assert.match(php, /X-Frame-Options: DENY/);
assert.match(php, /\$key_is_valid \? \$supplied_key : ''/);
assert.match(php, /\$args\['screen'\] = \$target_screen/);
assert.match(php, /render_kitchen\( 'catering' \)/);
assert.match(php, /home_url\( '\/catering-kitchen\/' \)/);
assert.match(php, /Catering team sign-in/);
assert.doesNotMatch(publicTheme, /catering-kitchen/);
assert.match(css, /min-height: 52px/);
assert.match(boardCss, /min-width: 1500px/);
assert.match(boardCss, /doughboss-board--screen-catering/);
assert.match(js, /requestFullscreen/);

console.log('PASS: hidden standalone kitchen, catering and management portals retain authentication, shop scope, route security, touch targets and verified board-key navigation');
