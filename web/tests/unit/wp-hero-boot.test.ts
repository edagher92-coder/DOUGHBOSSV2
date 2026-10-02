/* eslint-disable @typescript-eslint/no-explicit-any -- loosely typed JSON fixtures and event logs in tests */
import { createHash } from "node:crypto";
import { describe, expect, it } from "vitest";
import { createBoot, type BootDeps, type BootOptions, type HeroManifest } from "../../tools/wp-hero/boot-core";
import type { HeroScene } from "../../tools/wp-hero/scene";

const GLB = new Uint8Array(64).fill(7).buffer as ArrayBuffer;
const sri = (buf: ArrayBuffer) => "sha384-" + createHash("sha384").update(Buffer.from(buf)).digest("base64");
const manifest = (): HeroManifest => ({
  baseUrl: "https://example.test/vendor/",
  files: [
    { role: "script", path: "hero-webgl.aaaaaaaa.js", bytes: 10, sha384: sri(new ArrayBuffer(1)) },
    { role: "scene", path: "hero-scene.bbbbbbbb.glb", bytes: GLB.byteLength, sha384: sri(GLB) },
  ],
});

class FakeScene implements HeroScene {
  progress: number[] = [];
  renders = 0;
  disposed = 0;
  setProgress(p: number) {
    this.progress.push(p);
  }
  render() {
    this.renders += 1;
  }
  resize() {}
  dispose() {
    this.disposed += 1;
  }
}

function harness(over: Partial<BootDeps> = {}) {
  const scene = new FakeScene();
  const doc = {
    hidden: false,
    listeners: new Set<() => void>(),
    addEventListener(_t: string, fn: () => void) {
      this.listeners.add(fn);
    },
    removeEventListener(_t: string, fn: () => void) {
      this.listeners.delete(fn);
    },
    setHidden(v: boolean) {
      this.hidden = v;
      this.listeners.forEach((fn) => fn());
    },
  };
  const raf = { queue: new Map<number, (ts: number) => void>(), next: 1, now: 0, cancelled: 0 };
  const io = { cb: null as null | ((e: Array<{ isIntersecting: boolean }>) => void), disconnected: 0 };
  const fetched: Array<{ url: string; integrity: string }> = [];
  const deps: BootDeps = {
    createScene: async () => scene,
    requestAnimationFrame: (cb) => {
      const id = raf.next++;
      raf.queue.set(id, cb);
      return id;
    },
    cancelAnimationFrame: (id) => {
      raf.cancelled += 1;
      raf.queue.delete(id);
    },
    document: doc,
    IntersectionObserver: class {
      constructor(cb: (e: Array<{ isIntersecting: boolean }>) => void) {
        io.cb = cb;
      }
      observe() {}
      disconnect() {
        io.disconnected += 1;
      }
    },
    matchMedia: () => ({ matches: false }),
    fetch: async (url, init) => {
      fetched.push({ url, integrity: init.integrity });
      return { ok: true, status: 200, arrayBuffer: async () => GLB };
    },
    digest384: async (buf) => sri(buf),
    ...over,
  };
  const boot = createBoot(() => deps);
  /** Run one animation frame at +dt ms; returns false when nothing was scheduled. */
  const frame = (dt: number): boolean => {
    const entry = [...raf.queue.entries()][0];
    if (!entry) return false;
    raf.queue.delete(entry[0]);
    raf.now += dt;
    entry[1](raf.now);
    return true;
  };
  const frames = (n: number, dt: number) => {
    for (let i = 0; i < n; i += 1) frame(dt);
  };
  const settle = async () => {
    for (let i = 0; i < 6; i += 1) await Promise.resolve();
  };
  const canvas = {} as HTMLCanvasElement;
  return { boot, scene, doc, raf, io, fetched, frame, frames, settle, canvas, deps };
}

function hooks() {
  const log: Array<{ type: string; detail: any }> = [];
  const options: BootOptions = {
    onReady: (d) => log.push({ type: "ready", detail: d }),
    onFrameBudgetExceeded: (m, s) => log.push({ type: "budget", detail: { m, s } }),
    onError: (e) => log.push({ type: "error", detail: e }),
    onComplete: () => log.push({ type: "complete", detail: null }),
  };
  return { log, options };
}

