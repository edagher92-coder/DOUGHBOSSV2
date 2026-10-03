import { parse } from "acorn";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { PINNED, assertPinned, build, checkReproducible, readFrameApproval, WEB_ROOT } from "../../tools/wp-hero/build.mjs";
import { BUDGETS } from "../../tools/wp-hero/budget.mjs";
import { AI_STILL_RE, BANNED_COPY, validateManifest, verifyFiles, type HeroBuildManifest } from "../../tools/wp-hero/manifest.mjs";

const ENV = { SOURCE_DATE_EPOCH: "1790000000" };
const NO_FRAMES = { framesApprovalFile: null, env: ENV };
const TIMEOUT = 90_000;

let tmp: string;
let dirA: string;
let dirB: string;
let built: HeroBuildManifest;

const read = (dir: string) => (p: string) => {
  try {
    return readFileSync(path.join(dir, p));
  } catch {
    return null;
  }
};
const listing = (dir: string, base = dir): string[] =>
  readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? listing(path.join(dir, e.name), base) : [path.relative(base, path.join(dir, e.name))],
  );

beforeAll(async () => {
  tmp = mkdtempSync(path.join(tmpdir(), "wp-hero-test-"));
  dirA = path.join(tmp, "a");
  dirB = path.join(tmp, "b");
  built = (await build({ outDir: dirA, ...NO_FRAMES })).manifest;
  await build({ outDir: dirB, ...NO_FRAMES });
}, TIMEOUT);

afterAll(() => {
  rmSync(tmp, { recursive: true, force: true });
});

describe("hero build: reproducibility", () => {
  it("two consecutive builds are byte-identical (every file)", () => {
    const a = listing(dirA).sort();
    const b = listing(dirB).sort();
    expect(a).toEqual(b);
    for (const f of a) expect(readFileSync(path.join(dirA, f)).equals(readFileSync(path.join(dirB, f)))).toBe(true);
  });

  it("checkReproducible reports no difference for a fresh build and flags a tampered file (negative control)", async () => {
    expect(await checkReproducible(dirA, NO_FRAMES)).toEqual([]);
    const copy = path.join(tmp, "copy");
    await build({ outDir: copy, ...NO_FRAMES });
    const js = readdirSync(copy).find((n) => n.endsWith(".js")) as string;
    writeFileSync(path.join(copy, js), readFileSync(path.join(copy, js), "utf8") + "\n//x");
    const diffs = await checkReproducible(copy, NO_FRAMES);
    expect(diffs.join("|")).toMatch(/differs: hero-webgl\./);
  }, TIMEOUT);

  it("a different SOURCE_DATE_EPOCH changes only the timestamp, not the shipped bytes", async () => {
    const other = path.join(tmp, "other");
    const m = (await build({ outDir: other, framesApprovalFile: null, env: { SOURCE_DATE_EPOCH: "1790000001" } })).manifest;
    expect(m.built_at).not.toBe(built.built_at);
    expect(m.files).toEqual(built.files);
    expect(m.source_sha256).toBe(built.source_sha256);
  }, TIMEOUT);

  it("without SOURCE_DATE_EPOCH the timestamp is the fixed placeholder, never the clock", async () => {
    const out = path.join(tmp, "noepoch");
    const m = (await build({ outDir: out, framesApprovalFile: null, env: {} })).manifest;
    expect(m.built_at).toBe("1970-01-01T00:00:00Z");
  }, TIMEOUT);
});

