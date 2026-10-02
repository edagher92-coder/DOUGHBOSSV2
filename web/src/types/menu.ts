/**
 * Shared domain types for the Dough Boss storefront.
 *
 * Two rules are encoded in the types themselves so they can't be forgotten:
 *  1. `priceCents: number | null` — null means "not confirmed". Never coerce to 0.
 *  2. Dietary claims carry a `dietaryVerified` flag; an unverified claim is
 *     treated as "unknown" everywhere (UI badges, filters, checkout).
 */

export type DietaryTag = "HALAL" | "VEGETARIAN" | "VEGAN" | "NUT_FREE" | "GLUTEN_FREE";

/** Filters offered in the menu UI. SPICY is a heat attribute, not a dietary claim. */
export type DietFilter = "VEGETARIAN" | "VEGAN" | "NUT_FREE" | "SPICY";

export type CategorySlug = "manoush" | "pizza" | "pies" | "wraps" | "catering" | "drinks-sweets";

export type StoreSlug = "revesby" | "bankstown" | "roselands";

export type ModifierSelectionMode = "SINGLE" | "MULTIPLE";

export interface ModifierOption {
  slug: string;
  name: string;
  /** Added to the item price. null = price not confirmed → option can't be ordered. */
  priceDeltaCents: number | null;
  isDefault: boolean;
}

export interface ModifierGroup {
  slug: string;
  name: string;
  selection: ModifierSelectionMode;
  minSelect: number;
  maxSelect: number;
  options: ModifierOption[];
}

export interface MenuItem {
  id: string;
  slug: string;
  categorySlug: CategorySlug;
  name: string;
  description: string;
  /** null = price not confirmed → shown as "Price to be confirmed" and not orderable. */
  priceCents: number | null;
  /**
   * Provenance for a non-null price: where it was confirmed and when
   * (e.g. "doughboss.com.au/menu, retrieved 2026-10-02"). A price without a
   * source must never reach production data — enforced by a unit test.
   */
  priceSource?: string;
  isSpicy: boolean;
  dietaryTags: DietaryTag[];
  /** false → dietaryTags are a draft; the UI says "check with the store". */
  dietaryVerified: boolean;
  modifierGroups: ModifierGroup[];
  /** Stores where the item is currently unavailable (e.g. sold out / not stocked). */
  unavailableAt: StoreSlug[];
}

export interface Category {
  slug: CategorySlug;
  name: string;
  blurb: string;
}

export interface Catalogue {
  categories: Category[];
  items: MenuItem[];
  /** True when the catalogue is the labelled-fake demo set. Never true in production. */
  isDemo: boolean;
}

/** One continuous opening window on one weekday. Minutes are from local midnight. */
export interface StoreHoursWindow {
  /** 0 = Sunday … 6 = Saturday (JS Date#getDay). */
  dayOfWeek: number;
  opensMin: number;
  /** Exclusive. */
  closesMin: number;
}

export interface Store {
  slug: StoreSlug;
  name: string;
  addressLine1: string;
  addressLine2?: string;
  suburb: string;
  state: string;
  postcode: string;
  /** E.164, for tel: links. */
  phone: string;
  phoneDisplay: string;
  timezone: string;
  mapsUrl: string;
  acceptsOnline: boolean;
  /** Minutes from order to earliest pickup. */
  prepMinutes: number;
  hours: StoreHoursWindow[];
  /** Local YYYY-MM-DD dates the store is closed (public holidays etc). */
  closures: string[];
  /** Short human summary of trading hours, taken from the source page. */
  hoursSummary: string;
  /** Extra line for the picker, e.g. a co-located brand. */
  note?: string;
}

/** What a cart line stores: which item, how many, and the *choices* — never prices. */
export interface CartLine {
  lineId: string;
  itemSlug: string;
  quantity: number;
  /** groupSlug → chosen option slugs. */
  selections: Record<string, string[]>;
  note?: string;
}

export interface PricedModifier {
  groupSlug: string;
  slug: string;
  name: string;
  priceDeltaCents: number;
}

export type PriceFailure =
  | "ITEM_NOT_FOUND"
  | "ITEM_UNPRICED"
  | "ITEM_UNAVAILABLE_AT_STORE"
  | "MODIFIER_UNPRICED"
  | "INVALID_SELECTION"
  | "INVALID_QUANTITY";

export type PricedLine =
  | {
      ok: true;
      lineId: string;
      item: MenuItem;
      quantity: number;
      unitCents: number;
      lineCents: number;
      modifiers: PricedModifier[];
    }
  | { ok: false; lineId: string; reason: PriceFailure; detail: string };

export interface PricedCart {
  lines: PricedLine[];
  /** Sum of all priced lines. */
  subtotalCents: number;
  /** True only if every line priced successfully and there is at least one line. */
  orderable: boolean;
}

export interface PickupSlot {
  /** Absolute instant, ISO-8601 UTC. */
  iso: string;
  /** e.g. "7:15am" in the store's timezone. */
  label: string;
  /** e.g. "Today", "Tomorrow", "Sat 4 Oct". */
  dayLabel: string;
}

export type StoreStatus =
  | { state: "open"; closesAt: Date; label: string }
  | { state: "closed"; opensAt: Date | null; label: string };

export type PackAppetite = "light" | "standard" | "hearty";

export type MinisKind = "MINI_ZAATAR" | "MINI_CHEESE" | "MINI_MEAT" | "MINI_PIES";
