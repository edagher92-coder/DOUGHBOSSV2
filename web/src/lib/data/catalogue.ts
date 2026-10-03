/**
 * Production seed data — the single source for `prisma/seed.ts` and the
 * no-database fallback.
 *
 * PROVENANCE (so nobody has to wonder where a number came from):
 *  - Stores: address, trading hours and phone numbers are copied from the live
 *    Dough Boss locations page (edagher92-coder/doughxsnow → web/locations/index.html).
 *    Re-confirm before launch; the source does not list public-holiday trading.
 *  - Items: only dishes named in the project brief are listed. Pizza, wrap,
 *    catering-box and drinks/sweets items are NOT invented here — add them via
 *    the database or this file once the real range is supplied.
 *
 * [CONFIRM] markers below are things only the Dough Boss team can supply. Until
 * they are filled in the site stays honest: items show "Price to be confirmed"
 * and cannot be added to a cart; dietary info reads "check with the store".
 */
import type { Catalogue, Category, MenuItem, ModifierGroup, Store, StoreHoursWindow } from "@/types/menu";

const window = (days: number[], opensMin: number, closesMin: number): StoreHoursWindow[] =>
  days.map((dayOfWeek) => ({ dayOfWeek, opensMin, closesMin }));

const hm = (h: number, m = 0) => h * 60 + m;
const EVERY_DAY = [0, 1, 2, 3, 4, 5, 6];
const WEEKDAYS = [1, 2, 3, 4, 5];

const mapsSearch = (q: string) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;

/** [CONFIRM] prepMinutes with each kitchen — 20 is a conservative placeholder, not a promise. */
const PREP_MINUTES = 20;

export const STORES: Store[] = [
  {
    slug: "revesby",
    name: "Revesby",
    addressLine1: "12/25 Selems Parade",
    suburb: "Revesby",
    state: "NSW",
    postcode: "2212",
    phone: "+61297742286",
    phoneDisplay: "(02) 9774 2286",
    timezone: "Australia/Sydney",
    mapsUrl: mapsSearch("12/25 Selems Parade Revesby NSW 2212"),
    acceptsOnline: true,
    prepMinutes: PREP_MINUTES,
    hours: window(EVERY_DAY, hm(6, 30), hm(14, 30)),
    closures: [],
    hoursSummary: "Open 7 days, 6:30am – 2:30pm",
    note: "Flagship store and online pickup hub.",
  },
  {
    slug: "bankstown",
    name: "Bankstown",
    addressLine1: "462 Chapel Rd",
    addressLine2: "Little Saigon Plaza, cnr Kitchener Pde & French Ave",
    suburb: "Bankstown",
    state: "NSW",
    postcode: "2200",
    phone: "+61287646783",
    phoneDisplay: "(02) 8764 6783",
    timezone: "Australia/Sydney",
    mapsUrl: mapsSearch("462 Chapel Rd Bankstown NSW 2200"),
    acceptsOnline: true,
    prepMinutes: PREP_MINUTES,
    hours: window(WEEKDAYS, hm(7), hm(14)),
    closures: [],
    hoursSummary: "Mon–Fri, 7:00am – 2:00pm",
    note: "Home of Dough Boss Bankstown — and soon Snow Boss, in the same store.",
  },
  {
    slug: "roselands",
    name: "Roselands Centro",
    addressLine1: "Shop MM03, Roselands Dr",
    suburb: "Roselands",
    state: "NSW",
    postcode: "2196",
    phone: "+61466353133",
    phoneDisplay: "0466 353 133",
    timezone: "Australia/Sydney",
    mapsUrl: mapsSearch("Roselands Centro Roselands Dr Roselands NSW 2196"),
    acceptsOnline: true,
    prepMinutes: PREP_MINUTES,
    hours: window(EVERY_DAY, hm(8), hm(15)),
    closures: [],
    hoursSummary: "Daily, 8:00am – 3:00pm",
  },
];

export const CATEGORIES: Category[] = [
  { slug: "manoush", name: "Manoush", blurb: "Stone-baked Lebanese flatbread — folded or flat, oven-hot." },
  { slug: "pizza", name: "Pizza", blurb: "Artisan stone-baked pizzas. Crisp base, pillowy crust." },
  { slug: "pies", name: "Artisan Pies", blurb: "Savoury baked pies, fresh from the oven." },
  { slug: "wraps", name: "Wraps", blurb: "Rolled to go." },
  { slug: "catering", name: "Catering Boxes", blurb: "Spreads for the office, the party, the whole family." },
  { slug: "drinks-sweets", name: "Drinks & Sweets", blurb: "Something to wash it down — and something sweet." },
];

/** [CONFIRM] every priceDeltaCents below. null ⇒ the option shows "price TBC" and can't be ordered. */
export const BASE_STYLE: ModifierGroup = {
  slug: "base-style",
  name: "Folded or flat",
  selection: "SINGLE",
  minSelect: 1,
  maxSelect: 1,
  options: [
    { slug: "folded", name: "Folded", priceDeltaCents: null, isDefault: false },
    { slug: "flat", name: "Flat", priceDeltaCents: null, isDefault: false },
  ],
};

export const EXTRAS: ModifierGroup = {
  slug: "extras",
  name: "Extras",
  selection: "MULTIPLE",
  minSelect: 0,
  maxSelect: 4,
  options: [
    { slug: "extra-cheese", name: "Extra cheese", priceDeltaCents: null, isDefault: false },
    { slug: "add-veggies", name: "Add veggies", priceDeltaCents: null, isDefault: false },
  ],
};

export const BAKE: ModifierGroup = {
  slug: "bake",
  name: "Bake",
  selection: "MULTIPLE",
  minSelect: 0,
  maxSelect: 1,
  options: [{ slug: "crispy-crust", name: "Crispy crust", priceDeltaCents: null, isDefault: false }],
};

/** [CONFIRM] priceCents, dietaryTags and dietaryVerified for every item. */
const item = (
  slug: string,
  categorySlug: MenuItem["categorySlug"],
  name: string,
  description: string,
  modifierGroups: ModifierGroup[],
): MenuItem => ({
  id: `seed_${slug}`,
  slug,
  categorySlug,
  name,
  description,
  priceCents: null,
  isSpicy: false,
  dietaryTags: [],
  dietaryVerified: false,
  modifierGroups,
  unavailableAt: [],
});

export const ITEMS: MenuItem[] = [
  item("zaatar-manoush", "manoush", "Za’atar Manoush", "The classic. Za’atar and olive oil on fresh-baked dough.", [BASE_STYLE, EXTRAS, BAKE]),
  item("cheese-manoush", "manoush", "Cheese Manoush", "Melted cheese on fresh-baked dough, straight from the oven.", [BASE_STYLE, EXTRAS, BAKE]),
  item("lahm-bi-ajin", "manoush", "Lahm Bi Ajin", "Spiced minced-meat topping baked onto our dough.", [BASE_STYLE, EXTRAS, BAKE]),
  item("shanklish-manoush", "manoush", "Shanklish Manoush", "Shanklish cheese, baked on fresh dough.", [BASE_STYLE, EXTRAS, BAKE]),
  item("halloumi-pie", "pies", "Halloumi Pie", "Halloumi in a savoury baked pie.", []),
  item("spinach-pie", "pies", "Spinach Pie", "Spinach in a savoury baked pie.", []),
];

export const PRODUCTION_CATALOGUE: Catalogue = {
  categories: CATEGORIES,
  items: ITEMS,
  isDemo: false,
};
