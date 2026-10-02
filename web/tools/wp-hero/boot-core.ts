// Lifecycle of the tier-2 hero: load -> probe (frame-time guard) -> ready -> play/pause -> stop.
//
// Everything environment-specific (three.js scene, rAF, document, fetch, crypto) arrives through
// `BootDeps`, so the unit tests drive the whole lifecycle with fakes. entry.ts wires the real
// browser dependencies.
//
// Fail-closed rules: no scene file, no integrity value, a hash/size mismatch, reduced motion,
// a scene that does not match the node-name contract, or a median frame time over budget all
// end in a disposed, inert controller; the caller never receives a half-working scene.
// There is no autonomous loop: frames are produced only while probing, while playing, or when
// a pose changed, and never while the tab is hidden or the canvas is out of view.

import { DEFAULT_FRAME_BUDGET_MS, DEFAULT_SAMPLE_FRAMES, FrameWindow, type FrameStats } from "./stats";
import type { HeroScene, SceneSize } from "./scene";

export interface HeroFileEntry {
  path: string;
  bytes: number;
  /** Subresource-Integrity token, "sha384-<base64>". */
  sha384: string;
  role?: string;
}

export interface HeroManifest {
  files: HeroFileEntry[];
  /** Added by PHP when it localises the manifest; prefix for every file path. */
  baseUrl?: string;
}

export type HeroState = "loading" | "probing" | "ready" | "playing" | "stopped" | "failed";

export interface BootInfo {
  renderer: "webgl2";
  medianFrameMs: number | null;
  sampleFrames: number;
}

export interface BootError {
  code: string;
  message: string;
}

export interface BootOptions {
  onReady?: (info: BootInfo) => void;
  /** Called once with the measured median (ms) when the guard fails; the scene is disposed afterwards. */
  onFrameBudgetExceeded?: (medianMs: number, stats: FrameStats) => void;
  onError?: (error: BootError) => void;
  /** Called when a play() sweep reaches progress 1. */
  onComplete?: () => void;
  /** Median frame-time budget in ms (default 24). */
  frameBudgetMs?: number;
  /** Frames measured by the guard (default 60). 0 disables the probe. */
  sampleFrames?: number;
  /** Already-fetched GLB bytes (the loader's integrity-checked fetch). Verified again here. */
  glb?: ArrayBuffer;
  /** Overrides manifest.baseUrl. */
  baseUrl?: string;
  /** Explicit render size; otherwise the canvas CSS size is used. */
  size?: SceneSize;
  /** Duration of one explosion sweep in ms (default 1600). */
  durationMs?: number;
  /** Refuse to start under prefers-reduced-motion (default true). */
  respectReducedMotion?: boolean;
}

export interface HeroController {
  readonly state: HeroState;
  /** Stop everything and release the GPU context. Idempotent. */
  stop(): void;
  /** User pause (Stop button). Frames stop until resume(). */
  pause(): void;
  resume(): void;
  /** Run one explosion sweep from the current progress to 1. */
  play(): void;
  /** Reset to the rest pose and play again. */
  replay(): void;
  /** Scroll-linked mode: set the progress directly (clamped to [0, 1]). */
  setProgress(p: number): void;
  getStats(): FrameStats;
}

interface DocumentLike {
  hidden: boolean;
  addEventListener(type: string, fn: () => void): void;
  removeEventListener(type: string, fn: () => void): void;
}

interface ObserverLike {
  observe(el: unknown): void;
  disconnect(): void;
}

type ObserverCtor = new (cb: (entries: Array<{ isIntersecting: boolean }>) => void) => ObserverLike;

export interface BootDeps {
  createScene(canvas: HTMLCanvasElement, glb: ArrayBuffer, size?: SceneSize): Promise<HeroScene>;
  requestAnimationFrame(cb: (ts: number) => void): number;
  cancelAnimationFrame(id: number): void;
  document: DocumentLike | null;
  IntersectionObserver: ObserverCtor | null;
  matchMedia: ((query: string) => { matches: boolean }) | null;
  fetch: ((url: string, init: { integrity: string; signal?: AbortSignal }) => Promise<{ ok: boolean; status: number; arrayBuffer(): Promise<ArrayBuffer> }>) | null;
  /** SHA-384 of the bytes as an SRI token, or null when the platform cannot compute it. */
  digest384: ((buf: ArrayBuffer) => Promise<string | null>) | null;
}

const SRI_PATTERN = /^sha384-[A-Za-z0-9+/]{64}$/;
const MAX_STEP_MS = 100; // clamp a long gap so a paused/throttled tab never jumps the animation
const DEFAULT_DURATION_MS = 1600;

function safe(fn: (() => void) | undefined): void {
  if (!fn) return;
  try {
    fn();
  } catch {
    // A throwing hook must never corrupt the lifecycle.
  }
}

function joinUrl(base: string | undefined, path: string): string {
  if (!base) return path;
  return base.charAt(base.length - 1) === "/" ? base + path : base + "/" + path;
}

