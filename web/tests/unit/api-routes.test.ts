import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { GET as getMenu } from "@/app/api/menu/route";
import { GET as getStoresRoute } from "@/app/api/stores/route";
import { CatalogueUnavailableError } from "@/lib/errors";
import { resetEnvCache } from "@/lib/env";
import { DEMO_CATALOGUE } from "@/lib/data/demo-catalogue";
import { PRODUCTION_CATALOGUE } from "@/lib/data/catalogue";
import * as data from "@/lib/data";

// Real data layer by default (static mode); individual tests make it throw.
vi.mock("@/lib/data", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/data")>();
  return { ...actual, getCatalogue: vi.fn(actual.getCatalogue), getStores: vi.fn(actual.getStores) };
});

beforeEach(() => {
  vi.stubEnv("DATABASE_URL", "");
  vi.stubEnv("DOUGHBOSS_DEMO_DATA", "");
  vi.stubEnv("NODE_ENV", "test");
  resetEnvCache();
  vi.spyOn(console, "warn").mockImplementation(() => {});
  vi.spyOn(console, "error").mockImplementation(() => {});
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.useRealTimers();
  vi.restoreAllMocks();
  resetEnvCache();
});

const menuRequest = () => new Request("http://localhost/api/menu");
const storesRequest = () => new Request("http://localhost/api/stores");

describe("GET /api/menu", () => {
  it("returns { mode, catalogue } with the shared-cache header in static mode", async () => {
    const res = await getMenu(menuRequest());
    expect(res.status).toBe(200);
    expect(res.headers.get("Cache-Control")).toBe("public, s-maxage=60, stale-while-revalidate=300");
    const body = await res.json();
    expect(body.mode).toBe("static");
    expect(body.catalogue.isDemo).toBe(false);
    expect(body.catalogue.items).toHaveLength(PRODUCTION_CATALOGUE.items.length);
  });

  it("keeps a null price null in the JSON (never 0)", async () => {
    const body = await (await getMenu(menuRequest())).json();
    expect(body.catalogue.items.every((i: { priceCents: number | null }) => i.priceCents === null)).toBe(true);
  });

  it("demo mode is labelled and never cacheable", async () => {
    vi.stubEnv("DOUGHBOSS_DEMO_DATA", "1");
    resetEnvCache();
    const res = await getMenu(menuRequest());
    expect(res.status).toBe(200);
    expect(res.headers.get("Cache-Control")).toBe("no-store");
    const body = await res.json();
    expect(body.mode).toBe("demo");
    expect(body.catalogue.isDemo).toBe(true);
    expect(body.catalogue.items).toHaveLength(DEMO_CATALOGUE.items.length);
  });

  it("answers 503 MENU_UNAVAILABLE (never an empty 200) when the catalogue throws", async () => {
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    resetEnvCache();
    vi.mocked(data.getCatalogue).mockRejectedValueOnce(new CatalogueUnavailableError("db down"));
    const res = await getMenu(menuRequest());
    expect(res.status).toBe(503);
    expect(res.headers.get("Cache-Control")).toBe("no-store");
    expect(await res.json()).toEqual({ error: "MENU_UNAVAILABLE" });
  });

  it("answers 503 for an unexpected error too, without leaking its message", async () => {
    vi.mocked(data.getCatalogue).mockRejectedValueOnce(new Error("password=hunter2 in connection string"));
    const res = await getMenu(menuRequest());
    expect(res.status).toBe(503);
    expect(JSON.stringify(await res.json())).not.toContain("hunter2");
  });

  it("answers 503 when the environment is invalid (demo data in production)", async () => {
    vi.stubEnv("NODE_ENV", "production");
    vi.stubEnv("DOUGHBOSS_DEMO_DATA", "1");
    resetEnvCache();
    const res = await getMenu(menuRequest());
    expect(res.status).toBe(503);
    expect(await res.json()).toEqual({ error: "MENU_UNAVAILABLE" });
  });
});

describe("GET /api/stores", () => {
  it("returns every store with a serialised status and the short-cache header", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-10-05T00:00:00Z")); // Mon 11:00 AEDT
    const res = await getStoresRoute(storesRequest());
    expect(res.status).toBe(200);
    expect(res.headers.get("Cache-Control")).toBe("public, s-maxage=15, stale-while-revalidate=30");

    const { stores } = await res.json();
    expect(stores.map((s: { slug: string }) => s.slug)).toEqual(["revesby", "bankstown", "roselands"]);
    for (const s of stores) {
      expect(s.acceptsOnline).toBe(true);
      expect(s.status.state).toBe("open");
      expect(typeof s.status.closesAt).toBe("string");
      expect(new Date(s.status.closesAt).toISOString()).toBe(s.status.closesAt);
      expect(s.status.label).toMatch(/^Open now/);
    }
  });

  it("reports a closed store with an ISO opensAt (Bankstown does not trade on Saturdays)", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-10-03T00:00:00Z")); // Sat 10:00 AEST
    const { stores } = await (await getStoresRoute(storesRequest())).json();
    const bankstown = stores.find((s: { slug: string }) => s.slug === "bankstown");
    expect(bankstown.status.state).toBe("closed");
    expect(typeof bankstown.status.opensAt).toBe("string");
    expect(bankstown.status).not.toHaveProperty("closesAt");
    expect(stores.find((s: { slug: string }) => s.slug === "revesby").status.state).toBe("open");
  });

  it("answers 503 STORES_UNAVAILABLE (never an empty list) when the read fails", async () => {
    vi.mocked(data.getStores).mockRejectedValueOnce(new CatalogueUnavailableError("db down"));
    const res = await getStoresRoute(storesRequest());
    expect(res.status).toBe(503);
    expect(res.headers.get("Cache-Control")).toBe("no-store");
    expect(await res.json()).toEqual({ error: "STORES_UNAVAILABLE" });
  });
});
