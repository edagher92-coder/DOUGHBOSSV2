// Reproducible build of the hero WebGL bundle and its media set.
//
//   node tools/wp-hero/build.mjs                         # build into tools/wp-hero/dist/
//   node tools/wp-hero/build.mjs --check                 # rebuild in a temp dir and byte-compare dist/
//   node tools/wp-hero/build.mjs --out <dir> --frames-approval <file>
//   SOURCE_DATE_EPOCH=<seconds> node tools/wp-hero/build.mjs   # real timestamp in the manifest
//
// Run from anywhere; paths are resolved relative to web/. Uses the esbuild and three that are already
// in web/node_modules (never npm install). Output is a pure function of the sources, the pinned
// toolchain and SOURCE_DATE_EPOCH: no clock, no absolute paths, no randomness.

import { build as esbuild, version as esbuildVersion } from "esbuild";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { BUDGETS, assertBudgets, formatBudgetReport, gzipBytes } from "./budget.mjs";
import { compactPoses, validatePoseManifest } from "./contract.mjs";
import {
  AI_STILL_RE,
  BANNED_COPY,
  buildManifest,
  fileEntry,
  frameName,
  resolveBuiltAt,
  sceneName,
  scriptName,
  serializeManifest,
  sha256Hex,
  validateManifest,
} from "./manifest.mjs";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const WEB_ROOT = path.resolve(HERE, "..", "..");

/** The toolchain is pinned: a different version changes the bytes, so the build refuses it. */
export const PINNED = Object.freeze({ three: "0.186.1", esbuild: "0.28.2" });

/** Source files whose bytes decide the output (hashed into source_sha256). */
const SOURCE_FILES = [
  "tools/wp-hero/entry.ts",
  "tools/wp-hero/boot-core.ts",
  "tools/wp-hero/scene.ts",
  "tools/wp-hero/pose.ts",
  "tools/wp-hero/stats.ts",
  "tools/wp-hero/contract.mjs",
  "tools/wp-hero/budget.mjs",
  "tools/wp-hero/manifest.mjs",
  "tools/wp-hero/build.mjs",
  "public/hero/manifest.json",
  "public/hero/exploded-manoush.glb",
];

const FRAME_SCOPES = ["real-photo", "owner-approved-stylised-render"];

function readJson(file) {
  return JSON.parse(readFileSync(file, "utf8"));
}

function readVersions(root) {
  const three = readJson(path.join(root, "node_modules", "three", "package.json")).version;
  return { three, esbuild: esbuildVersion };
}

export function assertPinned(versions, pinned = PINNED) {
  const problems = [];
  if (versions.three !== pinned.three) problems.push("three " + versions.three + " (pinned " + pinned.three + ")");
  if (versions.esbuild !== pinned.esbuild) problems.push("esbuild " + versions.esbuild + " (pinned " + pinned.esbuild + ")");
  if (problems.length > 0) throw new Error("toolchain is not the pinned one: " + problems.join(", "));
}

function assertGlb(buf) {
  if (buf.length < 20 || buf.toString("latin1", 0, 4) !== "glTF" || buf.readUInt32LE(4) !== 2 || buf.readUInt32LE(8) !== buf.length) {
    throw new Error("scene file is not a well-formed GLB 2.0 container");
  }
}

function isWebp(buf) {
  return buf.length > 12 && buf.toString("latin1", 0, 4) === "RIFF" && buf.toString("latin1", 8, 12) === "WEBP";
}

/**
 * Frames ship only with a recorded owner approval (00 section 3.3: real photographed layers, or the
 * stylised 3D renders if Elie approves them). Returns null when no approval file exists.
 */
