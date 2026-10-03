import { describe, expect, it } from "vitest";
import {
  MINIS_ORDER,
  PACK_MAX,
  PACK_MIN,
  PIECES_PER_GUEST,
  SUGGESTED_MIX,
  clampPieces,
  estimatePack,
  splitPieces,
} from "@/lib/packs";
import type { MinisKind } from "@/types/menu";

const sum = (r: Record<MinisKind, number>) => MINIS_ORDER.reduce((s, k) => s + r[k], 0);

describe("SUGGESTED_MIX", () => {
  it("sums to 100 percent", () => {
    expect(sum(SUGGESTED_MIX)).toBe(100);
  });
});

describe("splitPieces", () => {
  it("sums exactly to the total, with non-negative integer parts, for every total 0..300", () => {
    for (let total = 0; total <= 300; total++) {
      const parts = splitPieces(total);
      expect(sum(parts), `total ${total}`).toBe(total);
      for (const k of MINIS_ORDER) {
        expect(Number.isInteger(parts[k]), `${k} @ ${total}`).toBe(true);
        expect(parts[k], `${k} @ ${total}`).toBeGreaterThanOrEqual(0);
      }
    }
  });

  it("splits 100 exactly by the suggested mix", () => {
    expect(splitPieces(100)).toEqual({ MINI_ZAATAR: 30, MINI_CHEESE: 30, MINI_MEAT: 25, MINI_PIES: 15 });
  });

  it("splits 20 as 6/6/5/3", () => {
    expect(splitPieces(20)).toEqual({ MINI_ZAATAR: 6, MINI_CHEESE: 6, MINI_MEAT: 5, MINI_PIES: 3 });
  });

  it("splits 50 as 15/15/13/7 (the .5 remainders go to the earlier kinds in MINIS_ORDER)", () => {
    // Exact shares are 15, 15, 12.5, 7.5; two remainders tie at .5 and one extra piece is left,
    // so the tie-break must hand it to MINI_MEAT (ahead of MINI_PIES in MINIS_ORDER).
    expect(splitPieces(50)).toEqual({ MINI_ZAATAR: 15, MINI_CHEESE: 15, MINI_MEAT: 13, MINI_PIES: 7 });
  });

  it("returns all zeros for a total of 0", () => {
    expect(splitPieces(0)).toEqual({ MINI_ZAATAR: 0, MINI_CHEESE: 0, MINI_MEAT: 0, MINI_PIES: 0 });
  });

  it("is deterministic across repeated calls", () => {
    expect(splitPieces(70)).toEqual(splitPieces(70));
  });

  it("normalises custom weights that do not sum to 100", () => {
    const parts = splitPieces(40, { MINI_ZAATAR: 1, MINI_CHEESE: 1, MINI_MEAT: 1, MINI_PIES: 1 });
    expect(parts).toEqual({ MINI_ZAATAR: 10, MINI_CHEESE: 10, MINI_MEAT: 10, MINI_PIES: 10 });
  });

  it("breaks exact ties in MINIS_ORDER with equal weights", () => {
    // 5 pieces over 4 equal kinds: one extra piece, and it goes to the first kind.
    expect(splitPieces(5, { MINI_ZAATAR: 1, MINI_CHEESE: 1, MINI_MEAT: 1, MINI_PIES: 1 })).toEqual({
      MINI_ZAATAR: 2,
      MINI_CHEESE: 1,
      MINI_MEAT: 1,
      MINI_PIES: 1,
    });
  });

  it("gives a kind with zero weight zero pieces", () => {
    const parts = splitPieces(30, { MINI_ZAATAR: 1, MINI_CHEESE: 1, MINI_MEAT: 0, MINI_PIES: 0 });
    expect(parts).toEqual({ MINI_ZAATAR: 15, MINI_CHEESE: 15, MINI_MEAT: 0, MINI_PIES: 0 });
  });

  it.each([-1, 1.5, NaN, Infinity])("throws for a bad total (%s)", (bad) => {
    expect(() => splitPieces(bad)).toThrow();
  });

  it("throws when the weights sum to zero or less", () => {
    expect(() => splitPieces(10, { MINI_ZAATAR: 0, MINI_CHEESE: 0, MINI_MEAT: 0, MINI_PIES: 0 })).toThrow();
    expect(() => splitPieces(10, { MINI_ZAATAR: -1, MINI_CHEESE: 0, MINI_MEAT: 0, MINI_PIES: 0 })).toThrow();
  });

  it("throws for a negative individual weight even when the total is positive", () => {
    // Otherwise one kind would get a negative piece count while the sum still matches.
    expect(() => splitPieces(10, { MINI_ZAATAR: -1, MINI_CHEESE: 3, MINI_MEAT: 0, MINI_PIES: 0 })).toThrow();
  });

  it("throws for NaN weights rather than returning NaN pieces", () => {
    expect(() => splitPieces(10, { MINI_ZAATAR: NaN, MINI_CHEESE: 1, MINI_MEAT: 1, MINI_PIES: 1 })).toThrow();
  });
});

