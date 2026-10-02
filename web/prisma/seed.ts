/**
 * Production seed: stores (+hours, closures), categories, items (+modifier
 * groups, options, join rows) from src/lib/data/catalogue.ts.
 *
 *   npm run db:seed                 # writes to DATABASE_URL
 *   npx tsx prisma/seed.ts --dry-run   # prints counts, touches no database
 *
 * Re-running is safe (everything is an upsert) and deliberately gentle with
 * data staff own once the site is live:
 *  - a price is only ever written when the seed HAS one (a null never wipes a confirmed price);
 *  - dietary tags and the verification stamp are only written for items the
 *    seed marks verified, and an existing verification time is kept;
 *  - prepMinutes / acceptsOnline are set on create only (the seed value is a placeholder);
 *  - isActive is never touched; closures are only added, never removed.
 * Opening hours ARE replaced to match the seed, because a stale window left
 * behind would silently publish wrong trading hours.
 */
import type { Prisma, PrismaClient } from "@prisma/client";
import { CATEGORIES, ITEMS, STORES } from "../src/lib/data/catalogue";
import { getPrisma } from "../src/lib/db";
import { getEnv } from "../src/lib/env";
import type { Category, MenuItem, ModifierGroup, Store, StoreHoursWindow, StoreSlug } from "../src/types/menu";

// ───────────────────────── Plan (pure, no database) ─────────────────────────

export interface SeedSource {
  stores: Store[];
  categories: Category[];
  items: MenuItem[];
}

export interface PlannedGroup {
  slug: string;
  name: string;
  selection: ModifierGroup["selection"];
  minSelect: number;
  maxSelect: number;
  sortOrder: number;
  options: Array<{ slug: string; name: string; priceDeltaCents: number | null; isDefault: boolean; sortOrder: number }>;
}

export interface PlannedItem {
  slug: string;
  categorySlug: string;
  name: string;
  description: string;
  priceCents: number | null;
  priceSource: string | null;
  isSpicy: boolean;
  dietaryTags: MenuItem["dietaryTags"];
  dietaryVerified: boolean;
  sortOrder: number;
  groupSlugs: string[];
  unavailableAt: StoreSlug[];
}

export interface SeedPlan {
  stores: Array<{ store: Store; sortOrder: number; hours: StoreHoursWindow[]; closures: string[] }>;
  categories: Array<Category & { sortOrder: number }>;
  groups: PlannedGroup[];
  items: PlannedItem[];
}

export interface SeedCounts {
  stores: number;
  hoursWindows: number;
  closures: number;
  categories: number;
  modifierGroups: number;
  modifierOptions: number;
  items: number;
  itemsPriced: number;
  itemsUnpriced: number;
  itemGroupLinks: number;
  storeAvailabilityRows: number;
}

const sameJson = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

/** Validates the source and flattens it into the rows to write. Throws on anything unsafe. */
export function buildSeedPlan(source: SeedSource): SeedPlan {
  const problems: string[] = [];

  const unsourced = source.items.filter((i) => i.priceCents !== null && !i.priceSource?.trim());
  for (const i of unsourced) problems.push(`item "${i.slug}" has a price but no priceSource`);

  for (const i of source.items) {
    if (i.priceCents !== null && (!Number.isInteger(i.priceCents) || i.priceCents < 0)) {
      problems.push(`item "${i.slug}" price must be a non-negative integer number of cents`);
    }
  }

  const dupes = (label: string, keys: string[]) => {
    for (const k of new Set(keys.filter((x, idx) => keys.indexOf(x) !== idx))) problems.push(`duplicate ${label} "${k}"`);
  };
  dupes("store slug", source.stores.map((s) => s.slug));
  dupes("category slug", source.categories.map((c) => c.slug));
  dupes("item slug", source.items.map((i) => i.slug));

  const categorySlugs = new Set(source.categories.map((c) => c.slug));
  for (const i of source.items) {
    if (!categorySlugs.has(i.categorySlug)) problems.push(`item "${i.slug}" uses unknown category "${i.categorySlug}"`);
  }

  // Modifier groups are shared rows (globally unique slug), so every use must agree.
  const groups = new Map<string, PlannedGroup>();
  for (const item of source.items) {
    for (const g of item.modifierGroups) {
      const planned: PlannedGroup = {
        slug: g.slug,
        name: g.name,
        selection: g.selection,
        minSelect: g.minSelect,
        maxSelect: g.maxSelect,
        sortOrder: groups.size,
        options: g.options.map((o, idx) => ({
          slug: o.slug,
          name: o.name,
          priceDeltaCents: o.priceDeltaCents,
          isDefault: o.isDefault,
          sortOrder: idx,
        })),
      };
      const seen = groups.get(g.slug);
      if (!seen) groups.set(g.slug, planned);
      else if (!sameJson({ ...seen, sortOrder: 0 }, { ...planned, sortOrder: 0 })) {
        problems.push(`modifier group "${g.slug}" is defined differently on different items`);
      }
    }
  }

  // The join table has no order column, so group order comes from the group's sortOrder.
  const order = [...groups.keys()];
  for (const item of source.items) {
    const idx = item.modifierGroups.map((g) => order.indexOf(g.slug));
    if (idx.some((v, k) => k > 0 && v < (idx[k - 1] ?? -1))) {
      problems.push(`item "${item.slug}" lists modifier groups in an order the database cannot represent`);
    }
  }

  if (problems.length > 0) throw new Error(`Seed refused:\n - ${problems.join("\n - ")}`);

  return {
    stores: source.stores.map((store, i) => ({ store, sortOrder: i, hours: store.hours, closures: store.closures })),
    categories: source.categories.map((c, i) => ({ ...c, sortOrder: i })),
    groups: [...groups.values()],
    items: source.items.map((i, idx) => ({
      slug: i.slug,
      categorySlug: i.categorySlug,
      name: i.name,
      description: i.description,
      priceCents: i.priceCents,
      priceSource: i.priceSource?.trim() ?? null,
      isSpicy: i.isSpicy,
      dietaryTags: i.dietaryTags,
      dietaryVerified: i.dietaryVerified,
      sortOrder: idx,
      groupSlugs: i.modifierGroups.map((g) => g.slug),
      unavailableAt: i.unavailableAt,
    })),
  };
}

