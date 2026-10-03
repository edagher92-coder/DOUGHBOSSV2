import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  applySeedPlan,
  assertSeedAllowed,
  buildSeedPlan,
  countPlan,
  formatCounts,
  runSeedCli,
  type SeedDb,
  type SeedSource,
} from "../../prisma/seed";
import { CATEGORIES, ITEMS, STORES } from "@/lib/data/catalogue";
import { resetEnvCache } from "@/lib/env";
import * as db from "@/lib/db";
import type { MenuItem, ModifierGroup } from "@/types/menu";

vi.mock("@/lib/db", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/db")>();
  return { ...actual, getPrisma: vi.fn() };
});

beforeEach(() => {
  vi.stubEnv("DOUGHBOSS_DEMO_DATA", "");
  vi.stubEnv("DATABASE_URL", "");
  vi.stubEnv("NODE_ENV", "test");
  resetEnvCache();
  vi.mocked(db.getPrisma).mockReset();
});
afterEach(() => {
  vi.unstubAllEnvs();
  resetEnvCache();
});

const source = (): SeedSource => ({ stores: STORES, categories: CATEGORIES, items: ITEMS });
const withItem = (over: Partial<MenuItem>): SeedSource => ({
  ...source(),
  items: [{ ...ITEMS[0]!, ...over }, ...ITEMS.slice(1)],
});

describe("buildSeedPlan", () => {
  it("plans the production seed file as-is", () => {
    const counts = countPlan(buildSeedPlan(source()));
    expect(counts).toEqual({
      stores: 3,
      hoursWindows: 19,
      closures: 0,
      categories: 6,
      modifierGroups: 3,
      modifierOptions: 5,
      items: 6,
      itemsPriced: 0,
      itemsUnpriced: 6,
      itemGroupLinks: 12,
      storeAvailabilityRows: 0,
    });
  });

  it("shares modifier groups between items and numbers them in first-seen order", () => {
    const plan = buildSeedPlan(source());
    expect(plan.groups.map((g) => [g.slug, g.sortOrder])).toEqual([
      ["base-style", 0],
      ["extras", 1],
      ["bake", 2],
    ]);
    expect(plan.items.find((i) => i.slug === "halloumi-pie")?.groupSlugs).toEqual([]);
  });

  it("REFUSES any item with a price but no priceSource", () => {
    expect(() => buildSeedPlan(withItem({ priceCents: 650 }))).toThrow(/zaatar-manoush.*price but no priceSource/s);
    expect(() => buildSeedPlan(withItem({ priceCents: 650, priceSource: "   " }))).toThrow(/priceSource/);
  });

  it("accepts a sourced price and carries the source into the plan", () => {
    const plan = buildSeedPlan(withItem({ priceCents: 650, priceSource: "doughboss.com.au/menu, retrieved 2026-10-02" }));
    expect(plan.items[0]).toMatchObject({ priceCents: 650, priceSource: "doughboss.com.au/menu, retrieved 2026-10-02" });
    expect(countPlan(plan)).toMatchObject({ itemsPriced: 1, itemsUnpriced: 5 });
  });

  it("allows an unpriced item without a source (null stays null)", () => {
    expect(buildSeedPlan(withItem({ priceCents: null })).items[0]?.priceCents).toBeNull();
  });

  it("rejects non-integer or negative prices", () => {
    expect(() => buildSeedPlan(withItem({ priceCents: 6.5, priceSource: "x" }))).toThrow(/non-negative integer/);
    expect(() => buildSeedPlan(withItem({ priceCents: -1, priceSource: "x" }))).toThrow(/non-negative integer/);
  });

  it("rejects duplicate slugs and unknown categories", () => {
    expect(() => buildSeedPlan({ ...source(), items: [...ITEMS, ITEMS[0]!] })).toThrow(/duplicate item slug "zaatar-manoush"/);
    expect(() => buildSeedPlan({ ...source(), stores: [...STORES, STORES[0]!] })).toThrow(/duplicate store slug/);
    expect(() => buildSeedPlan(withItem({ categorySlug: "wraps", name: "ok" }))).not.toThrow();
    expect(() => buildSeedPlan({ ...source(), categories: CATEGORIES.filter((c) => c.slug !== "manoush") })).toThrow(/unknown category "manoush"/);
  });

  it("rejects a shared modifier group defined two different ways", () => {
    const tweaked: ModifierGroup = { ...ITEMS[0]!.modifierGroups[0]!, name: "Different name" };
    const item = { ...ITEMS[1]!, modifierGroups: [tweaked] };
    expect(() => buildSeedPlan({ ...source(), items: [ITEMS[0]!, item] })).toThrow(/"base-style" is defined differently/);
  });

  it("rejects a group order the join table cannot represent", () => {
    const [base, extras] = ITEMS[0]!.modifierGroups;
    const flipped = { ...ITEMS[1]!, modifierGroups: [extras!, base!] };
    expect(() => buildSeedPlan({ ...source(), items: [ITEMS[0]!, flipped] })).toThrow(/order the database cannot represent/);
  });

  it("reports every problem at once", () => {
    const bad = { ...ITEMS[0]!, priceCents: 100, categorySlug: "wraps" as const };
    const bad2 = { ...ITEMS[1]!, priceCents: 200 };
    expect(() => buildSeedPlan({ ...source(), items: [bad, bad2] })).toThrow(/zaatar-manoush[\s\S]*cheese-manoush/);
  });
});

