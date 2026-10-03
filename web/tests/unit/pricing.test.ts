import { describe, expect, it } from "vitest";
import { DEMO_CATALOGUE } from "@/lib/data/demo-catalogue";
import { PRODUCTION_CATALOGUE } from "@/lib/data/catalogue";
import { MAX_CART_LINES, MAX_LINE_QUANTITY, isItemOrderable, priceCart, priceLine } from "@/lib/pricing";
import type { CartLine, Catalogue, MenuItem, PricedLine } from "@/types/menu";

const line = (itemSlug: string, quantity = 1, selections: Record<string, string[]> = {}, lineId = `l-${itemSlug}`): CartLine => ({
  lineId,
  itemSlug,
  quantity,
  selections,
});

const VALID_MANOUSH = { "base-style": ["folded"], extras: ["extra-cheese"], bake: ["crispy-crust"] };

/** Narrow a PricedLine to its failure reason ("OK" when it priced), keeping assertions terse. */
const outcome = (p: PricedLine) => (p.ok ? "OK" : p.reason);

function demoItem(slug: string): MenuItem {
  const found = DEMO_CATALOGUE.items.find((i) => i.slug === slug);
  if (!found) throw new Error(`fixture item missing: ${slug}`);
  return found;
}

describe("priceLine: happy path", () => {
  it("prices a customised manoush: unit = base + extras, line = unit x quantity", () => {
    const priced = priceLine(DEMO_CATALOGUE, "revesby", line("zaatar-manoush", 2, VALID_MANOUSH));
    expect(priced.ok).toBe(true);
    if (!priced.ok) return;
    expect(priced.unitCents).toBe(700); // 500 + 0 (folded) + 200 (extra cheese) + 0 (crispy)
    expect(priced.lineCents).toBe(1400);
    expect(priced.quantity).toBe(2);
    expect(priced.modifiers).toEqual([
      { groupSlug: "base-style", slug: "folded", name: "Folded", priceDeltaCents: 0 },
      { groupSlug: "extras", slug: "extra-cheese", name: "Extra cheese", priceDeltaCents: 200 },
      { groupSlug: "bake", slug: "crispy-crust", name: "Crispy crust", priceDeltaCents: 0 },
    ]);
  });

  it("accepts an optional group left out entirely", () => {
    const priced = priceLine(DEMO_CATALOGUE, "revesby", line("zaatar-manoush", 1, { "base-style": ["flat"] }));
    expect(priced.ok && priced.unitCents).toBe(500);
  });

  it("sums several MULTIPLE options", () => {
    const priced = priceLine(
      DEMO_CATALOGUE,
      "revesby",
      line("cheese-manoush", 3, { "base-style": ["flat"], extras: ["extra-cheese", "add-veggies"] }),
    );
    expect(priced.ok && priced.unitCents).toBe(650 + 200 + 150);
    expect(priced.ok && priced.lineCents).toBe((650 + 200 + 150) * 3);
  });

  it("prices an item with no modifier groups from its base price", () => {
    const priced = priceLine(DEMO_CATALOGUE, "bankstown", line("demo-ayran", 4));
    expect(priced.ok && priced.lineCents).toBe(1800);
  });

  it("accepts the boundary quantities 1 and MAX_LINE_QUANTITY", () => {
    expect(outcome(priceLine(DEMO_CATALOGUE, "revesby", line("demo-ayran", 1)))).toBe("OK");
    expect(outcome(priceLine(DEMO_CATALOGUE, "revesby", line("demo-ayran", MAX_LINE_QUANTITY)))).toBe("OK");
  });

  it("carries the lineId through", () => {
    expect(priceLine(DEMO_CATALOGUE, "revesby", line("demo-ayran", 1, {}, "abc")).lineId).toBe("abc");
  });
});

describe("priceLine: invalid selections", () => {
  const price = (selections: Record<string, string[]>) =>
    priceLine(DEMO_CATALOGUE, "revesby", line("zaatar-manoush", 1, selections));

  it("rejects a missing required group", () => {
    expect(outcome(price({ extras: ["extra-cheese"] }))).toBe("INVALID_SELECTION");
  });

  it("rejects a required group given an empty array", () => {
    expect(outcome(price({ "base-style": [] }))).toBe("INVALID_SELECTION");
  });

  it("rejects two options in a SINGLE group", () => {
    expect(outcome(price({ "base-style": ["folded", "flat"] }))).toBe("INVALID_SELECTION");
  });

  it("rejects an unknown group key (never silently ignored)", () => {
    expect(outcome(price({ "base-style": ["folded"], sauces: ["garlic"] }))).toBe("INVALID_SELECTION");
  });

  it("rejects an unknown option slug", () => {
    expect(outcome(price({ "base-style": ["folded"], extras: ["truffle"] }))).toBe("INVALID_SELECTION");
  });

  it("rejects a duplicate option", () => {
    expect(outcome(price({ "base-style": ["folded"], extras: ["extra-cheese", "extra-cheese"] }))).toBe("INVALID_SELECTION");
  });

  it("rejects more options than maxSelect", () => {
    // Bake allows one option; the second slug is only reachable by exceeding the cap.
    const catalogue = withGroupOptions("zaatar-manoush", "bake", [
      { slug: "crispy-crust", name: "Crispy crust", priceDeltaCents: 0, isDefault: false },
      { slug: "well-done", name: "Well done", priceDeltaCents: 0, isDefault: false },
    ]);
    const priced = priceLine(catalogue, "revesby", line("zaatar-manoush", 1, { "base-style": ["folded"], bake: ["crispy-crust", "well-done"] }));
    expect(outcome(priced)).toBe("INVALID_SELECTION");
  });

  it("rejects a selection key that is an Object.prototype property name", () => {
    expect(outcome(price({ "base-style": ["folded"], constructor: ["x"] }))).toBe("INVALID_SELECTION");
  });
});

