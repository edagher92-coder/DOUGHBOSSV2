'use strict';
/*
 * WP-01 node tests: the ES5 gate (scripts/es5-check.mjs) with planted-violation negative controls, and the PHP 7.4
 * guard command line (when a php binary is available). Dependency-free: node:test and node:assert only.
 *
 * The gate needs acorn. It is resolved from $ACORN_PATH (CI installs it in a scratch directory) or, on a
 * developer machine, from the repository's web/node_modules (read only). When neither exists the acorn-dependent
 * tests are SKIPPED with a visible reason, never passed.
 */
const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const PLUGIN_DIR = path.resolve(__dirname, '..');
const GATE = path.join(PLUGIN_DIR, 'scripts', 'es5-check.mjs');
const GUARD = path.join(PLUGIN_DIR, 'scripts', 'php74-guard.php');

function acornAvailable() {
  const candidates = [];
  if (process.env.ACORN_PATH) {
    candidates.push(path.resolve(process.env.ACORN_PATH, 'package.json'));
  }
  candidates.push(path.resolve(PLUGIN_DIR, '..', 'web', 'node_modules', 'acorn', 'package.json'));
  return candidates.some((candidate) => fs.existsSync(candidate));
}

const HAVE_ACORN = acornAvailable();
const SKIP_ACORN = HAVE_ACORN ? false : 'acorn not found (set ACORN_PATH to an acorn package directory)';

function scratch() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'dbgr-es5-'));
}

function writeFiles(root, files) {
  Object.keys(files).forEach((relative) => {
    const full = path.join(root, relative);
    fs.mkdirSync(path.dirname(full), { recursive: true });
    fs.writeFileSync(full, files[relative]);
  });
}

function runGate(args, options) {
  return spawnSync(process.execPath, [GATE].concat(args), Object.assign({ encoding: 'utf8' }, options || {}));
}

const GOOD = "(function () {\n  'use strict';\n  var items = [1, 2, 3];\n  function total(list) {\n    var sum = 0;\n    for (var i = 0; i < list.length; i += 1) { sum += list[i]; }\n    return sum;\n  }\n  document.getElementById('x').textContent = String(total(items));\n}());\n";

test('es5 gate: a house-style ES5 file passes (positive control)', { skip: SKIP_ACORN }, () => {
  const dir = scratch();
  writeFiles(dir, { 'public/js/dbgr-good.js': GOOD });
  const result = runGate([path.join(dir, 'public')]);
  assert.strictEqual(result.status, 0, result.stderr);
  assert.match(result.stdout, /1 file\(s\) checked, no violations/);
});

test('es5 gate: a file-level use strict without an IIFE is accepted', { skip: SKIP_ACORN }, () => {
  const dir = scratch();
  writeFiles(dir, { 'public/js/a.js': "'use strict';\nvar a = 1;\n" });
  assert.strictEqual(runGate([path.join(dir, 'public')]).status, 0);
});

const PLANTED = {
  'arrow function (the required negative control)': 'var f = (x) => x * 2;\n',
  'arrow function inside an IIFE': "(function () {\n 'use strict';\n var f = function () { return [1].map((x) => x); };\n}());\n",
  'let': "(function () {\n 'use strict';\n let a = 1;\n}());\n",
  'const': "(function () {\n 'use strict';\n const a = 1;\n}());\n",
  'template literal': "(function () {\n 'use strict';\n var s = `hello`;\n}());\n",
  'class': "(function () {\n 'use strict';\n class A {}\n}());\n",
  'destructuring': "(function () {\n 'use strict';\n var o = { a: 1 }; var { a } = o;\n}());\n",
  'default parameter': "(function () {\n 'use strict';\n function f(a = 1) { return a; }\n}());\n",
  'spread': "(function () {\n 'use strict';\n var a = [1]; var b = [...a];\n}());\n",
  'import statement': "import x from './x.js';\n",
  'async function': "(function () {\n 'use strict';\n async function f() {}\n}());\n",
  'exponent operator': "(function () {\n 'use strict';\n var a = 2 ** 3;\n}());\n",
  'optional chaining': "(function () {\n 'use strict';\n var a = {}; var b = a?.b;\n}());\n",
};