describe("assertSeedAllowed / runSeedCli", () => {
  it("refuses when the demo flag is set", () => {
    expect(() => assertSeedAllowed(true)).toThrow(/DOUGHBOSS_DEMO_DATA/);
    expect(() => assertSeedAllowed(false)).not.toThrow();
  });

  it("--dry-run prints counts, exits 0 and never asks for a database client", async () => {
    const lines: string[] = [];
    const code = await runSeedCli(["--dry-run"], { log: (l) => lines.push(l) });
    expect(code).toBe(0);
    expect(lines.join("\n")).toContain("Dry run (no database touched)");
    expect(lines.join("\n")).toContain("items:                6 (0 priced, 6 price to be confirmed)");
    expect(db.getPrisma).not.toHaveBeenCalled();
  });

  it("--dry-run works even with a database configured, and still touches nothing", async () => {
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    resetEnvCache();
    expect(await runSeedCli(["--dry-run"], { log: () => {} })).toBe(0);
    expect(db.getPrisma).not.toHaveBeenCalled();
  });

  it("refuses to run (exit 1) when DOUGHBOSS_DEMO_DATA is set, dry run or not", async () => {
    vi.stubEnv("DOUGHBOSS_DEMO_DATA", "1");
    resetEnvCache();
    const lines: string[] = [];
    expect(await runSeedCli(["--dry-run"], { log: (l) => lines.push(l) })).toBe(1);
    expect(await runSeedCli([], { log: (l) => lines.push(l) })).toBe(1);
    expect(lines.join("\n")).toContain("Refusing to seed");
    expect(db.getPrisma).not.toHaveBeenCalled();
  });

  it("a real run without DATABASE_URL exits 1 with a hint, not a crash", async () => {
    const lines: string[] = [];
    expect(await runSeedCli([], { log: (l) => lines.push(l) })).toBe(1);
    expect(lines.join("\n")).toMatch(/DATABASE_URL is not set/);
  });

  it("formatCounts is a readable summary", () => {
    expect(formatCounts(countPlan(buildSeedPlan(source())))).toContain("stores:               3 (19 hours windows, 0 closures)");
  });
});

// ───────────────────────── applySeedPlan against a recording fake ─────────────────────────

type Call = { model: string; method: string; args: unknown };

function recordingDb(existingDietaryVerifiedAt: Date | null = null) {
  const calls: Call[] = [];
  let n = 0;
  const model = (name: string, methods: string[]) =>
    Object.fromEntries(
      methods.map((method) => [
        method,
        vi.fn(async (args: unknown) => {
          calls.push({ model: name, method, args });
          if (name === "menuItem" && method === "findUnique") return { dietaryVerifiedAt: existingDietaryVerifiedAt };
          return { id: `${name}-${++n}`, count: 0 };
        }),
      ]),
    );
  const fake = {
    store: model("store", ["upsert"]),
    storeHours: model("storeHours", ["deleteMany", "createMany"]),
    storeClosure: model("storeClosure", ["upsert"]),
    category: model("category", ["upsert"]),
    modifierGroup: model("modifierGroup", ["upsert"]),
    modifier: model("modifier", ["upsert"]),
    menuItem: model("menuItem", ["findUnique", "upsert"]),
    menuItemModifierGroup: model("menuItemModifierGroup", ["upsert", "deleteMany"]),
    storeMenuItem: model("storeMenuItem", ["upsert"]),
    $transaction: vi.fn(async (ops: Promise<unknown>[]) => Promise.all(ops)),
  };
  return { fake: fake as unknown as SeedDb, calls };
}