describe("hero build: output", () => {
  it("writes the script, the scene, and the manifest only (no frames without an approval)", () => {
    const files = listing(dirA).sort();
    expect(files).toHaveLength(3);
    expect(files.some((f) => /^hero-webgl\.[0-9a-f]{8}\.js$/.test(f))).toBe(true);
    expect(files.some((f) => /^hero-scene\.[0-9a-f]{8}\.glb$/.test(f))).toBe(true);
    expect(files).toContain("hero-manifest.json");
    expect(built.frames).toMatchObject({ included: false, count: 0, approval: null });
    expect(built.confirm.join("\n")).toMatch(/\[CONFIRM\] Tier-1 frames are NOT in this build/);
    expect(built.confirm.join("\n")).toMatch(/\[CONFIRM\] Elie must approve the ES5 exception/);
  });

  it("the manifest on disk is valid and every file matches its size, sha384 and name hash", () => {
    const onDisk = JSON.parse(readFileSync(path.join(dirA, "hero-manifest.json"), "utf8")) as HeroBuildManifest;
    expect(onDisk).toEqual(built);
    expect(validateManifest(onDisk)).toEqual([]);
    expect(verifyFiles(onDisk, read(dirA))).toEqual([]);
    expect(onDisk.three_version).toBe(PINNED.three);
    expect(onDisk.esbuild_version).toBe(PINNED.esbuild);
    expect(onDisk.source_sha256).toMatch(/^[0-9a-f]{64}$/);
    expect(onDisk.built_at).toBe("2026-09-21T14:13:20Z");
  });

  it("the scene is the pipeline GLB byte for byte", () => {
    const scene = built.files.find((f) => f.role === "scene") as { path: string };
    const src = readFileSync(path.join(WEB_ROOT, "public/hero/exploded-manoush.glb"));
    expect(readFileSync(path.join(dirA, scene.path)).equals(src)).toBe(true);
    expect(src.length).toBe(515_376);
  });

  it("the bundle is ES2018 module syntax and exports only boot", () => {
    const script = built.files.find((f) => f.role === "script") as { path: string };
    const code = readFileSync(path.join(dirA, script.path), "utf8");
    const ast = parse(code, { ecmaVersion: 2018, sourceType: "module" }) as unknown as {
      body: Array<{ type: string; specifiers?: Array<{ exported: { name: string } }>; source?: unknown }>;
    };
    const exports = ast.body.filter((n) => n.type === "ExportNamedDeclaration");
    expect(exports).toHaveLength(1);
    expect(exports[0]?.specifiers?.map((s) => s.exported.name)).toEqual(["boot"]);
    expect(ast.body.some((n) => n.type === "ImportDeclaration")).toBe(false); // self-contained
    // an ES2019+ construct must be rejected by the same parser (negative control)
    expect(() => parse("try{}catch{}", { ecmaVersion: 2018, sourceType: "module" })).toThrow();
  });

  it("stays inside the byte budgets and records the headroom", () => {
    const script = built.files.find((f) => f.role === "script") as { bytes: number };
    const scene = built.files.find((f) => f.role === "scene") as { bytes: number };
    expect(script.bytes).toBeLessThanOrEqual(BUDGETS.scriptRawBytes);
    expect(scene.bytes).toBeLessThanOrEqual(BUDGETS.glbBytes);
    expect(built.budgets.scriptGzipBytes).toBeLessThanOrEqual(BUDGETS.scriptGzipBytes);
    expect(built.budgets.scriptRawLimit).toBe(BUDGETS.scriptRawBytes);
  });

  it("never references the AI stills, the banned teaser word, or an absolute path", () => {
    for (const f of listing(dirA)) {
      if (f.endsWith(".glb")) continue;
      const text = readFileSync(path.join(dirA, f), "utf8");
      expect(AI_STILL_RE.test(text), f + " references the AI stills").toBe(false);
      expect(BANNED_COPY.test(text), f + " contains banned copy").toBe(false);
      expect(text.includes(WEB_ROOT), f + " leaks the checkout path").toBe(false);
      expect(/\/home\/[a-z]/i.test(text), f + " leaks a home path").toBe(false);
    }
    // the shipped GLB has no embedded reference either
    const glb = readFileSync(path.join(dirA, built.files.find((f) => f.role === "scene")?.path as string)).toString("latin1");
    expect(AI_STILL_RE.test(glb)).toBe(false);
    // the sources the build hashes do not include anything under hero/ai
    expect(built.sources.some((s) => AI_STILL_RE.test(s.path))).toBe(false);
  });
});

