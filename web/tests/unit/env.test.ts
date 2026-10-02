import { afterEach, describe, expect, it, vi } from "vitest";
import { getEnv, parseEnv, resetEnvCache } from "@/lib/env";
import { getPrisma, isDatabaseConfigured } from "@/lib/db";
import { CatalogueUnavailableError, StorageUnavailableError } from "@/lib/errors";

afterEach(() => {
  vi.unstubAllEnvs();
  resetEnvCache();
});

describe("parseEnv", () => {
  it("applies safe defaults when nothing is set", () => {
    const env = parseEnv({});
    expect(env.NODE_ENV).toBe("development");
    expect(env.NEXT_PUBLIC_SITE_URL).toBe("https://doughboss.com.au");
    expect(env.ALLOW_PAY_AT_PICKUP).toBe(false);
    expect(env.DOUGHBOSS_DEMO_DATA).toBe(false);
    expect(env.DATABASE_URL).toBeUndefined();
    expect(env.WAITLIST_WEBHOOK_URL).toBeUndefined();
  });

  it("treats the blank placeholders from .env.example as unset", () => {
    const env = parseEnv({
      DATABASE_URL: "",
      DIRECT_URL: "  ",
      STRIPE_SECRET_KEY: "",
      WAITLIST_WEBHOOK_URL: "",
      UPSTASH_REDIS_REST_URL: "",
      RATE_LIMIT_SALT: "",
      NEXT_PUBLIC_SITE_URL: "",
      ALLOW_PAY_AT_PICKUP: "",
      DOUGHBOSS_DEMO_DATA: "",
    });
    expect(env.DATABASE_URL).toBeUndefined();
    expect(env.DIRECT_URL).toBeUndefined();
    expect(env.STRIPE_SECRET_KEY).toBeUndefined();
    expect(env.WAITLIST_WEBHOOK_URL).toBeUndefined();
    expect(env.RATE_LIMIT_SALT).toBeUndefined();
    expect(env.NEXT_PUBLIC_SITE_URL).toBe("https://doughboss.com.au");
    expect(env.ALLOW_PAY_AT_PICKUP).toBe(false);
  });

  it.each([
    ["1", true],
    ["true", true],
    ["TRUE", true],
    ["0", false],
    ["false", false],
  ])("reads the boolean flag %s as %s", (raw, expected) => {
    expect(parseEnv({ ALLOW_PAY_AT_PICKUP: raw }).ALLOW_PAY_AT_PICKUP).toBe(expected);
  });

  it("rejects an ambiguous flag value and names the key, never the value", () => {
    expect(() => parseEnv({ ALLOW_PAY_AT_PICKUP: "yes-please" })).toThrow(/ALLOW_PAY_AT_PICKUP/);
    expect(() => parseEnv({ ALLOW_PAY_AT_PICKUP: "yes-please" })).not.toThrow(/yes-please/);
  });

  it("rejects a malformed webhook URL without echoing a secret-bearing value", () => {
    const secretish = "not a url?token=abc123";
    expect(() => parseEnv({ WAITLIST_WEBHOOK_URL: secretish })).toThrow(/WAITLIST_WEBHOOK_URL/);
    expect(() => parseEnv({ WAITLIST_WEBHOOK_URL: secretish })).not.toThrow(/abc123/);
  });

  it("rejects an unknown NODE_ENV", () => {
    expect(() => parseEnv({ NODE_ENV: "staging" })).toThrow(/NODE_ENV/);
  });

  it("allows demo data outside production", () => {
    expect(parseEnv({ DOUGHBOSS_DEMO_DATA: "1", NODE_ENV: "development" }).DOUGHBOSS_DEMO_DATA).toBe(true);
    expect(parseEnv({ DOUGHBOSS_DEMO_DATA: "true", NODE_ENV: "test" }).DOUGHBOSS_DEMO_DATA).toBe(true);
  });

  it("THROWS when demo data is enabled in production", () => {
    expect(() => parseEnv({ DOUGHBOSS_DEMO_DATA: "1", NODE_ENV: "production" })).toThrow(/must never be enabled in production/);
  });

  it("does not throw for production without demo data", () => {
    expect(parseEnv({ NODE_ENV: "production", DATABASE_URL: "postgresql://u:p@h/db" }).NODE_ENV).toBe("production");
  });
});

describe("getEnv cache", () => {
  it("caches until resetEnvCache is called", () => {
    vi.stubEnv("RATE_LIMIT_SALT", "first");
    expect(getEnv().RATE_LIMIT_SALT).toBe("first");
    vi.stubEnv("RATE_LIMIT_SALT", "second");
    expect(getEnv().RATE_LIMIT_SALT).toBe("first");
    resetEnvCache();
    expect(getEnv().RATE_LIMIT_SALT).toBe("second");
  });

  it("throws on demo + production through the cached accessor too", () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("DOUGHBOSS_DEMO_DATA", "1");
    expect(() => getEnv()).toThrow(/production/);
  });
});

describe("database access", () => {
  it("reports whether a database is configured", () => {
    expect(isDatabaseConfigured({})).toBe(false);
    expect(isDatabaseConfigured({ DATABASE_URL: "" })).toBe(false);
    expect(isDatabaseConfigured({ DATABASE_URL: "   " })).toBe(false);
    expect(isDatabaseConfigured({ DATABASE_URL: "postgresql://u:p@h/db" })).toBe(true);
  });

  it("getPrisma throws StorageUnavailableError when DATABASE_URL is absent", () => {
    vi.stubEnv("DATABASE_URL", "");
    expect(() => getPrisma()).toThrow(StorageUnavailableError);
  });

  it("getPrisma returns one shared client (lazy: no connection is opened)", () => {
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    const a = getPrisma();
    expect(getPrisma()).toBe(a);
  });
});

describe("typed errors", () => {
  it("carry stable codes", () => {
    expect(new StorageUnavailableError().code).toBe("STORAGE_UNAVAILABLE");
    expect(new CatalogueUnavailableError().code).toBe("CATALOGUE_UNAVAILABLE");
  });

  it("CatalogueUnavailableError keeps its cause", () => {
    const cause = new Error("connection refused");
    const err = new CatalogueUnavailableError("down", { cause });
    expect(err.cause).toBe(cause);
    expect(err).toBeInstanceOf(Error);
    expect(err.name).toBe("CatalogueUnavailableError");
  });
});
