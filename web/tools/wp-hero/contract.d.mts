export const MAX_DELAY: number;
export const NODE_NAMES: readonly string[];
export function validatePoseManifest(m: unknown): string[];
export interface CompactPoses {
  maxDelay: number;
  camera: { position: number[]; target: number[]; fovDeg: number };
  nodes: Array<[string, number[], number[], number[], number[], number]>;
}
export function compactPoses(m: unknown): CompactPoses;
