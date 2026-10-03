// Optimise and verify the hero GLB exported by 3D Jutsu.
//
// Usage (gltf-transform is NOT a web/ dependency on purpose; install it in a
// scratch directory and point GLTF_TOOLS_DIR at it):
//
//   mkdir -p /tmp/gt && (cd /tmp/gt && npm init -y >/dev/null && \
//     npm i @gltf-transform/core@4 @gltf-transform/extensions@4 \
//           @gltf-transform/functions@4 meshoptimizer)
//   GLTF_TOOLS_DIR=/tmp/gt node tools/blender/optimize_glb.mjs \
//     raw.glb public/hero/exploded-manoush.glb public/hero/manifest.json
//
// Why a custom pipeline instead of `gltf-transform optimize`: optimize's
// join/flatten/palette/instance steps merge or rename nodes and materials,
// which would break the node-name contract with the web app, and position
// quantisation rewrites node translation/scale (moving each piece's pivot).
// Here POSITION stays float32; NORMAL, TEXCOORD_0 and COLOR_0 are quantised,
// then everything is meshopt-compressed (EXT_meshopt_compression). The
// decoder ships with three.js (no CDN), unlike Draco.
//
// The script exits non-zero if any contract node is missing or duplicated,
// or if a node's transform drifts from the manifest's rest pose.

import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import path from "node:path";

const toolsDir = process.env.GLTF_TOOLS_DIR;
if (!toolsDir) {
  console.error("Set GLTF_TOOLS_DIR to a directory with @gltf-transform/* and meshoptimizer installed.");
  process.exit(2);
}
const req = createRequire(path.join(path.resolve(toolsDir), "package.json"));
const { Logger, NodeIO } = req("@gltf-transform/core");
const { ALL_EXTENSIONS, EXTMeshoptCompression, KHRMeshQuantization } = req("@gltf-transform/extensions");
const { dedup, prune, quantize, reorder } = req("@gltf-transform/functions");
const { MeshoptEncoder, MeshoptDecoder } = req("meshoptimizer");

const [input, output, manifestPath] = process.argv.slice(2);
if (!input || !output) {
  console.error("usage: optimize_glb.mjs <in.glb> <out.glb> [manifest.json]");
  process.exit(2);
}

const SLICE_COUNT = 8;
const contractNames = () => {
  const names = ["Peel"];
  for (let i = 0; i < SLICE_COUNT; i += 1) {
    names.push(`slice${i}_dough`, `slice${i}_rim`, `slice${i}_topping`);
  }
  for (let i = 0; i < 12; i += 1) names.push(`mint_${String(i).padStart(2, "0")}`);
  for (let i = 0; i < 16; i += 1) names.push(`chili_${String(i).padStart(2, "0")}`);
  return names;
};

await MeshoptEncoder.ready;
await MeshoptDecoder.ready;
const io = new NodeIO()
  .registerExtensions(ALL_EXTENSIONS)
  .registerDependencies({ "meshopt.encoder": MeshoptEncoder, "meshopt.decoder": MeshoptDecoder });

const doc = await io.read(input);
// Keep stdout as pure JSON (the report below); transform chatter is noise.
doc.setLogger(new Logger(Logger.Verbosity.WARN));
const root = doc.getRoot();

// Cameras and lights are art-direction for the offline renders only; the web
// app owns its own camera and lighting.
for (const node of root.listNodes()) {
  if (node.getCamera()) node.setCamera(null);
}
for (const camera of root.listCameras()) camera.dispose();
for (const ext of root.listExtensionsUsed()) {
  if (ext.extensionName === "KHR_lights_punctual") ext.dispose();
}
const allowed = new Set(contractNames());
for (const node of root.listNodes()) {
  if (!allowed.has(node.getName()) && !node.getMesh() && node.listChildren().length === 0) {
    node.dispose();
  }
}

await doc.transform(
  // keepAttributes: TEXCOORD_0 has no texture bound yet, but the web app maps
  // a top-down texture onto it at runtime, so it must survive pruning.
  prune({ keepAttributes: true }),
  dedup(),
  quantize({ pattern: /^(NORMAL|TEXCOORD_0|COLOR_0)$/, quantizeNormal: 10, quantizeTexcoord: 12, quantizeColor: 8 }),
  reorder({ encoder: MeshoptEncoder }),
);
// quantize() only declares KHR_mesh_quantization when POSITION is quantised,
// but int16 NORMALs need it too (glTF core allows float normals only).
const quantizedNormals = root
  .listMeshes()
  .flatMap((m) => m.listPrimitives())
  .some((p) => (p.getAttribute("NORMAL")?.getComponentType() ?? 5126) !== 5126);
if (quantizedNormals) doc.createExtension(KHRMeshQuantization).setRequired(true);
doc
  .createExtension(EXTMeshoptCompression)
  .setRequired(true)
  .setEncoderOptions({ method: EXTMeshoptCompression.EncoderMethod.QUANTIZE });
await io.write(output, doc);

// ---- verification on the written file ----
const check = await io.read(output);
const nodes = check.getRoot().listNodes();
const counts = new Map();
for (const node of nodes) counts.set(node.getName(), (counts.get(node.getName()) ?? 0) + 1);
const problems = [];
for (const name of contractNames()) {
  const n = counts.get(name) ?? 0;
  if (n !== 1) problems.push(`${name}: found ${n}`);
}
for (const [name] of counts) {
  if (!allowed.has(name)) problems.push(`unexpected node: ${name}`);
}

if (manifestPath) {
  const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
  const byName = new Map(nodes.map((n) => [n.getName(), n]));
  for (const entry of manifest.nodes) {
    const node = byName.get(entry.name);
    if (!node) continue;
    const t = node.getTranslation();
    const r = node.getRotation();
    const dt = Math.max(...t.map((v, k) => Math.abs(v - entry.rest.position[k])));
    // q and -q are the same rotation.
    const dq = Math.min(
      Math.max(...r.map((v, k) => Math.abs(v - entry.rest.quaternion[k]))),
      Math.max(...r.map((v, k) => Math.abs(-v - entry.rest.quaternion[k]))),
    );
    const s = node.getScale();
    const ds = Math.max(...s.map((v) => Math.abs(v - 1)));
    if (dt > 2e-5 || dq > 2e-5 || ds > 1e-6) {
      problems.push(`${entry.name}: transform drift t=${dt.toExponential(2)} q=${dq.toExponential(2)} s=${ds.toExponential(2)}`);
    }
  }
}

const meshes = check.getRoot().listMeshes();
const attrs = new Set();
let vertices = 0;
for (const mesh of meshes) {
  for (const prim of mesh.listPrimitives()) {
    vertices += prim.getAttribute("POSITION")?.getCount() ?? 0;
    for (const sem of prim.listSemantics()) attrs.add(`${sem}:${prim.getAttribute(sem).getComponentType()}`);
  }
}
const bytes = readFileSync(output).byteLength;
console.log(JSON.stringify({
  output,
  bytes,
  nodes: nodes.map((n) => n.getName()).sort(),
  nodeCount: nodes.length,
  vertices,
  attributes: [...attrs].sort(),
  extensionsUsed: check.getRoot().listExtensionsUsed().map((e) => e.extensionName),
  extensionsRequired: check.getRoot().listExtensionsRequired().map((e) => e.extensionName),
  materials: check.getRoot().listMaterials().map((m) => m.getName()),
  problems,
}, null, 2));
if (problems.length > 0) process.exit(1);
