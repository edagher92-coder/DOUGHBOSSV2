/**
 * Client cart (Zustand + localStorage).
 *
 * The cart stores CHOICES only — item slug, option slugs, quantity — never
 * prices. Prices are derived from the catalogue (`priceCart`) for display and
 * recomputed on the server at checkout, so editing localStorage can't change
 * what anyone pays. Persisted state is also re-validated on load: anything
 * malformed or stale is dropped rather than trusted.
 */
import { create } from "zustand";
import { createJSONStorage, persist, type StateStorage } from "zustand/middleware";
import { useSyncExternalStore } from "react";
import { z } from "zod";
import type { CartLine, StoreSlug } from "@/types/menu";
import { MAX_CART_LINES, MAX_LINE_QUANTITY } from "@/lib/pricing";
import { STORE_SLUGS } from "@/lib/validations";

export type Selections = CartLine["selections"];

export interface AddLineInput {
  itemSlug: string;
  quantity?: number;
  selections?: Selections;
  note?: string;
}

export type AddLineResult = { ok: true } | { ok: false; reason: "CART_FULL" };

// ───────────── pure helpers (unit-tested) ─────────────

/** Sorted, de-duplicated option slugs per group; empty groups dropped. Makes equal choices compare equal. */
export function normaliseSelections(selections: Selections | undefined): Selections {
  const out: Selections = {};
  const source = selections ?? {};
  for (const key of Object.keys(source).sort()) {
    const values = [...new Set(source[key] ?? [])].sort();
    if (values.length > 0) out[key] = values;
  }
  return out;
}

/** Two lines with the same signature are the same order line (so they merge). */
export function lineSignature(line: Pick<CartLine, "itemSlug" | "selections" | "note">): string {
  return JSON.stringify([line.itemSlug, normaliseSelections(line.selections), (line.note ?? "").trim()]);
}

export function clampQuantity(quantity: number): number {
  const whole = Number.isFinite(quantity) ? Math.floor(quantity) : 1;
  return Math.min(MAX_LINE_QUANTITY, Math.max(1, whole));
}

export function addToLines(
  lines: CartLine[],
  input: AddLineInput,
  makeId: () => string,
): { lines: CartLine[] } & AddLineResult {
  const signature = lineSignature({ itemSlug: input.itemSlug, selections: input.selections ?? {}, note: input.note });
  const existing = lines.find((l) => lineSignature(l) === signature);
  const quantity = clampQuantity(input.quantity ?? 1);

  if (existing) {
    return {
      ok: true,
      lines: lines.map((l) => (l === existing ? { ...l, quantity: clampQuantity(l.quantity + quantity) } : l)),
    };
  }
  if (lines.length >= MAX_CART_LINES) return { ok: false, reason: "CART_FULL", lines };

  const note = input.note?.trim();
  const line: CartLine = {
    lineId: makeId(),
    itemSlug: input.itemSlug,
    quantity,
    selections: normaliseSelections(input.selections),
    ...(note ? { note } : {}),
  };
  return { ok: true, lines: [...lines, line] };
}

const persistedLineSchema = z.object({
  lineId: z.string().min(1).max(64),
  itemSlug: z.string().min(1).max(80),
  quantity: z.number().int().min(1).max(MAX_LINE_QUANTITY),
  selections: z.record(z.string().max(60), z.array(z.string().max(60)).max(10)),
  note: z.string().max(140).optional(),
});

export interface PersistedCart {
  storeSlug: StoreSlug | null;
  lines: CartLine[];
  pickupAtIso: string | null;
}

const EMPTY_PERSISTED: PersistedCart = { storeSlug: null, lines: [], pickupAtIso: null };

