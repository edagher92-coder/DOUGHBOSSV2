/* eslint-disable @typescript-eslint/no-explicit-any -- loosely typed JSON fixtures and event logs in tests */
import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { NODE_NAMES, compactPoses, validatePoseManifest } from "../../tools/wp-hero/contract.mjs";
import { FrameWindow, median } from "../../tools/wp-hero/stats";
import { fovForAspect, frameProgress, nodeEase, poseAt, slerpQuat, smootherstep, type PoseRow } from "../../tools/wp-hero/pose";

const POSE_PATH = path.resolve(__dirname, "../../public/hero/manifest.json");
const real = (): any => JSON.parse(readFileSync(POSE_PATH, "utf8"));

describe("node-name contract", () => {
  it("lists exactly 53 unique names", () => {
    expect(NODE_NAMES).toHaveLength(53);
    expect(new Set(NODE_NAMES).size).toBe(53);
    expect(NODE_NAMES).toContain("Peel");
    expect(NODE_NAMES).toContain("slice7_topping");
    expect(NODE_NAMES).toContain("mint_11");
    expect(NODE_NAMES).toContain("chili_15");
  });

  it("accepts the shipped pose manifest", () => {
    expect(validatePoseManifest(real())).toEqual([]);
  });

  it("rejects a missing node (negative control)", () => {
    const m = real();
    m.nodes = m.nodes.filter((n: any) => n.name !== "slice3_rim");
    expect(validatePoseManifest(m)).toContain("missing node: slice3_rim");
  });

  it("rejects a duplicated node and an unexpected name", () => {
    const m = real();
    m.nodes.push({ ...m.nodes[1] });
    m.nodes.push({ ...m.nodes[2], name: "Garlic_00" });
    const errors = validatePoseManifest(m);
    expect(errors).toContain("duplicate node: slice0_dough");
    expect(errors).toContain("unexpected node name: Garlic_00");
  });

  it("rejects a non-unit quaternion, a NaN position and an out-of-range delay", () => {
    const m = real();
    m.nodes[1].rest.quaternion = [0, 0, 0, 2];
    m.nodes[2].exploded.position = [0, Number.NaN, 0];
    m.nodes[3].delay = 0.9;
    const errors = validatePoseManifest(m);
    expect(errors).toContain("slice0_dough: invalid rest pose");
    expect(errors).toContain("slice0_rim: invalid exploded pose");
    expect(errors).toContain("slice0_topping: delay must be a number in [0, maxDelay]");
  });

  it("compactPoses is canonical-order and throws on a broken manifest", () => {
    const m = real();
    m.nodes.reverse();
    const poses = compactPoses(m);
    expect(poses.nodes.map((r) => r[0])).toEqual(Array.from(NODE_NAMES));
    expect(poses.maxDelay).toBe(0.3);
    const broken = real();
    broken.nodes.pop();
    expect(() => compactPoses(broken)).toThrow(/pose manifest invalid/);
  });
});

