// Public entry of the hero bundle (hero-webgl.<sha8>.js).
//
//   import { boot } from "./hero-webgl.<sha8>.js";
//   const hero = boot(canvas, manifest, { onReady, onFrameBudgetExceeded });
//
// `manifest` is hero-manifest.json (optionally with `baseUrl` added by PHP). The pose data is
// compiled in at build time from public/hero/manifest.json (see build.mjs), so the bundle and the
// GLB it was validated against are pinned together by their hashes.

import poses from "hero-poses";
import { createBoot, type BootDeps } from "./boot-core";
import { createHeroScene } from "./scene";

export type {
  BootError,
  BootInfo,
  BootOptions,
  HeroController,
  HeroFileEntry,
  HeroManifest,
  HeroState,
} from "./boot-core";
export type { FrameStats } from "./stats";

function toBase64(buf: ArrayBuffer): string {
  const bytes = new Uint8Array(buf);
  let bin = "";
  for (let i = 0; i < bytes.length; i += 1) bin += String.fromCharCode(bytes[i] as number);
  return btoa(bin);
}

function browserDeps(): BootDeps {
  const g = globalThis as unknown as Record<string, unknown>;
  const subtle = (g.crypto as { subtle?: SubtleCrypto } | undefined)?.subtle;
  return {
    createScene: (canvas, glb, size) => createHeroScene(canvas, glb, poses, size),
    requestAnimationFrame: (cb) => globalThis.requestAnimationFrame(cb),
    cancelAnimationFrame: (id) => globalThis.cancelAnimationFrame(id),
    document: typeof document !== "undefined" ? document : null,
    IntersectionObserver: (g.IntersectionObserver as BootDeps["IntersectionObserver"]) || null,
    matchMedia: typeof globalThis.matchMedia === "function" ? (q) => globalThis.matchMedia(q) : null,
    fetch: typeof globalThis.fetch === "function" ? (url, init) => globalThis.fetch(url, init) : null,
    digest384: subtle ? (buf) => subtle.digest("SHA-384", buf).then((d) => "sha384-" + toBase64(d)) : null,
  };
}

export const boot = createBoot(browserDeps);
