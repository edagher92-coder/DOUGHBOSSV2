// Qwen as the storefront's coding partner. The lead (Claude) writes each brief and its oracle, the local model
// writes the change as SEARCH/REPLACE edits, and this loop applies them, runs the oracle plus the full storefront
// suites, feeds failures back and logs every attempt. Nothing is committed here: a passing change is left in the
// working tree for the lead to review; a failing one is put back byte for byte.
//
// Adapted from edagher92-coder/-UnifiedBusinessOS tools/qwen-task.mjs + tools/model-trial.mjs (same author, same
// guard rails), cut down to edit-in-place tasks on this repository.
//
//   node ops/qwen/qwen-site-task.mjs phone-helpers shop-contact-phone      named tasks, in order
//   node ops/qwen/qwen-site-task.mjs --all                                 every task not marked done
//   options: --endpoint http://127.0.0.1:8090/v1  --model qwen3.8-27b:code  --key-env LOCAL_QWEN_KEY  --attempts 4
//
// A task (ops/qwen/tasks/<id>.json) is { id, title, target, functions, newFunctions?, brief, context, oracle, attempts? }.
// Guard rails, enforced before and after every model call:
// - The NUMBERS RULE: no task may target a payment, checkout, cart, price, quote, voucher, catering, refund or order
//   function, file or test. A local model never writes code that decides a customer-facing number or a payment path.
// - The changed file must parse (acorn). Everything outside the task's named functions (and the new functions it
//   names), cut at their exact parsed ranges, must stay the same token for token; each named function must be
//   declared exactly once. Anything else is refused and the file restored, even when the tests pass.
// - The oracle and the regression suites run in a child `node --permission` process that may READ only the tracked
//   source folders (READ_DIRS; the run refuses to start if untracked or ignored files sit in them), with an empty
//   environment (no keys) and a 60 s limit. Network AND host unix sockets are cut where a probe proves it (see
//   networkIsolation); each attempt logs whether they were. Oracles are written by the lead, never by the model.
// - Logs record timings, token counts, pass/fail and test names. Never the API key.
import { spawn, spawnSync } from 'node:child_process';
import { appendFileSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import * as acorn from './vendor/acorn/acorn.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const TASKS = join(ROOT, 'ops/qwen/tasks');
const LOGS = join(ROOT, 'ops/qwen/log');
const REGRESSION = () => readdirSync(join(ROOT, 'tests')).filter(f => f.endsWith('.test.js')).sort().map(f => `tests/${f}`);
export const READ_DIRS = ['public', 'tests', 'themes', 'includes', 'scripts']; // everything the storefront suites read

// Storefront JavaScript only: the edit-scope guard below understands JavaScript functions, nothing else.
export const TARGETS = /^public\/js\/doughboss[a-z0-9-]*\.js$/;
export const STAKES = /pay|checkout|cart|price|money|total|tax|quote|voucher|catering|refund|order|square|stripe|tyro|pospal|invoice/i;
const ID = /^[a-z0-9-]{2,40}$/;
const FN = /^[A-Za-z_$][A-Za-z0-9_$]{0,60}$/;

export function checkTask(task) {
  const problems = [];
  if (!task || typeof task.id !== 'string' || !ID.test(task.id)) return ['id'];
  if (typeof task.target !== 'string' || !TARGETS.test(task.target)) problems.push('target is not an allowed storefront file');
  if (STAKES.test(task.target ?? '')) problems.push('target is a stakes file');
  const fns = [...(task.functions ?? []), ...(task.newFunctions ?? [])];
  if (fns.length === 0) problems.push('name the functions the change may touch');
  for (const fn of fns) {
    if (typeof fn !== 'string' || !FN.test(fn)) problems.push(`function name ${JSON.stringify(fn)}`);
    else if (STAKES.test(fn)) problems.push(`function ${fn} is stakes code`);
  }
  if (!/^ops\/qwen\/tasks\/[a-z0-9-]+\.md$/.test(task.brief ?? '')) problems.push('brief');
  if (!/^tests\/qwen\/[a-z0-9-]+\.oracle\.js$/.test(task.oracle ?? '')) problems.push('oracle');
  if (STAKES.test(task.oracle ?? '')) problems.push('oracle is a stakes test');
  for (const ctx of task.context ?? []) {
    if (typeof ctx?.path !== 'string' || !/^(public|includes|themes|tests)\//.test(ctx.path) || ctx.path.includes('..')) problems.push('context path');
  }
  for (const p of [task.brief, task.oracle, task.target]) if (typeof p === 'string' && !existsSync(join(ROOT, p))) problems.push(`missing ${p}`);
  return problems;
}

// --- source helpers ---------------------------------------------------------------------------------------------

// Function ranges come from a real parse of the whole file (acorn, vendored at ops/qwen/vendor/acorn), never from
// counting braces: a hand-written scanner can be fooled by a quote or brace inside a regex or string, and then the
// guard and the engine disagree about where a function ends. A file that does not parse is refused outright.
export function parseScript(source) {
  return acorn.parse(source, { ecmaVersion: 'latest', sourceType: 'script', allowReturnOutsideFunction: false });
}

// Every FunctionDeclaration in the tree, by name: name -> [{ start, end }] (character offsets, end exclusive).
export function functionDeclarations(ast) {
  const found = new Map();
  const visit = node => {
    if (!node || typeof node.type !== 'string') return;
    if (node.type === 'FunctionDeclaration' && node.id) {
      if (!found.has(node.id.name)) found.set(node.id.name, []);
      found.get(node.id.name).push({ start: node.start, end: node.end });
    }
    for (const key of Object.keys(node)) {
      const value = node[key];
      if (Array.isArray(value)) value.forEach(visit);
      else if (value && typeof value === 'object' && typeof value.type === 'string') visit(value);
    }
  };
  visit(ast);
  return found;
}

function excerpt(path, functions, lines) {
  const source = readFileSync(join(ROOT, path), 'utf8').replace(/\r\n/g, '\n');
  const parts = [];
  const decls = functions?.length ? functionDeclarations(parseScript(source)) : new Map();
  for (const name of functions ?? []) {
    const [range] = decls.get(name) ?? [];
    if (range) parts.push(`\t${source.slice(range.start, range.end)}`);
  }
  if (Array.isArray(lines)) parts.push(source.split('\n').slice(lines[0] - 1, lines[1]).join('\n'));
  return parts.join('\n\n');
}

// SEARCH/REPLACE blocks from the reply. <think> sections are dropped first.
export function parseEdits(text) {
  const answer = String(text).replace(/<think>[\s\S]*?<\/think>/g, '').replace(/\r\n/g, '\n');
  const edits = [];
  const re = /<<<<<<< SEARCH[^\n]*\n([\s\S]*?)\n?=======\n([\s\S]*?)\n?>>>>>>> REPLACE/g;
  let m;
  while ((m = re.exec(answer))) edits.push({ search: m[1], replace: m[2] });
  return edits;
}

const norm = line => line.replace(/\r$/, '').replace(/\t/g, '    ').replace(/\s+$/, '');

// Applies one edit. Exact match first; then a whitespace-tolerant line match (tabs vs spaces, trailing space, CRLF),
// re-indenting the replacement with tabs when the file does. The search text must match exactly once.
export function applyEdit(source, { search, replace }) {
  if (!search.trim()) return { error: 'an empty SEARCH section (to insert, SEARCH for the line you insert before and repeat it)' };
  const eol = source.includes('\r\n') ? null : '\n';
  const exact = source.split(search).length - 1;
  if (exact === 1) return { source: source.replace(search, () => replace) };
  if (exact > 1) return { error: `SEARCH text matches ${exact} places; include more surrounding lines` };
  const lines = source.split('\n');
  const want = search.split('\n').map(norm);
  const hits = [];
  for (let i = 0; i + want.length <= lines.length; i += 1) {
    let ok = true;
    for (let j = 0; j < want.length && ok; j += 1) ok = norm(lines[i + j]) === want[j];
    if (ok) hits.push(i);
  }
  if (hits.length !== 1) return { error: hits.length ? `SEARCH text matches ${hits.length} places; include more surrounding lines` : 'SEARCH text not found in the file; copy it exactly from the excerpt' };
  const at = hits[0];
  const crlf = eol === null && lines[at].endsWith('\r');
  const tabs = lines.slice(at, at + want.length).some(l => l.startsWith('\t'));
  const out = replace.split('\n').map(l => {
    let line = tabs ? l.replace(/^( {4})+/, s => '\t'.repeat(s.length / 4)) : l;
    return crlf ? `${line}\r` : line;
  });
  lines.splice(at, want.length, ...out);
  return { source: lines.join('\n') };
}

// The file with the named functions cut out at their exact character ranges, and every whitespace run that holds a
// line break folded to one line break (so the only slack is the blank line between a new function and its
// neighbours; a change that adds or removes a line break between tokens still counts, because of ASI).
function remainder(source, ranges) {
  let out = '', at = 0;
  for (const { start, end } of [...ranges].sort((x, y) => x.start - y.start)) { out += source.slice(at, start); at = end; }
  return (out + source.slice(at)).replace(/[ \t]*\n\s*/g, '\n');
}

// Refuses any change outside the task's functions. The new file must parse; each named function must be declared
// exactly once; and the file with those functions cut out must be identical before and after.
export function outsideAllowed(task, before, after) {
  const fns = task.functions ?? [], fresh = task.newFunctions ?? [];
  const now = after.replace(/\r\n/g, '\n'), was = before.replace(/\r\n/g, '\n');
  let nowDecls;
  try { nowDecls = functionDeclarations(parseScript(now)); }
  catch (error) { return { missing: [], unparsable: [`the file (${error.message})`], changedElsewhere: false }; }
  const wasDecls = functionDeclarations(parseScript(was));
  const missing = [...fns, ...fresh].filter(fn => (nowDecls.get(fn) ?? []).length !== 1);
  if (missing.length) return { missing, unparsable: [], changedElsewhere: false };
  const cut = (decls, names) => names.flatMap(fn => decls.get(fn) ?? []);
  const changedElsewhere = remainder(was, cut(wasDecls, fns)) !== remainder(now, cut(nowDecls, [...fns, ...fresh]));
  return { missing, unparsable: [], changedElsewhere };
}

// --- running the oracle ------------------------------------------------------------------------------------------

// How model code is kept off the network, decided once per run and only from a probe that tries BOTH a TCP
// connection and a unix socket the runner itself opens:
// - 'node': this Node's permission model already refuses both.
// - 'namespaces' (Linux): `unshare -rnm` gives the child its own empty network namespace and mount namespace, and
//   tmpfs is mounted over /run, /tmp, /dev/shm and the temp folder, so host unix sockets (a Docker or desktop bus
//   socket, say) are not reachable either. `unshare -rn` alone was not enough: unix sockets live in the filesystem.
// - false: neither works (Windows, or Node < 25 on macOS). Printed at the start and logged on every attempt, so a
//   run without isolation is never mistaken for one with it.
const READ_FLAGS = () => READ_DIRS.map(d => `--allow-fs-read=${join(ROOT, d)}`);
const HIDE = ['/run', '/tmp', '/dev/shm'];

export function sandboxed(nodeArgs, mode) {
  if (mode !== 'namespaces') return [process.execPath, nodeArgs];
  const inside = (path, dir) => `${path}${sep}`.startsWith(`${dir}${sep}`);
  const dirs = [...new Set([...HIDE, tmpdir()].map(d => resolve(d)))].filter(d => !inside(ROOT, d) && !inside(process.execPath, d));
  const script = 'set -e; while [ "$1" != "--" ]; do if [ -d "$1" ] && [ ! -L "$1" ]; then mount -t tmpfs tmpfs "$1"; fi; shift; done; shift; exec "$@"';
  return ['unshare', ['-rnm', 'sh', '-c', script, 'sh', ...dirs, '--', process.execPath, ...nodeArgs]];
}

const BLOCKED = /^(ENETUNREACH|EHOSTUNREACH|ERR_ACCESS_DENIED|EACCES|EPERM|ENOENT)$/;
export async function networkIsolation() {
  const dir = mkdtempSync(join(tmpdir(), 'qwen-probe-'));
  const sock = join(dir, 'probe.sock');
  const server = createServer(c => c.destroy());
  try {
    await new Promise((ok, fail) => server.once('error', fail).listen(sock, ok));
    const probe = `const n=require('net');const t=o=>new Promise(r=>{const c=n.connect(o);c.setTimeout(3000,()=>{c.destroy();r('TIMEOUT')});c.on('connect',()=>{c.destroy();r('OPEN')});c.on('error',e=>r(e.code||'ERR'))});Promise.all([t({host:'1.1.1.1',port:443}),t({host:'127.0.0.1',port:9}),t({path:${JSON.stringify(sock)}})]).then(v=>{console.log('PROBE '+v.join(' '));process.exit(0)})`;
    const attempt = mode => new Promise(ok => {
      const [cmd, argv] = sandboxed(['--permission', '-e', probe], mode);
      const child = spawn(cmd, argv, { stdio: ['ignore', 'pipe', 'pipe'] });
      let out = '';
      child.stdout.on('data', d => { out += d; }); child.stderr.on('data', d => { out += d; });
      child.on('error', () => ok(null)); child.on('close', () => ok((/PROBE (.+)/.exec(out) ?? [])[1] ?? null));
    });
    const modes = process.platform === 'linux' ? ['node', 'namespaces'] : ['node'];
    for (const mode of modes) {
      const result = await attempt(mode);
      if (result && result.split(' ').every(code => BLOCKED.test(code))) return mode;
    }
    return false;
  } catch { return false; } finally { server.close(); rmSync(dir, { recursive: true, force: true }); }
}
let ISOLATION = null;

function runTest(file) {
  const env = { PATH: process.env.PATH ?? '' };
  if (process.env.SystemRoot) env.SystemRoot = process.env.SystemRoot;
  const [cmd, argv] = sandboxed(['--permission', ...READ_FLAGS(), file], ISOLATION);
  const r = spawnSync(cmd, argv, { cwd: ROOT, env, encoding: 'utf8', timeout: 60_000 });
  const out = `${r.stdout ?? ''}${r.stderr ?? ''}`;
  const num = key => Number((new RegExp(`^# ${key} (\\d+)`, 'm').exec(out) ?? [])[1] ?? 0);
  const failed = [...out.matchAll(/^not ok \d+ - (.+)$/gm)].map(m => m[1].trim());
  return { file, passed: num('pass'), failed, ok: r.status === 0 && failed.length === 0, timedOut: r.error?.code === 'ETIMEDOUT', output: out };
}

function digest(results) {
  return results.filter(r => !r.ok).map(r => {
    const blocks = r.output.split(/^(?=not ok \d+ - )/m).filter(b => b.startsWith('not ok')).slice(0, 6)
      .map(b => b.split('\n').slice(0, 14).join('\n'));
    return `${r.file}: ${r.timedOut ? 'timed out after 60 s' : `${r.failed.length} failed`}\n${blocks.join('\n') || r.output.slice(-1500)}`;
  }).join('\n\n').slice(0, 6000);
}

// --- the model ---------------------------------------------------------------------------------------------------

async function readChatStream(stream) {
  const decoder = new TextDecoder();
  let buffer = '', content = '', usage = null;
  const take = line => {
    const data = line.replace(/^data:\s?/, '').trim();
    if (!line.startsWith('data:') || !data || data === '[DONE]') return;
    let chunk; try { chunk = JSON.parse(data); } catch { return; }
    if (chunk.error) throw new Error(`stream error: ${chunk.error.message ?? JSON.stringify(chunk.error)}`);
    content += chunk.choices?.[0]?.delta?.content ?? '';
    if (chunk.usage) usage = chunk.usage;
  };
  for await (const part of stream) {
    buffer += decoder.decode(part, { stream: true });
    const lines = buffer.split(/\r?\n/); buffer = lines.pop(); lines.forEach(take);
  }
  buffer += decoder.decode(); if (buffer) take(buffer);
  return { content, usage };
}

async function chat({ endpoint, model, prompt, apiKey }) {
  const started = performance.now();
  const response = await fetch(`${endpoint.replace(/\/+$/, '')}/chat/completions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...(apiKey ? { Authorization: `Bearer ${apiKey}` } : {}) },
    body: JSON.stringify({ model, stream: true, stream_options: { include_usage: true }, temperature: 0, messages: [{ role: 'user', content: prompt }] }),
    signal: AbortSignal.timeout(900_000)
  });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  const { content, usage } = await readChatStream(response.body);
  return { text: content, seconds: (performance.now() - started) / 1000, outputTokens: usage?.completion_tokens ?? null };
}

function prompt(task, feedback) {
  const brief = readFileSync(join(ROOT, task.brief), 'utf8');
  const context = (task.context ?? []).map(c => `### ${c.path}${c.functions ? ` (${c.functions.join(', ')})` : ''}\n\`\`\`\n${excerpt(c.path, c.functions, c.lines)}\n\`\`\``).join('\n\n');
  return [
    `You are editing \`${task.target}\` in the DoughBoss WordPress plugin. ES5 browser JavaScript: \`var\`, \`function\`, no arrow functions, no \`let\`/\`const\`, no template strings. Tabs for indentation, like the excerpts.`,
    brief,
    '## Current code (excerpts; the rest of the file is not shown and must not change)',
    context,
    `## How to reply\nReply with SEARCH/REPLACE blocks only, in this exact format, one block per change:\n\n<<<<<<< SEARCH\n(lines copied exactly from the excerpt above)\n=======\n(the new lines)\n>>>>>>> REPLACE\n\nEach SEARCH must match exactly one place. To add a new function, SEARCH for the first line of the function you insert before and put that line again at the end of REPLACE. Change only the functions this brief names: ${[...(task.functions ?? []), ...(task.newFunctions ?? [])].join(', ')}. No explanation outside the blocks.`,
    feedback ? `## Your previous attempt failed. The file has been put back exactly as shown above; start again from it.\n${feedback}` : ''
  ].filter(Boolean).join('\n\n');
}

// --- engine lock (one Qwen job at a time; ELZYDLAB: QWEN_LOCK_SCRIPT=C:\AI\locks\qwen-lock.ps1) --------------------

function takeEngineLock(script = process.env.QWEN_LOCK_SCRIPT) {
  if (!script) return () => {};
  const ps = args => spawnSync('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', script, ...args], { stdio: 'inherit' });
  const got = ps(['acquire', '-Kind', 'runner', '-HolderPid', String(process.pid), '-WaitS', String(process.env.QWEN_WAIT_S || '1800'), '-Note', 'doughboss-site-task']);
  if (got.status !== 0) throw new Error(`Qwen engine busy (lock script exit ${got.status}); run again when the other job finishes`);
  let released = false;
  const release = () => { if (!released) { released = true; ps(['release', '-HolderPid', String(process.pid)]); } };
  process.once('exit', release);
  for (const s of ['SIGINT', 'SIGTERM']) process.once(s, () => { release(); process.exit(130); });
  return release;
}

// --- one task ----------------------------------------------------------------------------------------------------

async function runTask(task, opts, stamp) {
  const file = join(ROOT, task.target);
  const original = readFileSync(file, 'utf8');
  const tests = [task.oracle, ...REGRESSION()];
  let feedback = '';
  for (let attempt = 1; attempt <= (task.attempts ?? opts.attempts); attempt += 1) {
    const log = { ts: new Date().toISOString(), stamp, task: task.id, attempt, model: opts.model, networkIsolated: ISOLATION || false };
    let reply;
    try { reply = await chat({ endpoint: opts.endpoint, model: opts.model, prompt: prompt(task, feedback), apiKey: opts.apiKey }); }
    catch (error) { appendLog({ ...log, transport: String(error.message ?? error) }); feedback = ''; continue; }
    writeFileSync(join(LOGS, `${task.id}.${stamp}.attempt${attempt}.txt`), reply.text);
    Object.assign(log, { seconds: Math.round(reply.seconds), outputTokens: reply.outputTokens });
    const edits = parseEdits(reply.text);
    let source = original, problem = edits.length ? '' : 'The reply had no SEARCH/REPLACE blocks.';
    for (const [i, edit] of edits.entries()) {
      const r = applyEdit(source, edit);
      if (r.error) { problem = `Block ${i + 1}: ${r.error}.`; break; }
      source = r.source;
    }
    if (!problem && source === original) problem = 'The edits changed nothing.';
    if (!problem) {
      const { missing, unparsable, changedElsewhere } = outsideAllowed(task, original, source);
      const allowed = [...(task.functions ?? []), ...(task.newFunctions ?? [])].join(', ');
      if (missing.length) problem = `These functions are missing or defined more than once: ${missing.join(', ')}.`;
      else if (unparsable.length) problem = `The edited file does not parse: ${unparsable.join(', ')}. Check every brace, string and comment.`;
      else if (changedElsewhere) problem = `Code outside the functions you may touch (${allowed}) was changed. Change only those functions.`;
    }
    if (problem) { appendLog({ ...log, edits: edits.length, refused: problem }); feedback = problem; continue; }
    writeFileSync(file, source);
    const results = tests.map(runTest);
    const oracle = results[0];
    Object.assign(log, { edits: edits.length, oraclePassed: oracle.passed, oracleFailed: oracle.failed, regressionFailed: results.slice(1).filter(r => !r.ok).map(r => r.file) });
    if (results.every(r => r.ok)) { appendLog({ ...log, pass: true }); return { id: task.id, pass: true, attempt }; }
    writeFileSync(file, original);
    appendLog({ ...log, pass: false });
    feedback = digest(results);
  }
  writeFileSync(file, original);
  return { id: task.id, pass: false };
}

function appendLog(entry) {
  mkdirSync(LOGS, { recursive: true });
  appendFileSync(join(LOGS, 'attempts.jsonl'), `${JSON.stringify(entry)}\n`);
}

async function main() {
  const { values, positionals } = parseArgs({ allowPositionals: true, options: {
    all: { type: 'boolean', default: false },
    endpoint: { type: 'string', default: 'http://127.0.0.1:8090/v1' },
    model: { type: 'string', default: 'qwen3.8-27b:code' },
    'key-env': { type: 'string', default: 'LOCAL_QWEN_KEY' },
    attempts: { type: 'string', default: '4' }
  } });
  const ids = values.all
    ? readdirSync(TASKS).filter(f => f.endsWith('.json')).map(f => f.slice(0, -5)).sort((a, b) => order(a) - order(b))
      .filter(id => !JSON.parse(readFileSync(join(TASKS, `${id}.json`), 'utf8')).done)
    : positionals;
  if (!ids.length) throw new Error('name one or more tasks, or pass --all');
  const tasks = ids.map(id => { if (!ID.test(id)) throw new Error(`not a task name: ${id}`); return JSON.parse(readFileSync(join(TASKS, `${id}.json`), 'utf8')); });
  for (const t of tasks) { const p = checkTask(t); if (p.length) throw new Error(`task ${t.id}: ${p.join('; ')}`); }
  const dirty = spawnSync('git', ['status', '--porcelain', '--', ...new Set(tasks.map(t => t.target))], { cwd: ROOT, encoding: 'utf8' }).stdout.trim();
  if (dirty) throw new Error(`commit or stash local changes to the target files first:\n${dirty}`);
  // Model code can read READ_DIRS, so nothing that is not in the public repository may sit there (a token, a .env).
  const extra = spawnSync('git', ['status', '--porcelain', '--ignored', '--untracked-files=all', '--', ...READ_DIRS], { cwd: ROOT, encoding: 'utf8' })
    .stdout.split('\n').filter(l => /^(\?\?|!!) /.test(l));
  if (extra.length) throw new Error(`untracked or ignored files sit where model code can read them; move them out first:\n${extra.slice(0, 20).join('\n')}`);
  ISOLATION = await networkIsolation();
  console.log(ISOLATION ? `Model code runs with no network and no host sockets (${ISOLATION}, probe-verified).` : 'WARNING: this platform cannot cut the network or host sockets for model code (a probe reached one). Reads are limited to the tracked source folders; every attempt logs networkIsolated:false.');
  const apiKey = process.env[values['key-env']] ?? '';
  const opts = { endpoint: values.endpoint, model: values.model, apiKey, attempts: Number(values.attempts) };
  const models = await fetch(`${opts.endpoint.replace(/\/+$/, '')}/models`, { headers: apiKey ? { Authorization: `Bearer ${apiKey}` } : {}, signal: AbortSignal.timeout(15_000) })
    .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
    .catch(error => { throw new Error(`Qwen not reachable at ${opts.endpoint} (${error.message}); nothing was changed`); });
  if (!(models.data ?? []).some(m => m.id === opts.model)) throw new Error(`${opts.model} is not served at ${opts.endpoint}; nothing was changed`);
  const release = takeEngineLock();
  const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, '').replace('T', 't');
  const summary = [];
  for (const task of tasks) {
    const after = tasks.indexOf(task) > 0 && summary.some(s => !s.pass && (task.after ?? []).includes(s.id));
    if (after) { summary.push({ id: task.id, pass: false, skipped: 'an earlier task it builds on failed' }); continue; }
    console.log(`\n${task.id}: ${task.title}`);
    const result = await runTask(task, opts, stamp);
    console.log(result.pass ? `  PASS on attempt ${result.attempt} (left in the working tree for the lead's review)` : '  FAIL: file restored');
    summary.push(result);
  }
  release();
  const rows = summary.map(s => `| ${s.id} | ${s.pass ? `pass (attempt ${s.attempt})` : s.skipped ?? 'fail'} |`).join('\n');
  appendFileSync(join(LOGS, 'RUNS.md'), `\n## Run ${stamp} (${opts.model})\n\n| Task | Result |\n|---|---|\n${rows}\n`);
  console.log(`\nLogged to ${relative(ROOT, LOGS).split(sep).join('/')}/ (attempts.jsonl, RUNS.md, one file per reply).`);
  process.exitCode = summary.every(s => s.pass) ? 0 : 1;
}

const order = id => { try { return JSON.parse(readFileSync(join(TASKS, `${id}.json`), 'utf8')).order ?? 99; } catch { return 99; } };

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch(error => { console.error(error.message ?? error); process.exit(2); });
}