/** Salvage what is valid from untrusted persisted JSON; never throw. */
export function sanitisePersisted(raw: unknown, now: Date = new Date()): PersistedCart {
  if (typeof raw !== "object" || raw === null) return { ...EMPTY_PERSISTED };
  const r = raw as Record<string, unknown>;

  const store = z.enum(STORE_SLUGS).safeParse(r.storeSlug);
  const lines: CartLine[] = [];
  const seenIds = new Set<string>();
  if (Array.isArray(r.lines)) {
    for (const candidate of r.lines) {
      const parsed = persistedLineSchema.safeParse(candidate);
      if (!parsed.success || seenIds.has(parsed.data.lineId) || lines.length >= MAX_CART_LINES) continue;
      seenIds.add(parsed.data.lineId);
      lines.push({ ...parsed.data, selections: normaliseSelections(parsed.data.selections) });
    }
  }

  const pickup = z.iso.datetime().safeParse(r.pickupAtIso);
  // A slot that has already passed can't be honoured — make the customer pick again.
  const pickupAtIso = pickup.success && new Date(pickup.data).getTime() > now.getTime() ? pickup.data : null;

  return { storeSlug: store.success ? store.data : null, lines, pickupAtIso };
}

/** localStorage when usable, else in-memory (private browsing / blocked storage must not crash the site). */
export function safeStorage(): StateStorage {
  const memory = new Map<string, string>();
  const fallback: StateStorage = {
    getItem: (key) => memory.get(key) ?? null,
    setItem: (key, value) => void memory.set(key, value),
    removeItem: (key) => void memory.delete(key),
  };
  try {
    const ls = globalThis.localStorage;
    if (!ls) return fallback;
    const probe = "__doughboss_probe__";
    ls.setItem(probe, "1");
    ls.removeItem(probe);
    return ls;
  } catch {
    return fallback;
  }
}

const makeLineId = (): string =>
  typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `l_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;

// ───────────── store ─────────────

interface CartState extends PersistedCart {
  /** UI state only — deliberately not persisted. */
  isCartOpen: boolean;
  setStore: (slug: StoreSlug | null) => void;
  addLine: (input: AddLineInput) => AddLineResult;
  setQuantity: (lineId: string, quantity: number) => void;
  removeLine: (lineId: string) => void;
  setPickup: (iso: string | null) => void;
  clear: () => void;
  openCart: () => void;
  closeCart: () => void;
}

export const useCartStore = create<CartState>()(
  persist(
    (set, get) => ({
      ...EMPTY_PERSISTED,
      isCartOpen: false,

      // Pickup slots belong to a store, so switching store invalidates the chosen slot.
      setStore: (slug) => set((s) => (s.storeSlug === slug ? s : { storeSlug: slug, pickupAtIso: null })),

      addLine: (input) => {
        const result = addToLines(get().lines, input, makeLineId);
        if (result.ok) set({ lines: result.lines });
        return result.ok ? { ok: true } : { ok: false, reason: result.reason };
      },

      setQuantity: (lineId, quantity) =>
        set((s) => ({
          lines:
            quantity <= 0
              ? s.lines.filter((l) => l.lineId !== lineId)
              : s.lines.map((l) => (l.lineId === lineId ? { ...l, quantity: clampQuantity(quantity) } : l)),
        })),

      removeLine: (lineId) => set((s) => ({ lines: s.lines.filter((l) => l.lineId !== lineId) })),

      setPickup: (iso) => set({ pickupAtIso: iso }),

      // Keep the chosen store: after an order people usually come back to the same one.
      clear: () => set({ lines: [], pickupAtIso: null }),

      openCart: () => set({ isCartOpen: true }),
      closeCart: () => set({ isCartOpen: false }),
    }),
    {
      name: "doughboss-cart",
      version: 1,
      storage: createJSONStorage<PersistedCart>(() => safeStorage()),
      partialize: (s): PersistedCart => ({ storeSlug: s.storeSlug, lines: s.lines, pickupAtIso: s.pickupAtIso }),
      // Hydrate explicitly from <CartHydrator/> after mount so server and client markup match.
      skipHydration: true,
      merge: (persisted, current) => ({ ...current, ...sanitisePersisted(persisted) }),
      migrate: () => ({ ...EMPTY_PERSISTED }),
    },
  ),
);

/** True once persisted state has been loaded. Render cart-dependent UI only after this to avoid mismatches. */
export function useCartHydrated(): boolean {
  return useSyncExternalStore(
    (onChange) => useCartStore.persist.onFinishHydration(onChange),
    () => useCartStore.persist.hasHydrated(),
    () => false,
  );
}

/** Total units in the cart (0 until hydrated). */
export function useCartCount(): number {
  const hydrated = useCartHydrated();
  return useCartStore((s) => (hydrated ? s.lines.reduce((n, l) => n + l.quantity, 0) : 0));
}