async function ready(h: ReturnType<typeof harness>, extra: BootOptions = {}) {
  const { log, options } = hooks();
  const hero = h.boot(h.canvas, manifest(), { ...options, ...extra });
  await h.settle();
  h.frames(61, 16.7); // 61 timestamps = 60 deltas
  return { hero, log };
}

describe("boot: loading and the frame-time guard", () => {
  it("fetches the scene with its sha384 integrity and reaches ready with the median frame time", async () => {
    const h = harness();
    const { hero, log } = await ready(h);
    expect(h.fetched).toEqual([{ url: "https://example.test/vendor/hero-scene.bbbbbbbb.glb", integrity: manifest().files[1]?.sha384 }]);
    expect(log.map((e) => e.type)).toEqual(["ready"]);
    expect(log[0]?.detail).toMatchObject({ renderer: "webgl2", sampleFrames: 60 });
    expect(log[0]?.detail.medianFrameMs).toBeCloseTo(16.7, 6);
    expect(hero.state).toBe("ready");
    expect(hero.getStats()).toMatchObject({ sampleCount: 60, exceeded: false });
    expect(h.scene.progress[h.scene.progress.length - 1]).toBe(0); // back to rest after the probe
    expect(h.scene.progress.some((p) => p > 0.9)).toBe(true); // the probe swept the explosion
  });

  it("a median over budget calls onFrameBudgetExceeded once, disposes, and never becomes ready (negative control)", async () => {
    const h = harness();
    const { log, options } = hooks();
    const hero = h.boot(h.canvas, manifest(), options);
    await h.settle();
    h.frames(61, 40);
    expect(log.map((e) => e.type)).toEqual(["budget"]);
    expect(log[0]?.detail.m).toBeCloseTo(40, 6);
    expect(log[0]?.detail.s.exceeded).toBe(true);
    expect(hero.state).toBe("failed");
    expect(h.scene.disposed).toBe(1);
    expect(h.frame(16)).toBe(false); // nothing scheduled any more
  });

  it("honours a custom budget and sample window", async () => {
    const h = harness();
    const { log, options } = hooks();
    h.boot(h.canvas, manifest(), { ...options, frameBudgetMs: 10, sampleFrames: 5 });
    await h.settle();
    h.frames(6, 16.7);
    expect(log[0]?.type).toBe("budget");
  });

  it("sampleFrames 0 skips the probe and is ready immediately", async () => {
    const h = harness();
    const { log, options } = hooks();
    h.boot(h.canvas, manifest(), { ...options, sampleFrames: 0 });
    await h.settle();
    expect(log.map((e) => e.type)).toEqual(["ready"]);
  });

  it("the median ignores a handful of slow frames", async () => {
    const h = harness();
    const { log, options } = hooks();
    h.boot(h.canvas, manifest(), options);
    await h.settle();
    h.frame(16);
    for (let i = 0; i < 60; i += 1) h.frame(i % 10 === 0 ? 90 : 16.7);
    expect(log.map((e) => e.type)).toEqual(["ready"]);
  });

  it("uses an already-fetched GLB when it matches the manifest hash, without fetching", async () => {
    const h = harness();
    const { log, options } = hooks();
    h.boot(h.canvas, manifest(), { ...options, glb: GLB, sampleFrames: 0 });
    await h.settle();
    expect(h.fetched).toHaveLength(0);
    expect(log.map((e) => e.type)).toEqual(["ready"]);
  });
});

