'use strict';

// Dependency-free release check. Never starts a server or sends a request.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const vm = require('node:vm');

const output = path.resolve(process.argv[2] || '_site');
const digest = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const manifest = JSON.parse(fs.readFileSync(path.join(output, 'manifest.json'), 'utf8'));
const pages = ['index.html', 'order.html', 'paused.html', 'partners.html', 'wholesale.html'];
const generated = [...pages, 'preview/menu-options.js'];
for (const template of ['page-franchising.php', 'page-wholesale.php', 'template-parts/partner-page.php']) {
	assert.ok(manifest.source_sha256['themes/doughboss-final/' + template], 'partner template provenance is required');
}
assert.equal(manifest.preview_only, true);
assert.deepEqual(manifest.pages, pages);
// This is an immutable exported snapshot, not source from this publication branch.
// The full source validator passed in the canonical checkout before export.
assert.equal(digest(path.join(output, 'manifest.json')), 'a65648ea334d0f44e4c993f24499a85f403ae529af2dcdf41ae3f541e3fb0a6d');
assert.equal(manifest.source_commit, 'd35264f6606ef3759ab8a7656a7d4c53f7311ee4');
assert.ok(manifest.source_sha256['scripts/build-visual-preview.php'], 'builder provenance required');
assert.ok(manifest.source_sha256['includes/class-doughboss-post-types.php'], 'post types provenance required');

function safeRelative(relative) {
  assert.match(relative, /^[a-zA-Z0-9_./-]+$/);
  assert.ok(!relative.startsWith('/') && !relative.split('/').includes('..'));
}
for (const [relative, hash] of Object.entries(manifest.source_sha256)) {
  safeRelative(relative);
  assert.match(hash, /^[a-f0-9]{64}$/);
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
  assert.match(relative, /\.(?:html|js|css|woff2|jpg|webp|avif)$/);
  assert.ok(!/(?:^|\/)(?:docs|admin|includes|scripts|tests|\.git)(?:\/|$)/.test(relative));
  assert.equal(digest(path.join(output, relative)), hash, `output hash: ${relative}`);
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
assert.equal(responsiveItems.length, 3, 'preview covers responsive menu originals and unsupported fallbacks');
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
    validateSrcset(srcset, page);
  }
  assert.doesNotMatch(html, /class="[^"]*\b(?:db-cart|db-builder)\b/);
  for (const script of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    assert.match(script[1], /\bsrc="[^"]+"/);
    assert.equal(script[2].trim(), '', 'no inline scripts');
  }
  for (const match of html.matchAll(/\b(?:src|href)="([^"]+)"/g)) localReference(match[1], page);
  if (page === 'order.html' || page === 'paused.html') {
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
async function validatePreviewLocations() {
  const adapter = fs.readFileSync(path.join(output, 'preview/preview-api.js'), 'utf8');
  for (const paused of [false, true]) {
    const previewWindow = { DoughBossPreviewOptions: fixture };
    const previewDocument = {
      currentScript: { src: 'https://preview.example/DOUGHBOSSV2/preview/preview-api.js' },
      baseURI: 'https://preview.example/DOUGHBOSSV2/index.html',
      body: { classList: { contains: name => name === 'dbf-ordering-paused' && paused } },
      addEventListener: () => {}
    };
    vm.runInNewContext(adapter, { window: previewWindow, document: previewDocument, URL, Response }, { timeout: 1000 });
    const locationResponse = await previewWindow.fetch('https://preview.invalid/doughboss/v1/locations');
    assert.equal(locationResponse.status, 200);
    const locations = await locationResponse.json();
    assert.deepEqual(locations.map(location => [location.id, location.name, location.slug]), [
      [901, 'Sample Revesby', 'sample-revesby'],
      [902, 'Sample Bankstown', 'sample-bankstown'],
      [903, 'Sample Roselands', 'sample-roselands']
    ], 'all three distinct sample pickup shops must reach the exported adapter');
    for (const location of locations) {
      assert.equal(location.pickup_enabled, true);
      assert.equal(location.delivery_enabled, false);
      assert.equal(location.address, 'Sample address — preview only');
      assert.equal(location.timezone, 'Australia/Sydney');
    }
    const config = await (await previewWindow.fetch('https://preview.invalid/doughboss/v1/config')).json();
    assert.equal(config.ordering_open, !paused);
    assert.equal(config.payments_enabled, false);
    assert.equal(previewWindow.DoughBossData.payments.enabled, false);
    assert.equal((await previewWindow.fetch('https://preview.invalid/doughboss/v1/locations', { method: 'POST' })).status, 405);
    assert.equal((await previewWindow.fetch('https://doughboss.com.au/wp-json/doughboss/v1/locations')).status, 403);
  }
}

validatePreviewLocations().then(() => {
  console.log(`Snapshot validated: ${pages.length} pages, ${actual.length} files; pinned provenance, exact output hashes, local assets, three sample pickup shops and read-only boundaries.`);
}).catch(error => {
  console.error(error);
  process.exitCode = 1;
});
