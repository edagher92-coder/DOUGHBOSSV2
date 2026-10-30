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
// - Every changed line must sit inside one of the task's named functions (or a new function the task names). An
//   edit anywhere else in the file is refused and the file restored, even when the tests pass.
// - The oracle and the regression suites run in a child `node --permission` process that may only READ the repo,
//   with an empty environment (no keys) and a 60 s limit. Oracles are written by the lead, never by the model.
// - Logs record timings, token counts, pass/fail and test names. Never the API key.
import { spawnSync } from 'node:child_process';
import { appendFileSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const TASKS = join(ROOT, 'ops/qwen/tasks');
const LOGS = join(ROOT, 'ops/qwen/log');
const REGRESSION = () => readdirSync(join(ROOT, 'tests')).filter(f => f.endsWith('.test.js')).sort().map(f => `tests/${f}`);

export const TARGETS = /^(public\/js\/doughboss\.js|public\/css\/doughboss[a-z0-9-]*\.css|themes\/doughboss-final\/[a-z0-9\/-]+\.(php|css))$/;
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

// Line spans [first, last] (1-based) of `function name(` … its closing brace, or null.
export function functionSpan(source, name) {
  const start = source.indexOf(`function ${name}(`);
  if (start === -1) return null;
  let depth = 0, started = false;
  for (let i = start; i < source.length; i += 1) {
    if (source[i] === '{') { started = true; depth += 1; }
    else if (source[i] === '}' && started && --depth === 0) {
      const lineOf = at => source.slice(0, at).split('\n').length;
      return [lineOf(start), lineOf(i)];
    }
  }
  return null;
}

function excerpt(path, functions, lines) {
  const source = readFileSync(join(ROOT, path), 'utf8').replace(/\r\n/g, '\n');
  const parts = [];
  for (const name of functions ?? []) {
    const span = functionSpan(source, name);
    if (span) parts.push(source.split('\n').slice(span[0] - 1, span[1]).join('\n'));
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

// New-file line numbers touched by the change, from git's own diff (works the same on Windows and Linux).
export function changedLines(before, after) {
  const dir = mkdtempSync(join(tmpdir(), 'qwen-site-'));
  try {
    writeFileSync(join(dir, 'a'), before); writeFileSync(join(dir, 'b'), after);
    const diff = spawnSync('git', ['diff', '--no-index', '--no-color', '-U0', 'a', 'b'], { cwd: dir, encoding: 'utf8' });
    if (diff.status !== 0 && diff.status !== 1) throw new Error(`git diff failed: ${diff.stderr}`);
    const touched = [];
    for (const m of diff.stdout.matchAll(/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/gm)) {
      const start = Number(m[1]), count = m[2] === undefined ? 1 : Number(m[2]);
      if (count === 0) touched.push(start, start + 1); // a pure deletion sits between these lines
      for (let k = 0; k < count; k += 1) touched.push(start + k);
    }
    return touched;
  } finally { rmSync(dir, { recursive: true, force: true }); }
}

export function outsideAllowed(task, before, after) {
  const source = after.replace(/\r\n/g, '\n');
  const spans = [...(task.functions ?? []), ...(task.newFunctions ?? [])].map(fn => functionSpan(source, fn)).filter(Boolean);
  const missing = (task.newFunctions ?? []).filter(fn => !functionSpan(source, fn));
  const stray = changedLines(before.replace(/\r\n/g, '\n'), source)
    .filter(line => !spans.some(([a, b]) => line >= a - 1 && line <= b + 1)); // a blank line beside a function is fine
  return { stray, missing };
}

// --- running the oracle ------------------------------------------------------------------------------------------

function runTest(file) {
  const env = { PATH: process.env.PATH ?? '' };
  if (process.env.SystemRoot) env.SystemRoot = process.env.SystemRoot;
  const r = spawnSync(process.execPath, ['--permission', `--allow-fs-read=${ROOT}`, file], { cwd: ROOT, env, encoding: 'utf8', timeout: 60_000 });
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
    const log = { ts: new Date().toISOString(), stamp, task: task.id, attempt, model: opts.model };
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
      const { stray, missing } = outsideAllowed(task, original, source);
      if (missing.length) problem = `These new functions are missing: ${missing.join(', ')}.`;
      else if (stray.length) problem = `Lines ${stray.slice(0, 8).join(', ')} were changed outside the functions you may touch (${[...(task.functions ?? []), ...(task.newFunctions ?? [])].join(', ')}).`;
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
