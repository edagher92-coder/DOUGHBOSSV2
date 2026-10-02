import { describe, expect, it } from "vitest";
import { BUDGETS, KB, assertBudgets, checkBudgets, formatBudgetReport, gzipBytes } from "../../tools/wp-hero/budget.mjs";

const ok = { scriptRawBytes: 600_000, scriptGzipBytes: 160_000, glbBytes: 515_376 };

describe("hero byte budgets", () => {
  it("states the limits from the architecture (650 / 170 / 550 KB, frames 60 KB, sm 600 KB, lg 1.2 MB)", () => {
    expect(BUDGETS.scriptRawBytes).toBe(650 * KB);
    expect(BUDGETS.scriptGzipBytes).toBe(170 * KB);
    expect(BUDGETS.glbBytes).toBe(550 * KB);
    expect(BUDGETS.frameMaxBytes).toBe(60 * KB);
    expect(BUDGETS.framesPerBreakpoint).toBe(24);
    expect(BUDGETS.smTotalBytes).toBe(600 * KB);
    expect(BUDGETS.lgTotalBytes).toBe(1200 * KB);
  });

  it("passes at the limit and fails one byte over (boundary)", () => {
    expect(checkBudgets({ ...ok, scriptRawBytes: BUDGETS.scriptRawBytes }).ok).toBe(true);
    const over = checkBudgets({ ...ok, scriptRawBytes: BUDGETS.scriptRawBytes + 1 });
    expect(over.ok).toBe(false);
    expect(over.results.find((r) => !r.ok)?.name).toBe("script raw bytes");
  });

  it("fails each of raw, gzip and GLB independently (negative control)", () => {
    expect(checkBudgets({ ...ok, scriptGzipBytes: BUDGETS.scriptGzipBytes + 1 }).ok).toBe(false);
    expect(checkBudgets({ ...ok, glbBytes: BUDGETS.glbBytes + 1 }).ok).toBe(false);
    expect(() => assertBudgets({ ...ok, glbBytes: BUDGETS.glbBytes + 1 })).toThrow(/hero budget exceeded[\s\S]*FAIL scene glb bytes/);
  });

  it("only checks frame budgets when frames are present", () => {
    expect(checkBudgets(ok).results).toHaveLength(3);
    const frames = Array.from({ length: 24 }, () => ({ breakpoint: "sm", bytes: 20_000 }));
    expect(checkBudgets({ ...ok, frames }).ok).toBe(true);
  });

  it("rejects an oversized frame, too many frames, oversized totals and an unknown breakpoint", () => {
    const sm = (n: number, bytes: number) => Array.from({ length: n }, () => ({ breakpoint: "sm", bytes }));
    expect(checkBudgets({ ...ok, frames: sm(1, BUDGETS.frameMaxBytes + 1) }).ok).toBe(false);
    expect(checkBudgets({ ...ok, frames: sm(25, 1000) }).ok).toBe(false);
    expect(checkBudgets({ ...ok, frames: sm(24, 30_000) }).ok).toBe(false); // 720 KB > sm total
    expect(checkBudgets({ ...ok, frames: [{ breakpoint: "xl", bytes: 10 }] }).ok).toBe(false);
  });

  it("formats a readable report", () => {
    const text = formatBudgetReport(checkBudgets({ ...ok, glbBytes: 9_999_999 }));
    expect(text).toMatch(/^ok {3}script raw bytes/m);
    expect(text).toMatch(/^FAIL scene glb bytes: 9999999/m);
  });

  it("gzipBytes is deterministic and smaller for compressible input", () => {
    const buf = Buffer.alloc(10_000, 7);
    expect(gzipBytes(buf)).toBe(gzipBytes(buf));
    expect(gzipBytes(buf)).toBeLessThan(200);
  });
});
