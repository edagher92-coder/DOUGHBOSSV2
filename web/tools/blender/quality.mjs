// Quality ladder for encode_frames.mjs, kept pure so it can be unit-tested.
export const MIN_QUALITY = 50;
export const QUALITY_STEP = 6;

/** Next (lower) WebP quality to try; never below MIN_QUALITY. */
export function nextQuality(quality) {
  return Math.max(MIN_QUALITY, quality - QUALITY_STEP);
}
