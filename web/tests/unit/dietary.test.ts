import { describe, expect, it } from "vitest";
import { DEMO_CATALOGUE } from "@/lib/data/demo-catalogue";
import { matchDiet, partitionByDiet, visibleDietaryTags } from "@/lib/dietary";
import type { DietFilter, MenuItem } from "@/types/menu";

const base: MenuItem = {
  id: "t",
  slug: "t",
  categorySlug: "manoush",
  name: "Test",
  description: "",
  priceCents: 100,
  isSpicy: false,
  dietaryTags: [],
  dietaryVerified: true,
  modifierGroups: [],
  unavailableAt: [],
};
const make = (over: Partial<MenuItem>): MenuItem => ({ ...base, ...over });

const demo = (slug: string): MenuItem => {
  const found = DEMO_CATALOGUE.items.find((i) => i.slug === slug);
  if (!found) throw new Error(`fixture item missing: ${slug}`);
  return found;
};
const slugs = (items: MenuItem[]) => items.map((i) => i.slug);

describe("matchDiet: unverified items", () => {
  it.each<DietFilter>(["VEGAN", "VEGETARIAN", "NUT_FREE"])("%s is unknown even when the tags say yes", (filter) => {
    const item = make({ dietaryVerified: false, dietaryTags: ["VEGAN", "VEGETARIAN", "NUT_FREE"] });
    expect(matchDiet(item, filter)).toBe("unknown");
  });

  it("is unknown with no tags at all", () => {
    expect(matchDiet(make({ dietaryVerified: false }), "NUT_FREE")).toBe("unknown");
  });
});

describe("matchDiet: SPICY", () => {
  it("is yes when flagged spicy, even if dietary info is unverified", () => {
    expect(matchDiet(make({ isSpicy: true, dietaryVerified: false }), "SPICY")).toBe("yes");
  });

  it("is yes when flagged spicy and verified", () => {
    expect(matchDiet(make({ isSpicy: true }), "SPICY")).toBe("yes");
  });

  it("is unknown when unverified and not flagged spicy", () => {
    expect(matchDiet(make({ isSpicy: false, dietaryVerified: false }), "SPICY")).toBe("unknown");
  });

  it("is no when verified and not spicy", () => {
    expect(matchDiet(make({ isSpicy: false, dietaryVerified: true }), "SPICY")).toBe("no");
  });
});

describe("matchDiet: verified items", () => {
  it("VEGAN needs the VEGAN tag", () => {
    expect(matchDiet(make({ dietaryTags: ["VEGAN"] }), "VEGAN")).toBe("yes");
    expect(matchDiet(make({ dietaryTags: ["VEGETARIAN"] }), "VEGAN")).toBe("no");
    expect(matchDiet(make({ dietaryTags: [] }), "VEGAN")).toBe("no");
  });

  it("vegan satisfies vegetarian, but vegetarian does not satisfy vegan", () => {
    const vegan = make({ dietaryTags: ["VEGAN"] });
    const vegetarian = make({ dietaryTags: ["VEGETARIAN"] });
    expect(matchDiet(vegan, "VEGETARIAN")).toBe("yes");
    expect(matchDiet(vegetarian, "VEGETARIAN")).toBe("yes");
    expect(matchDiet(vegetarian, "VEGAN")).toBe("no");
  });

  it("NUT_FREE needs the NUT_FREE tag (absence of a nut tag is not enough)", () => {
    expect(matchDiet(make({ dietaryTags: ["NUT_FREE"] }), "NUT_FREE")).toBe("yes");
    expect(matchDiet(make({ dietaryTags: ["VEGAN", "HALAL"] }), "NUT_FREE")).toBe("no");
    expect(matchDiet(make({ dietaryTags: [] }), "NUT_FREE")).toBe("no");
  });

  it("does not treat HALAL or GLUTEN_FREE as meat or allergen proxies", () => {
    const item = make({ dietaryTags: ["HALAL", "GLUTEN_FREE"] });
    expect(matchDiet(item, "VEGETARIAN")).toBe("no");
    expect(matchDiet(item, "NUT_FREE")).toBe("no");
  });
});

