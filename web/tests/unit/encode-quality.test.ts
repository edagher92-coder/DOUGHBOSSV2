import { describe, expect, it } from "vitest";
// @ts-expect-error plain .mjs tool module without type declarations
import { MIN_QUALITY, nextQuality } from "../../tools/blender/quality.mjs";

describe("encode_frames quality ladder", () => {
  it("steps 78, 72, 66, 60, 54, then clamps to the floor of 50 (never 48)", () => {
    const seen: number[] = [78];
    while (seen[seen.length - 1]! > MIN_QUALITY) seen.push(nextQuality(seen[seen.length - 1]!));
    expect(seen).toEqual([78, 72, 66, 60, 54, 50]);
    expect(Math.min(...seen)).toBeGreaterThanOrEqual(MIN_QUALITY);
  });

  it("stays at the floor once reached", () => {
    expect(nextQuality(MIN_QUALITY)).toBe(MIN_QUALITY);
  });
});
