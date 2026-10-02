import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  formatPhoneDisplay,
  mapCatalogue,
  mapItem,
  mapStore,
  mapStores,
  summariseHours,
  type CategoryRow,
  type ItemRow,
  type StoreRow,
} from "@/lib/data/db-catalogue";
import { CatalogueUnavailableError } from "@/lib/errors";
import { resetEnvCache } from "@/lib/env";
import { PRODUCTION_CATALOGUE, STORES } from "@/lib/data/catalogue";
import { DEMO_CATALOGUE } from "@/lib/data/demo-catalogue";
import type { StoreHoursWindow } from "@/types/menu";

// The loaders are mocked for the data-mode tests; the pure mappers stay real.
vi.mock("@/lib/data/db-catalogue", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/data/db-catalogue")>();
  return { ...actual, loadCatalogue: vi.fn(), loadStores: vi.fn() };
});
vi.mock("@/lib/db", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/db")>();
  return { ...actual, getPrisma: vi.fn(() => ({})) };
});

// ───────────────────────── Row builders ─────────────────────────

const T = new Date("2026-10-01T00:00:00Z");

const storeRow = (over: Partial<StoreRow> = {}): StoreRow => ({
  id: "s1",
  slug: "revesby",
  name: "Revesby",
  addressLine1: "12/25 Selems Parade",
  addressLine2: null,
  suburb: "Revesby",
  state: "NSW",
  postcode: "2212",
  phone: "+61297742286",
  timezone: "Australia/Sydney",
  mapsUrl: null,
  acceptsOnline: true,
  prepMinutes: 20,
  sortOrder: 0,
  createdAt: T,
  updatedAt: T,
  hours: [],
  closures: [],
  ...over,
});

const categoryRow = (over: Partial<CategoryRow> = {}): CategoryRow => ({
  id: "c1",
  slug: "manoush",
  name: "Manoush",
  blurb: "Flatbread",
  sortOrder: 0,
  createdAt: T,
  ...over,
});

const groupRow = (
  over: Partial<ItemRow["modifierGroups"][number]["group"]> = {},
): ItemRow["modifierGroups"][number]["group"] => ({
  id: "g1",
  slug: "extras",
  name: "Extras",
  selection: "MULTIPLE",
  minSelect: 0,
  maxSelect: 4,
  sortOrder: 0,
  modifiers: [],
  ...over,
});

const itemRow = (over: Partial<ItemRow> = {}): ItemRow => ({
  id: "i1",
  slug: "zaatar-manoush",
  categoryId: "c1",
  name: "Za’atar Manoush",
  description: "Classic.",
  priceCents: null,
  priceSource: null,
  imageUrl: null,
  isSpicy: false,
  dietaryTags: [],
  dietaryVerifiedAt: null,
  isActive: true,
  sortOrder: 0,
  createdAt: T,
  updatedAt: T,
  category: categoryRow(),
  modifierGroups: [],
  stores: [],
  ...over,
});

const modifier = (over: Partial<ItemRow["modifierGroups"][number]["group"]["modifiers"][number]> = {}) => ({
  id: "m1",
  groupId: "g1",
  slug: "extra-cheese",
  name: "Extra cheese",
  priceDeltaCents: null as number | null,
  isDefault: false,
  isActive: true,
  sortOrder: 0,
  ...over,
});

// ───────────────────────── Mappers ─────────────────────────

describe("mapStore", () => {
  it("maps a store with hours windows and closures, sorted", () => {
    const store = mapStore(
      storeRow({
        hours: [
          { id: "h2", storeId: "s1", dayOfWeek: 2, opensMin: 390, closesMin: 870 },
          { id: "h1", storeId: "s1", dayOfWeek: 1, opensMin: 390, closesMin: 870 },
        ],
        closures: [
          { id: "x2", storeId: "s1", date: "2026-12-26", reason: "Boxing Day" },
          { id: "x1", storeId: "s1", date: "2026-12-25", reason: null },
        ],
      }),
    );
    expect(store.slug).toBe("revesby");
    expect(store.hours).toEqual([
      { dayOfWeek: 1, opensMin: 390, closesMin: 870 },
      { dayOfWeek: 2, opensMin: 390, closesMin: 870 },
    ]);
    expect(store.closures).toEqual(["2026-12-25", "2026-12-26"]);
    expect(store.phoneDisplay).toBe("(02) 9774 2286");
    expect(store.addressLine2).toBeUndefined();
    expect(store.note).toBeUndefined();
  });

  it("keeps a stored maps link and builds one from the address only when missing", () => {
    expect(mapStore(storeRow({ mapsUrl: "https://maps.example/x" })).mapsUrl).toBe("https://maps.example/x");
    expect(mapStore(storeRow()).mapsUrl).toContain("12%2F25%20Selems%20Parade%20Revesby%20NSW%202212");
  });

  it("keeps acceptsOnline false rather than defaulting it", () => {
    expect(mapStore(storeRow({ acceptsOnline: false })).acceptsOnline).toBe(false);
  });

  it("REJECTS an unknown store slug instead of dropping it", () => {
    expect(() => mapStore(storeRow({ slug: "parramatta" }))).toThrow(CatalogueUnavailableError);
    expect(() => mapStores([storeRow(), storeRow({ id: "s2", slug: "parramatta" })])).toThrow(/parramatta/);
  });

  it("orders stores by sortOrder", () => {
    const out = mapStores([
      storeRow({ id: "b", slug: "bankstown", sortOrder: 1 }),
      storeRow({ id: "r", slug: "revesby", sortOrder: 0 }),
    ]);
    expect(out.map((s) => s.slug)).toEqual(["revesby", "bankstown"]);
  });
});

