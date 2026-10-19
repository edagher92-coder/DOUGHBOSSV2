'use strict';

// Dependency-free release check. Never starts a server or sends a request.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');

const output = path.resolve(process.argv[2] || '_site');
const root = path.resolve(__dirname, '..');
const digest = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const manifest = JSON.parse(fs.readFileSync(path.join(output, 'manifest.json'), 'utf8'));
const heroIntegrated = manifest.hero_integration === 'shared-snippet-pinned-media';
assert.ok(heroIntegrated || manifest.hero_integration === 'photo-only-approved-film-not-included', 'explicit hero preview mode required');
if (process.argv.includes('--require-hero')) assert.ok(heroIntegrated, 'release review requires shared hero and pinned local media');
const heroSourcePath = 'scripts/hero-commercial/homepage-hero-v6-snippet.php';
const heroSource = fs.readFileSync(path.join(root, heroSourcePath), 'utf8');
assert.ok(manifest.source_sha256[heroSourcePath], 'shared hero source provenance required');
const heroScripts = new Map();
if (heroIntegrated) {
  for (const match of heroSource.matchAll(/<script\s+id="([a-z0-9-]+)">([\s\S]*?)<\/script>/g)) {
    const hash = crypto.createHash('sha256').update(match[2]).digest('hex');
    heroScripts.set(`preview/${match[1]}-${hash.slice(0, 16)}.js`, match[2]);
  }
  assert.equal(heroScripts.size, 4, 'all shared presentation controllers accounted for');
}
assert.deepEqual([...manifest.generated_scripts].sort(), [...heroScripts.keys()].sort(), 'only exact shared controllers may be generated');
const approvedMedia = {
  'doughboss-commercial-v3-web-loop.mp4': ['6035ffcef72dcae2c667ced78cd56d8e7823e5841c6bd530be2e0be9cf9ecff4', 2720427],
  'doughboss-commercial-v5-poster.jpg': ['1717edb9630d2e7444a8190d1f56a34cb7d9e9b370bc4dbe4260bfd15f68fa9d', 212551],
  'doughboss-commercial-v6-desktop-square-poster.jpg': ['18bba423cf237b725453a657b6802cff07e949d447f820200fd62a565aab8cb3', 44957]
};
const mediaOutputs = new Set();
assert.equal(Object.keys(manifest.hero_media).length, heroIntegrated ? 3 : 0);
if (heroIntegrated) {
  for (const [filename, [sha256, bytes]] of Object.entries(approvedMedia)) {
    const url = 'https://doughboss.com.au/wp-content/uploads/' + filename;
    const relative = 'preview/media/' + filename;
    assert.deepEqual(manifest.hero_media[url], { sha256, bytes, output: relative }, 'receipt-pinned original media provenance');
    assert.equal(digest(path.join(output, relative)), sha256, 'exact approved local media bytes');
    assert.equal(fs.statSync(path.join(output, relative)).size, bytes);
    mediaOutputs.add(relative);
  }
}
const pages = ['index.html', 'order.html', 'cart.html', 'paused.html', 'partners.html', 'wholesale.html', 'catering.html', 'locations.html', 'members.html'];
const generated = [...pages, 'preview/menu-options.js'];
for (const template of ['page-franchising.php', 'page-wholesale.php', 'template-parts/partner-page.php']) {
	assert.ok(manifest.source_sha256['themes/doughboss-final/' + template], 'partner template provenance is required');
}
assert.equal(manifest.preview_only, true);
assert.deepEqual(manifest.pages, pages);
assert.equal(manifest.source_commit, execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim());
assert.ok(manifest.source_sha256['scripts/build-visual-preview.php'], 'builder provenance required');
assert.ok(manifest.source_sha256['includes/class-doughboss-post-types.php'], 'post types provenance required');

function safeRelative(relative) {
  assert.match(relative, /^[a-zA-Z0-9_./-]+$/);
  assert.ok(!relative.startsWith('/') && !relative.split('/').includes('..'));
}
for (const [relative, hash] of Object.entries(manifest.source_sha256)) {
  safeRelative(relative);
  assert.equal(digest(path.join(root, relative)), hash, `source hash: ${relative}`);
}

const actual = [];
function walk(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    assert.ok(!entry.isSymbolicLink(), 'preview cannot contain links');
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(file);
    else actual.push(path.relative(output, file).replace(/\\/g, '/'));
  }
}
walk(output);
assert.deepEqual(actual.sort(), [...Object.keys(manifest.output_sha256), 'manifest.json'].sort(), 'no unmanifested output');
for (const [relative, hash] of Object.entries(manifest.output_sha256)) {
  safeRelative(relative);
  assert.match(relative, /\.(?:html|js|css|woff2|jpg|png|webp|avif|mp4)$/);
  if (relative.endsWith('.png')) assert.equal(fs.readFileSync(path.join(output, relative)).subarray(0, 8).toString('hex'), '89504e470d0a1a0a', 'PNG assets have a genuine PNG signature');
  assert.ok(!/(?:^|\/)(?:docs|admin|includes|scripts|tests|\.git)(?:\/|$)/.test(relative));
  assert.equal(digest(path.join(output, relative)), hash, `output hash: ${relative}`);
  if (heroScripts.has(relative)) {
    assert.equal(fs.readFileSync(path.join(output, relative), 'utf8'), heroScripts.get(relative), 'no copied/rewritten hero runtime');
    continue;
  }
  if (mediaOutputs.has(relative)) continue;
  if (generated.includes(relative)) continue;
  const source = relative.startsWith('preview/') ? 'scripts/visual-preview/' + path.basename(relative) : relative;
  assert.ok(manifest.source_sha256[source], `allowlisted source: ${relative}`);
  assert.equal(hash, manifest.source_sha256[source], `exact source bytes: ${relative}`);
}

