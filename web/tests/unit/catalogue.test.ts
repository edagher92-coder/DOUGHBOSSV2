import { describe, expect, it } from "vitest";
import { CATEGORIES, ITEMS, PRODUCTION_CATALOGUE, STORES } from "@/lib/data/catalogue";
import { DEMO_CATALOGUE, assertDemoAllowed } from "@/lib/data/demo-catalogue";
import type { Catalogue, ModifierGroup, StoreHoursWindow } from "@/types/menu";

const hoursFor = (slug: string): StoreHoursWindow[] => STORES.find((s) => s.slug === slug)?.hours ?? [];
const days = (windows: StoreHoursWindow[]) => windows.map((w) => w.dayOfWeek).sort();
const duplicates = (values: string[]) => values.filter((v, i) => values.indexOf(v) !== i);

describe("stores", () => {
  it("lists the three trading stores", () => {
    expect(STORES.map((s) => s.slug)).toEqual(["revesby", "bankstown", "roselands"]);
  });

  it.each(STORES.map((s) => [s.slug, s] as const))("%s has an E.164 Australian phone (+61 then 9 digits)", (_slug, store) => {
    expect(store.phone).toMatch(/^\+61\d{9}$/);
  });

  it.each(STORES.map((s) => [s.slug, s.timezone] as const))("%s has a valid IANA timezone", (_slug, timezone) => {
    expect(() => new Intl.DateTimeFormat("en-AU", { timeZone: timezone })).not.toThrow();
    expect(timezone).toBe("Australia/Sydney");
  });

  it.each(STORES.map((s) => [s.slug, s] as const))("%s has sane hours windows that never overlap on a day", (_slug, store) => {
    expect(store.hours.length).toBeGreaterThan(0);
    for (const w of store.hours) {
      expect(Number.isInteger(w.dayOfWeek)).toBe(true);
      expect(w.dayOfWeek).toBeGreaterThanOrEqual(0);
      expect(w.dayOfWeek).toBeLessThanOrEqual(6);
      expect(w.opensMin).toBeGreaterThanOrEqual(0);
      expect(w.opensMin).toBeLessThan(w.closesMin);
      expect(w.closesMin).toBeLessThanOrEqual(1440);
    }
    for (let day = 0; day <= 6; day++) {
      const windows = store.hours.filter((w) => w.dayOfWeek === day).sort((a, b) => a.opensMin - b.opensMin);
      windows.slice(1).forEach((w, i) => {
        expect(w.opensMin, `day ${day} overlaps`).toBeGreaterThanOrEqual(windows[i]?.closesMin ?? 0);
      });
    }
  });

  it("Bankstown trades Monday to Friday only, 07:00-14:00", () => {
    const h = hoursFor("bankstown");
    expect(days(h)).toEqual([1, 2, 3, 4, 5]);
    expect(h.every((w) => w.opensMin === 7 * 60 && w.closesMin === 14 * 60)).toBe(true);
  });

  it("Roselands trades daily, 08:00-15:00", () => {
    const h = hoursFor("roselands");
    expect(days(h)).toEqual([0, 1, 2, 3, 4, 5, 6]);
    expect(h.every((w) => w.opensMin === 8 * 60 && w.closesMin === 15 * 60)).toBe(true);
  });

  it("Revesby trades daily, 06:30-14:30", () => {
    const h = hoursFor("revesby");
    expect(days(h)).toEqual([0, 1, 2, 3, 4, 5, 6]);
    expect(h.every((w) => w.opensMin === 6 * 60 + 30 && w.closesMin === 14 * 60 + 30)).toBe(true);
  });

  it("has a positive whole-minute prep time and well-formed closure dates", () => {
    for (const s of STORES) {
      expect(Number.isInteger(s.prepMinutes) && s.prepMinutes > 0).toBe(true);
      for (const c of s.closures) expect(c).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    }
  });
});

