import { afterEach, describe, expect, it, vi } from "vitest";
import { resetEnvCache } from "@/lib/env";
import {
  clientKey,
  createMemoryRateLimiter,
  createRateLimiter,
  createUpstashRateLimiter,
} from "@/lib/rate-limit";

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  resetEnvCache();
});

const clock = (start = 1_000_000) => {
  let t = start;
  return { now: () => t, advance: (ms: number) => (t += ms) };
};

describe("in-memory rate limiter", () => {
  it("allows `limit` calls then blocks the (limit+1)th", async () => {
    const c = clock();
    const rl = createMemoryRateLimiter({ now: c.now });
    const opts = { limit: 3, windowMs: 60_000 };

    const results = [];
    for (let i = 0; i < 4; i++) results.push(await rl.check("k", opts));

    expect(results.map((r) => r.ok)).toEqual([true, true, true, false]);
    expect(results.map((r) => r.remaining)).toEqual([2, 1, 0, 0]);
    expect(results[3]?.retryAfterMs).toBe(60_000);
  });

  it("recovers after the window passes", async () => {
    const c = clock();
    const rl = createMemoryRateLimiter({ now: c.now });
    const opts = { limit: 2, windowMs: 10_000 };
    await rl.check("k", opts);
    await rl.check("k", opts);
    expect((await rl.check("k", opts)).ok).toBe(false);

    c.advance(10_000);
    const again = await rl.check("k", opts);
    expect(again.ok).toBe(true);
    expect(again.remaining).toBe(1);
  });

  it("slides: only the calls that have aged out free up capacity", async () => {
    const c = clock();
    const rl = createMemoryRateLimiter({ now: c.now });
    const opts = { limit: 2, windowMs: 10_000 };
    await rl.check("k", opts); // t=0
    c.advance(6_000);
    await rl.check("k", opts); // t=6s
    c.advance(5_000); // t=11s: first call aged out, second has not
    const third = await rl.check("k", opts);
    expect(third.ok).toBe(true);
    const fourth = await rl.check("k", opts);
    expect(fourth.ok).toBe(false);
    expect(fourth.retryAfterMs).toBe(5_000); // second call (t=6s) expires at t=16s
  });

  it("does not count blocked calls, so hammering does not extend the lockout", async () => {
    const c = clock();
    const rl = createMemoryRateLimiter({ now: c.now });
    const opts = { limit: 1, windowMs: 10_000 };
    await rl.check("k", opts);
    for (let i = 0; i < 5; i++) {
      c.advance(1_000);
      expect((await rl.check("k", opts)).ok).toBe(false);
    }
    c.advance(5_000); // 10s since the one counted call
    expect((await rl.check("k", opts)).ok).toBe(true);
  });

  it("tracks keys independently", async () => {
    const rl = createMemoryRateLimiter({ now: clock().now });
    const opts = { limit: 1, windowMs: 1_000 };
    expect((await rl.check("a", opts)).ok).toBe(true);
    expect((await rl.check("a", opts)).ok).toBe(false);
    expect((await rl.check("b", opts)).ok).toBe(true);
  });

  it("keeps memory bounded: expired keys are swept", async () => {
    const c = clock();
    const rl = createMemoryRateLimiter({ now: c.now, sweepEveryMs: 1_000 });
    for (let i = 0; i < 50; i++) await rl.check(`k${i}`, { limit: 1, windowMs: 5_000 });
    expect(rl.size()).toBe(50);
    c.advance(6_000);
    await rl.check("fresh", { limit: 1, windowMs: 5_000 });
    expect(rl.size()).toBe(1);
  });

  it("keeps memory bounded: a flood of distinct live keys is capped", async () => {
    const rl = createMemoryRateLimiter({ now: clock().now, maxKeys: 100 });
    for (let i = 0; i < 1_000; i++) await rl.check(`k${i}`, { limit: 1, windowMs: 60_000 });
    expect(rl.size()).toBeLessThanOrEqual(101);
  });
});

