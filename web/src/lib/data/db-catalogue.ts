/**
 * Database rows → domain types.
 *
 * The mappers are pure (rows in, domain objects out) so they can be tested
 * without a database. The loaders at the bottom are thin Prisma calls.
 *
 * Silence rule: a row we cannot represent (an unknown store or category slug)
 * throws CatalogueUnavailableError. Dropping it quietly would make the menu
 * look complete while missing something, which is worse than saying "unavailable".
 */
import type { Prisma, PrismaClient } from "@prisma/client";
import type {
  Catalogue,
  Category,
  CategorySlug,
  MenuItem,
  ModifierGroup,
  Store,
  StoreHoursWindow,
  StoreSlug,
} from "@/types/menu";
import { CatalogueUnavailableError } from "../errors";
import { formatClock } from "../hours";

// ───────────────────────── Row shapes (what the loaders select) ─────────────────────────

export const storeInclude = { hours: true, closures: true } satisfies Prisma.StoreInclude;
export type StoreRow = Prisma.StoreGetPayload<{ include: typeof storeInclude }>;

export const categoryArgs = {} satisfies Prisma.CategoryFindManyArgs;
export type CategoryRow = Prisma.CategoryGetPayload<typeof categoryArgs>;

export const itemInclude = {
  category: true,
  modifierGroups: { include: { group: { include: { modifiers: true } } } },
  stores: { include: { store: true } },
} satisfies Prisma.MenuItemInclude;
export type ItemRow = Prisma.MenuItemGetPayload<{ include: typeof itemInclude }>;

// ───────────────────────── Slug guards ─────────────────────────

const STORE_SLUGS: readonly StoreSlug[] = ["revesby", "bankstown", "roselands"];
const CATEGORY_SLUGS: readonly CategorySlug[] = ["manoush", "pizza", "pies", "wraps", "catering", "drinks-sweets"];

function storeSlug(raw: string): StoreSlug {
  const found = STORE_SLUGS.find((s) => s === raw);
  if (!found) throw new CatalogueUnavailableError(`Unknown store slug in database: "${raw}".`);
  return found;
}

function categorySlug(raw: string): CategorySlug {
  const found = CATEGORY_SLUGS.find((s) => s === raw);
  if (!found) throw new CatalogueUnavailableError(`Unknown category slug in database: "${raw}".`);
  return found;
}

/** sortOrder first, slug second: deterministic even when two rows share a sortOrder. */
const bySortThenSlug = <T extends { sortOrder: number; slug: string }>(a: T, b: T): number =>
  a.sortOrder - b.sortOrder || a.slug.localeCompare(b.slug);

// ───────────────────────── Stores ─────────────────────────

/**
 * "+61297742286" → "(02) 9774 2286", "+61466353133" → "0466 353 133".
 * A pure re-formatting of the stored number. Anything unrecognised falls back
 * to the E.164 string rather than guessing a layout.
 */
export function formatPhoneDisplay(e164: string): string {
  const m = /^\+61(\d)(\d{8})$/.exec(e164);
  if (!m) return e164;
  const [, lead, rest] = m;
  if (lead === undefined || rest === undefined) return e164;
  if (lead === "4") return `04${rest.slice(0, 2)} ${rest.slice(2, 5)} ${rest.slice(5)}`;
  if ("2378".includes(lead)) return `(0${lead}) ${rest.slice(0, 4)} ${rest.slice(4)}`;
  return e164;
}

const DAY_NAMES = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] as const;
/** Monday-first display order (Australian convention). */
const DISPLAY_ORDER = [1, 2, 3, 4, 5, 6, 0] as const;

/**
 * Summary line derived from the hours windows themselves, so it can never
 * disagree with the windows the open/closed logic uses.
 */
export function summariseHours(windows: StoreHoursWindow[]): string {
  if (windows.length === 0) return "Opening hours to be confirmed";

  const timesByDay = new Map<number, string>();
  for (const day of DISPLAY_ORDER) {
    const ws = windows.filter((w) => w.dayOfWeek === day).sort((a, b) => a.opensMin - b.opensMin);
    if (ws.length > 0) {
      timesByDay.set(day, ws.map((w) => `${formatClock(w.opensMin)} – ${formatClock(w.closesMin)}`).join(", "));
    }
  }

  // Group days sharing identical times, then collapse consecutive days into ranges.
  const groups = new Map<string, number[]>();
  for (const day of DISPLAY_ORDER) {
    const times = timesByDay.get(day);
    if (times !== undefined) groups.set(times, [...(groups.get(times) ?? []), day]);
  }

  const dayLabel = (days: number[]): string => {
    if (days.length === 7) return "Daily";
    const positions = days.map((d) => DISPLAY_ORDER.indexOf(d as (typeof DISPLAY_ORDER)[number]));
    const consecutive = positions.every((p, i) => i === 0 || p === (positions[i - 1] ?? -2) + 1);
    const name = (d: number | undefined) => (d === undefined ? "" : DAY_NAMES[d]);
    if (consecutive && days.length > 2) return `${name(days[0])}–${name(days[days.length - 1])}`;
    return days.map(name).join(", ");
  };

  return [...groups.entries()].map(([times, days]) => `${dayLabel(days)}, ${times}`).join("; ");
}