export function readFrameApproval(file) {
  if (!file || !existsSync(file)) return null;
  const a = readJson(file);
  const bad = [];
  if (a.approved !== true) bad.push("approved must be true");
  if (typeof a.approved_by !== "string" || a.approved_by.trim() === "" || /\[CONFIRM|TODO|TBC/i.test(a.approved_by)) {
    bad.push("approved_by must name the approver");
  }
  if (typeof a.approved_on !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(a.approved_on)) bad.push("approved_on must be YYYY-MM-DD");
  if (FRAME_SCOPES.indexOf(a.scope) === -1) bad.push("scope must be one of " + FRAME_SCOPES.join(", "));
  if (bad.length > 0) throw new Error("frames approval is invalid: " + bad.join("; "));
  return { approved_by: a.approved_by.trim(), approved_on: a.approved_on, scope: a.scope, source_dir: typeof a.source_dir === "string" ? a.source_dir : "public/hero/frames" };
}

function loadFrames(root, approval, expectedCount) {
  const sourceRel = approval.source_dir;
  const segments = sourceRel.split(/[\\/]/);
  if (segments.indexOf("ai") !== -1 || AI_STILL_RE.test(sourceRel.replace(/\\/g, "/") + "/")) {
    throw new Error("frames source may not be the AI stills directory");
  }
  const frames = [];
  ["sm", "lg"].forEach((bp) => {
    const dir = path.resolve(root, sourceRel, bp);
    if (!dir.startsWith(root + path.sep) && dir !== root) throw new Error("frames source must live under web/");
    if (!existsSync(dir)) throw new Error("frames source directory is missing: " + sourceRel + "/" + bp);
    const names = readdirSync(dir).filter((n) => /^frame-\d{3}\.webp$/.test(n)).sort();
    if (names.length !== expectedCount) throw new Error(bp + ": expected " + expectedCount + " frames, found " + names.length);
    names.forEach((n, i) => {
      if (n !== "frame-" + String(i).padStart(3, "0") + ".webp") throw new Error(bp + ": frames must be consecutive from frame-000");
      const buf = readFileSync(path.join(dir, n));
      if (!isWebp(buf)) throw new Error(bp + "/" + n + " is not a WebP file");
      frames.push({ breakpoint: bp, index: i, buf });
    });
  });
  return frames;
}

function posesPlugin(poses) {
  return {
    name: "hero-poses",
    setup(b) {
      b.onResolve({ filter: /^hero-poses$/ }, () => ({ path: "hero-poses", namespace: "hero-poses" }));
      b.onLoad({ filter: /.*/, namespace: "hero-poses" }, () => ({ contents: JSON.stringify(poses), loader: "json" }));
    },
  };
}

async function bundle(root, poses) {
  const result = await esbuild({
    absWorkingDir: root,
    entryPoints: [path.join(root, "tools/wp-hero/entry.ts")],
    outfile: "hero-webgl.js",
    bundle: true,
    write: false,
    format: "esm",
    platform: "browser",
    target: "es2018",
    minify: true,
    treeShaking: true,
    legalComments: "eof",
    // Generated vendor-style output: keep the repo-wide eslint run (which would otherwise lint dist/) quiet.
    banner: { js: "/* eslint-disable */\n/* Generated by web/tools/wp-hero/build.mjs. Do not edit. */" },
    charset: "ascii",
    sourcemap: false,
    metafile: false,
    logLevel: "silent",
    define: { "process.env.NODE_ENV": '"production"' },
    // Do not read the repository tsconfig: the bytes must not depend on unrelated config edits.
    tsconfigRaw: { compilerOptions: { target: "es2018", useDefineForClassFields: false } },
    plugins: [posesPlugin(poses)],
  });
  if (result.outputFiles.length !== 1) throw new Error("expected a single bundle output");
  return Buffer.from(result.outputFiles[0].contents);
}

function scanBundle(code, root) {
  const text = code.toString("utf8");
  if (AI_STILL_RE.test(text)) throw new Error("bundle references the AI stills");
  if (BANNED_COPY.test(text)) throw new Error("bundle contains banned teaser copy");
  if (text.indexOf(root) !== -1 || /\/home\/[a-z]/i.test(text)) throw new Error("bundle leaks an absolute path");
}

/**
 * Build everything. Options (all optional):
 *   outDir, root, glbPath, poseManifestPath, framesApprovalFile, budgets, env
 * Resolves with { outDir, manifest, files: [{ path, bytes }] }.
 */
export async function build(options = {}) {
  const root = options.root || WEB_ROOT;
  const env = options.env || process.env;
  const outDir = path.resolve(options.outDir || path.join(HERE, "dist"));
  const glbPath = options.glbPath || path.join(root, "public/hero/exploded-manoush.glb");
  const posePath = options.poseManifestPath || path.join(root, "public/hero/manifest.json");
  const approvalFile = options.framesApprovalFile !== undefined ? options.framesApprovalFile : path.join(HERE, "frames-approval.json");
  const budgets = options.budgets || BUDGETS;

  const versions = readVersions(root);
  assertPinned(versions, options.pinned || PINNED);
  const builtAt = options.builtAt || resolveBuiltAt(env);

  const poseManifest = readJson(posePath);
  const poseErrors = validatePoseManifest(poseManifest);
  if (poseErrors.length > 0) throw new Error("pose manifest breaks the node-name contract: " + poseErrors.slice(0, 5).join("; "));
  const poses = compactPoses(poseManifest);

  const glb = readFileSync(glbPath);
  assertGlb(glb);

  const code = await bundle(root, poses);
  scanBundle(code, root);

  const approval = readFrameApproval(approvalFile);
  const confirm = [
    "[CONFIRM] Elie must approve the ES5 exception for the generated ES2018 module hero-webgl.<sha8>.js (docs/wp/00 section 3.3); until then tier 2 must not ship.",
  ];
  let frames = [];
  let framesInfo;
  if (approval) {
    frames = loadFrames(root, approval, poseManifest.frames.count);
    const sizes = poseManifest.frames.sizes;
    framesInfo = {
      included: true,
      count: poseManifest.frames.count,
      approval,
      sizes: { sm: { width: sizes.sm.width, height: sizes.sm.height }, lg: { width: sizes.lg.width, height: sizes.lg.height } },
    };
  } else {
    framesInfo = { included: false, count: 0, approval: null, sizes: null };
    confirm.push(
      "[CONFIRM] Tier-1 frames are NOT in this build: Elie has not recorded approval for the stylised 3D renders (or a real photo shoot). Add tools/wp-hero/frames-approval.json to include them (docs/wp/00 section 3.3).",
    );
  }

  const report = assertBudgets(
    {
      scriptRawBytes: code.length,
      scriptGzipBytes: gzipBytes(code),
      glbBytes: glb.length,
      frames: frames.map((f) => ({ breakpoint: f.breakpoint, bytes: f.buf.length })),
    },
    budgets,
  );

  const files = [fileEntry("script", scriptName(code), code), fileEntry("scene", sceneName(glb), glb)];
  const payload = new Map([
    [files[0].path, code],
    [files[1].path, glb],
  ]);
  frames.forEach((f) => {
    const name = frameName(f.breakpoint, f.index, f.buf);
    files.push(fileEntry("frame", name, f.buf, { breakpoint: f.breakpoint, index: f.index }));
    payload.set(name, f.buf);
  });

  // When a caller overrides the GLB or pose manifest (tests), hash the override instead.
  const overrides = {};
  if (options.glbPath) overrides["public/hero/exploded-manoush.glb"] = glb;
  if (options.poseManifestPath) overrides["public/hero/manifest.json"] = readFileSync(posePath);
  const sources = SOURCE_FILES.map((rel) => ({ path: rel, sha256: sha256Hex(overrides[rel] || readFileSync(path.join(root, rel))) }));

  const manifest = buildManifest({
    versions,
    sources,
    builtAt,
    files,
    scene: { nodeCount: poses.nodes.length, radiusMetres: poseManifest.radiusMetres },
    frames: framesInfo,
    budgets: {
      scriptRawBytes: code.length,
      scriptRawLimit: budgets.scriptRawBytes,
      scriptGzipBytes: gzipBytes(code),
      scriptGzipLimit: budgets.scriptGzipBytes,
      glbBytes: glb.length,
      glbLimit: budgets.glbBytes,
    },
    confirm,
  });
  const manifestErrors = validateManifest(manifest);
  if (manifestErrors.length > 0) throw new Error("generated manifest is invalid: " + manifestErrors.join("; "));

  // Write (never into a directory that is not an earlier hero build).
  if (existsSync(outDir)) {
    const entries = readdirSync(outDir);
    if (entries.length > 0 && entries.indexOf("hero-manifest.json") === -1) {
      throw new Error("refusing to overwrite " + outDir + ": it is not an earlier hero build");
    }
    rmSync(outDir, { recursive: true, force: true });
  }
  mkdirSync(outDir, { recursive: true });
  payload.forEach((buf, rel) => {
    const target = path.join(outDir, rel);
    mkdirSync(path.dirname(target), { recursive: true });
    writeFileSync(target, buf);
  });
  writeFileSync(path.join(outDir, "hero-manifest.json"), serializeManifest(manifest));

  return {
    outDir,
    manifest,
    report,
    files: files.map((f) => ({ path: f.path, bytes: f.bytes })).concat([{ path: "hero-manifest.json", bytes: Buffer.byteLength(serializeManifest(manifest)) }]),
  };
}

function listFiles(dir, base = dir) {
  const out = [];
  readdirSync(dir, { withFileTypes: true }).forEach((e) => {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...listFiles(full, base));
    else out.push(path.relative(base, full).split(path.sep).join("/"));
  });
  return out.sort();
}

