// Write public/hero/manifest.json from the pose data exported by 3D Jutsu.
//
//   node tools/blender/make_manifest.mjs <pose-data.png> [public/hero]
//
// pose-data.png comes from export_pose_data.py (run in
// scene_builder_3d_query_python). It carries the pose JSON byte-for-byte in
// its RGB channels with a SHA-256, so nothing is transcribed by hand. Frame
// and poster entries are measured from the files on disk, never assumed, so
// run this AFTER encode_frames.mjs.

import { createHash } from "node:crypto";
import { existsSync, readdirSync, statSync, writeFileSync } from "node:fs";
import path from "node:path";
import sharp from "sharp";

const [posePng, heroDirArg] = process.argv.slice(2);
if (!posePng) {
  console.error("usage: make_manifest.mjs <pose-data.png> [public/hero]");
  process.exit(2);
}
const heroDir = heroDirArg ?? path.join("public", "hero");

async function decodePose(file) {
  const { data, info } = await sharp(file).removeAlpha().raw().toBuffer({ resolveWithObject: true });
  if (info.channels !== 3 || info.height !== 1) throw new Error(`unexpected pose image ${info.width}x${info.height}x${info.channels}`);
  const magic = data.subarray(0, 8).toString("latin1");
  if (magic !== "DBPOSE1\u0000") throw new Error("pose image magic mismatch");
  const length = data.readUInt32BE(8);
  const digest = data.subarray(12, 44).toString("hex");
  const payload = data.subarray(44, 44 + length);
  const actual = createHash("sha256").update(payload).digest("hex");
  if (actual !== digest) throw new Error(`pose payload sha256 mismatch: ${actual} != ${digest}`);
  return { pose: JSON.parse(payload.toString("utf8")), sha256: digest };
}

async function frameSet(dir, publicPrefix) {
  if (!existsSync(dir)) return null;
  const files = readdirSync(dir).filter((f) => /^frame-\d{3}\.webp$/.test(f)).sort();
  if (files.length === 0) return null;
  const first = await sharp(path.join(dir, files[0])).metadata();
  let bytes = 0;
  for (const f of files) {
    const meta = await sharp(path.join(dir, f)).metadata();
    if (meta.width !== first.width || meta.height !== first.height) throw new Error(`${f}: size differs from frame-000`);
    if (!meta.hasAlpha) throw new Error(`${f}: no alpha channel`);
    bytes += statSync(path.join(dir, f)).size;
  }
  return {
    count: files.length,
    entry: { width: first.width, height: first.height, pattern: `${publicPrefix}/frame-%03d.webp`, totalBytes: bytes },
  };
}

async function poster(file, src) {
  if (!existsSync(file)) return null;
  const meta = await sharp(file).metadata();
  return { src, width: meta.width, height: meta.height, alpha: Boolean(meta.hasAlpha), bytes: statSync(file).size };
}

const { pose, sha256 } = await decodePose(posePng);
const lg = await frameSet(path.join(heroDir, "frames", "lg"), "/hero/frames/lg");
const sm = await frameSet(path.join(heroDir, "frames", "sm"), "/hero/frames/sm");
if (lg && sm && lg.count !== sm.count) throw new Error("lg and sm frame counts differ");
const glbPath = path.join(heroDir, "exploded-manoush.glb");

const manifest = {
  version: 1,
  generator: `tools/blender/build_exploded_manoush.py via Higgsfield 3D Jutsu (Blender ${pose.blender})`,
  poseDataSha256: sha256,
  radiusMetres: 0.15,
  sliceCount: 8,
  up: "Y",
  units: "metres",
  glb: existsSync(glbPath)
    ? { src: "/hero/exploded-manoush.glb", bytes: statSync(glbPath).size, compression: "EXT_meshopt_compression" }
    : null,
  camera: {
    position: pose.camera.position,
    target: pose.camera.target,
    fovDeg: pose.camera.fovDeg,
    fovAxis: "vertical (square 1:1 render; widen for other aspects)",
    lensMm: pose.camera.lensMm,
  },
  animation: {
    // Shared with tools/blender/render_frames.py so frames and WebGL agree.
    progress: "p in [0, 1]; frame k of N is p = k / (N - 1)",
    nodeT: "t = clamp((p - delay) / (1 - maxDelay), 0, 1)",
    easing: "smootherstep: e = t^3 * (t * (6t - 15) + 10)",
    interpolation: "position = lerp(rest, exploded, e); quaternion = slerp(rest, exploded, e)",
    maxDelay: 0.3,
  },
  nodes: pose.nodes.map((n) => ({
    name: n.name,
    kind: n.kind,
    slice: n.slice,
    layer: n.layer,
    rest: n.rest,
    exploded: n.exploded,
    delay: n.delay,
  })),
  frames: lg
    ? { count: lg.count, alpha: true, sizes: { lg: lg.entry, ...(sm ? { sm: sm.entry } : {}) } }
    : null,
  posters: {
    assembled: await poster(path.join(heroDir, "poster-assembled.webp"), "/hero/poster-assembled.webp"),
    exploded: await poster(path.join(heroDir, "poster-exploded.webp"), "/hero/poster-exploded.webp"),
  },
};

const out = path.join(heroDir, "manifest.json");
writeFileSync(out, `${JSON.stringify(manifest, null, 2)}\n`);
console.log(JSON.stringify({ out, nodes: manifest.nodes.length, frames: manifest.frames, sha256 }, null, 2));
