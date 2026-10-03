// Pure pose maths shared by the frames and the WebGL hero (docs/3d-assets.md, "Animation formula").
//
//   t = clamp((p - delay) / (1 - maxDelay), 0, 1)
//   e = t * t * t * (t * (6 * t - 15) + 10)            // smootherstep
//   position   = lerp(rest.position, exploded.position, e)
//   quaternion = slerp(rest.quaternion, exploded.quaternion, e)
//
// No three.js import here so the unit tests run in plain Node.

export type Vec3 = [number, number, number];
export type Quat = [number, number, number, number];

/** [name, restPosition, restQuaternion, explodedPosition, explodedQuaternion, delay] */
export type PoseRow = [string, number[], number[], number[], number[], number];

export interface PoseData {
  maxDelay: number;
  camera: { position: number[]; target: number[]; fovDeg: number };
  nodes: PoseRow[];
}

export function clamp01(x: number): number {
  if (!(x > 0)) return 0; // also maps NaN to 0
  return x > 1 ? 1 : x;
}

export function smootherstep(t: number): number {
  const c = clamp01(t);
  return c * c * c * (c * (6 * c - 15) + 10);
}

/** Per-node eased progress for a global progress p. */
export function nodeEase(p: number, delay: number, maxDelay: number): number {
  return smootherstep((clamp01(p) - delay) / (1 - maxDelay));
}

export function lerpVec3(a: ArrayLike<number>, b: ArrayLike<number>, e: number, out: number[]): number[] {
  for (let i = 0; i < 3; i += 1) {
    const av = a[i] as number;
    out[i] = av + ((b[i] as number) - av) * e;
  }
  return out;
}

/** Shortest-path spherical interpolation between unit quaternions (x, y, z, w). */
export function slerpQuat(a: ArrayLike<number>, b: ArrayLike<number>, e: number, out: number[]): number[] {
  let bx = b[0] as number;
  let by = b[1] as number;
  let bz = b[2] as number;
  let bw = b[3] as number;
  const ax = a[0] as number;
  const ay = a[1] as number;
  const az = a[2] as number;
  const aw = a[3] as number;
  let dot = ax * bx + ay * by + az * bz + aw * bw;
  if (dot < 0) {
    dot = -dot;
    bx = -bx;
    by = -by;
    bz = -bz;
    bw = -bw;
  }
  let s0: number;
  let s1: number;
  if (dot > 0.9995) {
    s0 = 1 - e;
    s1 = e;
  } else {
    const theta = Math.acos(dot);
    const sin = Math.sin(theta);
    s0 = Math.sin((1 - e) * theta) / sin;
    s1 = Math.sin(e * theta) / sin;
  }
  const x = s0 * ax + s1 * bx;
  const y = s0 * ay + s1 * by;
  const z = s0 * az + s1 * bz;
  const w = s0 * aw + s1 * bw;
  const len = Math.sqrt(x * x + y * y + z * z + w * w) || 1;
  out[0] = x / len;
  out[1] = y / len;
  out[2] = z / len;
  out[3] = w / len;
  return out;
}

export interface PoseAt {
  position: number[];
  quaternion: number[];
}

/** Pose of one node at global progress p. */
export function poseAt(row: PoseRow, p: number, maxDelay: number, out?: PoseAt): PoseAt {
  const target: PoseAt = out || { position: [0, 0, 0], quaternion: [0, 0, 0, 1] };
  const e = nodeEase(p, row[5], maxDelay);
  lerpVec3(row[1], row[3], e, target.position);
  slerpQuat(row[2], row[4], e, target.quaternion);
  return target;
}

/** Progress of frame k out of n (frame 0 is rest, frame n-1 is fully exploded). */
export function frameProgress(k: number, n: number): number {
  if (!Number.isInteger(n) || n < 2) return 0;
  return clamp01(k / (n - 1));
}

/**
 * Vertical field of view for a viewport. The manifest FOV frames a square render, so a
 * portrait viewport widens the vertical FOV to keep the same horizontal extent; landscape
 * keeps it as is.
 */
export function fovForAspect(fovDeg: number, aspect: number): number {
  if (!(aspect > 0) || aspect >= 1) return fovDeg;
  const half = (fovDeg * Math.PI) / 360;
  return (2 * Math.atan(Math.tan(half) / aspect) * 180) / Math.PI;
}
