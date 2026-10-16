'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const repoRoot = path.resolve(__dirname, '..');
const css = fs.readFileSync(path.join(repoRoot, 'themes', 'doughboss-final', 'style.css'), 'utf8');
const cateringPage = fs.readFileSync(path.join(repoRoot, 'themes', 'doughboss-final', 'page-catering.php'), 'utf8');

test('mobile open navigation keeps banner below scrim and header drawer', () => {
	assert.match(css, /\.dbf-launch-bar\s*\{[^}]*\bz-index:\s*102\b[^}]*\}/, 'normal launch bar stacking remains z102');
	assert.match(css, /body\.dbf-menu-open::before\s*\{[^}]*\bz-index:\s*98\b[^}]*\}/, 'open-state scrim remains z98');
	assert.match(css, /\.dbf-header\s*\{[^}]*\bz-index:\s*100\b[^}]*\}/, 'header remains z100');

	const mobileRules = css.match(/@media\s*\(max-width:\s*1100px\)\s*\{[\s\S]*?\n\}/)?.[0];
	assert.ok(mobileRules, 'mobile navigation media block is present');
	const controller = fs.readFileSync(path.join(repoRoot, 'themes', 'doughboss-final', 'assets', 'theme.js'), 'utf8');
	assert.match(controller, /matchMedia\('\(max-width: 1100px\)'\)/, 'menu behavior and CSS use the same readable-navigation breakpoint');
	assert.match(mobileRules, /body\.dbf-menu-open\s+\.dbf-launch-bar\s*\{\s*z-index:\s*97;\s*\}/, 'only open mobile launch bar is lowered beneath scrim');
	assert.equal((css.match(/body\.dbf-menu-open\s+\.dbf-launch-bar/g) || []).length, 1, 'mobile override is singular');
	assert.match(mobileRules, /\.dbf-nav\s*\{[^}]*position:\s*fixed;[^}]*top:\s*0;[^}]*right:\s*0;[^}]*bottom:\s*0;[^}]*max-height:\s*100vh;[^}]*max-height:\s*100dvh;/, 'drawer remains fixed to the viewport with full-height constraints');
});

test('catering hero secondary action targets the existing enquiry section', () => {
	const heroShortcode = cateringPage.match(/\[doughboss_manoush_hero[^\]]+\]/)?.[0];
	assert.ok(heroShortcode, 'catering hero shortcode is present');
	assert.match(heroShortcode, /variant="catering"/);
	assert.match(heroShortcode, /secondary_url="#catering-enquiry"/, 'catering hero uses the enquiry fragment');
	assert.match(cateringPage, /<section\s+id="catering-enquiry"/, 'matching enquiry section id remains present');
	assert.equal((cateringPage.match(/\[doughboss_catering\]/g) || []).length, 1, 'page keeps one existing catering form shortcode');
});
