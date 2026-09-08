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
const pages = ['index.html', 'order.html', 'paused.html'];
const generated = [...pages, 'preview/menu-options.js'];
assert.equal(manifest.preview_only, true);
assert.deepEqual(manifest.pages, pages);
assert.equal(manifest.source_commit, execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim());
assert.ok(manifest.source_sha256['scripts/build-visual-preview.php'], 'builder provenance required');

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
  assert.match(relative, /\.(?:html|js|css|woff2|jpg|webp)$/);
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
  assert.doesNotMatch(html, /\b(?:srcset|poster)\s*=|http-equiv\s*=\s*["']?refresh/i, 'unsupported resource/navigation attributes');
  assert.doesNotMatch(html, /class="[^"]*\b(?:db-cart|db-builder)\b/);
  for (const script of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    assert.match(script[1], /\bsrc="[^"]+"/);
    assert.equal(script[2].trim(), '', 'no inline scripts');
  }
  for (const match of html.matchAll(/\b(?:src|href)="([^"]+)"/g)) localReference(match[1], page);
  if (page !== 'index.html') assert.match(html, /<body[^>]*class="[^"]*\bdoughboss-order-page\b/);
}
for (const relative of actual.filter(file => file.endsWith('.css'))) {
  const css = fs.readFileSync(path.join(output, relative), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  assert.doesNotMatch(css, /@import\b/i, 'CSS imports are not part of this self-contained export');
  for (const match of css.matchAll(/url\(\s*['"]?([^)'"\s]+)['"]?\s*\)/g)) localReference(match[1], relative);
}
console.log(`Preview validated: ${pages.length} pages, ${actual.length} files; exact source hashes, local assets and read-only boundaries.`);
