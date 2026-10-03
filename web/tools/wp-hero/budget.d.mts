export const KB: number;
export interface Budgets {
  scriptRawBytes: number;
  scriptGzipBytes: number;
  glbBytes: number;
  frameMaxBytes: number;
  framesPerBreakpoint: number;
  smTotalBytes: number;
  lgTotalBytes: number;
}
export const BUDGETS: Readonly<Budgets>;
export function gzipBytes(buf: Uint8Array): number;
export interface BudgetInput {
  scriptRawBytes: number;
  scriptGzipBytes: number;
  glbBytes: number;
  frames?: Array<{ breakpoint: string; bytes: number }>;
}
export interface BudgetResult {
  name: string;
  actual: number;
  limit: number;
  ok: boolean;
}
export interface BudgetReport {
  ok: boolean;
  results: BudgetResult[];
}
export function checkBudgets(input: BudgetInput, budgets?: Budgets): BudgetReport;
export function formatBudgetReport(report: BudgetReport): string;
export function assertBudgets(input: BudgetInput, budgets?: Budgets): BudgetReport;
