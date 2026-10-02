// Encode the blow-out PNG renders into alpha WebP frames and posters.
//
//   node tools/blender/encode_frames.mjs <render-dir> [public/hero]
//
// <render-dir> holds frame-000.png .. frame-023.png (rendered square, RGBA)
// and poster-assembled.png / poster-exploded.png from render_frames.py.
// Uses sharp from web/node_modules (already a Next.js dependency).
//
// Budgets (whole directory): lg <= 1.8 MB, sm <= 700 KB. If a set is over
// budget, quality steps down until it fits (never below 50) and the chosen
// quality is reported, so a budget is never silently blown.

import { mkdirSync, readdirSync, rmSync, statSync } from "node:fs";
import path from "node:path";
import sharp from "sharp";
import { MIN_QUALITY, nextQuality } from "./quality.mjs";

const [renderDir, heroDirArg] = process.argv.slice(2);
if (!renderDir) {
  console.error("usage: encode_frames.mjs <render-dir> [public/hero]");
  process.exit(2);
}
const heroDir = heroDirArg ?? path.join("public", "hero");

const SETS = [
  { name: "lg", size: 840, quality: 78, budget: 1.8 * 1000 * 1000 },
  { name: "sm", size: 480, quality: 78, budget: 700 * 1000 },
];
const POSTER_SIZE = 1200;

const frames = readdirSync(renderDir).filter((f) => /^frame-\d{3}\.png$/.test(f)).sort();
if (frames.length === 0) throw new Error(`no frame-NNN.png in ${renderDir}`);

async function encode(src, dest, size, quality) {
  await sharp(src)
    .resize(size, size, { fit: "fill", kernel: "lanczos3" })
    .webp({ quality, alphaQuality: Math.min(100, quality + 12), effort: 6, smartSubsample: true })
    .toFile(dest);
  return statSync(dest).size;
}

const report = {};
for (const set of SETS) {
  const outDir = path.join(heroDir, "frames", set.name);
  let quality = set.quality;
  for (;;) {
    rmSync(outDir, { recursive: true, force: true });
    mkdirSync(outDir, { recursive: true });
    let total = 0;
    for (const f of frames) {
      total += await encode(path.join(renderDir, f), path.join(outDir, f.replace(/\.png$/, ".webp")), set.size, quality);
    }
    if (total <= set.budget || quality <= MIN_QUALITY) {
      report[set.name] = { frames: frames.length, size: set.size, quality, totalBytes: total, budgetBytes: set.budget, withinBudget: total <= set.budget };
      break;
    }
    quality = nextQuality(quality);
  }
}

for (const name of ["poster-assembled", "poster-exploded"]) {
  const src = path.join(renderDir, `${name}.png`);
  try {
    statSync(src);
  } catch {
    report[name] = "missing";
    continue;
  }
  const dest = path.join(heroDir, `${name}.webp`);
  report[name] = { size: POSTER_SIZE, bytes: await encode(src, dest, POSTER_SIZE, 80) };
}

console.log(JSON.stringify(report, null, 2));