describe("clampPieces", () => {
  it.each([
    [5, 20],
    [1000, 200],
    [47, 50],
    [45, 50],
    [44, 40],
    [20, 20],
    [200, 200],
    [NaN, 20],
    [Infinity, 20],
    [-Infinity, 20],
  ])("%s -> %s", (input, expected) => {
    expect(clampPieces(input)).toBe(expected);
  });

  it("always returns a value on the 10-piece grid within bounds", () => {
    for (let n = -50; n <= 400; n++) {
      const out = clampPieces(n);
      expect(out % 10).toBe(0);
      expect(out).toBeGreaterThanOrEqual(PACK_MIN);
      expect(out).toBeLessThanOrEqual(PACK_MAX);
    }
  });
});

describe("estimatePack", () => {
  it("estimates a 100-piece standard pack", () => {
    const e = estimatePack(100, "standard");
    expect(e).toMatchObject({
      pieces: 100,
      guests: 20,
      guestsMin: 12, // floor(100 / 8)
      guestsMax: 33, // floor(100 / 3)
      isLarge: true,
      needsCustomQuote: false,
    });
    expect(sum(e.split)).toBe(100);
  });

  it("defaults the appetite to standard", () => {
    expect(estimatePack(100)).toEqual(estimatePack(100, "standard"));
  });

  it("asks for a custom quote at the top of the slider", () => {
    expect(estimatePack(200).needsCustomQuote).toBe(true);
    expect(estimatePack(5000).needsCustomQuote).toBe(true); // clamped to 200
    expect(estimatePack(5000).pieces).toBe(200);
  });

  it("estimates the hearty appetite for the smallest pack", () => {
    const e = estimatePack(20, "hearty");
    expect(e.guests).toBe(2);
    expect(e.isLarge).toBe(false);
    expect(e.needsCustomQuote).toBe(false);
  });

  it("clamps out-of-range input before estimating", () => {
    expect(estimatePack(3).pieces).toBe(20);
    expect(estimatePack(NaN).pieces).toBe(20);
  });

  it("keeps guestsMin <= guests <= guestsMax for every appetite and size", () => {
    for (const appetite of Object.keys(PIECES_PER_GUEST) as (keyof typeof PIECES_PER_GUEST)[]) {
      for (let p = PACK_MIN; p <= PACK_MAX; p += 10) {
        const e = estimatePack(p, appetite);
        expect(e.guestsMin).toBeLessThanOrEqual(e.guests);
        expect(e.guests).toBeLessThanOrEqual(e.guestsMax);
      }
    }
  });

  it("marks isLarge from 100 pieces upwards only", () => {
    expect(estimatePack(90).isLarge).toBe(false);
    expect(estimatePack(100).isLarge).toBe(true);
  });
});
