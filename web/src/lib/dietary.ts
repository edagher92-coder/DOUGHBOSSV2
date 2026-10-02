/**
 * Dietary filtering with an explicit "unknown" state.
 *
 * A wrong "nut-free" label can seriously harm someone, so a dietary tag only
 * counts as a positive match once the team has verified the item's dietary
 * info (`dietaryVerified`). Until then the item is *unknown* for that filter:
 * it is not shown as a match, and it is not silently hidden either — the UI
 * lists it separately with "check with the store".
 */
import type { DietFilter, MenuItem } from "@/types/menu";

export type DietMatch = "yes" | "no" | "unknown";

export const DIET_FILTERS: { value: DietFilter; label: string }[] = [
  { value: "VEGETARIAN", label: "Vegetarian" },
  { value: "VEGAN", label: "Vegan" },
  { value: "NUT_FREE", label: "Nut-free" },
  { value: "SPICY", label: "Spicy" },
];

export function matchDiet(item: MenuItem, filter: DietFilter): DietMatch {
  // Heat is a conservative, self-evident marker: if staff flagged it spicy, it's spicy.
  if (filter === "SPICY") {
    if (item.isSpicy) return "yes";
    return item.dietaryVerified ? "no" : "unknown";
  }
  if (!item.dietaryVerified) return "unknown";
  const has = (tag: MenuItem["dietaryTags"][number]) => item.dietaryTags.includes(tag);
  switch (filter) {
    case "VEGAN":
      return has("VEGAN") ? "yes" : "no";
    case "VEGETARIAN":
      // Vegan food is vegetarian.
      return has("VEGETARIAN") || has("VEGAN") ? "yes" : "no";
    case "NUT_FREE":
      return has("NUT_FREE") ? "yes" : "no";
  }
}

export interface DietPartition {
  /** Verified matches for every active filter. */
  matches: MenuItem[];
  /** Not ruled out, but at least one filter can't be confirmed yet. */
  unverified: MenuItem[];
}

export function partitionByDiet(items: MenuItem[], active: DietFilter[]): DietPartition {
  if (active.length === 0) return { matches: items, unverified: [] };
  const matches: MenuItem[] = [];
  const unverified: MenuItem[] = [];
  for (const item of items) {
    const results = active.map((f) => matchDiet(item, f));
    if (results.includes("no")) continue;
    if (results.every((r) => r === "yes")) matches.push(item);
    else unverified.push(item);
  }
  return { matches, unverified };
}

/** Tags the UI may display as facts for this item (none until verified). */
export function visibleDietaryTags(item: MenuItem): MenuItem["dietaryTags"] {
  return item.dietaryVerified ? item.dietaryTags : [];
}

export const DIETARY_LABELS: Record<MenuItem["dietaryTags"][number], string> = {
  HALAL: "Halal",
  VEGETARIAN: "Vegetarian",
  VEGAN: "Vegan",
  NUT_FREE: "Nut-free",
  GLUTEN_FREE: "Gluten-free",
};