export function countPlan(plan: SeedPlan): SeedCounts {
  const priced = plan.items.filter((i) => i.priceCents !== null).length;
  return {
    stores: plan.stores.length,
    hoursWindows: plan.stores.reduce((n, s) => n + s.hours.length, 0),
    closures: plan.stores.reduce((n, s) => n + s.closures.length, 0),
    categories: plan.categories.length,
    modifierGroups: plan.groups.length,
    modifierOptions: plan.groups.reduce((n, g) => n + g.options.length, 0),
    items: plan.items.length,
    itemsPriced: priced,
    itemsUnpriced: plan.items.length - priced,
    itemGroupLinks: plan.items.reduce((n, i) => n + i.groupSlugs.length, 0),
    storeAvailabilityRows: plan.items.reduce((n, i) => n + i.unavailableAt.length, 0),
  };
}

export function formatCounts(c: SeedCounts): string {
  return [
    `stores:               ${c.stores} (${c.hoursWindows} hours windows, ${c.closures} closures)`,
    `categories:           ${c.categories}`,
    `modifier groups:      ${c.modifierGroups} (${c.modifierOptions} options)`,
    `items:                ${c.items} (${c.itemsPriced} priced, ${c.itemsUnpriced} price to be confirmed)`,
    `item<->group links:   ${c.itemGroupLinks}`,
    `store-unavailable rows: ${c.storeAvailabilityRows}`,
  ].join("\n");
}

/** The seed is production data only; demo mode is refused outright. */
export function assertSeedAllowed(demoFlag: boolean): void {
  if (demoFlag) {
    throw new Error("Refusing to seed: DOUGHBOSS_DEMO_DATA is set. The seed writes PRODUCTION data only.");
  }
}

// ───────────────────────── Apply (Prisma) ─────────────────────────

export type SeedDb = Pick<
  PrismaClient,
  | "$transaction"
  | "store"
  | "storeHours"
  | "storeClosure"
  | "category"
  | "modifierGroup"
  | "modifier"
  | "menuItem"
  | "menuItemModifierGroup"
  | "storeMenuItem"
>;