/**
 * Rebuild into a temp dir and byte-compare with `distDir`. Returns a list of differences. The
 * timestamp is taken from the existing manifest (built_at) so only real differences show up.
 */
export async function checkReproducible(distDir, options = {}) {
  const tmp = mkdtempSync(path.join(tmpdir(), "wp-hero-check-"));
  try {
    const manifestFile = path.join(distDir, "hero-manifest.json");
    const builtAt = options.builtAt || (existsSync(manifestFile) ? readJson(manifestFile).built_at : undefined);
    await build({ ...options, builtAt, outDir: path.join(tmp, "dist") });
    const a = listFiles(distDir);
    const b = listFiles(path.join(tmp, "dist"));
    const diffs = [];
    a.filter((f) => b.indexOf(f) === -1).forEach((f) => diffs.push("only in dist: " + f));
    b.filter((f) => a.indexOf(f) === -1).forEach((f) => diffs.push("missing from dist: " + f));
    a.filter((f) => b.indexOf(f) !== -1).forEach((f) => {
      if (!readFileSync(path.join(distDir, f)).equals(readFileSync(path.join(tmp, "dist", f)))) diffs.push("differs: " + f);
    });
    return diffs;
  } finally {
    rmSync(tmp, { recursive: true, force: true });
  }
}