Object.keys(PLANTED).forEach((label) => {
  test('es5 gate NEGATIVE control: ' + label + ' is rejected with file and line', { skip: SKIP_ACORN }, () => {
    const dir = scratch();
    writeFiles(dir, { 'public/js/dbgr-bad.js': PLANTED[label] });
    const result = runGate([path.join(dir, 'public')]);
    assert.strictEqual(result.status, 1, 'expected exit 1, stderr: ' + result.stderr);
    assert.match(result.stderr, /dbgr-bad\.js:\d+ /);
    assert.match(result.stderr, /FAIL: \d+ ES5 violation/);
  });
});

test('es5 gate: the house shape is enforced (use strict, single IIFE)', { skip: SKIP_ACORN }, () => {
  const dir = scratch();
  writeFiles(dir, {
    'public/js/no-strict.js': '(function () { var a = 1; }());\n',
    'public/js/loose.js': 'var a = 1;\n',
    'public/js/two-blocks.js': "(function () { 'use strict'; }());\nvar leak = 1;\n",
    'public/js/empty.js': '\n',
  });
  const result = runGate([path.join(dir, 'public')]);
  assert.strictEqual(result.status, 1);
  assert.match(result.stderr, /no-strict\.js:1 must be an IIFE/);
  assert.match(result.stderr, /loose\.js:1 must be an IIFE/);
  assert.match(result.stderr, /two-blocks\.js:2 code outside the IIFE/);
  assert.match(result.stderr, /empty\.js:1 empty file/);
});

test('es5 gate: dynamic markup and code-evaluation APIs are refused; strings and comments are not', { skip: SKIP_ACORN }, () => {
  const wrap = (body) => "(function () {\n 'use strict';\n" + body + "\n}());\n";
  const dir = scratch();
  writeFiles(dir, {
    'public/js/inner.js': wrap('var e = document.body; e.innerHTML = "x";'),
    'public/js/outer.js': wrap('var e = document.body; var h = e.outerHTML;'),
    'public/js/adjacent.js': wrap('document.body.insertAdjacentHTML("beforeend", "<b></b>");'),
    'public/js/write.js': wrap('document.write("x");'),
    'public/js/eval.js': wrap('eval("1");'),
    'public/js/newfn.js': wrap('var f = new Function("return 1");'),
    'public/js/fine.js': wrap('// el.innerHTML is banned, so this comment must not trip the gate\n var note = "use textContent, never innerHTML or document.write(x) or eval(y)"; var o = { evaluate: 1, written: 2 };'),
  });
  const result = runGate([path.join(dir, 'public')]);
  assert.strictEqual(result.status, 1);
  ['inner', 'outer', 'adjacent', 'write', 'eval', 'newfn'].forEach((name) => {
    assert.match(result.stderr, new RegExp(name + '\\.js:\\d+ '), name + '.js should be flagged');
  });
  assert.doesNotMatch(result.stderr, /fine\.js/, 'comments and strings must not be flagged');
  assert.match(result.stderr, /6 ES5 violation\(s\) in 7 file\(s\)/);
});

test('es5 gate: public/vendor is skipped by path, the same code in public/js is not', { skip: SKIP_ACORN }, () => {
  const dir = scratch();
  writeFiles(dir, {
    'public/vendor/generated.js': 'export const x = (y) => y;\n',
    'public/vendor/deep/more.js': 'const z = `t`;\n',
    'public/js/good.js': GOOD,
  });
  const skipped = runGate([path.join(dir, 'public')]);
  assert.strictEqual(skipped.status, 0, skipped.stderr);
  assert.match(skipped.stdout, /1 file\(s\) checked/, 'only the hand-written file was checked');
  writeFiles(dir, { 'public/js/arrow.js': 'var f = (x) => x;\n' });
  const control = runGate([path.join(dir, 'public')]);
  assert.strictEqual(control.status, 1, 'control: the same arrow function outside vendor fails');
});

test('es5 gate: a .mjs file under the scanned tree is refused (nosniff on the live host)', { skip: SKIP_ACORN }, () => {
  const dir = scratch();
  writeFiles(dir, { 'public/js/module.mjs': "export var a = 1;\n" });
  const result = runGate([path.join(dir, 'public')]);
  assert.strictEqual(result.status, 1);
  assert.match(result.stderr, /module\.mjs:1 a \.mjs file is not allowed/);
});

