// Frame-time statistics for the tier-2 frame guard (docs/wp/00 section 3.3: the median of the
// first 60 frames must stay at or under 24 ms, otherwise the loader falls back to tier 1).

export const DEFAULT_FRAME_BUDGET_MS = 24;
export const DEFAULT_SAMPLE_FRAMES = 60;

export interface FrameStats {
  /** Number of frame-to-frame deltas collected so far. */
  sampleCount: number;
  /** Median frame time in ms, or null before any sample exists. */
  medianMs: number | null;
  budgetMs: number;
  /** True once a full window has been collected and its median is over budget. */
  exceeded: boolean;
}

export function median(values: readonly number[]): number | null {
  if (values.length === 0) return null;
  const sorted = values.slice().sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  if (sorted.length % 2 === 1) return sorted[mid] as number;
  return ((sorted[mid - 1] as number) + (sorted[mid] as number)) / 2;
}

export class FrameWindow {
  private readonly deltas: number[] = [];
  private last: number | null = null;

  constructor(
    readonly sampleFrames: number = DEFAULT_SAMPLE_FRAMES,
    readonly budgetMs: number = DEFAULT_FRAME_BUDGET_MS,
  ) {}

  /** Record a frame timestamp (ms). The first call only primes the clock. */
  push(timestampMs: number): void {
    if (typeof timestampMs !== "number" || !Number.isFinite(timestampMs)) return;
    if (this.last !== null) {
      const dt = timestampMs - this.last;
      if (dt > 0 && this.deltas.length < this.sampleFrames) this.deltas.push(dt);
    }
    this.last = timestampMs;
  }

  /** Forget the previous timestamp (e.g. after the tab was hidden) without dropping samples. */
  breakSequence(): void {
    this.last = null;
  }

  reset(): void {
    this.deltas.length = 0;
    this.last = null;
  }

  get complete(): boolean {
    return this.deltas.length >= this.sampleFrames;
  }

  stats(): FrameStats {
    const med = median(this.deltas);
    return {
      sampleCount: this.deltas.length,
      medianMs: med,
      budgetMs: this.budgetMs,
      exceeded: this.complete && med !== null && med > this.budgetMs,
    };
  }
}