describe("hero build: fail-closed inputs", () => {
  const scratch = (name: string) => path.join(tmp, name);

  it("refuses a toolchain that is not the pinned one", () => {
    expect(() => assertPinned({ three: PINNED.three, esbuild: PINNED.esbuild })).not.toThrow();
    expect(() => assertPinned({ three: "0.187.0", esbuild: PINNED.esbuild })).toThrow(/three 0\.187\.0/);
    expect(() => assertPinned({ three: PINNED.three, esbuild: "0.29.0" })).toThrow(/esbuild 0\.29\.0/);
  });

  it("fails the build when the scene is over the GLB budget (negative control)", async () => {
    const big = scratch("big.glb");
    const body = Buffer.alloc(BUDGETS.glbBytes + 1000, 1);
    const header = Buffer.alloc(12);
    header.write("glTF", 0, "latin1");
    header.writeUInt32LE(2, 4);
    header.writeUInt32LE(12 + body.length, 8);
    writeFileSync(big, Buffer.concat([header, body]));
    await expect(build({ outDir: scratch("o1"), glbPath: big, ...NO_FRAMES })).rejects.toThrow(/hero budget exceeded[\s\S]*FAIL scene glb bytes/);
  }, TIMEOUT);

  it("fails the build when the JS is over its raw or gzip budget", async () => {
    await expect(build({ outDir: scratch("o2"), budgets: { ...BUDGETS, scriptRawBytes: 100_000 }, ...NO_FRAMES })).rejects.toThrow(/FAIL script raw bytes/);
    await expect(build({ outDir: scratch("o3"), budgets: { ...BUDGETS, scriptGzipBytes: 50_000 }, ...NO_FRAMES })).rejects.toThrow(/FAIL script gzip bytes/);
  }, TIMEOUT);

  it("rejects a scene file that is not a GLB container", async () => {
    const bad = scratch("bad.glb");
    writeFileSync(bad, Buffer.from("this is not a glb at all, just text padding"));
    await expect(build({ outDir: scratch("o4"), glbPath: bad, ...NO_FRAMES })).rejects.toThrow(/well-formed GLB/);
  }, TIMEOUT);

  it("rejects a pose manifest that breaks the node-name contract", async () => {
    const m = JSON.parse(readFileSync(path.join(WEB_ROOT, "public/hero/manifest.json"), "utf8"));
    m.nodes = m.nodes.filter((n: { name: string }) => n.name !== "Peel");
    const file = scratch("poses.json");
    writeFileSync(file, JSON.stringify(m));
    await expect(build({ outDir: scratch("o5"), poseManifestPath: file, ...NO_FRAMES })).rejects.toThrow(/missing node: Peel/);
  }, TIMEOUT);

  it("refuses to clean a directory that is not an earlier hero build", async () => {
    const dir = scratch("precious");
    mkdirSync(dir);
    writeFileSync(path.join(dir, "keep-me.txt"), "important");
    await expect(build({ outDir: dir, ...NO_FRAMES })).rejects.toThrow(/refusing to overwrite/);
    expect(readFileSync(path.join(dir, "keep-me.txt"), "utf8")).toBe("important");
  }, TIMEOUT);
});