test('es5 gate: usage errors exit 2 and a missing default public directory is not an error', { skip: SKIP_ACORN }, () => {
  assert.strictEqual(runGate(['--nope']).status, 2, 'unknown option');
  const missing = runGate([path.join(os.tmpdir(), 'dbgr-definitely-missing-' + process.pid)]);
  assert.strictEqual(missing.status, 2, 'an explicit missing path is an error');
  const real = runGate([]);
  assert.strictEqual(real.status, 0, 'the real plugin public/ directory passes (or is absent): ' + real.stderr);
  const help = runGate(['--help']);
  assert.strictEqual(help.status, 0);
  assert.match(help.stdout, /Usage:/);
});

test('es5 gate: fails with exit 2 (never a silent pass) when acorn cannot be found', () => {
  const dir = scratch();
  fs.mkdirSync(path.join(dir, 'scripts'), { recursive: true });
  fs.copyFileSync(GATE, path.join(dir, 'scripts', 'es5-check.mjs'));
  writeFiles(dir, { 'public/js/a.js': GOOD });
  const result = spawnSync(process.execPath, [path.join(dir, 'scripts', 'es5-check.mjs'), path.join(dir, 'public')], {
    encoding: 'utf8',
    cwd: dir,
    env: Object.assign({}, process.env, { ACORN_PATH: path.join(dir, 'no-acorn-here') }),
  });
  assert.strictEqual(result.status, 2, result.stderr + result.stdout);
  assert.match(result.stderr, /acorn was not found/);
});

test('es5 gate: real front-end JavaScript shipped so far (public/js) passes the gate', { skip: SKIP_ACORN }, () => {
  const publicDir = path.join(PLUGIN_DIR, 'public');
  if (!fs.existsSync(publicDir)) {
    return; // No hand-written front-end JavaScript has been added yet.
  }
  const result = runGate([publicDir]);
  assert.strictEqual(result.status, 0, result.stderr);
});

function phpAvailable() {
  const probe = spawnSync('php', ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' });
  return probe.status === 0;
}
const SKIP_PHP = phpAvailable() ? false : 'php binary not available';

test('php74 guard CLI: exits 1 on a planted PHP 8 construct, 0 on clean code, 0 on the plugin itself', { skip: SKIP_PHP }, () => {
  const dir = scratch();
  writeFiles(dir, {
    'clean.php': "<?php\nfinal class Clean { public $a = 1; public function f( array $x = array() ) { return strpos( 'abc', 'b' ); } }\n",
    'planted/bad.php': "<?php\n$ok = str_contains( 'abc', 'b' );\n",
  });
  const bad = spawnSync('php', [GUARD, path.join(dir, 'planted')], { encoding: 'utf8' });
  assert.strictEqual(bad.status, 1, bad.stdout + bad.stderr);
  assert.match(bad.stderr, /bad\.php:2 \[php8_function\] str_contains\(\) needs PHP 8/);
  const clean = spawnSync('php', [GUARD, path.join(dir, 'clean.php')], { encoding: 'utf8' });
  assert.strictEqual(clean.status, 0, clean.stderr);
  const plugin = spawnSync('php', [GUARD, PLUGIN_DIR], { encoding: 'utf8' });
  assert.strictEqual(plugin.status, 0, plugin.stderr);
});

// Built from pieces so this file never contains the words it forbids.
const CANCELLED = new RegExp('he' + 'ro|web' + 'gl|\\.g' + 'lb$', 'i');

test('scope: the companion contains no .mjs under public/ and no cancelled hero or media artefacts', () => {
  const publicDir = path.join(PLUGIN_DIR, 'public');
  const found = [];
  (function walk(dir) {
    if (!fs.existsSync(dir)) {
      return;
    }
    fs.readdirSync(dir).forEach((name) => {
      const full = path.join(dir, name);
      if (fs.statSync(full).isDirectory()) {
        walk(full);
      } else if (/\.mjs$/i.test(name) || CANCELLED.test(name)) {
        found.push(full);
      }
    });
  }(publicDir));
  assert.deepStrictEqual(found, []);
  assert.ok(!fs.existsSync(path.resolve(PLUGIN_DIR, '..', 'doughboss-growth' + '-media')), 'the media plugin directory must not exist');
});
