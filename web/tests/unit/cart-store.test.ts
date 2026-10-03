import { describe, expect, it } from "vitest";
import {
  addToLines,
  clampQuantity,
  lineSignature,
  normaliseSelections,
  safeStorage,
  sanitisePersisted,
  useCartStore,
} from "@/store/useCartStore";
import { MAX_CART_LINES, MAX_LINE_QUANTITY } from "@/lib/pricing";
import type { CartLine } from "@/types/menu";

let n = 0;
const id = () => `line-${++n}`;
const line = (over: Partial<CartLine> = {}): CartLine => ({
  lineId: id(),
  itemSlug: "zaatar-manoush",
  quantity: 1,
  selections: {},
  ...over,
});

describe("normaliseSelections / lineSignature", () => {
  it("sorts, de-duplicates and drops empty groups", () => {
    expect(normaliseSelections({ extras: ["b", "a", "a"], bake: [], "base-style": ["folded"] })).toEqual({
      "base-style": ["folded"],
      extras: ["a", "b"],
    });
  });

  it("treats the same choices in a different order as the same line", () => {
    const a = lineSignature({ itemSlug: "x", selections: { extras: ["a", "b"], bake: ["c"] } });
    const b = lineSignature({ itemSlug: "x", selections: { bake: ["c"], extras: ["b", "a"] } });
    expect(a).toBe(b);
  });

  it("distinguishes different items, options and notes", () => {
    const base = lineSignature({ itemSlug: "x", selections: {} });
    expect(lineSignature({ itemSlug: "y", selections: {} })).not.toBe(base);
    expect(lineSignature({ itemSlug: "x", selections: { extras: ["a"] } })).not.toBe(base);
    expect(lineSignature({ itemSlug: "x", selections: {}, note: "no onion" })).not.toBe(base);
  });
});

describe("addToLines", () => {
  it("adds a new line with normalised selections and a trimmed note", () => {
    const r = addToLines([], { itemSlug: "a", selections: { g: ["z", "y"] }, note: "  well done " }, id);
    expect(r.ok).toBe(true);
    expect(r.lines).toHaveLength(1);
    expect(r.lines[0]).toMatchObject({ itemSlug: "a", quantity: 1, selections: { g: ["y", "z"] }, note: "well done" });
  });

  it("omits an empty note entirely", () => {
    const r = addToLines([], { itemSlug: "a", note: "   " }, id);
    expect(r.lines[0] && "note" in r.lines[0]).toBe(false);
  });

  it("merges an identical line instead of duplicating it", () => {
    const first = addToLines([], { itemSlug: "a", selections: { g: ["x", "y"] }, quantity: 2 }, id);
    const second = addToLines(first.lines, { itemSlug: "a", selections: { g: ["y", "x"] }, quantity: 3 }, id);
    expect(second.lines).toHaveLength(1);
    expect(second.lines[0]?.quantity).toBe(5);
  });

  it("caps merged quantity at the per-line maximum", () => {
    const first = addToLines([], { itemSlug: "a", quantity: MAX_LINE_QUANTITY }, id);
    const second = addToLines(first.lines, { itemSlug: "a", quantity: 5 }, id);
    expect(second.lines[0]?.quantity).toBe(MAX_LINE_QUANTITY);
  });

  it("refuses a new line when the cart is full, but still merges into an existing one", () => {
    const full = Array.from({ length: MAX_CART_LINES }, (_, i) => line({ itemSlug: `item-${i}` }));
    const refused = addToLines(full, { itemSlug: "brand-new" }, id);
    expect(refused).toMatchObject({ ok: false, reason: "CART_FULL" });
    expect(refused.lines).toBe(full);

    const merged = addToLines(full, { itemSlug: "item-3" }, id);
    expect(merged.ok).toBe(true);
    expect(merged.lines.find((l) => l.itemSlug === "item-3")?.quantity).toBe(2);
  });

  it("clamps nonsense quantities", () => {
    expect(clampQuantity(0)).toBe(1);
    expect(clampQuantity(-4)).toBe(1);
    expect(clampQuantity(2.9)).toBe(2);
    expect(clampQuantity(Number.NaN)).toBe(1);
    expect(clampQuantity(999)).toBe(MAX_LINE_QUANTITY);
  });
});