describe("derived store display fields", () => {
  it("formats the three real numbers exactly as the seed file displays them", () => {
    for (const s of STORES) expect(formatPhoneDisplay(s.phone)).toBe(s.phoneDisplay);
  });

  it("falls back to the stored E.164 for formats it does not recognise", () => {
    expect(formatPhoneDisplay("+14155550123")).toBe("+14155550123");
    expect(formatPhoneDisplay("0297742286")).toBe("0297742286");
  });

  it("summarises the three real stores from their windows alone", () => {
    const bySlug = Object.fromEntries(STORES.map((s) => [s.slug, summariseHours(s.hours)]));
    expect(bySlug).toEqual({
      revesby: "Daily, 6:30am – 2:30pm",
      bankstown: "Mon–Fri, 7am – 2pm",
      roselands: "Daily, 8am – 3pm",
    });
  });

  it("handles split days, odd day sets and no hours at all", () => {
    const w = (dayOfWeek: number, opensMin: number, closesMin: number): StoreHoursWindow => ({ dayOfWeek, opensMin, closesMin });
    expect(summariseHours([])).toBe("Opening hours to be confirmed");
    expect(summariseHours([w(1, 420, 840), w(3, 420, 840)])).toBe("Mon, Wed, 7am – 2pm");
    expect(summariseHours([w(6, 480, 780), w(0, 480, 780)])).toBe("Sat, Sun, 8am – 1pm");
    expect(summariseHours([w(1, 360, 600), w(1, 720, 900)])).toBe("Mon, 6am – 10am, 12pm – 3pm");
    expect(summariseHours([w(1, 420, 840), w(2, 420, 840), w(3, 420, 840), w(6, 480, 780)])).toBe(
      "Mon–Wed, 7am – 2pm; Sat, 8am – 1pm",
    );
  });
});