describe("clientKey", () => {
  const h = (init: Record<string, string>) => new Headers(init);

  it("uses the first hop of x-forwarded-for", () => {
    const a = clientKey(h({ "x-forwarded-for": "203.0.113.7, 10.0.0.1, 10.0.0.2" }), "salt");
    const b = clientKey(h({ "x-forwarded-for": "203.0.113.7" }), "salt");
    const other = clientKey(h({ "x-forwarded-for": "203.0.113.8, 10.0.0.1" }), "salt");
    expect(a).toBe(b);
    expect(a).not.toBe(other);
  });

  it("falls back to x-real-ip, then to a constant 'unknown'", () => {
    expect(clientKey(h({ "x-real-ip": "198.51.100.4" }), "salt")).toBe(clientKey(h({ "x-forwarded-for": "198.51.100.4" }), "salt"));
    expect(clientKey(h({}), "salt")).toBe(clientKey(h({ "x-forwarded-for": "" }), "salt"));
  });

  it("never contains the raw IP and is a fixed-length hex prefix", () => {
    const key = clientKey(h({ "x-forwarded-for": "203.0.113.7" }), "salt");
    expect(key).toMatch(/^[0-9a-f]{24}$/);
    expect(key).not.toContain("203");
  });

  it("depends on the salt", () => {
    const headers = h({ "x-forwarded-for": "203.0.113.7" });
    expect(clientKey(headers, "one")).not.toBe(clientKey(headers, "two"));
  });

  it("reads RATE_LIMIT_SALT from the environment by default", () => {
    vi.stubEnv("RATE_LIMIT_SALT", "env-salt");
    resetEnvCache();
    const headers = h({ "x-forwarded-for": "203.0.113.7" });
    expect(clientKey(headers)).toBe(clientKey(headers, "env-salt"));
  });
});

describe("upstash rate limiter", () => {
  const respond = (results: unknown[]) =>
    vi.fn(async () => new Response(JSON.stringify(results.map((result) => ({ result }))), { status: 200 }));

  it("allows while the counter is within the limit and sends a bearer token", async () => {
    const fetchImpl = respond([2, 1, 55_000]);
    const rl = createUpstashRateLimiter({ url: "https://redis.example/", token: "tok", fetchImpl });
    const r = await rl.check("abc", { limit: 3, windowMs: 60_000 });
    expect(r).toEqual({ ok: true, remaining: 1, retryAfterMs: 0 });

    const [url, init] = fetchImpl.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe("https://redis.example/pipeline");
    expect((init.headers as Record<string, string>).Authorization).toBe("Bearer tok");
    expect(JSON.parse(String(init.body))).toEqual([
      ["INCR", "rl:abc"],
      ["PEXPIRE", "rl:abc", "60000", "NX"],
      ["PTTL", "rl:abc"],
    ]);
  });

  it("blocks over the limit and reports the remaining TTL", async () => {
    const rl = createUpstashRateLimiter({ url: "https://redis.example", token: "t", fetchImpl: respond([4, 0, 12_345]) });
    expect(await rl.check("abc", { limit: 3, windowMs: 60_000 })).toEqual({ ok: false, remaining: 0, retryAfterMs: 12_345 });
  });

  it("fails open (and says so in the log) when Redis errors, so ordering is not taken down", async () => {
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    const down = createUpstashRateLimiter({
      url: "https://redis.example",
      token: "t",
      fetchImpl: vi.fn(async () => {
        throw new Error("ECONNRESET");
      }),
    });
    expect((await down.check("k", { limit: 1, windowMs: 1_000 })).ok).toBe(true);

    const bad = createUpstashRateLimiter({
      url: "https://redis.example",
      token: "t",
      fetchImpl: vi.fn(async () => new Response("nope", { status: 500 })),
    });
    expect((await bad.check("k", { limit: 1, windowMs: 1_000 })).ok).toBe(true);
    expect(spy).toHaveBeenCalledTimes(2);
    spy.mockRestore();
  });
});

describe("createRateLimiter", () => {
  it("uses in-memory limiting when Upstash is not configured", async () => {
    vi.stubEnv("UPSTASH_REDIS_REST_URL", "");
    vi.stubEnv("UPSTASH_REDIS_REST_TOKEN", "");
    const fetchSpy = vi.fn();
    vi.stubGlobal("fetch", fetchSpy);
    const rl = createRateLimiter();
    expect((await rl.check("k", { limit: 1, windowMs: 1_000 })).ok).toBe(true);
    expect((await rl.check("k", { limit: 1, windowMs: 1_000 })).ok).toBe(false);
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it("uses Upstash over fetch when both variables are set", async () => {
    vi.stubEnv("UPSTASH_REDIS_REST_URL", "https://redis.example");
    vi.stubEnv("UPSTASH_REDIS_REST_TOKEN", "tok");
    const fetchSpy = vi.fn(async () => new Response(JSON.stringify([{ result: 1 }, { result: 1 }, { result: 1000 }]), { status: 200 }));
    vi.stubGlobal("fetch", fetchSpy);
    resetEnvCache();
    const rl = createRateLimiter();
    expect((await rl.check("k", { limit: 5, windowMs: 1_000 })).ok).toBe(true);
    expect(fetchSpy).toHaveBeenCalledTimes(1);
  });
});