function parseArgs(argv) {
  const args = { check: false, out: undefined, approval: undefined };
  for (let i = 0; i < argv.length; i += 1) {
    const a = argv[i];
    if (a === "--check") args.check = true;
    else if (a === "--out") args.out = argv[(i += 1)];
    else if (a === "--frames-approval") args.approval = argv[(i += 1)];
    else throw new Error("unknown argument: " + a);
  }
  return args;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const options = {};
  if (args.approval) options.framesApprovalFile = path.resolve(args.approval);
  if (args.check) {
    const dist = path.resolve(args.out || path.join(HERE, "dist"));
    if (!existsSync(dist)) throw new Error("nothing to check: " + dist + " does not exist (run the build first)");
    const diffs = await checkReproducible(dist, options);
    if (diffs.length > 0) {
      console.error("NOT reproducible:\n" + diffs.join("\n"));
      process.exit(1);
    }
    console.log("reproducible: a fresh build is byte-identical to " + dist);
    return;
  }
  if (args.out) options.outDir = args.out;
  const result = await build(options);
  console.log(formatBudgetReport(result.report));
  result.files.forEach((f) => console.log(f.bytes + "\t" + f.path));
  result.manifest.confirm.forEach((c) => console.log(c));
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((err) => {
    console.error(err && err.message ? err.message : err);
    process.exit(1);
  });
}