describe("mapItem / mapCatalogue", () => {
  it("maps core fields and keeps a null price null (never 0)", () => {
    const item = mapItem(itemRow());
    expect(item.priceCents).toBeNull();
    expect(item.categorySlug).toBe("manoush");
    expect(item.priceSource).toBeUndefined();
  });

  it("carries a confirmed price with its source", () => {
    const item = mapItem(itemRow({ priceCents: 650, priceSource: "doughboss.com.au/menu, retrieved 2026-10-02" }));
    expect(item.priceCents).toBe(650);
    expect(item.priceSource).toBe("doughboss.com.au/menu, retrieved 2026-10-02");
  });

  it("dietaryVerifiedAt non-null -> verified; null -> unverified, tags kept as draft", () => {
    const verified = mapItem(itemRow({ dietaryTags: ["HALAL"], dietaryVerifiedAt: T }));
    const draft = mapItem(itemRow({ dietaryTags: ["HALAL"], dietaryVerifiedAt: null }));
    expect(verified.dietaryVerified).toBe(true);
    expect(draft.dietaryVerified).toBe(false);
    expect(draft.dietaryTags).toEqual(["HALAL"]);
  });

  it("puts a store where isAvailable=false into unavailableAt, and ignores available rows", () => {
    const item = mapItem(
      itemRow({
        stores: [
          { storeId: "s1", menuItemId: "i1", isAvailable: false, store: storeRow({ slug: "roselands" }) },
          { storeId: "s2", menuItemId: "i1", isAvailable: true, store: storeRow({ id: "s2", slug: "revesby" }) },
        ],
      }),
    );
    expect(item.unavailableAt).toEqual(["roselands"]);
  });

  it("REJECTS an unknown store slug on an availability row and an unknown category slug", () => {
    expect(() =>
      mapItem(itemRow({ stores: [{ storeId: "s9", menuItemId: "i1", isAvailable: false, store: storeRow({ slug: "mars" }) }] })),
    ).toThrow(CatalogueUnavailableError);
    expect(() => mapItem(itemRow({ category: categoryRow({ slug: "desserts" }) }))).toThrow(/desserts/);
    expect(() => mapCatalogue([categoryRow({ slug: "desserts" })], [])).toThrow(CatalogueUnavailableError);
  });

  it("maps modifier groups: only active options, null deltas stay null, sorted by sortOrder", () => {
    const item = mapItem(
      itemRow({
        modifierGroups: [
          {
            menuItemId: "i1",
            groupId: "g2",
            group: groupRow({ id: "g2", slug: "bake", name: "Bake", sortOrder: 1, modifiers: [modifier({ slug: "crispy", name: "Crispy", priceDeltaCents: 0 })] }),
          },
          {
            menuItemId: "i1",
            groupId: "g1",
            group: groupRow({
              sortOrder: 0,
              modifiers: [
                modifier({ id: "m2", slug: "veg", name: "Add veggies", sortOrder: 2, priceDeltaCents: 150 }),
                modifier({ id: "m1", slug: "cheese", name: "Extra cheese", sortOrder: 1, priceDeltaCents: null }),
                modifier({ id: "m3", slug: "retired", name: "Retired", sortOrder: 0, isActive: false }),
              ],
            }),
          },
        ],
      }),
    );
    expect(item.modifierGroups.map((g) => g.slug)).toEqual(["extras", "bake"]);
    expect(item.modifierGroups[0]?.options).toEqual([
      { slug: "cheese", name: "Extra cheese", priceDeltaCents: null, isDefault: false },
      { slug: "veg", name: "Add veggies", priceDeltaCents: 150, isDefault: false },
    ]);
    expect(item.modifierGroups[1]?.options[0]?.priceDeltaCents).toBe(0);
  });

  it("keeps a required group even when all its options are inactive (so the item stays unorderable, not 'simpler')", () => {
    const item = mapItem(
      itemRow({
        modifierGroups: [
          { menuItemId: "i1", groupId: "g1", group: groupRow({ minSelect: 1, selection: "SINGLE", modifiers: [modifier({ isActive: false })] }) },
        ],
      }),
    );
    expect(item.modifierGroups).toHaveLength(1);
    expect(item.modifierGroups[0]?.options).toEqual([]);
    expect(item.modifierGroups[0]?.minSelect).toBe(1);
  });

  it("mapCatalogue drops inactive items, sorts categories and items, and is never demo", () => {
    const cat = mapCatalogue(
      [categoryRow({ id: "c2", slug: "pies", name: "Pies", blurb: null, sortOrder: 1 }), categoryRow()],
      [
        itemRow({ id: "i2", slug: "b-item", sortOrder: 1 }),
        itemRow({ id: "i3", slug: "gone", sortOrder: 0, isActive: false }),
        itemRow({ id: "i1", slug: "a-item", sortOrder: 0 }),
      ],
    );
    expect(cat.isDemo).toBe(false);
    expect(cat.categories.map((c) => c.slug)).toEqual(["manoush", "pies"]);
    expect(cat.categories[1]?.blurb).toBe("");
    expect(cat.items.map((i) => i.slug)).toEqual(["a-item", "b-item"]);
  });
});

// The module is mocked above for the data-mode tests, so fetch the REAL loaders explicitly.
const realLoaders = () => vi.importActual<typeof import("@/lib/data/db-catalogue")>("@/lib/data/db-catalogue");

describe("loaders", () => {
  it("wrap any Prisma failure as CatalogueUnavailableError with the cause attached", async () => {
    const boom = new Error("P1001 cannot reach database");
    const db = {
      store: { findMany: vi.fn().mockRejectedValue(boom) },
      category: { findMany: vi.fn().mockRejectedValue(boom) },
      menuItem: { findMany: vi.fn().mockRejectedValue(boom) },
    };
    const { loadStores, loadCatalogue } = await realLoaders();
    const stores = await loadStores(db as never).catch((e) => e);
    const cat = await loadCatalogue(db as never).catch((e) => e);
    expect(stores).toBeInstanceOf(CatalogueUnavailableError);
    expect(cat).toBeInstanceOf(CatalogueUnavailableError);
    expect((cat as CatalogueUnavailableError).cause).toBe(boom);
  });

  it("returns mapped data on success and queries only active items", async () => {
    const findItems = vi.fn().mockResolvedValue([itemRow()]);
    const db = {
      store: { findMany: vi.fn().mockResolvedValue([storeRow()]) },
      category: { findMany: vi.fn().mockResolvedValue([categoryRow()]) },
      menuItem: { findMany: findItems },
    };
    const { loadStores, loadCatalogue } = await realLoaders();
    expect((await loadCatalogue(db as never)).items).toHaveLength(1);
    expect((await loadStores(db as never))[0]?.slug).toBe("revesby");
    expect(findItems.mock.calls[0]?.[0]).toMatchObject({ where: { isActive: true } });
  });

  it("a mapping failure (unknown slug) surfaces as unavailable, not as a partial menu", async () => {
    const db = {
      store: { findMany: vi.fn() },
      category: { findMany: vi.fn().mockResolvedValue([categoryRow({ slug: "desserts" })]) },
      menuItem: { findMany: vi.fn().mockResolvedValue([]) },
    };
    const { loadCatalogue } = await realLoaders();
    await expect(loadCatalogue(db as never)).rejects.toBeInstanceOf(CatalogueUnavailableError);
  });
});