const mapsSearch = (q: string) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;

export function mapStore(row: StoreRow): Store {
  const hours: StoreHoursWindow[] = row.hours
    .map((h) => ({ dayOfWeek: h.dayOfWeek, opensMin: h.opensMin, closesMin: h.closesMin }))
    .sort((a, b) => a.dayOfWeek - b.dayOfWeek || a.opensMin - b.opensMin);

  return {
    slug: storeSlug(row.slug),
    name: row.name,
    addressLine1: row.addressLine1,
    ...(row.addressLine2 ? { addressLine2: row.addressLine2 } : {}),
    suburb: row.suburb,
    state: row.state,
    postcode: row.postcode,
    phone: row.phone,
    phoneDisplay: formatPhoneDisplay(row.phone),
    timezone: row.timezone,
    // Prisma allows null; a link built from the store's own address is not an invented fact.
    mapsUrl:
      row.mapsUrl ?? mapsSearch([row.addressLine1, row.suburb, row.state, row.postcode].join(" ")),
    acceptsOnline: row.acceptsOnline,
    prepMinutes: row.prepMinutes,
    hours,
    closures: row.closures.map((c) => c.date).sort(),
    hoursSummary: summariseHours(hours),
    // `Store.note` has no database column, so it is intentionally absent here.
  };
}

export function mapStores(rows: StoreRow[]): Store[] {
  return [...rows].sort((a, b) => a.sortOrder - b.sortOrder || a.slug.localeCompare(b.slug)).map(mapStore);
}

// ───────────────────────── Catalogue ─────────────────────────

function mapCategory(row: CategoryRow): Category {
  return { slug: categorySlug(row.slug), name: row.name, blurb: row.blurb ?? "" };
}

type GroupRow = ItemRow["modifierGroups"][number]["group"];

function mapGroup(row: GroupRow): ModifierGroup {
  return {
    slug: row.slug,
    name: row.name,
    selection: row.selection,
    minSelect: row.minSelect,
    maxSelect: row.maxSelect,
    options: row.modifiers
      .filter((m) => m.isActive)
      .sort(bySortThenSlug)
      .map((m) => ({
        slug: m.slug,
        name: m.name,
        // null stays null: an unconfirmed option price must keep the option unorderable.
        priceDeltaCents: m.priceDeltaCents,
        isDefault: m.isDefault,
      })),
  };
}

export function mapItem(row: ItemRow): MenuItem {
  const unavailableAt = row.stores
    .filter((s) => !s.isAvailable)
    .map((s) => storeSlug(s.store.slug))
    .sort();

  return {
    id: row.id,
    slug: row.slug,
    categorySlug: categorySlug(row.category.slug),
    name: row.name,
    description: row.description,
    priceCents: row.priceCents,
    ...(row.priceSource ? { priceSource: row.priceSource } : {}),
    isSpicy: row.isSpicy,
    dietaryTags: [...row.dietaryTags],
    // Tags only count as claims once staff stamped a verification time.
    dietaryVerified: row.dietaryVerifiedAt !== null,
    modifierGroups: row.modifierGroups
      .map((j) => j.group)
      .sort(bySortThenSlug)
      .map(mapGroup),
    unavailableAt,
  };
}

export function mapCatalogue(categories: CategoryRow[], items: ItemRow[]): Catalogue {
  const activeItems = items.filter((i) => i.isActive).sort((a, b) => a.sortOrder - b.sortOrder || a.slug.localeCompare(b.slug));
  return {
    categories: [...categories].sort(bySortThenSlug).map(mapCategory),
    items: activeItems.map(mapItem),
    isDemo: false,
  };
}

// ───────────────────────── Loaders (thin Prisma wrappers) ─────────────────────────

type Db = Pick<PrismaClient, "store" | "category" | "menuItem">;

/** Any driver/connection/mapping failure is surfaced as "unavailable", with the cause attached for logs. */
function unavailable(what: string, cause: unknown): CatalogueUnavailableError {
  if (cause instanceof CatalogueUnavailableError) return cause;
  return new CatalogueUnavailableError(`${what} could not be read from the database.`, { cause });
}

export async function loadStores(db: Db): Promise<Store[]> {
  try {
    return mapStores(await db.store.findMany({ include: storeInclude }));
  } catch (cause) {
    throw unavailable("Stores", cause);
  }
}

export async function loadCatalogue(db: Db): Promise<Catalogue> {
  try {
    const [categories, items] = await Promise.all([
      db.category.findMany(categoryArgs),
      db.menuItem.findMany({ where: { isActive: true }, include: itemInclude }),
    ]);
    return mapCatalogue(categories, items);
  } catch (cause) {
    throw unavailable("The menu", cause);
  }
}