describe("applySeedPlan", () => {
  const NOW = new Date("2026-10-02T00:00:00Z");

  it("only upserts (idempotent) and replaces hours inside one transaction; never creates plain rows", async () => {
    const { fake, calls } = recordingDb();
    await applySeedPlan(fake, buildSeedPlan(source()), NOW);

    const methods = new Set(calls.map((c) => `${c.model}.${c.method}`));
    expect([...methods].sort()).toEqual(
      [
        "category.upsert",
        "menuItem.findUnique",
        "menuItem.upsert",
        "menuItemModifierGroup.deleteMany",
        "menuItemModifierGroup.upsert",
        "modifier.upsert",
        "modifierGroup.upsert",
        "store.upsert",
        "storeHours.createMany",
        "storeHours.deleteMany",
      ].sort(),
    );
    expect(calls.filter((c) => c.model === "store")).toHaveLength(3);
    expect(calls.filter((c) => c.model === "menuItem" && c.method === "upsert")).toHaveLength(6);
    expect(calls.filter((c) => c.model === "menuItemModifierGroup" && c.method === "upsert")).toHaveLength(12);
    expect(fake.$transaction).toHaveBeenCalledTimes(3);
  });

  it("running twice issues the same writes (nothing accumulates)", async () => {
    const a = recordingDb();
    const b = recordingDb();
    const plan = buildSeedPlan(source());
    await applySeedPlan(a.fake, plan, NOW);
    await applySeedPlan(b.fake, plan, NOW);
    const sig = (calls: Call[]) => calls.map((c) => `${c.model}.${c.method}`);
    expect(sig(a.calls)).toEqual(sig(b.calls));
  });

  it("never writes a null price over an existing one, but writes a sourced price", async () => {
    const unpriced = recordingDb();
    await applySeedPlan(unpriced.fake, buildSeedPlan(source()), NOW);
    const update = (unpriced.calls.find((c) => c.model === "menuItem" && c.method === "upsert")?.args as { update: Record<string, unknown>; create: Record<string, unknown> });
    expect(update.update).not.toHaveProperty("priceCents");
    expect(update.update).not.toHaveProperty("priceSource");
    expect(update.create).toMatchObject({ priceCents: null, priceSource: null });

    const priced = recordingDb();
    await applySeedPlan(priced.fake, buildSeedPlan(withItem({ priceCents: 650, priceSource: "menu page 2026-10-02" })), NOW);
    const args = priced.calls.find((c) => c.model === "menuItem" && c.method === "upsert")?.args as { update: Record<string, unknown> };
    expect(args.update).toMatchObject({ priceCents: 650, priceSource: "menu page 2026-10-02" });
  });

  it("does not touch dietary data for unverified items, and keeps an existing verification time", async () => {
    const unverified = recordingDb();
    await applySeedPlan(unverified.fake, buildSeedPlan(source()), NOW);
    const u = unverified.calls.find((c) => c.model === "menuItem" && c.method === "upsert")?.args as { update: Record<string, unknown>; create: Record<string, unknown> };
    expect(u.update).not.toHaveProperty("dietaryTags");
    expect(u.update).not.toHaveProperty("dietaryVerifiedAt");
    expect(u.create).toMatchObject({ dietaryVerifiedAt: null });

    const verifiedSrc = withItem({ dietaryVerified: true, dietaryTags: ["HALAL"] });
    const fresh = recordingDb(null);
    await applySeedPlan(fresh.fake, buildSeedPlan(verifiedSrc), NOW);
    const f = fresh.calls.find((c) => c.model === "menuItem" && c.method === "upsert")?.args as { update: Record<string, unknown> };
    expect(f.update).toMatchObject({ dietaryTags: ["HALAL"], dietaryVerifiedAt: NOW });

    const earlier = new Date("2026-09-01T00:00:00Z");
    const stamped = recordingDb(earlier);
    await applySeedPlan(stamped.fake, buildSeedPlan(verifiedSrc), NOW);
    const s = stamped.calls.find((c) => c.model === "menuItem" && c.method === "upsert")?.args as { update: Record<string, unknown> };
    expect(s.update).toMatchObject({ dietaryTags: ["HALAL"] });
    expect(s.update).not.toHaveProperty("dietaryVerifiedAt");
  });

  it("sets prepMinutes and acceptsOnline on create only", async () => {
    const { fake, calls } = recordingDb();
    await applySeedPlan(fake, buildSeedPlan(source()), NOW);
    const args = calls.find((c) => c.model === "store")?.args as { create: Record<string, unknown>; update: Record<string, unknown> };
    expect(args.create).toMatchObject({ prepMinutes: 20, acceptsOnline: true });
    expect(args.update).not.toHaveProperty("prepMinutes");
    expect(args.update).not.toHaveProperty("acceptsOnline");
  });

  it("writes store-unavailable rows only for declared exceptions and prunes stale group links", async () => {
    const { fake, calls } = recordingDb();
    await applySeedPlan(fake, buildSeedPlan(withItem({ unavailableAt: ["roselands"] })), NOW);
    const rows = calls.filter((c) => c.model === "storeMenuItem");
    expect(rows).toHaveLength(1);
    expect(rows[0]?.args).toMatchObject({ create: { isAvailable: false }, update: { isAvailable: false } });
    expect(calls.filter((c) => c.model === "menuItemModifierGroup" && c.method === "deleteMany")).toHaveLength(6);
  });
});
