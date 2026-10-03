/**
 * Cart pricing — the one implementation shared by the browser (to show totals)
 * and the server (to charge them). The server NEVER trusts a price from the
 * client: a cart line carries only *choices* (item slug, option slugs,
 * quantity) and this module turns those into dollars from the catalogue.
 *
 * An item or option whose price is null (unconfirmed) cannot be priced, so it
 * cannot be sold. That is deliberate — never guess a price.
 */
import type {
  CartLine,
  Catalogue,
  MenuItem,
  ModifierGroup,
  PricedCart,
  PricedLine,
  PricedModifier,
  StoreSlug,
} from "@/types/menu";

export const MAX_LINE_QUANTITY = 20;
export const MAX_CART_LINES = 30;

type Selections = CartLine["selections"];

function fail(lineId: string, reason: Extract<PricedLine, { ok: false }>["reason"], detail: string): PricedLine {
  return { ok: false, lineId, reason, detail };
}

/** Validate chosen option slugs against one group and return the priced modifiers. */
function resolveGroup(
  group: ModifierGroup,
  chosen: string[],
): { ok: true; modifiers: PricedModifier[] } | { ok: false; reason: "INVALID_SELECTION" | "MODIFIER_UNPRICED"; detail: string } {
  if (new Set(chosen).size !== chosen.length) {
    return { ok: false, reason: "INVALID_SELECTION", detail: `Duplicate option in “${group.name}”.` };
  }
  const max = group.selection === "SINGLE" ? 1 : group.maxSelect;
  if (chosen.length < group.minSelect) {
    return { ok: false, reason: "INVALID_SELECTION", detail: `Choose ${group.minSelect > 1 ? `at least ${group.minSelect}` : "an option"} for “${group.name}”.` };
  }
  if (chosen.length > max) {
    return { ok: false, reason: "INVALID_SELECTION", detail: `Too many options chosen for “${group.name}”.` };
  }
  const modifiers: PricedModifier[] = [];
  for (const slug of chosen) {
    const option = group.options.find((o) => o.slug === slug);
    if (!option) return { ok: false, reason: "INVALID_SELECTION", detail: `Unknown option “${slug}” in “${group.name}”.` };
    if (option.priceDeltaCents === null) {
      return { ok: false, reason: "MODIFIER_UNPRICED", detail: `“${option.name}” doesn’t have a confirmed price yet.` };
    }
    modifiers.push({ groupSlug: group.slug, slug: option.slug, name: option.name, priceDeltaCents: option.priceDeltaCents });
  }
  return { ok: true, modifiers };
}

export function priceLine(catalogue: Catalogue, storeSlug: StoreSlug, line: CartLine): PricedLine {
  const item: MenuItem | undefined = catalogue.items.find((i) => i.slug === line.itemSlug);
  if (!item) return fail(line.lineId, "ITEM_NOT_FOUND", `“${line.itemSlug}” is no longer on the menu.`);
  if (!Number.isInteger(line.quantity) || line.quantity < 1 || line.quantity > MAX_LINE_QUANTITY) {
    return fail(line.lineId, "INVALID_QUANTITY", `Quantity must be between 1 and ${MAX_LINE_QUANTITY}.`);
  }
  if (item.priceCents === null) {
    return fail(line.lineId, "ITEM_UNPRICED", `${item.name} doesn’t have a confirmed price yet.`);
  }
  if (item.unavailableAt.includes(storeSlug)) {
    return fail(line.lineId, "ITEM_UNAVAILABLE_AT_STORE", `${item.name} isn’t available at this store.`);
  }

  const selections: Selections = line.selections ?? {};
  for (const key of Object.keys(selections)) {
    if (!item.modifierGroups.some((g) => g.slug === key)) {
      return fail(line.lineId, "INVALID_SELECTION", `“${key}” isn’t an option for ${item.name}.`);
    }
  }

  const modifiers: PricedModifier[] = [];
  for (const group of item.modifierGroups) {
    const result = resolveGroup(group, selections[group.slug] ?? []);
    if (!result.ok) return fail(line.lineId, result.reason, result.detail);
    modifiers.push(...result.modifiers);
  }

  const unitCents = item.priceCents + modifiers.reduce((sum, m) => sum + m.priceDeltaCents, 0);
  if (unitCents < 0) return fail(line.lineId, "INVALID_SELECTION", "Line total can’t be negative.");

  return {
    ok: true,
    lineId: line.lineId,
    item,
    quantity: line.quantity,
    unitCents,
    lineCents: unitCents * line.quantity,
    modifiers,
  };
}

export function priceCart(catalogue: Catalogue, storeSlug: StoreSlug, lines: CartLine[]): PricedCart {
  const priced = lines.map((l) => priceLine(catalogue, storeSlug, l));
  const subtotalCents = priced.reduce((sum, l) => (l.ok ? sum + l.lineCents : sum), 0);
  return {
    lines: priced,
    subtotalCents,
    orderable: priced.length > 0 && priced.length <= MAX_CART_LINES && priced.every((l) => l.ok),
  };
}

/** Can this item be added to a cart at all (ignoring option choices)? */
export function isItemOrderable(item: MenuItem, storeSlug: StoreSlug | null): boolean {
  if (item.priceCents === null) return false;
  if (storeSlug && item.unavailableAt.includes(storeSlug)) return false;
  // A required group whose every option is unpriced would make the item impossible to complete.
  return item.modifierGroups.every(
    (g) => g.minSelect === 0 || g.options.some((o) => o.priceDeltaCents !== null),
  );
}
