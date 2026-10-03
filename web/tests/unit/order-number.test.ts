import { describe, expect, it } from "vitest";
import { CROCKFORD, STORE_CODES, generateOrderNumber } from "@/lib/order-number";
import type { StoreSlug } from "@/types/menu";

const FORMAT = /^DB-[A-Z]{3}-[0-9A-HJKMNP-TV-Z]{6}$/;
const SLUGS: StoreSlug[] = ["revesby", "bankstown", "roselands"];

describe("generateOrderNumber", () => {
  it("matches the documented format for every store, with the default crypto rng", () => {
    for (const slug of SLUGS) {
      for (let i = 0; i < 200; i++) expect(generateOrderNumber(slug)).toMatch(FORMAT);
    }
  });

  it("uses the store's three-letter code", () => {
    expect(generateOrderNumber("revesby")).toMatch(/^DB-REV-/);
    expect(Object.values(STORE_CODES)).toEqual(["REV", "BAN", "ROS"]);
    expect(new Set(Object.values(STORE_CODES)).size).toBe(3);
  });

  it("uses a 32-character Crockford alphabet without I, L, O or U", () => {
    expect(CROCKFORD).toHaveLength(32);
    expect(new Set(CROCKFORD).size).toBe(32);
    expect(CROCKFORD).not.toMatch(/[ILOU]/);
  });

  it("is deterministic with an injected rng and reaches both ends of the alphabet", () => {
    expect(generateOrderNumber("bankstown", () => 0)).toBe("DB-BAN-000000");
    expect(generateOrderNumber("roselands", () => 31)).toBe("DB-ROS-ZZZZZZ");
    const seq = [4, 15, 20, 18, 2, 26]; // arbitrary indexes into the alphabet
    let i = 0;
    const out = generateOrderNumber("revesby", () => seq[i++] ?? 0);
    expect(out).toBe(`DB-REV-${seq.map((n) => CROCKFORD[n]).join("")}`);
    expect(out).toMatch(FORMAT);
  });

  it("asks the rng for numbers below the alphabet size", () => {
    const seen: number[] = [];
    generateOrderNumber("revesby", (max) => {
      seen.push(max);
      return 0;
    });
    expect(seen).toEqual([32, 32, 32, 32, 32, 32]);
  });

  it("rejects an rng that returns an out-of-range or fractional value", () => {
    expect(() => generateOrderNumber("revesby", () => 32)).toThrow(RangeError);
    expect(() => generateOrderNumber("revesby", () => -1)).toThrow(RangeError);
    expect(() => generateOrderNumber("revesby", () => 1.5)).toThrow(RangeError);
  });

  it("produces varied numbers (no accidental constant)", () => {
    const set = new Set(Array.from({ length: 200 }, () => generateOrderNumber("revesby")));
    expect(set.size).toBeGreaterThan(190);
  });
});
