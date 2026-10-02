/**
 * Request rate limiting for the public write endpoints (waitlist, checkout).
 *
 * Two backends behind one interface:
 *  - Upstash Redis over its REST API (plain fetch, no dependency) when configured,
 *    so limits hold across serverless instances;
 *  - an in-memory sliding window otherwise (per-instance, which is the honest
 *    limit of running without Redis).
 *
 * Raw IP addresses are never stored: keys are salted SHA-256 prefixes.
 */
import { createHash } from "node:crypto";
import { getEnv } from "./env";

export interface RateLimitOptions {
  limit: number;
  windowMs: number;
}

export interface RateLimitResult {
  ok: boolean;
  remaining: number;
  retryAfterMs: number;
}

export interface RateLimiter {
  check(key: string, options: RateLimitOptions): Promise<RateLimitResult>;
}

// ───────────────────────── Client key ─────────────────────────

/**
 * Without RATE_LIMIT_SALT the hash is still one-way, but a fixed, public salt
 * would let someone precompute the (small) IPv4 space. Production should set one.
 */
const FALLBACK_SALT = "doughboss-rate-limit";

export function clientKey(headers: Headers, salt: string | undefined = getEnv().RATE_LIMIT_SALT): string {
  // The first hop is the client as seen by our edge; later hops are proxies.
  const forwarded = headers.get("x-forwarded-for")?.split(",")[0]?.trim();
  const ip = forwarded || headers.get("x-real-ip")?.trim() || "unknown";
  return createHash("sha256")
    .update(`${salt ?? FALLBACK_SALT}:${ip}`)
    .digest("hex")
    .slice(0, 24);
}

// ───────────────────────── In-memory sliding window ─────────────────────────

export interface MemoryLimiterOptions {
  now?: () => number;
  /** Hard cap on tracked keys; the oldest are evicted first when exceeded. */
  maxKeys?: number;
  /** Sweep expired keys at most this often. */
  sweepEveryMs?: number;
}

export interface MemoryRateLimiter extends RateLimiter {
  /** Number of keys currently tracked (exposed so tests can prove memory stays bounded). */
  size(): number;
}

export function createMemoryRateLimiter(opts: MemoryLimiterOptions = {}): MemoryRateLimiter {
  const now = opts.now ?? Date.now;
  const maxKeys = opts.maxKeys ?? 10_000;
  const sweepEveryMs = opts.sweepEveryMs ?? 60_000;
  // key -> request timestamps still inside that key's window, plus the window so the sweep can expire it.
  const hits = new Map<string, { stamps: number[]; windowMs: number }>();
  let lastSweep = now();

  function sweep(t: number): void {
    if (t - lastSweep < sweepEveryMs && hits.size <= maxKeys) return;
    lastSweep = t;
    for (const [key, entry] of hits) {
      const newest = entry.stamps[entry.stamps.length - 1];
      if (newest === undefined || t - newest >= entry.windowMs) hits.delete(key);
    }
    // Still over the cap (a flood of distinct keys): evict oldest-inserted first.
    for (const key of hits.keys()) {
      if (hits.size <= maxKeys) break;
      hits.delete(key);
    }
  }

  return {
    size: () => hits.size,
    async check(key, { limit, windowMs }) {
      const t = now();
      sweep(t);
      const entry = hits.get(key) ?? { stamps: [], windowMs };
      entry.windowMs = windowMs;
      entry.stamps = entry.stamps.filter((s) => t - s < windowMs);

      if (entry.stamps.length >= limit) {
        const oldest = entry.stamps[0] ?? t;
        hits.set(key, entry);
        // Blocked calls are not recorded, so a hammering client recovers on schedule.
        return { ok: false, remaining: 0, retryAfterMs: Math.max(0, oldest + windowMs - t) };
      }
      entry.stamps.push(t);
      hits.delete(key); // re-insert so Map order tracks recency for eviction
      hits.set(key, entry);
      return { ok: true, remaining: limit - entry.stamps.length, retryAfterMs: 0 };
    },
  };
}

// ───────────────────────── Upstash (REST) ─────────────────────────

export interface UpstashConfig {
  url: string;
  token: string;
  fetchImpl?: typeof fetch;
  now?: () => number;
}

/**
 * Fixed-window counter (INCR + PEXPIRE, then PTTL) in one pipelined round trip.
 * Coarser than the in-memory sliding window but cheap and safe across instances.
 *
 * If Redis is unreachable we fail OPEN: a limiter outage must not take down
 * ordering. The error is logged; the caller still gets a normal result.
 */
export function createUpstashRateLimiter(cfg: UpstashConfig): RateLimiter {
  const doFetch = cfg.fetchImpl ?? fetch;
  const base = cfg.url.replace(/\/+$/, "");

  return {
    async check(key, { limit, windowMs }) {
      const bucket = `rl:${key}`;
      try {
        const res = await doFetch(`${base}/pipeline`, {
          method: "POST",
          headers: { Authorization: `Bearer ${cfg.token}`, "Content-Type": "application/json" },
          body: JSON.stringify([
            ["INCR", bucket],
            ["PEXPIRE", bucket, String(windowMs), "NX"],
            ["PTTL", bucket],
          ]),
          signal: AbortSignal.timeout(2_000),
        });
        if (!res.ok) throw new Error(`Upstash responded ${res.status}`);
        const body = (await res.json()) as Array<{ result?: unknown; error?: string }>;
        const count = Number(body[0]?.result);
        const ttl = Number(body[2]?.result);
        if (!Number.isFinite(count)) throw new Error("Upstash returned no count");
        const ok = count <= limit;
        return {
          ok,
          remaining: Math.max(0, limit - count),
          retryAfterMs: ok ? 0 : Number.isFinite(ttl) && ttl > 0 ? ttl : windowMs,
        };
      } catch (err) {
        console.error("[doughboss] rate limiter unavailable, allowing request:", err instanceof Error ? err.message : err);
        return { ok: true, remaining: limit, retryAfterMs: 0 };
      }
    },
  };
}

// ───────────────────────── Factory ─────────────────────────

let shared: RateLimiter | undefined;

export function createRateLimiter(): RateLimiter {
  const env = getEnv();
  if (env.UPSTASH_REDIS_REST_URL && env.UPSTASH_REDIS_REST_TOKEN) {
    return createUpstashRateLimiter({ url: env.UPSTASH_REDIS_REST_URL, token: env.UPSTASH_REDIS_REST_TOKEN });
  }
  return createMemoryRateLimiter();
}

/** Process-wide limiter so every route shares one set of counters. */
export function getRateLimiter(): RateLimiter {
  shared ??= createRateLimiter();
  return shared;
}

export function resetRateLimiter(): void {
  shared = undefined;
}
