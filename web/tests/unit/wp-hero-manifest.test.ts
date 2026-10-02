import { describe, expect, it } from "vitest";
import {
  AI_STILL_RE,
  BANNED_COPY,
  EPOCH_FALLBACK_ISO,
  buildManifest,
  fileEntry,
  frameName,
  resolveBuiltAt,
  sceneName,
  scriptName,
  serializeManifest,
  sha256Hex,
  sha384Sri,
  sha8,
  validateManifest,
  verifyFiles,
  type HeroBuildManifest,
} from "../../tools/wp-hero/manifest.mjs";

const script = Buffer.from("export const boot=1;");
const glb = Buffer.from("glTF-fake-scene");
const frameA = Buffer.from("RIFF0000WEBPa");
const frameB = Buffer.from("RIFF0000WEBPb");

function manifest(withFrames = false): HeroBuildManifest {
  const files = [fileEntry("script", scriptName(script), script), fileEntry("scene", sceneName(glb), glb)];
  if (withFrames) {
    for (const bp of ["sm", "lg"] as const) {
      files.push(fileEntry("frame", frameName(bp, 0, frameA), frameA, { breakpoint: bp, index: 0 }));
      files.push(fileEntry("frame", frameName(bp, 1, frameB), frameB, { breakpoint: bp, index: 1 }));
    }
  }
  return buildManifest({
    versions: { three: "0.186.1", esbuild: "0.28.2" },
    sources: [
      { path: "b.ts", sha256: sha256Hex("b") },
      { path: "a.ts", sha256: sha256Hex("a") },
    ],
    builtAt: "2026-10-02T00:00:00Z",
    files,
    scene: { nodeCount: 53 },
    frames: withFrames
      ? { included: true, count: 2, approval: { approved_by: "Elie Dagher", approved_on: "2026-10-02", scope: "real-photo" } }
      : { included: false, count: 0, approval: null },
    budgets: { scriptRawBytes: 1 },
    confirm: [],
  });
}

describe("hashing and names", () => {
  it("produces SRI sha384 tokens and content-hash names", () => {
    expect(sha384Sri(script)).toMatch(/^sha384-[A-Za-z0-9+/]{64}$/);
    expect(sha8(script)).toBe(sha256Hex(script).slice(0, 8));
    expect(scriptName(script)).toBe("hero-webgl." + sha8(script) + ".js");
    expect(sceneName(glb)).toBe("hero-scene." + sha8(glb) + ".glb");
    expect(frameName("sm", 7, frameA)).toBe("frames/sm/" + sha8(frameA) + "-07.webp");
  });

  it("changes the name when one byte changes", () => {
    expect(scriptName(Buffer.from("a"))).not.toBe(scriptName(Buffer.from("b")));
  });

  it("rejects an unknown breakpoint and an out-of-range frame index", () => {
    expect(() => frameName("md", 0, frameA)).toThrow(/breakpoint/);
    expect(() => frameName("sm", 100, frameA)).toThrow(/index/);
    expect(() => frameName("sm", -1, frameA)).toThrow(/index/);
  });
});

describe("build timestamp", () => {
  it("is reproducible: SOURCE_DATE_EPOCH or a fixed placeholder, never the clock", () => {
    expect(resolveBuiltAt({})).toBe(EPOCH_FALLBACK_ISO);
    expect(resolveBuiltAt({ SOURCE_DATE_EPOCH: "" })).toBe(EPOCH_FALLBACK_ISO);
    expect(resolveBuiltAt({ SOURCE_DATE_EPOCH: "1790000000" })).toBe("2026-09-21T14:13:20Z");
    expect(() => resolveBuiltAt({ SOURCE_DATE_EPOCH: "tomorrow" })).toThrow(/SOURCE_DATE_EPOCH/);
  });
});

describe("manifest generation", () => {
  it("is deterministic and independent of input order", () => {
    expect(serializeManifest(manifest())).toBe(serializeManifest(manifest()));
    const m = manifest();
    expect(m.sources.map((s) => s.path)).toEqual(["a.ts", "b.ts"]);
    expect(m.files.map((f) => f.role)).toEqual(["script", "scene"]);
  });

  it("carries path, bytes and sha384 for every file plus the pinned toolchain", () => {
    const m = manifest();
    expect(m.three_version).toBe("0.186.1");
    expect(m.esbuild_version).toBe("0.28.2");
    expect(m.source_sha256).toMatch(/^[0-9a-f]{64}$/);
    expect(m.built_at).toBe("2026-10-02T00:00:00Z");
    for (const f of m.files) {
      expect(typeof f.path).toBe("string");
      expect(f.bytes).toBeGreaterThan(0);
      expect(f.sha384).toMatch(/^sha384-/);
    }
  });

  it("source_sha256 changes when a source or a pinned version changes", () => {
    const base = manifest().source_sha256;
    const changed = buildManifest({
      versions: { three: "0.186.2", esbuild: "0.28.2" },
      sources: [
        { path: "a.ts", sha256: sha256Hex("a") },
        { path: "b.ts", sha256: sha256Hex("b") },
      ],
      builtAt: "2026-10-02T00:00:00Z",
      files: manifest().files,
      scene: {},
      frames: { included: false },
      budgets: {},
      confirm: [],
    });
    expect(changed.source_sha256).not.toBe(base);
  });
});