describe("sanitisePersisted (untrusted localStorage)", () => {
  const now = new Date("2026-10-02T21:00:00.000Z");

  it("returns an empty cart for garbage", () => {
    const empty = { storeSlug: null, lines: [], pickupAtIso: null };
    expect(sanitisePersisted(null, now)).toEqual(empty);
    expect(sanitisePersisted("nope", now)).toEqual(empty);
    expect(sanitisePersisted(42, now)).toEqual(empty);
    expect(sanitisePersisted({ lines: "x" }, now)).toEqual(empty);
  });

  it("salvages valid lines and drops tampered ones (huge quantity, bad shapes, duplicate ids)", () => {
    const good = { lineId: "a", itemSlug: "zaatar-manoush", quantity: 2, selections: { extras: ["b", "a"] } };
    const result = sanitisePersisted(
      {
        storeSlug: "revesby",
        lines: [
          good,
          { ...good, lineId: "b", quantity: 9999 },
          { ...good, lineId: "c", selections: { extras: [1, 2] } },
          { lineId: "d" },
          { ...good }, // duplicate lineId "a"
          null,
        ],
        pickupAtIso: null,
      },
      now,
    );
    expect(result.storeSlug).toBe("revesby");
    expect(result.lines).toHaveLength(1);
    expect(result.lines[0]).toMatchObject({ lineId: "a", quantity: 2, selections: { extras: ["a", "b"] } });
  });

  it("rejects an unknown store and a pickup slot that has already passed", () => {
    expect(sanitisePersisted({ storeSlug: "narnia" }, now).storeSlug).toBeNull();
    expect(sanitisePersisted({ pickupAtIso: "2026-10-02T20:00:00.000Z" }, now).pickupAtIso).toBeNull();
    expect(sanitisePersisted({ pickupAtIso: "2026-10-03T21:30:00.000Z" }, now).pickupAtIso).toBe("2026-10-03T21:30:00.000Z");
    expect(sanitisePersisted({ pickupAtIso: "not a date" }, now).pickupAtIso).toBeNull();
  });

  it("never keeps more than the maximum number of lines", () => {
    const many = Array.from({ length: MAX_CART_LINES + 10 }, (_, i) => ({
      lineId: `l${i}`,
      itemSlug: `i${i}`,
      quantity: 1,
      selections: {},
    }));
    expect(sanitisePersisted({ lines: many }, now).lines).toHaveLength(MAX_CART_LINES);
  });
});

describe("safeStorage", () => {
  it("falls back to working in-memory storage when localStorage is unavailable (node)", () => {
    const s = safeStorage();
    s.setItem("k", "v");
    expect(s.getItem("k")).toBe("v");
    s.removeItem("k");
    expect(s.getItem("k")).toBeNull();
  });
});

describe("useCartStore actions", () => {
  const reset = () => useCartStore.setState({ storeSlug: null, lines: [], pickupAtIso: null, isCartOpen: false });

  it("adds, merges, re-quantities and removes lines", () => {
    reset();
    const s = useCartStore.getState();
    expect(s.addLine({ itemSlug: "a", quantity: 2 })).toEqual({ ok: true });
    s.addLine({ itemSlug: "a", quantity: 1 });
    expect(useCartStore.getState().lines).toHaveLength(1);
    const lineId = useCartStore.getState().lines[0]!.lineId;
    expect(useCartStore.getState().lines[0]?.quantity).toBe(3);

    useCartStore.getState().setQuantity(lineId, 7);
    expect(useCartStore.getState().lines[0]?.quantity).toBe(7);
    useCartStore.getState().setQuantity(lineId, 0);
    expect(useCartStore.getState().lines).toHaveLength(0);
  });

  it("clears the chosen pickup slot when the store changes, but not when it stays the same", () => {
    reset();
    useCartStore.getState().setStore("revesby");
    useCartStore.getState().setPickup("2026-10-03T21:30:00.000Z");
    useCartStore.getState().setStore("revesby");
    expect(useCartStore.getState().pickupAtIso).toBe("2026-10-03T21:30:00.000Z");
    useCartStore.getState().setStore("roselands");
    expect(useCartStore.getState().pickupAtIso).toBeNull();
  });

  it("clear() empties the cart and slot but keeps the chosen store", () => {
    reset();
    useCartStore.getState().setStore("bankstown");
    useCartStore.getState().addLine({ itemSlug: "a" });
    useCartStore.getState().setPickup("2026-10-05T20:00:00.000Z");
    useCartStore.getState().clear();
    expect(useCartStore.getState()).toMatchObject({ storeSlug: "bankstown", lines: [], pickupAtIso: null });
  });

  it("does not persist the drawer open/closed UI state", () => {
    reset();
    useCartStore.getState().openCart();
    expect(useCartStore.getState().isCartOpen).toBe(true);
    useCartStore.getState().closeCart();
    expect(useCartStore.getState().isCartOpen).toBe(false);
  });
});