// ───────────────────────── Data mode (src/lib/data/index.ts) ─────────────────────────

describe("data mode selection", () => {
  const load = async () => {
    const index = await import("@/lib/data");
    const mocked = await import("@/lib/data/db-catalogue");
    return { index, loadCatalogue: vi.mocked(mocked.loadCatalogue), loadStores: vi.mocked(mocked.loadStores) };
  };

  beforeEach(() => {
    vi.clearAllMocks();
    vi.stubEnv("DOUGHBOSS_DEMO_DATA", "");
    vi.stubEnv("DATABASE_URL", "");
    vi.stubEnv("NODE_ENV", "test");
    resetEnvCache();
  });
  afterEach(() => {
    vi.unstubAllEnvs();
    resetEnvCache();
    vi.restoreAllMocks();
  });

  it("static: no DATABASE_URL -> production seed data, logged exactly once", async () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const { index, loadCatalogue: lc } = await load();
    index.resetDataModeLog();
    expect(index.getDataMode()).toBe("static");
    expect(await index.getCatalogue()).toBe(PRODUCTION_CATALOGUE);
    expect(await index.getStores()).toBe(STORES);
    expect(warn).toHaveBeenCalledTimes(1);
    expect(lc).not.toHaveBeenCalled();
  });

  it("demo: flag -> demo catalogue; production + flag throws before anything loads", async () => {
    const { index } = await load();
    vi.stubEnv("DOUGHBOSS_DEMO_DATA", "1");
    resetEnvCache();
    expect(index.getDataMode()).toBe("demo");
    expect((await index.getCatalogue()).isDemo).toBe(true);
    expect(await index.getCatalogue()).toBe(DEMO_CATALOGUE);

    vi.stubEnv("NODE_ENV", "production");
    resetEnvCache();
    expect(() => index.getDataMode()).toThrow(/production/);
    await expect(index.getCatalogue()).rejects.toThrow(/production/);
  });

  it("demo wins over a configured database", async () => {
    const { index, loadCatalogue: lc } = await load();
    vi.stubEnv("DOUGHBOSS_DEMO_DATA", "1");
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    resetEnvCache();
    expect(index.getDataMode()).toBe("demo");
    await index.getCatalogue();
    expect(lc).not.toHaveBeenCalled();
  });

  it("database: DATABASE_URL set -> the loader's result, not static data", async () => {
    const { index, loadCatalogue: lc, loadStores: ls } = await load();
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    resetEnvCache();
    const fromDb = { categories: [], items: [], isDemo: false };
    lc.mockResolvedValueOnce(fromDb);
    ls.mockResolvedValueOnce([]);
    expect(index.getDataMode()).toBe("database");
    expect(await index.getCatalogue()).toBe(fromDb);
    expect(await index.getStores()).toEqual([]);
  });

  it("NEVER falls back to static data when the database read fails (silence rule)", async () => {
    const { index, loadCatalogue: lc, loadStores: ls } = await load();
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    resetEnvCache();
    lc.mockRejectedValue(new Error("connection refused"));
    ls.mockRejectedValue(new CatalogueUnavailableError("already typed"));

    const catalogueError = await index.getCatalogue().catch((e) => e);
    expect(catalogueError).toBeInstanceOf(CatalogueUnavailableError);
    expect((catalogueError as CatalogueUnavailableError).cause).toBeInstanceOf(Error);

    const storesError = await index.getStores().catch((e) => e);
    expect(storesError).toBeInstanceOf(CatalogueUnavailableError);
    expect(storesError.message).toBe("already typed");
  });

  it("database mode also fails closed when getPrisma itself throws", async () => {
    const { index } = await load();
    const db = await import("@/lib/db");
    vi.mocked(db.getPrisma).mockImplementationOnce(() => {
      throw new Error("client init failed");
    });
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    resetEnvCache();
    await expect(index.getCatalogue()).rejects.toBeInstanceOf(CatalogueUnavailableError);
  });
});
