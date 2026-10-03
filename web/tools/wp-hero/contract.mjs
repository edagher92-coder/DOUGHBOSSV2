// Node-name contract and pose-manifest validation for the hero scene.
//
// The names below are a CONTRACT with tools/blender/build_exploded_manoush.py and
// public/hero/manifest.json (see docs/3d-assets.md). This module is shared by the build
// (plain Node) and by the bundled scene (via esbuild), so it must stay dependency-free.

export const MAX_DELAY = 0.3;

function range(count, make) {
  const out = [];
  for (let i = 0; i < count; i += 1) out.push(make(i));
  return out;
}

function two(n) {
  return n < 10 ? "0" + n : String(n);
}

const SLICES = range(8, (i) => i);

/** The 53 node names, in canonical (deterministic) order. */
export const NODE_NAMES = Object.freeze(
  ["Peel"]
    .concat(SLICES.map((i) => "slice" + i + "_dough"))
    .concat(SLICES.map((i) => "slice" + i + "_rim"))
    .concat(SLICES.map((i) => "slice" + i + "_topping"))
    .concat(range(12, (i) => "mint_" + two(i)))
    .concat(range(16, (i) => "chili_" + two(i))),
);

function isVec(v, n) {
  if (!Array.isArray(v) || v.length !== n) return false;
  for (let i = 0; i < n; i += 1) {
    if (typeof v[i] !== "number" || !Number.isFinite(v[i])) return false;
  }
  return true;
}

function isUnitQuat(q) {
  if (!isVec(q, 4)) return false;
  const len = Math.sqrt(q[0] * q[0] + q[1] * q[1] + q[2] * q[2] + q[3] * q[3]);
  return Math.abs(len - 1) < 1e-3;
}

/**
 * Validate the pose manifest (public/hero/manifest.json). Returns a list of error strings;
 * an empty list means the manifest satisfies the node-name contract.
 */
export function validatePoseManifest(m) {
  const errors = [];
  if (m === null || typeof m !== "object") return ["pose manifest is not an object"];
  if (m.version !== 1) errors.push("pose manifest version must be 1");
  if (m.up !== "Y") errors.push("pose manifest up axis must be Y");
  if (m.units !== "metres") errors.push("pose manifest units must be metres");
  if (typeof m.radiusMetres !== "number" || !(m.radiusMetres > 0)) errors.push("radiusMetres must be a positive number");

  const anim = m.animation;
  if (!anim || typeof anim.maxDelay !== "number" || !(anim.maxDelay >= 0 && anim.maxDelay < 1)) {
    errors.push("animation.maxDelay must be a number in [0, 1)");
  }
  const maxDelay = anim && typeof anim.maxDelay === "number" ? anim.maxDelay : MAX_DELAY;

  const cam = m.camera;
  if (!cam || !isVec(cam.position, 3) || !isVec(cam.target, 3)) {
    errors.push("camera.position and camera.target must be 3-vectors");
  } else if (typeof cam.fovDeg !== "number" || !(cam.fovDeg > 10 && cam.fovDeg < 120)) {
    errors.push("camera.fovDeg must be a number between 10 and 120");
  }

  if (!Array.isArray(m.nodes)) {
    errors.push("nodes must be an array");
    return errors;
  }
  const seen = new Map();
  m.nodes.forEach((n, i) => {
    const name = n && typeof n.name === "string" ? n.name : "";
    if (!name) {
      errors.push("nodes[" + i + "] has no name");
      return;
    }
    seen.set(name, (seen.get(name) || 0) + 1);
    if (NODE_NAMES.indexOf(name) === -1) errors.push("unexpected node name: " + name);
    if (!n.rest || !isVec(n.rest.position, 3) || !isUnitQuat(n.rest.quaternion)) errors.push(name + ": invalid rest pose");
    if (!n.exploded || !isVec(n.exploded.position, 3) || !isUnitQuat(n.exploded.quaternion)) errors.push(name + ": invalid exploded pose");
    if (typeof n.delay !== "number" || !Number.isFinite(n.delay) || n.delay < 0 || n.delay > maxDelay) {
      errors.push(name + ": delay must be a number in [0, maxDelay]");
    }
  });
  NODE_NAMES.forEach((name) => {
    const count = seen.get(name) || 0;
    if (count === 0) errors.push("missing node: " + name);
    if (count > 1) errors.push("duplicate node: " + name);
  });
  return errors;
}

/**
 * Compact, canonical-order pose data embedded in the bundle. Throws when the pose manifest
 * breaks the contract (the build then fails closed).
 */
export function compactPoses(m) {
  const errors = validatePoseManifest(m);
  if (errors.length > 0) throw new Error("pose manifest invalid: " + errors.slice(0, 5).join("; "));
  const byName = new Map();
  m.nodes.forEach((n) => byName.set(n.name, n));
  return {
    maxDelay: m.animation.maxDelay,
    camera: {
      position: m.camera.position.slice(),
      target: m.camera.target.slice(),
      fovDeg: m.camera.fovDeg,
    },
    nodes: NODE_NAMES.map((name) => {
      const n = byName.get(name);
      return [name, n.rest.position.slice(), n.rest.quaternion.slice(), n.exploded.position.slice(), n.exploded.quaternion.slice(), n.delay];
    }),
  };
}