describe("manifest validation", () => {
  it("accepts a manifest without frames and one with approved frames", () => {
    expect(validateManifest(manifest())).toEqual([]);
    expect(validateManifest(manifest(true))).toEqual([]);
  });

  it("rejects frames without a recorded owner approval", () => {
    const m = manifest(true);
    m.frames.approval = null;
    expect(validateManifest(m)).toContain("frames included without a recorded owner approval");
  });

  it("rejects frame files when frames.included is false, and unequal sm/lg counts", () => {
    const m = manifest(true);
    m.frames = { included: false, count: 0, approval: null };
    expect(validateManifest(m).join("|")).toMatch(/frame files present/);
    const n = manifest(true);
    n.files = n.files.filter((f) => !(f.breakpoint === "lg" && f.index === 1));
    expect(validateManifest(n).join("|")).toMatch(/same non-zero number/);
  });

  it("rejects bad hashes, missing required files and duplicate paths", () => {
    const m = manifest();
    (m.files[0] as { sha384: string }).sha384 = "sha384-short";
    expect(validateManifest(m).join("|")).toMatch(/sha384 must be an SRI token/);
    const n = manifest();
    n.files = n.files.slice(1);
    expect(validateManifest(n)).toContain("exactly one script file is required");
    const d = manifest();
    d.files = [d.files[0] as (typeof d.files)[number], d.files[0] as (typeof d.files)[number], d.files[1] as (typeof d.files)[number]];
    expect(validateManifest(d).join("|")).toMatch(/duplicate path/);
  });

  it("rejects unsafe paths (traversal, absolute, backslash)", () => {
    for (const bad of ["../hero-webgl.deadbeef.js", "/hero-webgl.deadbeef.js", "a\\b.js", "frames//x.webp"]) {
      const m = manifest();
      (m.files[0] as { path: string }).path = bad;
      expect(validateManifest(m).join("|")).toMatch(/unsafe or empty path/);
    }
  });

  it("rejects a script whose name is not content-hash shaped", () => {
    const m = manifest();
    (m.files[0] as { path: string }).path = "hero-webgl.js";
    expect(validateManifest(m).join("|")).toMatch(/script path must be/);
  });

  it("rejects banned teaser copy and any reference to the AI stills", () => {
    const word = "Mi" + "nis";
    expect(BANNED_COPY.test(word)).toBe(true);
    expect(BANNED_COPY.test("terminis geminis")).toBe(false); // whole word only
    const m = manifest();
    m.confirm = ["Coming soon: " + word];
    expect(validateManifest(m)).toContain("manifest contains banned teaser copy");
    const n = manifest();
    n.confirm = ["see public/hero/ai/manoush-blowout-still.webp"];
    expect(validateManifest(n)).toContain("manifest references the AI stills");
    expect(AI_STILL_RE.test("public/hero/exploded-manoush.glb")).toBe(false);
  });

  it("rejects non-objects and malformed headers", () => {
    expect(validateManifest(null)).toEqual(["manifest is not an object"]);
    expect(validateManifest({}).length).toBeGreaterThan(3);
  });
});

describe("file verification", () => {
  const store: Record<string, Buffer> = {
    [scriptName(script)]: script,
    [sceneName(glb)]: glb,
  };
  const read = (p: string) => store[p] ?? null;

  it("passes for untouched files", () => {
    expect(verifyFiles(manifest(), read)).toEqual([]);
  });

  it("detects a flipped byte, a truncated file and a missing file (negative control)", () => {
    const flipped = Buffer.from(script);
    flipped[3] = (flipped[3] as number) ^ 1;
    expect(verifyFiles(manifest(), (p) => (p === scriptName(script) ? flipped : read(p))).join("|")).toMatch(/sha384 mismatch/);
    expect(verifyFiles(manifest(), (p) => (p === sceneName(glb) ? glb.subarray(0, 4) : read(p))).join("|")).toMatch(/size/);
    expect(verifyFiles(manifest(), (p) => (p === sceneName(glb) ? null : read(p)))).toContain(sceneName(glb) + ": missing");
  });

  it("detects a file whose name hash no longer matches its content", () => {
    const m = manifest();
    const renamed = fileEntry("script", "hero-webgl.00000000.js", script);
    m.files[0] = renamed;
    const errors = verifyFiles(m, (p) => (p === "hero-webgl.00000000.js" ? script : read(p)));
    expect(errors.join("|")).toMatch(/file name hash does not match content/);
  });
});