describe("priceLine: item-level failures", () => {
  it("rejects an unpriced demo item", () => {
    expect(outcome(priceLine(DEMO_CATALOGUE, "revesby", line("demo-unpriced-wrap")))).toBe("ITEM_UNPRICED");
  });

  it("rejects an unknown slug", () => {
    expect(outcome(priceLine(DEMO_CATALOGUE, "revesby", line("no-such-item")))).toBe("ITEM_NOT_FOUND");
  });

  it("rejects an item that is unavailable at the chosen store, but prices it at another", () => {
    const manoush = { "base-style": ["folded"] };
    expect(outcome(priceLine(DEMO_CATALOGUE, "roselands", line("lahm-bi-ajin", 1, manoush)))).toBe("ITEM_UNAVAILABLE_AT_STORE");
    expect(outcome(priceLine(DEMO_CATALOGUE, "revesby", line("lahm-bi-ajin", 1, manoush)))).toBe("OK");
  });

  it.each([0, 21, 1.5, NaN, -1, Infinity])("rejects quantity %s", (quantity) => {
    expect(outcome(priceLine(DEMO_CATALOGUE, "revesby", line("demo-ayran", quantity)))).toBe("INVALID_QUANTITY");
  });

  it("checks quantity before price so a bad quantity on an unpriced item reports the quantity", () => {
    expect(outcome(priceLine(DEMO_CATALOGUE, "revesby", line("demo-unpriced-wrap", 0)))).toBe("INVALID_QUANTITY");
  });
});

describe("priceLine: unconfirmed modifier prices", () => {
  /** Clone with every extra's `extra-cheese` option left unpriced. */
  const unpricedExtra: Catalogue = withGroupOptions("zaatar-manoush", "extras", [
    { slug: "extra-cheese", name: "Extra cheese", priceDeltaCents: null, isDefault: false },
    { slug: "add-veggies", name: "Add veggies", priceDeltaCents: 150, isDefault: false },
  ]);

  it("returns MODIFIER_UNPRICED when the unpriced option is chosen", () => {
    const priced = priceLine(unpricedExtra, "revesby", line("zaatar-manoush", 1, { "base-style": ["folded"], extras: ["extra-cheese"] }));
    expect(outcome(priced)).toBe("MODIFIER_UNPRICED");
  });

  it("prices fine when the unpriced option is not chosen", () => {
    const priced = priceLine(unpricedExtra, "revesby", line("zaatar-manoush", 1, { "base-style": ["folded"], extras: ["add-veggies"] }));
    expect(priced.ok && priced.unitCents).toBe(650);
    expect(outcome(priceLine(unpricedExtra, "revesby", line("zaatar-manoush", 1, { "base-style": ["folded"] })))).toBe("OK");
  });

  it("does not mutate the shared demo catalogue when cloning", () => {
    expect(demoItem("zaatar-manoush").modifierGroups.find((g) => g.slug === "extras")?.options[0]?.priceDeltaCents).toBe(200);
  });

  it("refuses a negative line total", () => {
    const discounted = withGroupOptions("zaatar-manoush", "extras", [
      { slug: "extra-cheese", name: "Extra cheese", priceDeltaCents: -9999, isDefault: false },
    ]);
    const priced = priceLine(discounted, "revesby", line("zaatar-manoush", 1, { "base-style": ["folded"], extras: ["extra-cheese"] }));
    expect(outcome(priced)).toBe("INVALID_SELECTION");
  });
});

