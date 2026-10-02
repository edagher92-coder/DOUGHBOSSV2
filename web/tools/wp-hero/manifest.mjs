// hero-manifest.json: generation, validation and verification.
//
// The manifest is the single description of a hero build: every shipped file with its size and
// SHA-384 (as an SRI token, ready for an `integrity` attribute), plus the pinned toolchain and a
// hash of every source that went into the build. It is serialised deterministically so two builds
// of the same sources are byte-identical.

import { createHash } from "node:crypto";

export const MANIFEST_SCHEMA = 1;
export const EPOCH_FALLBACK_ISO = "1970-01-01T00:00:00Z";

const SCRIPT_RE = /^hero-webgl\.[0-9a-f]{8}\.js$/;
const SCENE_RE = /^hero-scene\.[0-9a-f]{8}\.glb$/;
const FRAME_RE = /^frames\/(sm|lg)\/[0-9a-f]{8}-(\d{2})\.webp$/;
const SRI_RE = /^sha384-[A-Za-z0-9+/]{64}$/;
const HEX64_RE = /^[0-9a-f]{64}$/;
const ISO_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

// Words that must never appear in anything the hero ships (teaser-direction.md). Built from
// parts so this file does not itself contain the banned word.
export const BANNED_COPY = new RegExp("\\bmi" + "nis\\b", "i");
// The AI stills are illustrative only and must never be referenced.
export const AI_STILL_RE = /hero\/ai\b|manoush-blowout-still|manoush-topdown-reference/i;

export function sha256Hex(buf) {
  return createHash("sha256").update(buf).digest("hex");
}

/** SRI token, "sha384-<base64>". */
export function sha384Sri(buf) {
  return "sha384-" + createHash("sha384").update(buf).digest("base64");
}

/** First 8 hex characters of the SHA-256 (the content-hash part of a file name). */
export function sha8(buf) {
  return sha256Hex(buf).slice(0, 8);
}

export function scriptName(buf) {
  return "hero-webgl." + sha8(buf) + ".js";
}

export function sceneName(buf) {
  return "hero-scene." + sha8(buf) + ".glb";
}

export function frameName(breakpoint, index, buf) {
  if (breakpoint !== "sm" && breakpoint !== "lg") throw new Error("unknown frame breakpoint: " + breakpoint);
  if (!Number.isInteger(index) || index < 0 || index > 99) throw new Error("frame index out of range: " + index);
  return "frames/" + breakpoint + "/" + sha8(buf) + "-" + (index < 10 ? "0" + index : String(index)) + ".webp";
}

/**
 * Build timestamp. Reproducible builds must not read the clock: use SOURCE_DATE_EPOCH when set,
 * otherwise the fixed placeholder 1970-01-01T00:00:00Z (documented in the README).
 */
export function resolveBuiltAt(env) {
  const raw = env && env.SOURCE_DATE_EPOCH;
  if (raw === undefined || raw === "") return EPOCH_FALLBACK_ISO;
  if (!/^\d{1,11}$/.test(String(raw))) throw new Error("SOURCE_DATE_EPOCH must be whole seconds since the epoch");
  return new Date(Number(raw) * 1000).toISOString().replace(/\.\d{3}Z$/, "Z");
}

export function fileEntry(role, path, buf, extra) {
  return Object.assign({ role, path, bytes: buf.length, sha384: sha384Sri(buf) }, extra || {});
}

/**
 * Assemble the manifest object. `input`:
 *   { versions: { three, esbuild }, sources: [{ path, sha256 }], builtAt,
 *     files: [fileEntry...], scene: { nodeCount, radiusMetres }, frames: {...}, budgets, confirm: [] }
 */
export function buildManifest(input) {
  const sources = input.sources
    .map((s) => ({ path: s.path, sha256: s.sha256 }))
    .sort((a, b) => (a.path < b.path ? -1 : a.path > b.path ? 1 : 0));
  const sourceSha256 = sha256Hex(sources.map((s) => s.sha256 + "  " + s.path).join("\n") + "\n" + input.versions.three + "\n" + input.versions.esbuild + "\n");
  const rank = { script: 0, scene: 1, frame: 2 };
  const files = input.files.slice().sort((a, b) => {
    if (rank[a.role] !== rank[b.role]) return rank[a.role] - rank[b.role];
    return a.path < b.path ? -1 : a.path > b.path ? 1 : 0;
  });
  return {
    schema: MANIFEST_SCHEMA,
    three_version: input.versions.three,
    esbuild_version: input.versions.esbuild,
    target: "es2018",
    source_sha256: sourceSha256,
    built_at: input.builtAt,
    sources,
    files,
    scene: input.scene,
    frames: input.frames,
    budgets: input.budgets,
    confirm: input.confirm,
  };
}

/** Deterministic serialisation (fixed key order as built, 2-space indent, trailing newline). */
export function serializeManifest(m) {
  return JSON.stringify(m, null, 2) + "\n";
}

function safeRelativePath(p) {
  return (
    typeof p === "string" &&
    p.length > 0 &&
    p.indexOf("\\") === -1 &&
    p.charAt(0) !== "/" &&
    p.split("/").every((seg) => seg !== "" && seg !== "." && seg !== "..")
  );
}