describe("partitionByDiet", () => {
  it("returns everything in matches when no filters are active", () => {
    const result = partitionByDiet(DEMO_CATALOGUE.items, []);
    expect(result.matches).toBe(DEMO_CATALOGUE.items);
    expect(result.unverified).toEqual([]);
  });

  it("NUT_FREE on the demo menu: verified tag matches, unverified is listed separately, tagless verified is dropped", () => {
    const { matches, unverified } = partitionByDiet(DEMO_CATALOGUE.items, ["NUT_FREE"]);
    expect(slugs(matches)).toContain("demo-margherita");
    expect(slugs(unverified)).toContain("halloumi-pie");
    expect(slugs(matches)).not.toContain("halloumi-pie");
    // Baklava is verified and has no NUT_FREE tag: it must not appear anywhere under this filter.
    expect(slugs(matches)).not.toContain("demo-baklava");
    expect(slugs(unverified)).not.toContain("demo-baklava");
  });

  it("never lets an unverified item into matches for a dietary filter", () => {
    for (const filter of ["VEGAN", "VEGETARIAN", "NUT_FREE"] as const) {
      const { matches } = partitionByDiet(DEMO_CATALOGUE.items, [filter]);
      expect(matches.every((i) => i.dietaryVerified)).toBe(true);
    }
  });

  it("ANDs multiple filters", () => {
    const items = [
      make({ slug: "both", dietaryTags: ["VEGAN", "NUT_FREE"] }),
      make({ slug: "vegan-only", dietaryTags: ["VEGAN"] }),
      make({ slug: "nut-only", dietaryTags: ["NUT_FREE"] }),
    ];
    const { matches, unverified } = partitionByDiet(items, ["VEGAN", "NUT_FREE"]);
    expect(slugs(matches)).toEqual(["both"]);
    expect(unverified).toEqual([]);
  });

  it("an item ruled out by one filter is dropped even if another is unknown", () => {
    const unverifiedButNo = make({ slug: "x", isSpicy: false, dietaryVerified: false });
    // SPICY -> unknown (unverified, not flagged); NUT_FREE -> unknown. Both unknown, so it is listed as unverified.
    expect(slugs(partitionByDiet([unverifiedButNo], ["SPICY", "NUT_FREE"]).unverified)).toEqual(["x"]);
    const verifiedNotSpicy = make({ slug: "y", dietaryTags: ["NUT_FREE"], isSpicy: false });
    // SPICY -> no, so dropped despite NUT_FREE yes.
    const result = partitionByDiet([verifiedNotSpicy], ["SPICY", "NUT_FREE"]);
    expect(result.matches).toEqual([]);
    expect(result.unverified).toEqual([]);
  });

  it("puts an item with a mix of yes and unknown into unverified, not matches", () => {
    const spicyUnverified = make({ slug: "z", isSpicy: true, dietaryVerified: false, dietaryTags: ["NUT_FREE"] });
    const result = partitionByDiet([spicyUnverified], ["SPICY", "NUT_FREE"]);
    expect(result.matches).toEqual([]);
    expect(slugs(result.unverified)).toEqual(["z"]);
  });

  it("preserves input order in both lists", () => {
    const { matches } = partitionByDiet(DEMO_CATALOGUE.items, ["VEGETARIAN"]);
    const order = DEMO_CATALOGUE.items.map((i) => i.slug);
    const idx = matches.map((i) => order.indexOf(i.slug));
    expect(idx).toEqual([...idx].sort((a, b) => a - b));
  });

  it("SPICY on the demo menu: flagged items match, unverified unflagged items are unknown", () => {
    const { matches, unverified } = partitionByDiet(DEMO_CATALOGUE.items, ["SPICY"]);
    expect(slugs(matches)).toEqual(expect.arrayContaining(["lahm-bi-ajin", "shanklish-manoush", "demo-soujouk-pizza"]));
    expect(slugs(unverified)).toEqual(expect.arrayContaining(["halloumi-pie", "demo-unpriced-wrap"]));
    expect(slugs(matches)).not.toContain("zaatar-manoush");
  });
});

describe("visibleDietaryTags", () => {
  it("returns no tags while unverified", () => {
    expect(visibleDietaryTags(demo("halloumi-pie"))).toEqual([]);
    expect(visibleDietaryTags(make({ dietaryVerified: false, dietaryTags: ["VEGAN"] }))).toEqual([]);
  });

  it("returns the tags once verified", () => {
    expect(visibleDietaryTags(demo("demo-margherita"))).toEqual(["VEGETARIAN", "NUT_FREE"]);
  });
});