describe("boot: fail closed", () => {
  const failing = async (m: HeroManifest, over: Partial<BootDeps> = {}, opts: BootOptions = {}) => {
    const h = harness(over);
    const { log, options } = hooks();
    const hero = h.boot(h.canvas, m, { ...options, ...opts });
    await h.settle();
    return { h, hero, log };
  };

  it("reduced motion: inert, no fetch, state failed", async () => {
    const { h, hero, log } = await failing(manifest(), { matchMedia: (q) => ({ matches: /reduce/.test(q) }) });
    expect(log[0]).toMatchObject({ type: "error", detail: { code: "reduced-motion" } });
    expect(h.fetched).toHaveLength(0);
    expect(hero.state).toBe("failed");
  });

  it("an unreadable motion preference is treated as reduced motion", async () => {
    const { log } = await failing(manifest(), {
      matchMedia: () => {
        throw new Error("boom");
      },
    });
    expect(log[0]?.detail.code).toBe("reduced-motion");
  });

  it("no scene file, malformed sha384, and empty manifest all fail before any request", async () => {
    const noScene = await failing({ files: [] });
    expect(noScene.log[0]?.detail.code).toBe("no-scene-file");
    const m = manifest();
    (m.files[1] as { sha384: string }).sha384 = "sha384-nope";
    const badHash = await failing(m);
    expect(badHash.log[0]?.detail.code).toBe("integrity-missing");
    expect(badHash.h.fetched).toHaveLength(0);
    const none = await failing(null as unknown as HeroManifest);
    expect(none.log[0]?.detail.code).toBe("no-scene-file");
  });

  it("a rejected fetch (what a failed SRI check looks like) and an HTTP error both fail closed", async () => {
    const rejected = await failing(manifest(), {
      fetch: async () => {
        throw new TypeError("Failed to fetch");
      },
    });
    expect(rejected.log[0]?.detail.code).toBe("fetch-failed");
    const http = await failing(manifest(), { fetch: async () => ({ ok: false, status: 404, arrayBuffer: async () => GLB }) });
    expect(http.log[0]?.detail.code).toBe("fetch-failed");
    expect(http.hero.state).toBe("failed");
  });

  it("a fetched body whose size differs from the manifest is rejected", async () => {
    const { log } = await failing(manifest(), { fetch: async () => ({ ok: true, status: 200, arrayBuffer: async () => new ArrayBuffer(3) }) });
    expect(log[0]?.detail.code).toBe("integrity-mismatch");
  });

  it("a supplied GLB with the wrong hash or size is rejected, and so is one that cannot be verified", async () => {
    const tampered = new Uint8Array(GLB.byteLength).fill(9).buffer as ArrayBuffer;
    expect((await failing(manifest(), {}, { glb: tampered })).log[0]?.detail.code).toBe("integrity-mismatch");
    expect((await failing(manifest(), {}, { glb: new ArrayBuffer(5) })).log[0]?.detail.code).toBe("integrity-mismatch");
    expect((await failing(manifest(), { digest384: null }, { glb: GLB })).log[0]?.detail.code).toBe("integrity-unverifiable");
  });

  it("a scene that rejects (contract mismatch, no WebGL2) reports its code and is not ready", async () => {
    const { log, hero } = await failing(manifest(), {
      createScene: async () => {
        throw Object.assign(new Error("GLB node 'Peel' must exist exactly once"), { code: "contract" });
      },
    });
    expect(log).toHaveLength(1);
    expect(log[0]?.detail).toMatchObject({ code: "contract" });
    expect(hero.state).toBe("failed");
  });

  it("an unexpected error without a code is reported as scene-failed", async () => {
    const { log } = await failing(manifest(), {
      createScene: async () => {
        throw new Error("kaboom");
      },
    });
    expect(log[0]?.detail.code).toBe("scene-failed");
  });

  it("a throwing hook cannot corrupt the lifecycle", async () => {
    const h = harness();
    const hero = h.boot(h.canvas, manifest(), {
      onReady: () => {
        throw new Error("hook bug");
      },
    });
    await h.settle();
    h.frames(61, 16);
    expect(hero.state).toBe("ready");
  });

  it("start-up errors are reported after boot() returns, never synchronously", () => {
    const h = harness({ matchMedia: () => ({ matches: true }) });
    let calls = 0;
    h.boot(h.canvas, manifest(), { onError: () => (calls += 1) });
    expect(calls).toBe(0);
  });
});