/** Validate a manifest object. Returns a list of error strings; empty means valid. */
export function validateManifest(m) {
  const errors = [];
  if (m === null || typeof m !== "object") return ["manifest is not an object"];
  if (m.schema !== MANIFEST_SCHEMA) errors.push("schema must be " + MANIFEST_SCHEMA);
  if (typeof m.three_version !== "string" || !/^\d+\.\d+\.\d+$/.test(m.three_version)) errors.push("three_version missing or malformed");
  if (typeof m.esbuild_version !== "string" || !/^\d+\.\d+\.\d+$/.test(m.esbuild_version)) errors.push("esbuild_version missing or malformed");
  if (typeof m.source_sha256 !== "string" || !HEX64_RE.test(m.source_sha256)) errors.push("source_sha256 must be 64 hex characters");
  if (typeof m.built_at !== "string" || !ISO_RE.test(m.built_at)) errors.push("built_at must be an ISO-8601 UTC timestamp");
  if (!Array.isArray(m.files)) {
    errors.push("files must be an array");
    return errors;
  }

  const seen = new Set();
  const counts = { script: 0, scene: 0, sm: 0, lg: 0 };
  const frameIdx = { sm: [], lg: [] };
  m.files.forEach((f, i) => {
    const where = "files[" + i + "]";
    if (!f || typeof f !== "object") {
      errors.push(where + " is not an object");
      return;
    }
    if (!safeRelativePath(f.path)) {
      errors.push(where + ": unsafe or empty path");
      return;
    }
    if (seen.has(f.path)) errors.push(where + ": duplicate path " + f.path);
    seen.add(f.path);
    if (!Number.isInteger(f.bytes) || f.bytes <= 0) errors.push(where + ": bytes must be a positive integer");
    if (typeof f.sha384 !== "string" || !SRI_RE.test(f.sha384)) errors.push(where + ": sha384 must be an SRI token (sha384-<base64>)");
    if (f.role === "script") {
      counts.script += 1;
      if (!SCRIPT_RE.test(f.path)) errors.push(where + ": script path must be hero-webgl.<sha8>.js");
    } else if (f.role === "scene") {
      counts.scene += 1;
      if (!SCENE_RE.test(f.path)) errors.push(where + ": scene path must be hero-scene.<sha8>.glb");
    } else if (f.role === "frame") {
      const match = FRAME_RE.exec(f.path);
      if (!match) {
        errors.push(where + ": frame path must be frames/<sm|lg>/<sha8>-NN.webp");
      } else {
        counts[match[1]] += 1;
        frameIdx[match[1]].push(Number(match[2]));
        if (f.breakpoint !== match[1]) errors.push(where + ": breakpoint does not match the path");
        if (f.index !== Number(match[2])) errors.push(where + ": index does not match the path");
      }
    } else {
      errors.push(where + ": unknown role " + String(f.role));
    }
  });
  if (counts.script !== 1) errors.push("exactly one script file is required");
  if (counts.scene !== 1) errors.push("exactly one scene file is required");

  const frames = m.frames;
  if (!frames || typeof frames.included !== "boolean") {
    errors.push("frames.included must be a boolean");
  } else if (!frames.included) {
    if (counts.sm + counts.lg !== 0) errors.push("frame files present although frames.included is false");
  } else {
    if (counts.sm !== counts.lg || counts.sm === 0) errors.push("sm and lg must carry the same non-zero number of frames");
    ["sm", "lg"].forEach((bp) => {
      const sorted = frameIdx[bp].slice().sort((a, b) => a - b);
      sorted.forEach((v, k) => {
        if (v !== k) errors.push(bp + " frame indexes must be consecutive from 0");
      });
    });
    if (!frames.approval || typeof frames.approval.approved_by !== "string" || frames.approval.approved_by === "") {
      errors.push("frames included without a recorded owner approval");
    }
  }

  const text = JSON.stringify(m);
  if (BANNED_COPY.test(text)) errors.push("manifest contains banned teaser copy");
  if (AI_STILL_RE.test(text)) errors.push("manifest references the AI stills");
  return errors;
}

/**
 * Verify shipped files against the manifest. `read(path)` returns a Buffer or null.
 * Returns a list of error strings; empty means every file matches (size, SHA-384, name hash).
 */
export function verifyFiles(m, read) {
  const errors = [];
  (m.files || []).forEach((f) => {
    const buf = read(f.path);
    if (!buf) {
      errors.push(f.path + ": missing");
      return;
    }
    if (buf.length !== f.bytes) errors.push(f.path + ": size " + buf.length + " != manifest " + f.bytes);
    if (sha384Sri(buf) !== f.sha384) errors.push(f.path + ": sha384 mismatch");
    const named = /\.([0-9a-f]{8})\.(?:js|glb)$|\/([0-9a-f]{8})-\d{2}\.webp$/.exec(f.path);
    const expected = named ? named[1] || named[2] : null;
    if (expected && expected !== sha8(buf)) errors.push(f.path + ": file name hash does not match content");
  });
  return errors;
}