describe("priceLine: production catalogue (nothing is priced yet)", () => {
  const unpriced = PRODUCTION_CATALOGUE.items.filter((i) => i.priceCents === null);

  it("has unpriced items to test", () => {
    expect(unpriced.length).toBeGreaterThan(0);
  });

  it("returns ITEM_UNPRICED for every item with a null price, at every store", () => {
    for (const item of unpriced) {
      for (const store of ["revesby", "bankstown", "roselands"] as const) {
        expect(outcome(priceLine(PRODUCTION_CATALOGUE, store, line(item.slug)))).toBe("ITEM_UNPRICED");
      }
    }
  });

  it("therefore cannot sell anything until prices are confirmed", () => {
    const cart = priceCart(
      PRODUCTION_CATALOGUE,
      "revesby",
      PRODUCTION_CATALOGUE.items.map((i) => line(i.slug)),
    );
    expect(cart.orderable).toBe(false);
    expect(cart.subtotalCents).toBe(0);
    expect(cart.lines.every((l) => !l.ok)).toBe(true);
  });

  it("reports an unpriced item as unpriced rather than unavailable (lahm-bi-ajin at roselands)", () => {
    // Price is checked first, so the honest answer for production data is still "not priced".
    expect(outcome(priceLine(PRODUCTION_CATALOGUE, "roselands", line("lahm-bi-ajin")))).toBe("ITEM_UNPRICED");
  });
});

describe("priceCart", () => {
  const good = line("demo-ayran", 2, {}, "a"); // 900
  const good2 = line("demo-falafel-wrap", 1, {}, "b"); // 1100

  it("sums every priced line", () => {
    const cart = priceCart(DEMO_CATALOGUE, "revesby", [good, good2]);
    expect(cart.subtotalCents).toBe(2000);
    expect(cart.orderable).toBe(true);
    expect(cart.lines).toHaveLength(2);
  });

  it("sums only ok lines and is not orderable when any line fails", () => {
    const bad = line("demo-unpriced-wrap", 1, {}, "c");
    const cart = priceCart(DEMO_CATALOGUE, "revesby", [good, bad, good2]);
    expect(cart.subtotalCents).toBe(2000);
    expect(cart.orderable).toBe(false);
    expect(cart.lines.map(outcome)).toEqual(["OK", "ITEM_UNPRICED", "OK"]);
  });

  it("is not orderable for an empty cart", () => {
    expect(priceCart(DEMO_CATALOGUE, "revesby", [])).toEqual({ lines: [], subtotalCents: 0, orderable: false });
  });

  it("allows exactly MAX_CART_LINES lines but not one more", () => {
    const many = (n: number) => Array.from({ length: n }, (_, i) => line("demo-ayran", 1, {}, `l${i}`));
    expect(priceCart(DEMO_CATALOGUE, "revesby", many(MAX_CART_LINES)).orderable).toBe(true);
    const over = priceCart(DEMO_CATALOGUE, "revesby", many(MAX_CART_LINES + 1));
    expect(over.orderable).toBe(false);
    // Lines still price individually; only the cart as a whole is refused.
    expect(over.subtotalCents).toBe(450 * (MAX_CART_LINES + 1));
  });
});

describe("isItemOrderable", () => {
  it("is false for an unpriced item", () => {
    expect(isItemOrderable(demoItem("demo-unpriced-wrap"), "revesby")).toBe(false);
    expect(isItemOrderable(demoItem("demo-unpriced-wrap"), null)).toBe(false);
  });

  it("is false when unavailable at the store, true elsewhere and when no store is chosen yet", () => {
    const lahm = demoItem("lahm-bi-ajin");
    expect(isItemOrderable(lahm, "roselands")).toBe(false);
    expect(isItemOrderable(lahm, "revesby")).toBe(true);
    expect(isItemOrderable(lahm, null)).toBe(true);
  });

  it("is false when a required group has every option unpriced", () => {
    const base = demoItem("zaatar-manoush");
    const stuck: MenuItem = {
      ...base,
      modifierGroups: base.modifierGroups.map((g) =>
        g.slug === "base-style" ? { ...g, options: g.options.map((o) => ({ ...o, priceDeltaCents: null })) } : g,
      ),
    };
    expect(isItemOrderable(stuck, "revesby")).toBe(false);
  });

  it("stays true when only an optional group is entirely unpriced", () => {
    const base = demoItem("zaatar-manoush");
    const fine: MenuItem = {
      ...base,
      modifierGroups: base.modifierGroups.map((g) =>
        g.slug === "extras" ? { ...g, options: g.options.map((o) => ({ ...o, priceDeltaCents: null })) } : g,
      ),
    };
    expect(isItemOrderable(fine, "revesby")).toBe(true);
  });

  it("is true for a fully priced demo item", () => {
    expect(isItemOrderable(demoItem("zaatar-manoush"), "revesby")).toBe(true);
  });

  it("is false for every production item today", () => {
    expect(PRODUCTION_CATALOGUE.items.some((i) => isItemOrderable(i, null))).toBe(false);
  });
});

/** Deep-enough clone of the demo catalogue with one modifier group's options replaced on one item. */
function withGroupOptions(itemSlug: string, groupSlug: string, options: MenuItem["modifierGroups"][number]["options"]): Catalogue {
  return {
    ...DEMO_CATALOGUE,
    items: DEMO_CATALOGUE.items.map((i) =>
      i.slug === itemSlug
        ? { ...i, modifierGroups: i.modifierGroups.map((g) => (g.slug === groupSlug ? { ...g, options } : g)) }
        : i,
    ),
  };
}