export async function applySeedPlan(db: SeedDb, plan: SeedPlan, now: Date = new Date()): Promise<void> {
  const storeIds = new Map<string, string>();
  for (const { store, sortOrder, hours, closures } of plan.stores) {
    const common = {
      name: store.name,
      addressLine1: store.addressLine1,
      addressLine2: store.addressLine2 ?? null,
      suburb: store.suburb,
      state: store.state,
      postcode: store.postcode,
      phone: store.phone,
      timezone: store.timezone,
      mapsUrl: store.mapsUrl,
      sortOrder,
    };
    const row = await db.store.upsert({
      where: { slug: store.slug },
      create: { slug: store.slug, ...common, acceptsOnline: store.acceptsOnline, prepMinutes: store.prepMinutes },
      update: common,
      select: { id: true },
    });
    storeIds.set(store.slug, row.id);

    // Replace, don't merge: see the header comment on stale windows.
    await db.$transaction([
      db.storeHours.deleteMany({ where: { storeId: row.id } }),
      db.storeHours.createMany({
        data: hours.map((h) => ({ storeId: row.id, dayOfWeek: h.dayOfWeek, opensMin: h.opensMin, closesMin: h.closesMin })),
      }),
    ]);

    for (const date of closures) {
      await db.storeClosure.upsert({
        where: { storeId_date: { storeId: row.id, date } },
        create: { storeId: row.id, date },
        update: {},
      });
    }
  }

  const categoryIds = new Map<string, string>();
  for (const c of plan.categories) {
    const row = await db.category.upsert({
      where: { slug: c.slug },
      create: { slug: c.slug, name: c.name, blurb: c.blurb, sortOrder: c.sortOrder },
      update: { name: c.name, blurb: c.blurb, sortOrder: c.sortOrder },
      select: { id: true },
    });
    categoryIds.set(c.slug, row.id);
  }

  const groupIds = new Map<string, string>();
  for (const g of plan.groups) {
    const fields = { name: g.name, selection: g.selection, minSelect: g.minSelect, maxSelect: g.maxSelect, sortOrder: g.sortOrder };
    const row = await db.modifierGroup.upsert({
      where: { slug: g.slug },
      create: { slug: g.slug, ...fields },
      update: fields,
      select: { id: true },
    });
    groupIds.set(g.slug, row.id);
    for (const o of g.options) {
      const optionFields = { name: o.name, isDefault: o.isDefault, sortOrder: o.sortOrder };
      await db.modifier.upsert({
        where: { groupId_slug: { groupId: row.id, slug: o.slug } },
        create: { groupId: row.id, slug: o.slug, ...optionFields, priceDeltaCents: o.priceDeltaCents },
        // Same rule as item prices: the seed never blanks a price someone confirmed.
        update: { ...optionFields, ...(o.priceDeltaCents !== null ? { priceDeltaCents: o.priceDeltaCents } : {}) },
      });
    }
  }

  for (const item of plan.items) {
    const categoryId = categoryIds.get(item.categorySlug);
    if (!categoryId) throw new Error(`Category "${item.categorySlug}" was not seeded.`);

    const existing = await db.menuItem.findUnique({ where: { slug: item.slug }, select: { dietaryVerifiedAt: true } });
    const base = {
      categoryId,
      name: item.name,
      description: item.description,
      isSpicy: item.isSpicy,
      sortOrder: item.sortOrder,
    };
    const created: Prisma.MenuItemUncheckedCreateInput = {
      slug: item.slug,
      ...base,
      priceCents: item.priceCents,
      priceSource: item.priceSource,
      dietaryTags: item.dietaryTags,
      dietaryVerifiedAt: item.dietaryVerified ? now : null,
    };
    const updated: Prisma.MenuItemUncheckedUpdateInput = {
      ...base,
      ...(item.priceCents !== null ? { priceCents: item.priceCents, priceSource: item.priceSource } : {}),
      ...(item.dietaryVerified
        ? { dietaryTags: item.dietaryTags, ...(existing?.dietaryVerifiedAt ? {} : { dietaryVerifiedAt: now }) }
        : {}),
    };
    const row = await db.menuItem.upsert({ where: { slug: item.slug }, create: created, update: updated, select: { id: true } });

    const wanted = item.groupSlugs.map((s) => {
      const id = groupIds.get(s);
      if (!id) throw new Error(`Modifier group "${s}" was not seeded.`);
      return id;
    });
    for (const groupId of wanted) {
      await db.menuItemModifierGroup.upsert({
        where: { menuItemId_groupId: { menuItemId: row.id, groupId } },
        create: { menuItemId: row.id, groupId },
        update: {},
      });
    }
    await db.menuItemModifierGroup.deleteMany({ where: { menuItemId: row.id, groupId: { notIn: wanted } } });

    // No StoreMenuItem row means "available everywhere", so only exceptions are written.
    for (const slug of item.unavailableAt) {
      const storeId = storeIds.get(slug);
      if (!storeId) throw new Error(`Store "${slug}" was not seeded.`);
      await db.storeMenuItem.upsert({
        where: { storeId_menuItemId: { storeId, menuItemId: row.id } },
        create: { storeId, menuItemId: row.id, isAvailable: false },
        update: { isAvailable: false },
      });
    }
  }
}

// ───────────────────────── CLI ─────────────────────────

export interface CliIo {
  log: (line: string) => void;
}

/** Returns a process exit code. Throws only for programmer errors. */
export async function runSeedCli(argv: string[], io: CliIo = console): Promise<number> {
  const dryRun = argv.includes("--dry-run");
  try {
    assertSeedAllowed(getEnv().DOUGHBOSS_DEMO_DATA);
    const plan = buildSeedPlan({ stores: STORES, categories: CATEGORIES, items: ITEMS });
    const counts = formatCounts(countPlan(plan));

    if (dryRun) {
      io.log(`Dry run (no database touched). Would seed:\n${counts}`);
      return 0;
    }
    if (!getEnv().DATABASE_URL) {
      io.log("DATABASE_URL is not set, so there is nothing to seed. Use --dry-run to preview.");
      return 1;
    }
    const prisma = getPrisma();
    try {
      await applySeedPlan(prisma, plan);
    } finally {
      await prisma.$disconnect();
    }
    io.log(`Seeded:\n${counts}`);
    return 0;
  } catch (err) {
    io.log(err instanceof Error ? err.message : String(err));
    return 1;
  }
}

// Run only when invoked as `tsx prisma/seed.ts`, never when imported by a test.
if (process.argv[1] && /[\\/]seed\.ts$/.test(process.argv[1])) {
  runSeedCli(process.argv.slice(2)).then((code) => {
    process.exitCode = code;
  });
}
