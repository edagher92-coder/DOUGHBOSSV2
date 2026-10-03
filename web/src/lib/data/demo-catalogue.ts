/**
 * DEMO DATA — NOT REAL PRICES, NOT A REAL MENU.
 *
 * Exists so the ordering flow (filters, customiser, cart, checkout validation)
 * can be exercised end to end before the real prices are confirmed. Every
 * price and dietary flag below is invented for that purpose.
 *
 * Safety rails:
 *  - Only loaded when DOUGHBOSS_DEMO_DATA=1 (see ./index.ts).
 *  - `assertDemoAllowed()` throws when NODE_ENV=production, so a stray env var
 *    can never put fake prices in front of customers.
 *  - `isDemo: true` makes the UI render a permanent "DEMO DATA" banner.
 */
import type { Catalogue, MenuItem, ModifierGroup } from "@/types/menu";
import { BAKE, BASE_STYLE, CATEGORIES } from "./catalogue";

export function assertDemoAllowed(env: { NODE_ENV?: string } = process.env): void {
  if (env.NODE_ENV === "production") {
    throw new Error("DOUGHBOSS_DEMO_DATA must never be enabled in production: demo prices are fake.");
  }
}

const priced = (g: ModifierGroup, deltas: Record<string, number>): ModifierGroup => ({
  ...g,
  options: g.options.map((o) => ({ ...o, priceDeltaCents: deltas[o.slug] ?? 0 })),
});

const DEMO_BASE = priced(BASE_STYLE, { folded: 0, flat: 0 });
const DEMO_BAKE = priced(BAKE, { "crispy-crust": 0 });
const DEMO_EXTRAS: ModifierGroup = {
  slug: "extras",
  name: "Extras",
  selection: "MULTIPLE",
  minSelect: 0,
  maxSelect: 4,
  options: [
    { slug: "extra-cheese", name: "Extra cheese", priceDeltaCents: 200, isDefault: false },
    { slug: "add-veggies", name: "Add veggies", priceDeltaCents: 150, isDefault: false },
  ],
};

const demo = (
  slug: string,
  categorySlug: MenuItem["categorySlug"],
  name: string,
  description: string,
  priceCents: number,
  extra: Partial<MenuItem> = {},
): MenuItem => ({
  id: `demo_${slug}`,
  slug,
  categorySlug,
  name,
  description,
  priceCents,
  isSpicy: false,
  dietaryTags: [],
  dietaryVerified: true,
  modifierGroups: [],
  unavailableAt: [],
  ...extra,
});

const MANOUSH_MODS = [DEMO_BASE, DEMO_EXTRAS, DEMO_BAKE];

export const DEMO_CATALOGUE: Catalogue = {
  isDemo: true,
  categories: CATEGORIES,
  items: [
    demo("zaatar-manoush", "manoush", "Za’atar Manoush", "Demo item. Za’atar and olive oil on fresh-baked dough.", 500, {
      dietaryTags: ["VEGETARIAN", "VEGAN", "HALAL"],
      modifierGroups: MANOUSH_MODS,
    }),
    demo("cheese-manoush", "manoush", "Cheese Manoush", "Demo item. Melted cheese on fresh-baked dough.", 650, {
      dietaryTags: ["VEGETARIAN", "HALAL"],
      modifierGroups: MANOUSH_MODS,
    }),
    demo("lahm-bi-ajin", "manoush", "Lahm Bi Ajin", "Demo item. Spiced minced-meat topping.", 850, {
      isSpicy: true,
      dietaryTags: ["HALAL"],
      modifierGroups: MANOUSH_MODS,
      unavailableAt: ["roselands"],
    }),
    demo("shanklish-manoush", "manoush", "Shanklish Manoush", "Demo item. Shanklish cheese on fresh dough.", 750, {
      isSpicy: true,
      dietaryTags: ["VEGETARIAN", "HALAL"],
      modifierGroups: MANOUSH_MODS,
    }),
    demo("demo-margherita", "pizza", "Margherita (demo)", "Demo item. Tomato, mozzarella, basil.", 1600, {
      dietaryTags: ["VEGETARIAN", "NUT_FREE"],
      modifierGroups: [DEMO_EXTRAS, DEMO_BAKE],
    }),
    demo("demo-soujouk-pizza", "pizza", "Soujouk Pizza (demo)", "Demo item. Spiced sausage and cheese.", 1900, {
      isSpicy: true,
      dietaryTags: ["HALAL"],
      modifierGroups: [DEMO_EXTRAS, DEMO_BAKE],
    }),
    // Unverified dietary info on purpose: exercises the "unknown" filter path.
    demo("halloumi-pie", "pies", "Halloumi Pie", "Demo item. Halloumi in a baked pie.", 550, { dietaryVerified: false }),
    demo("spinach-pie", "pies", "Spinach Pie", "Demo item. Spinach in a baked pie.", 500, {
      dietaryTags: ["VEGETARIAN", "VEGAN", "NUT_FREE"],
    }),
    // Deliberately unpriced: must render "Price to be confirmed" and refuse to add.
    demo("demo-unpriced-wrap", "wraps", "Unpriced Wrap (demo)", "Demo item with no confirmed price.", 0, {
      priceCents: null,
      dietaryVerified: false,
    }),
    demo("demo-falafel-wrap", "wraps", "Falafel Wrap (demo)", "Demo item. Falafel, salad, tahini.", 1100, {
      dietaryTags: ["VEGETARIAN", "VEGAN"],
    }),
    demo("demo-manoush-box", "catering", "Manoush Box × 12 (demo)", "Demo item. A dozen assorted manoush.", 6800, {
      dietaryTags: ["HALAL"],
    }),
    demo("demo-ayran", "drinks-sweets", "Ayran (demo)", "Demo item. Salted yoghurt drink.", 450, {
      dietaryTags: ["VEGETARIAN", "NUT_FREE"],
    }),
    // Contains nuts → must never appear under a Nut-Free filter.
    demo("demo-baklava", "drinks-sweets", "Baklava Box (demo)", "Demo item. Pistachio baklava.", 1400, {
      dietaryTags: ["VEGETARIAN"],
    }),
  ],
};
