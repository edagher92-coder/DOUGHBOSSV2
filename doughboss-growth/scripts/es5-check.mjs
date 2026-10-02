#!/usr/bin/env node
/**
 * ES5 gate for the hand-written front-end JavaScript of the DoughBoss Growth companion.
 *
 * Every file is parsed with acorn at ecmaVersion 5 (so arrow functions, let/const, template literals,
 * classes, destructuring, spread, default parameters and modules are syntax errors) and must follow the
 * house shape: one IIFE whose body starts with 'use strict' (or a file-level 'use strict'). Dynamic markup
 * APIs are refused: innerHTML, outerHTML, insertAdjacentHTML, document.write, eval and new Function. Use
 * textContent and DOM calls instead. A .mjs file under the scanned tree is refused (the live host sends
 * X-Content-Type-Options: nosniff, so module scripts must not be shipped).
 *
 * Directories named "vendor" and "node_modules" are skipped (generated or third-party code is not
 * hand-written and is excluded by path).
 *
 * Usage:
 *   node scripts/es5-check.mjs [--quiet] [path ...]      default path: public (next to this scripts directory)
 *
 * acorn is resolved, in order, from: $ACORN_PATH (the acorn package directory or its dist file), a normal
 * resolve from this script or the current directory, then /home/user/DOUGHBOSSV2/web/node_modules via the
 * repository layout (../../web/node_modules/acorn, read only). Nothing is installed by this script.
 *
 * Exit codes: 0 clean, 1 violations, 2 usage error or acorn not found.
 */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SKIP_DIRS = new Set(['vendor', 'node_modules', '.git']);
const BANNED_MEMBERS = new Set(['innerHTML', 'outerHTML', 'insertAdjacentHTML']);

/** Load acorn from the first location that has it. */
export async function loadAcorn() {
  const candidates = [];
  if (process.env.ACORN_PATH) {
    const given = path.resolve(process.env.ACORN_PATH);
    candidates.push(given);
    candidates.push(path.join(given, 'dist', 'acorn.mjs'));
    candidates.push(path.join(given, 'dist', 'acorn.js'));
  }
  const requireFromHere = createRequire(import.meta.url);
  const requireFromCwd = createRequire(path.join(process.cwd(), 'noop.js'));
  for (const req of [requireFromHere, requireFromCwd]) {
    try {
      candidates.push(req.resolve('acorn'));
    } catch (e) {
      /* try the next location */
    }
  }
  candidates.push(path.resolve(HERE, '..', '..', 'web', 'node_modules', 'acorn', 'dist', 'acorn.mjs'));
  for (const candidate of candidates) {
    try {
      if (!fs.statSync(candidate).isFile()) {
        continue;
      }
      const mod = await import(pathToFileURL(candidate).href);
      const api = mod.parse ? mod : mod.default;
      if (api && typeof api.parse === 'function' && typeof api.tokenizer === 'function') {
        return api;
      }
    } catch (e) {
      /* try the next location */
    }
  }
  return null;
}

/** Recursively collect .js and .mjs files, skipping vendor directories. */
export function collectFiles(target, out = []) {
  let stat;
  try {
    stat = fs.lstatSync(target);
  } catch (e) {
    return out;
  }
  if (stat.isSymbolicLink()) {
    return out;
  }
  if (stat.isFile()) {
    if (/\.m?js$/i.test(target)) {
      out.push(target);
    }
    return out;
  }
  if (!stat.isDirectory()) {
    return out;
  }
  for (const name of fs.readdirSync(target).sort()) {
    if (stat.isDirectory() && SKIP_DIRS.has(name)) {
      continue;
    }
    collectFiles(path.join(target, name), out);
  }
  return out;
}

/** The first statement, unwrapped when it is an IIFE; returns the function body or null. */
function iifeBody(statement) {
  if (!statement || statement.type !== 'ExpressionStatement') {
    return null;
  }
  let expr = statement.expression;
  if (expr.type === 'UnaryExpression') {
    expr = expr.argument;
  }
  if (expr.type !== 'CallExpression') {
    return null;
  }
  const callee = expr.callee;
  if (callee.type === 'FunctionExpression') {
    return callee.body.body;
  }
  return null;
}

function isUseStrict(statement) {
  return !!statement
    && statement.type === 'ExpressionStatement'
    && statement.expression.type === 'Literal'
    && statement.expression.value === 'use strict';
}

/**
 * Check one file's source. Returns a list of { line, message }.
 */
