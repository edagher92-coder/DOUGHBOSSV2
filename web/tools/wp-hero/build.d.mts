import type { Budgets, BudgetReport } from "./budget.mjs";
import type { HeroBuildManifest } from "./manifest.mjs";
export const WEB_ROOT: string;
export const PINNED: Readonly<{ three: string; esbuild: string }>;
export function assertPinned(versions: { three: string; esbuild: string }, pinned?: { three: string; esbuild: string }): void;
export function readFrameApproval(file: string | null | undefined): {
  approved_by: string;
  approved_on: string;
  scope: string;
  source_dir: string;
} | null;
export interface BuildOptions {
  outDir?: string;
  root?: string;
  glbPath?: string;
  poseManifestPath?: string;
  framesApprovalFile?: string | null;
  budgets?: Budgets;
  env?: Record<string, string | undefined>;
  builtAt?: string;
  pinned?: { three: string; esbuild: string };
}
export function build(options?: BuildOptions): Promise<{
  outDir: string;
  manifest: HeroBuildManifest;
  report: BudgetReport;
  files: Array<{ path: string; bytes: number }>;
}>;
export function checkReproducible(distDir: string, options?: BuildOptions): Promise<string[]>;