function localReference(reference, from) {
  if (reference.startsWith('#') || reference.startsWith('data:')) return;
  assert.ok(!/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(reference), `external reference in ${from}`);
  const withoutQuery = reference.split(/[?#]/)[0];
  const relative = withoutQuery.startsWith('/')
    ? (assert.ok(withoutQuery.startsWith(manifest.base_path)), withoutQuery.slice(manifest.base_path.length))
    : path.posix.normalize(path.posix.join(path.posix.dirname(from), withoutQuery));
  safeRelative(relative);
  assert.ok(Object.hasOwn(manifest.output_sha256, relative), `missing local reference ${reference} in ${from}`);
}
function validateSrcset(srcset, page) {
  let previousWidth = 0;
  for (const candidate of srcset.split(',')) {
    const parsed = candidate.trim().match(/^([^\s,]+) ([1-9][0-9]*)w$/);
    assert.ok(parsed, 'local width-descriptor candidate required');
    assert.match(parsed[1], /\/public\/images\/responsive\/[a-z0-9-]+-([1-9][0-9]*)\.(?:avif|webp)$/);
    assert.ok(Number(parsed[2]) > previousWidth, 'ascending unique widths');
    assert.ok(parsed[1].endsWith(`-${parsed[2]}.avif`) || parsed[1].endsWith(`-${parsed[2]}.webp`), 'filename agrees with width');
    localReference(parsed[1], page);
    previousWidth = Number(parsed[2]);
  }
}
const fixtureScript = fs.readFileSync(path.join(output, 'preview/menu-options.js'), 'utf8');
const fixtureMatch = fixtureScript.match(/^window\.DoughBossPreviewOptions = (.+);\s*$/);
assert.ok(fixtureMatch, 'preview fixture remains a JSON assignment, not executable logic');
const fixture = JSON.parse(fixtureMatch[1]);
const responsiveItems = fixture.menu.filter(item => item.image_responsive);
assert.equal(responsiveItems.length, 11, 'preview covers legacy photos, new drinks, labelled illustrations and unsupported fallbacks');
for (const name of ['Sample Coke 600ml', 'Sample Vanilla Coke 600ml', 'Sample Half Meat & Cheese', 'Sample Sujuk Special', 'Sample All Meat', 'Sample Garlic Prawns', 'Sample Dough Boss Wrap', 'Sample Ultimate Chicken']) {
  assert.ok(responsiveItems.some(item => item.name === name), `new responsive media reaches the preview: ${name}`);
}
assert.ok(responsiveItems.some(item => item.name === 'Spinach Pie'), 'new photograph coverage reaches the preview');
for (const item of responsiveItems) {
  const data = item.image_responsive;
  assert.equal(data.original, manifest.base_path + item.image, 'preview candidates bind to the selected original');
  localReference(data.original, 'paused.html');
  assert.ok(Number.isInteger(data.width) && data.width > 0 && Number.isInteger(data.height) && data.height > 0);
  assert.deepEqual(data.sources.map(source => source.type), ['image/avif', 'image/webp']);
  for (const source of data.sources) validateSrcset(source.srcset, 'paused.html');
}
for (const page of pages) {
  const html = fs.readFileSync(path.join(output, page), 'utf8');
  assert.match(html, /name="robots" content="noindex,\s*nofollow(?:,[a-z]+)*"/);
  assert.match(html, /Content-Security-Policy/);
  assert.match(html, /default-src (?:&#39;|')none(?:&#39;|')/);
  assert.match(html, /connect-src (?:&#39;|')none(?:&#39;|')/);
  assert.match(html, /form-action (?:&#39;|')none(?:&#39;|')/);
  assert.match(html, /media-src (?:&#39;|')self(?:&#39;|')(?:;|")/);
  assert.match(html, /script-src (?:&#39;|')self(?:&#39;|')(?:;|")/);
  assert.match(html, /style-src (?:&#39;|')self(?:&#39;|') (?:&#39;|')unsafe-inline(?:&#39;|')(?:;|")/);
  assert.match(html, /img-src (?:&#39;|')self(?:&#39;|') data:(?:;|")/);
  assert.match(html, /font-src (?:&#39;|')self(?:&#39;|')(?:;|")/);
  assert.match(html, /Sample menu and prices/);
  assert.doesNotMatch(html, /<(?:form|iframe|object|embed)\b|\bon[a-z]+\s*=/i);
  assert.doesNotMatch(html, /\bposter\s*=|http-equiv\s*=\s*["']?refresh/i, 'unsupported resource/navigation attributes');
  // This exporter emits only quoted, local width-descriptor picture sources.
  const srcsets = [...html.matchAll(/\bsrcset="([^"]+)"/g)];
  assert.equal((html.match(/\bsrcset\s*=/gi) || []).length, srcsets.length, 'no unsupported srcset quoting');
  for (const [, srcset] of srcsets) {
    if (heroIntegrated && page === 'index.html' && srcset === manifest.base_path + 'preview/media/doughboss-commercial-v6-desktop-square-poster.jpg') {
      localReference(srcset, page);
      continue;
    }
    validateSrcset(srcset, page);
  }
  if (!heroIntegrated) {
    assert.match(html, /Photo-only preview; approved film not included\./, 'omitted film visibly disclosed');
    assert.doesNotMatch(html, /data-db-opening-video|id="db-opening-home-v6"/);
  } else if (page === 'index.html') {
    assert.equal((html.match(/id="db-opening-home-v6"/g) || []).length, 1, 'one actual shared hero');
    assert.equal((html.match(/<video\b/g) || []).length, 1, 'one shared film, no duplicate');
    assert.match(html, /<body[^>]*class="[^"]*\bhome\b/);
    assert.match(html, /data-db-opening-video/);
    assert.match(html, /data-db-opening-src="[^"]*preview\/media\/doughboss-commercial-v3-web-loop\.mp4"/);
    assert.match(html, /data-db-opening-desktop-src="[^"]*preview\/media\/doughboss-commercial-v3-web-loop\.mp4"/);
    assert.match(html, /muted playsinline preload="none"/);
    for (const control of ['opening-control', 'sound-control', 'steam-control', 'motion-control']) assert.match(html, new RegExp('data-db-' + control));
    assert.doesNotMatch(html, /data-db-manoush-variant="home"|v3-motion-subtle-audio\.mp4/);
    for (const relative of heroScripts.keys()) assert.ok(html.includes('src="' + manifest.base_path + relative + '"'), 'shared controller loaded on homepage');
  } else {
    assert.doesNotMatch(html, /data-db-opening-video|id="db-opening-home-v6"/);
  }
  if (page === 'catering.html') assert.match(html, /data-db-manoush-variant="catering"/, 'catering retains real photo shortcode');
  assert.doesNotMatch(html, /class="[^"]*\bdb-builder\b/);
  if (page === 'cart.html') {
    assert.match(html, /data-db-preview-cart="synthetic"/);
    assert.equal((html.match(/data-doughboss-cart/g) || []).length, 1, 'one genuine cart host in explicit synthetic page');
    assert.match(html, /Sample cart and checkout layout only/);
    assert.match(html, /Quantity, voucher, order and payment actions are disabled/);
  } else {
    assert.doesNotMatch(html, /data-db-preview-cart|data-doughboss-cart|class="[^"]*\bdb-cart\b/);
  }
  for (const script of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    assert.match(script[1], /\bsrc="[^"]+"/);
    assert.equal(script[2].trim(), '', 'no inline scripts');
  }
  for (const match of html.matchAll(/\b(?:src|href)="([^"]+)"/g)) localReference(match[1], page);
  if (page === 'order.html' || page === 'paused.html' || page === 'cart.html') {
    assert.match(html, /<body[^>]*class="[^"]*\bdoughboss-order-page\b/);
  } else {
    assert.doesNotMatch(html, /<body[^>]*class="[^"]*\bdoughboss-order-page\b/);
  }
  if (page === 'partners.html' || page === 'wholesale.html') {
    const hero = html.match(/<section class="dbf-page-hero">([\s\S]*?)<div class="dbf-wrap dbf-page-hero-inner">/);
    assert.ok(hero, 'partner pages retain the shared hero');
    assert.match(hero[1], /<picture\b/);
    assert.match(hero[1], /<source type="image\/avif"/);
    assert.match(hero[1], /<source type="image\/webp"/);
    assert.match(hero[1], /src="[^"]*doughboss-feast-real-v1\.jpg"/);
    assert.match(hero[1], /width="1080"/);
    assert.match(hero[1], /height="864"/);
    assert.match(hero[1], /fetchpriority="high"/);
    assert.doesNotMatch(hero[1], /menu\/zaatar\.webp/);
    assert.match(html, page === 'wholesale.html' ? /Bring Dough Boss to your customers\./ : /Grow with a bakery people remember\./);
  }
}
for (const relative of actual.filter(file => file.endsWith('.css'))) {
  const css = fs.readFileSync(path.join(output, relative), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  assert.doesNotMatch(css, /@import\b/i, 'CSS imports are not part of this self-contained export');
  for (const match of css.matchAll(/url\(\s*['"]?([^)'"\s]+)['"]?\s*\)/g)) localReference(match[1], relative);
}
console.log(`Preview validated: ${pages.length} pages, ${actual.length} files; ${manifest.hero_integration}; exact source hashes, local assets and read-only boundaries.`);