describe.each<[string, Catalogue]>([
  ["production", PRODUCTION_CATALOGUE],
  ["demo", DEMO_CATALOGUE],
])("%s catalogue integrity", (_name, catalogue) => {
  it("has unique item slugs and ids", () => {
    expect(duplicates(catalogue.items.map((i) => i.slug))).toEqual([]);
    expect(duplicates(catalogue.items.map((i) => i.id))).toEqual([]);
  });

  it("has unique category slugs", () => {
    expect(duplicates(catalogue.categories.map((c) => c.slug))).toEqual([]);
  });

  it("puts every item in a category that exists", () => {
    const known = new Set(catalogue.categories.map((c) => c.slug));
    for (const item of catalogue.items) expect(known.has(item.categorySlug), `${item.slug} -> ${item.categorySlug}`).toBe(true);
  });

  it("only marks items unavailable at stores that exist", () => {
    const stores = new Set(STORES.map((s) => s.slug));
    for (const item of catalogue.items) for (const s of item.unavailableAt) expect(stores.has(s)).toBe(true);
  });

  it("has well-formed modifier groups", () => {
    const groups: [string, ModifierGroup][] = catalogue.items.flatMap((i) => i.modifierGroups.map((g) => [i.slug, g] as [string, ModifierGroup]));
    expect(groups.length).toBeGreaterThan(0);
    for (const [itemSlug, g] of groups) {
      const where = `${itemSlug}/${g.slug}`;
      expect(g.minSelect, where).toBeGreaterThanOrEqual(0);
      expect(g.minSelect, where).toBeLessThanOrEqual(g.maxSelect);
      // A cap above the option count is harmless, but a minimum above it could never be satisfied.
      expect(g.minSelect, `${where} minimum is satisfiable`).toBeLessThanOrEqual(g.options.length);
      if (g.selection === "SINGLE") expect(g.maxSelect, `${where} SINGLE`).toBe(1);
      expect(duplicates(g.options.map((o) => o.slug)), `${where} option slugs`).toEqual([]);
      // Slugs are used as object keys in cart selections; keep them plain.
      expect(g.slug).toMatch(/^[a-z0-9-]+$/);
    }
    for (const i of catalogue.items) expect(duplicates(i.modifierGroups.map((g) => g.slug)), `${i.slug} group slugs`).toEqual([]);
  });

  it("only uses integer cents for prices and modifier deltas", () => {
    for (const i of catalogue.items) {
      if (i.priceCents !== null) expect(Number.isInteger(i.priceCents) && i.priceCents >= 0, i.slug).toBe(true);
      for (const g of i.modifierGroups)
        for (const o of g.options) if (o.priceDeltaCents !== null) expect(Number.isInteger(o.priceDeltaCents), `${i.slug}/${o.slug}`).toBe(true);
    }
  });
});

describe("cross-catalogue uniqueness", () => {
  it("has no duplicate slugs across stores, categories and items", () => {
    const all = [...STORES.map((s) => s.slug), ...CATEGORIES.map((c) => c.slug as string), ...ITEMS.map((i) => i.slug)];
    expect(duplicates(all)).toEqual([]);
  });
});

describe("honesty invariants", () => {
  it("every production item with a price cites a non-empty priceSource", () => {
    for (const item of PRODUCTION_CATALOGUE.items) {
      if (item.priceCents === null) continue;
      expect(item.priceSource?.trim(), `${item.slug} has a price but no priceSource`).toBeTruthy();
    }
  });

  it("every production modifier price is cited too (via its item's priceSource)", () => {
    for (const item of PRODUCTION_CATALOGUE.items) {
      const hasModifierPrice = item.modifierGroups.some((g) => g.options.some((o) => o.priceDeltaCents !== null));
      if (hasModifierPrice) expect(item.priceSource?.trim(), `${item.slug} has priced modifiers but no priceSource`).toBeTruthy();
    }
  });

  it("never claims dietary facts in production without verification", () => {
    for (const item of PRODUCTION_CATALOGUE.items) {
      if (!item.dietaryVerified) expect(item.dietaryTags, `${item.slug} carries unverified tags`).toEqual([]);
    }
  });

  it("flags production as real and demo as fake", () => {
    expect(PRODUCTION_CATALOGUE.isDemo).toBe(false);
    expect(DEMO_CATALOGUE.isDemo).toBe(true);
  });

  it("labels every demo item as demo so it can't be mistaken for the real menu", () => {
    // Items that share a slug with a production dish are described as "Demo item. ...".
    for (const item of DEMO_CATALOGUE.items) expect(item.description, item.slug).toMatch(/^Demo item/);
  });

  it("keeps the demo catalogue free of production price sources", () => {
    expect(DEMO_CATALOGUE.items.every((i) => i.priceSource === undefined)).toBe(true);
  });
});

describe("assertDemoAllowed", () => {
  it("throws in production", () => {
    expect(() => assertDemoAllowed({ NODE_ENV: "production" })).toThrow(/production/);
  });

  it.each(["development", "test"])("does not throw in %s", (NODE_ENV) => {
    expect(() => assertDemoAllowed({ NODE_ENV })).not.toThrow();
  });

  it("does not throw when NODE_ENV is unset", () => {
    expect(() => assertDemoAllowed({})).not.toThrow();
  });
});