describe("boot: pause, visibility and disposal", () => {
  it("renders nothing while the tab is hidden and ignores the gap when it returns", async () => {
    const h = harness();
    const { log, options } = hooks();
    h.boot(h.canvas, manifest(), options);
    await h.settle();
    h.frames(20, 16.7);
    h.doc.setHidden(true);
    const rendersBefore = h.scene.renders;
    expect(h.frame(16.7)).toBe(false);
    expect(h.raf.queue.size).toBe(0);
    expect(h.scene.renders).toBe(rendersBefore);
    h.raf.now += 60_000; // a minute in the background
    h.doc.setHidden(false);
    h.frames(42, 16.7);
    expect(log.map((e) => e.type)).toEqual(["ready"]); // the 60 s gap never became a sample
    expect(log[0]?.detail.medianFrameMs).toBeCloseTo(16.7, 6);
  });

  it("pauses when the canvas leaves the viewport and resumes when it returns", async () => {
    const h = harness();
    const { hero } = await ready(h);
    hero.setProgress(0.5);
    h.io.cb?.([{ isIntersecting: false }]);
    expect(h.frame(16)).toBe(false);
    h.io.cb?.([{ isIntersecting: true }]);
    expect(h.frame(16)).toBe(true);
  });

  it("user pause()/resume() stops and restarts frames", async () => {
    const h = harness();
    const { hero } = await ready(h);
    hero.play();
    hero.pause();
    expect(h.frame(16)).toBe(false);
    hero.resume();
    expect(h.frame(16)).toBe(true);
  });

  it("stop() disposes the scene, cancels the frame, removes listeners and is idempotent", async () => {
    const h = harness();
    const { hero } = await ready(h);
    hero.play();
    expect(h.raf.queue.size).toBe(1);
    hero.stop();
    hero.stop();
    expect(hero.state).toBe("stopped");
    expect(h.scene.disposed).toBe(1);
    expect(h.raf.queue.size).toBe(0);
    expect(h.doc.listeners.size).toBe(0);
    expect(h.io.disconnected).toBe(1);
    hero.play();
    hero.setProgress(1);
    expect(h.raf.queue.size).toBe(0); // inert after stop
  });

  it("stop() while the scene is still loading disposes it as soon as it exists", async () => {
    let resolveScene: (s: HeroScene) => void = () => {};
    const h = harness({ createScene: () => new Promise<HeroScene>((r) => (resolveScene = r)) });
    const { log, options } = hooks();
    const hero = h.boot(h.canvas, manifest(), options);
    await h.settle();
    hero.stop();
    resolveScene(h.scene);
    await h.settle();
    expect(h.scene.disposed).toBe(1);
    expect(log).toHaveLength(0);
    expect(hero.state).toBe("stopped");
  });
});

describe("boot: playback (no autonomous loop)", () => {
  it("is idle after ready: no frame is scheduled until something changes", async () => {
    const h = harness();
    await ready(h);
    expect(h.raf.queue.size).toBe(0);
  });

  it("play() sweeps to 1 over the duration, reports onComplete once, then goes idle", async () => {
    const h = harness();
    const { hero, log } = await ready(h, { durationMs: 1000 });
    log.length = 0;
    hero.play();
    expect(hero.state).toBe("playing");
    for (let i = 0; i < 25; i += 1) h.frame(50);
    expect(log.map((e) => e.type)).toEqual(["complete"]);
    expect(h.scene.progress[h.scene.progress.length - 1]).toBe(1);
    expect(hero.state).toBe("ready");
    expect(h.raf.queue.size).toBe(0);
  });

  it("a long frame gap is clamped so the animation never jumps", async () => {
    const h = harness();
    const { hero } = await ready(h, { durationMs: 1000 });
    hero.play();
    h.frame(16); // primes the clock
    h.frame(5000);
    expect(h.scene.progress[h.scene.progress.length - 1]).toBeLessThanOrEqual(0.11);
  });

  it("setProgress() clamps, ignores junk and renders once for scroll-linked use", async () => {
    const h = harness();
    const { hero } = await ready(h);
    hero.setProgress(5);
    h.frame(16);
    expect(h.scene.progress[h.scene.progress.length - 1]).toBe(1);
    hero.setProgress(Number.NaN);
    h.frame(16);
    expect(h.scene.progress[h.scene.progress.length - 1]).toBe(0);
    expect(h.raf.queue.size).toBe(0);
  });

  it("replay() restarts from rest", async () => {
    const h = harness();
    const { hero, log } = await ready(h, { durationMs: 200 });
    hero.setProgress(1);
    h.frame(16);
    log.length = 0;
    hero.replay();
    h.frame(16);
    expect(h.scene.progress[h.scene.progress.length - 1]).toBeLessThan(0.2);
    for (let i = 0; i < 10; i += 1) h.frame(50);
    expect(log.map((e) => e.type)).toEqual(["complete"]);
  });

  it("play/replay/setProgress are ignored before the scene is ready", async () => {
    const h = harness({ createScene: () => new Promise<HeroScene>(() => {}) });
    const hero = h.boot(h.canvas, manifest(), {});
    await h.settle();
    hero.play();
    hero.replay();
    hero.setProgress(1);
    expect(hero.state).toBe("loading");
    expect(h.raf.queue.size).toBe(0);
  });
});