describe("pose maths", () => {
  it("smootherstep is 0 at 0, 1 at 1, 0.5 at 0.5 and clamps", () => {
    expect(smootherstep(0)).toBe(0);
    expect(smootherstep(1)).toBe(1);
    expect(smootherstep(0.5)).toBeCloseTo(0.5, 12);
    expect(smootherstep(-3)).toBe(0);
    expect(smootherstep(7)).toBe(1);
    expect(smootherstep(Number.NaN)).toBe(0);
  });

  it("matches the documented formula t=clamp((p-delay)/(1-maxDelay))", () => {
    const p = 0.5;
    const delay = 0.2;
    const t = (p - delay) / 0.7;
    expect(nodeEase(p, delay, 0.3)).toBeCloseTo(t * t * t * (t * (6 * t - 15) + 10), 12);
    expect(nodeEase(0.1, 0.2, 0.3)).toBe(0); // has not started yet
    expect(nodeEase(1, 0.3, 0.3)).toBe(1); // every node has finished at p = 1
  });

  it("rest at p=0 and exploded at p=1 for every shipped node", () => {
    const poses = compactPoses(real());
    for (const row of poses.nodes as PoseRow[]) {
      const start = poseAt(row, 0, poses.maxDelay);
      const end = poseAt(row, 1, poses.maxDelay);
      row[1].forEach((v, i) => expect(start.position[i]).toBeCloseTo(v, 9));
      row[3].forEach((v, i) => expect(end.position[i]).toBeCloseTo(v, 9));
      row[2].forEach((v, i) => expect(start.quaternion[i]).toBeCloseTo(v, 5));
      // quaternion sign is free (q and -q are the same rotation): compare |dot|
      const dot = Math.abs(row[4].reduce((s, v, i) => s + v * (end.quaternion[i] as number), 0));
      expect(dot).toBeCloseTo(1, 5);
    }
  });

  it("ripples outward-in: the crust ring leaves before the dough slides out", () => {
    const poses = compactPoses(real());
    const delayOf = (n: string) => (poses.nodes.find((r) => r[0] === n) as PoseRow)[5];
    expect(delayOf("slice0_rim")).toBeLessThan(delayOf("slice0_topping"));
    expect(delayOf("slice0_topping")).toBeLessThan(delayOf("slice0_dough"));
  });

  it("slerp takes the short way round and stays unit length", () => {
    const out = [0, 0, 0, 1];
    slerpQuat([0, 0, 0, 1], [0, 0, 0, -1], 0.5, out); // same rotation, opposite sign
    expect(Math.abs(out[3] as number)).toBeCloseTo(1, 9);
    const half = Math.SQRT1_2;
    slerpQuat([0, 0, 0, 1], [0, half, 0, half], 0.5, out);
    expect(Math.hypot(...out)).toBeCloseTo(1, 12);
    expect(out[1]).toBeCloseTo(Math.sin(Math.PI / 8), 9);
  });

  it("frameProgress follows p = k/(N-1)", () => {
    expect(frameProgress(0, 24)).toBe(0);
    expect(frameProgress(23, 24)).toBe(1);
    expect(frameProgress(12, 24)).toBeCloseTo(12 / 23, 12);
    expect(frameProgress(5, 1)).toBe(0);
  });

  it("widens the vertical FOV only for portrait viewports", () => {
    expect(fovForAspect(54.432, 1)).toBe(54.432);
    expect(fovForAspect(54.432, 16 / 9)).toBe(54.432);
    expect(fovForAspect(54.432, 0.5)).toBeGreaterThan(54.432);
    expect(fovForAspect(54.432, 0)).toBe(54.432);
  });
});

describe("frame statistics", () => {
  it("median handles odd, even and empty input", () => {
    expect(median([])).toBeNull();
    expect(median([3, 1, 2])).toBe(2);
    expect(median([4, 1, 2, 3])).toBe(2.5);
  });

  it("collects deltas, ignores bad timestamps and reports exceeded only for a full window", () => {
    const w = new FrameWindow(3, 24);
    w.push(0);
    w.push(Number.NaN);
    w.push(30);
    expect(w.stats()).toMatchObject({ sampleCount: 1, medianMs: 30, exceeded: false }); // window not full yet
    w.push(60);
    w.push(90);
    expect(w.complete).toBe(true);
    expect(w.stats()).toMatchObject({ sampleCount: 3, medianMs: 30, exceeded: true });
  });

  it("a long gap does not count when the sequence is broken (tab was hidden)", () => {
    const w = new FrameWindow(2, 24);
    w.push(0);
    w.push(16);
    w.breakSequence();
    w.push(60000); // first frame after the break only primes the clock
    w.push(60016);
    expect(w.stats().medianMs).toBe(16);
    expect(w.stats().exceeded).toBe(false);
  });

  it("the median ignores a few slow frames (outliers)", () => {
    const w = new FrameWindow(60, 24);
    let t = 0;
    w.push(t);
    for (let i = 0; i < 60; i += 1) {
      t += i % 12 === 0 ? 250 : 16.7;
      w.push(t);
    }
    expect(w.stats().medianMs).toBeCloseTo(16.7, 6);
    expect(w.stats().exceeded).toBe(false);
  });
});