export function checkSource(acorn, source, file = 'input.js') {
  const problems = [];
  if (/\.mjs$/i.test(file)) {
    problems.push({ line: 1, message: 'a .mjs file is not allowed (module scripts are blocked by nosniff on the live host)' });
    return problems;
  }
  let ast;
  try {
    ast = acorn.parse(source, { ecmaVersion: 5, sourceType: 'script', locations: true, allowHashBang: false });
  } catch (error) {
    const line = error && error.loc ? error.loc.line : 1;
    problems.push({ line, message: 'not valid ES5: ' + (error && error.message ? error.message : String(error)) });
    return problems;
  }

  // House shape: an IIFE whose body starts with 'use strict', or a file-level 'use strict'.
  const body = ast.body;
  const first = body.length > 0 ? body[0] : null;
  if (!first) {
    problems.push({ line: 1, message: 'empty file' });
  } else if (!isUseStrict(first)) {
    const inner = iifeBody(first);
    if (!inner || !isUseStrict(inner[0])) {
      problems.push({ line: first.loc.start.line, message: "must be an IIFE whose first statement is 'use strict' (or start with 'use strict')" });
    } else if (body.length > 1) {
      problems.push({ line: body[1].loc.start.line, message: 'code outside the IIFE: the file must be one IIFE' });
    }
  }

  // Banned APIs, found through the token stream so that comments and strings cannot trip them.
  let previous = null;
  let beforePrevious = null;
  for (const token of acorn.tokenizer(source, { ecmaVersion: 5, locations: true })) {
    const name = token.value;
    const isName = token.type.label === 'name';
    const afterDot = previous && previous.type.label === '.';
    if (isName && afterDot && BANNED_MEMBERS.has(name)) {
      problems.push({ line: token.loc.start.line, message: '.' + name + ' is not allowed: build DOM nodes and use textContent' });
    }
    if (isName && afterDot && name === 'write' && beforePrevious && beforePrevious.value === 'document') {
      problems.push({ line: token.loc.start.line, message: 'document.write is not allowed' });
    }
    if (isName && name === 'eval' && !afterDot) {
      problems.push({ line: token.loc.start.line, message: 'eval is not allowed' });
    }
    if (isName && name === 'Function' && previous && previous.type.keyword === 'new') {
      problems.push({ line: token.loc.start.line, message: 'new Function is not allowed' });
    }
    beforePrevious = previous;
    previous = token;
  }
  return problems;
}

export async function main(argv) {
  const args = argv.slice(2);
  let quiet = false;
  const targets = [];
  for (const arg of args) {
    if (arg === '--quiet') {
      quiet = true;
    } else if (arg === '-h' || arg === '--help') {
      process.stdout.write('Usage: node scripts/es5-check.mjs [--quiet] [path ...]\n');
      return 0;
    } else if (arg.startsWith('--')) {
      process.stderr.write('Unknown option: ' + arg + '\n');
      return 2;
    } else {
      targets.push(arg);
    }
  }
  if (targets.length === 0) {
    targets.push(path.resolve(HERE, '..', 'public'));
  }
  const acorn = await loadAcorn();
  if (!acorn) {
    process.stderr.write('ERROR: acorn was not found. Set ACORN_PATH to an acorn package directory (CI installs it in a scratch directory).\n');
    return 2;
  }
  const files = [];
  for (const target of targets) {
    if (!fs.existsSync(target)) {
      if (target === targets[0] && targets.length === 1 && path.basename(target) === 'public') {
        continue; // No public directory yet: nothing to check.
      }
      process.stderr.write('ERROR: path does not exist: ' + target + '\n');
      return 2;
    }
    collectFiles(target, files);
  }
  let failures = 0;
  for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    for (const problem of checkSource(acorn, source, file)) {
      failures++;
      process.stderr.write(file + ':' + problem.line + ' ' + problem.message + '\n');
    }
  }
  if (failures > 0) {
    process.stderr.write('FAIL: ' + failures + ' ES5 violation(s) in ' + files.length + ' file(s) checked.\n');
    return 1;
  }
  if (!quiet) {
    process.stdout.write('ES5 gate: ' + files.length + ' file(s) checked, no violations' + (files.length === 0 ? ' (no hand-written JavaScript yet)' : '') + '.\n');
  }
  return 0;
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main(process.argv).then((code) => process.exit(code), (error) => {
    process.stderr.write('ERROR: ' + (error && error.stack ? error.stack : String(error)) + '\n');
    process.exit(2);
  });
}