describe("hero build: frames need a recorded owner approval", () => {
  const approvalFile = (obj: unknown, name: string) => {
    const f = path.join(tmp, name);
    writeFileSync(f, JSON.stringify(obj));
    return f;
  };
  const good = { approved: true, approved_by: "Elie Dagher", approved_on: "2026-10-02", scope: "owner-approved-stylised-render" };

  it("no approval file means no frames (the default)", () => {
    expect(readFrameApproval(path.join(tmp, "does-not-exist.json"))).toBeNull();
    expect(readFrameApproval(null)).toBeNull();
  });

  it("rejects an incomplete or placeholder approval (negative controls)", () => {
    expect(() => readFrameApproval(approvalFile({ ...good, approved: false }, "a1.json"))).toThrow(/approved must be true/);
    expect(() => readFrameApproval(approvalFile({ ...good, approved_by: "" }, "a2.json"))).toThrow(/approver/);
    expect(() => readFrameApproval(approvalFile({ ...good, approved_by: "[CONFIRM: who]" }, "a3.json"))).toThrow(/approver/);
    expect(() => readFrameApproval(approvalFile({ ...good, approved_on: "yesterday" }, "a4.json"))).toThrow(/YYYY-MM-DD/);
    expect(() => readFrameApproval(approvalFile({ ...good, scope: "ai-still" }, "a5.json"))).toThrow(/scope/);
  });

  it("refuses the AI stills directory and any path outside web/ as a frame source", async () => {
    for (const [i, dir] of ["public/hero/ai", "public/hero/ai/", "../outside"].entries()) {
      const f = approvalFile({ ...good, source_dir: dir }, "src" + i + ".json");
      await expect(build({ outDir: path.join(tmp, "fx" + i), framesApprovalFile: f, env: ENV })).rejects.toThrow(/AI stills|under web|missing/);
    }
  }, TIMEOUT);

  it("with an approval, includes the 24 + 24 pipeline frames as content-hashed files inside the budgets", async () => {
    const out = path.join(tmp, "withframes");
    const f = approvalFile(good, "good.json");
    const { manifest } = await build({ outDir: out, framesApprovalFile: f, env: ENV });
    const frames = manifest.files.filter((x) => x.role === "frame");
    expect(frames).toHaveLength(48);
    expect(frames.filter((x) => x.breakpoint === "sm")).toHaveLength(24);
    expect(frames.filter((x) => x.breakpoint === "lg")).toHaveLength(24);
    expect(frames.every((x) => /^frames\/(sm|lg)\/[0-9a-f]{8}-\d{2}\.webp$/.test(x.path))).toBe(true);
    expect(manifest.frames).toMatchObject({ included: true, count: 24, approval: { approved_by: "Elie Dagher", scope: "owner-approved-stylised-render" } });
    expect(manifest.confirm.join("\n")).not.toMatch(/frames are NOT in this build/);
    expect(validateManifest(manifest)).toEqual([]);
    expect(verifyFiles(manifest, read(out))).toEqual([]);
    const total = (bp: string) => frames.filter((x) => x.breakpoint === bp).reduce((s, x) => s + x.bytes, 0);
    expect(total("sm")).toBeLessThanOrEqual(BUDGETS.smTotalBytes);
    expect(total("lg")).toBeLessThanOrEqual(BUDGETS.lgTotalBytes);
    expect(Math.max(...frames.map((x) => x.bytes))).toBeLessThanOrEqual(BUDGETS.frameMaxBytes);
    // and a second build with frames is still byte-identical
    expect(await checkReproducible(out, { framesApprovalFile: f, env: ENV })).toEqual([]);
  }, TIMEOUT);

  it("fails the build when a frame is over its budget", async () => {
    await expect(
      build({ outDir: path.join(tmp, "fb"), framesApprovalFile: approvalFile(good, "good2.json"), budgets: { ...BUDGETS, frameMaxBytes: 10_000 }, env: ENV }),
    ).rejects.toThrow(/FAIL largest frame bytes/);
  }, TIMEOUT);
});

describe("committed dist", () => {
  const dist = path.join(WEB_ROOT, "tools/wp-hero/dist");

  it.skipIf(!existsSync(dist))("is valid, matches its own hashes and is what the current sources build (stale dist fails)", async () => {
    const m = JSON.parse(readFileSync(path.join(dist, "hero-manifest.json"), "utf8")) as HeroBuildManifest;
    expect(validateManifest(m)).toEqual([]);
    expect(verifyFiles(m, read(dist))).toEqual([]);
    expect(await checkReproducible(dist)).toEqual([]);
  }, TIMEOUT);
});
