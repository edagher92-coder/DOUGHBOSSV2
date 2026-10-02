export const MANIFEST_SCHEMA: number;
export const EPOCH_FALLBACK_ISO: string;
export const BANNED_COPY: RegExp;
export const AI_STILL_RE: RegExp;
export interface FileEntry {
  role: "script" | "scene" | "frame";
  path: string;
  bytes: number;
  sha384: string;
  breakpoint?: "sm" | "lg";
  index?: number;
}
export interface HeroBuildManifest {
  schema: number;
  three_version: string;
  esbuild_version: string;
  target: string;
  source_sha256: string;
  built_at: string;
  sources: Array<{ path: string; sha256: string }>;
  files: FileEntry[];
  scene: Record<string, unknown>;
  frames: { included: boolean; approval?: { approved_by: string } | null } & Record<string, unknown>;
  budgets: Record<string, number>;
  confirm: string[];
}
export function sha256Hex(buf: Uint8Array | string): string;
export function sha384Sri(buf: Uint8Array | string): string;
export function sha8(buf: Uint8Array | string): string;
export function scriptName(buf: Uint8Array): string;
export function sceneName(buf: Uint8Array): string;
export function frameName(breakpoint: string, index: number, buf: Uint8Array): string;
export function resolveBuiltAt(env: Record<string, string | undefined>): string;
export function fileEntry(role: FileEntry["role"], path: string, buf: Uint8Array, extra?: Record<string, unknown>): FileEntry;
export function buildManifest(input: {
  versions: { three: string; esbuild: string };
  sources: Array<{ path: string; sha256: string }>;
  builtAt: string;
  files: FileEntry[];
  scene: Record<string, unknown>;
  frames: Record<string, unknown>;
  budgets: Record<string, number>;
  confirm: string[];
}): HeroBuildManifest;
export function serializeManifest(m: unknown): string;
export function validateManifest(m: unknown): string[];
export function verifyFiles(m: { files: FileEntry[] }, read: (path: string) => Uint8Array | null): string[];