export function createBoot(getDeps: () => BootDeps) {
  return function boot(canvas: HTMLCanvasElement, manifest: HeroManifest, options: BootOptions = {}): HeroController {
    const deps = getDeps();
    const opts = options || {};
    const frameBudgetMs = typeof opts.frameBudgetMs === "number" && opts.frameBudgetMs > 0 ? opts.frameBudgetMs : DEFAULT_FRAME_BUDGET_MS;
    const sampleFrames =
      typeof opts.sampleFrames === "number" && opts.sampleFrames >= 0 ? Math.floor(opts.sampleFrames) : DEFAULT_SAMPLE_FRAMES;
    const durationMs = typeof opts.durationMs === "number" && opts.durationMs > 0 ? opts.durationMs : DEFAULT_DURATION_MS;

    const frameWindow = new FrameWindow(sampleFrames, frameBudgetMs);
    const abort = typeof AbortController !== "undefined" ? new AbortController() : null;

    let state: HeroState = "loading";
    let scene: HeroScene | null = null;
    let rafId: number | null = null;
    let lastTs: number | null = null;
    let userPaused = false;
    let inView = true;
    let dirty = false;
    let progress = 0;
    let playing = false;
    let probeFrames = 0;
    let observer: ObserverLike | null = null;
    const doc = deps.document;

    const terminal = (): boolean => state === "stopped" || state === "failed";
    const visible = (): boolean => !(doc && doc.hidden) && inView && !userPaused;

    const cancelFrame = (): void => {
      if (rafId !== null) {
        deps.cancelAnimationFrame(rafId);
        rafId = null;
      }
    };

    // Re-evaluate whether frames may run: cancel a pending one when hidden, schedule one when visible.
    const sync = (): void => {
      if (visible()) {
        wake();
      } else {
        cancelFrame();
      }
    };

    const onVisibility = (): void => {
      frameWindow.breakSequence();
      lastTs = null;
      sync();
    };

    const teardown = (): void => {
      cancelFrame();
      if (doc) doc.removeEventListener("visibilitychange", onVisibility);
      if (observer) {
        observer.disconnect();
        observer = null;
      }
      if (abort) abort.abort();
      if (scene) {
        const s = scene;
        scene = null;
        try {
          s.dispose();
        } catch {
          // Disposal is best effort; the state is terminal either way.
        }
      }
    };

    const fail = (code: string, message: string): void => {
      if (terminal()) return;
      state = "failed";
      teardown();
      const hook = opts.onError;
      // Deferred so a start-up failure is never reported before boot() has returned.
      if (hook) Promise.resolve().then(() => safe(() => hook({ code, message })));
    };

    const needsFrame = (): boolean => {
      if (terminal() || !scene) return false;
      return state === "probing" || playing || dirty;
    };

    const wake = (): void => {
      if (rafId !== null || !needsFrame() || !visible()) return;
      rafId = deps.requestAnimationFrame(tick);
    };

    const finishProbe = (): void => {
      const stats = frameWindow.stats();
      const med = stats.medianMs;
      if (stats.exceeded && med !== null) {
        state = "failed";
        teardown();
        const hook = opts.onFrameBudgetExceeded;
        if (hook) safe(() => hook(med, stats));
        return;
      }
      state = "ready";
      progress = 0;
      if (scene) {
        scene.setProgress(0);
        scene.render();
      }
      const ready = opts.onReady;
      if (ready) safe(() => ready({ renderer: "webgl2", medianFrameMs: med, sampleFrames: stats.sampleCount }));
    };

    function tick(ts: number): void {
      rafId = null;
      if (terminal() || !scene) return;
      if (!visible()) return; // wake() re-arms when visible again

      const dt = lastTs === null ? 0 : Math.min(Math.max(ts - lastTs, 0), MAX_STEP_MS);
      lastTs = ts;

      if (state === "probing") {
        frameWindow.push(ts);
        probeFrames += 1;
        // Sweep the whole explosion while measuring so the guard sees the heaviest poses.
        scene.setProgress(sampleFrames > 0 ? Math.min(probeFrames / sampleFrames, 1) : 1);
        scene.render();
        if (frameWindow.complete) {
          finishProbe();
          return;
        }
      } else {
        if (playing) {
          progress = Math.min(progress + dt / durationMs, 1);
          scene.setProgress(progress);
          if (progress >= 1) {
            playing = false;
            state = "ready";
            const done = opts.onComplete;
            scene.render();
            if (done) safe(done);
            return;
          }
        } else if (dirty) {
          scene.setProgress(progress);
        }
        dirty = false;
        scene.render();
      }
      wake();
    }

    // ---- start-up checks (all synchronous, all fail closed) -------------------------------
    if (opts.respectReducedMotion !== false && deps.matchMedia) {
      let reduced = false;
      try {
        reduced = deps.matchMedia("(prefers-reduced-motion: reduce)").matches;
      } catch {
        reduced = true; // cannot tell: stay inert
      }
      if (reduced) {
        fail("reduced-motion", "prefers-reduced-motion is set; the hero stays on tier 0");
        return controller();
      }
    }

    const files = manifest && Array.isArray(manifest.files) ? manifest.files : [];
    const sceneEntry = files.filter((f) => f && f.role === "scene")[0];
    if (!opts.glb && !sceneEntry) {
      fail("no-scene-file", "manifest has no scene file");
      return controller();
    }
    if (!sceneEntry || typeof sceneEntry.sha384 !== "string" || !SRI_PATTERN.test(sceneEntry.sha384)) {
      fail("integrity-missing", "scene file has no usable sha384 integrity value");
      return controller();
    }
    const entry: HeroFileEntry = sceneEntry;

    const obtainGlb = async (): Promise<ArrayBuffer> => {
      if (opts.glb) {
        const supplied = opts.glb;
        if (supplied.byteLength !== entry.bytes) {
          throw Object.assign(new Error("supplied GLB size does not match the manifest"), { code: "integrity-mismatch" });
        }
        const digest = deps.digest384 ? await deps.digest384(supplied) : null;
        if (digest === null) {
          throw Object.assign(new Error("integrity of the supplied GLB cannot be verified here"), { code: "integrity-unverifiable" });
        }
        if (digest !== entry.sha384) {
          throw Object.assign(new Error("supplied GLB does not match its sha384"), { code: "integrity-mismatch" });
        }
        return supplied;
      }
      if (!deps.fetch) throw Object.assign(new Error("fetch is not available"), { code: "fetch-failed" });
      const url = joinUrl(opts.baseUrl !== undefined ? opts.baseUrl : manifest.baseUrl, entry.path);
      let res;
      try {
        res = await deps.fetch(url, { integrity: entry.sha384, signal: abort ? abort.signal : undefined });
      } catch (err) {
        // A failed SRI check surfaces as a network error; both mean "do not use this file".
        throw Object.assign(new Error("scene file could not be fetched or failed its integrity check"), { code: "fetch-failed", cause: err });
      }
      if (!res.ok) throw Object.assign(new Error("scene file request failed with HTTP " + res.status), { code: "fetch-failed" });
      const buf = await res.arrayBuffer();
      if (buf.byteLength !== entry.bytes) {
        throw Object.assign(new Error("scene file size does not match the manifest"), { code: "integrity-mismatch" });
      }
      return buf;
    };

    if (doc) doc.addEventListener("visibilitychange", onVisibility);
    if (deps.IntersectionObserver) {
      try {
        observer = new deps.IntersectionObserver((entries) => {
          const last = entries[entries.length - 1];
          if (!last) return;
          inView = last.isIntersecting;
          if (!inView) {
            frameWindow.breakSequence();
            lastTs = null;
          }
          sync();
        });
        observer.observe(canvas);
      } catch {
        observer = null;
      }
    }

    obtainGlb()
      .then((buf) => deps.createScene(canvas, buf, opts.size))
      .then(
        (created) => {
          if (terminal()) {
            // stop() ran while the scene was loading: release it immediately.
            created.dispose();
            return;
          }
          scene = created;
          state = "probing";
          probeFrames = 0;
          if (sampleFrames === 0) {
            finishProbe();
            return;
          }
          wake();
        },
        (err: unknown) => {
          const e = err as { code?: unknown; message?: unknown } | null;
          const code = e && typeof e.code === "string" ? e.code : "scene-failed";
          const message = e && typeof e.message === "string" ? e.message : "scene failed to start";
          fail(code, message);
        },
      );

    return controller();

    function controller(): HeroController {
      return {
        get state(): HeroState {
          return state;
        },
        stop(): void {
          if (terminal()) return;
          state = "stopped";
          playing = false;
          teardown();
        },
        pause(): void {
          if (terminal() || userPaused) return;
          userPaused = true;
          frameWindow.breakSequence();
          lastTs = null;
          cancelFrame();
        },
        resume(): void {
          if (terminal() || !userPaused) return;
          userPaused = false;
          lastTs = null;
          wake();
        },
        play(): void {
          if (terminal() || (state !== "ready" && state !== "playing")) return;
          if (progress >= 1) progress = 0;
          playing = true;
          state = "playing";
          lastTs = null;
          wake();
        },
        replay(): void {
          if (terminal() || (state !== "ready" && state !== "playing")) return;
          progress = 0;
          dirty = true;
          playing = true;
          state = "playing";
          lastTs = null;
          wake();
        },
        setProgress(p: number): void {
          if (terminal() || (state !== "ready" && state !== "playing")) return;
          const v = typeof p === "number" && Number.isFinite(p) ? Math.min(Math.max(p, 0), 1) : 0;
          playing = false;
          if (state === "playing") state = "ready";
          progress = v;
          dirty = true;
          wake();
        },
        getStats(): FrameStats {
          return frameWindow.stats();
        },
      };
    }
  };
}
