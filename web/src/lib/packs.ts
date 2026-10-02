/**
 * Party Pack Sizer — pure maths for a pack-size slider.
 *
 * INTERNAL ONLY, DORMANT LIBRARY CODE. It is not used by any public page: the public
 * teaser is generic and makes no product, size or pack claim (docs/site/teaser-direction.md).
 * The labels below are working names, never customer-facing copy.
 *
 * IMPORTANT: everything here is a *planning estimate*, not a quote and not a
 * product spec. Pieces-per-guest are common catering rules of thumb for finger
 * food and the variety split is a suggested starting mix; the team confirms
 * final quantities. The UI says so. No prices appear here.
 */
import type { MinisKind, PackAppetite } from "@/types/menu";

export const PACK_MIN = 20;
export const PACK_MAX = 200;
export const PACK_STEP = 10;
/** Quick-pick sizes from the brief: 20, 50 and 100+. */
export const PACK_PRESETS = [20, 50, 100] as const;

/** Pieces a typical guest eats. "light" = a nibble alongside other food; "hearty" = the main event. */
export const PIECES_PER_GUEST: Record<PackAppetite, number> = { light: 3, standard: 5, hearty: 8 };

export const APPETITE_LABELS: Record<PackAppetite, { label: string; hint: string }> = {
  light: { label: "Nibbles", hint: "Alongside other food" },
  standard: { label: "Party", hint: "A good feed" },
  hearty: { label: "Main event", hint: "The whole meal" },
};

/** Suggested starting mix (percent). Sums to 100. */
export const SUGGESTED_MIX: Record<MinisKind, number> = {
  MINI_ZAATAR: 30,
  MINI_CHEESE: 30,
  MINI_MEAT: 25,
  MINI_PIES: 15,
};

export const MINIS_ORDER: MinisKind[] = ["MINI_ZAATAR", "MINI_CHEESE", "MINI_MEAT", "MINI_PIES"];

export const MINIS_LABELS: Record<MinisKind, string> = {
  MINI_ZAATAR: "Mini Za’atar",
  MINI_CHEESE: "Mini Cheese",
  MINI_MEAT: "Mini Lahmeh B Ajin",
  MINI_PIES: "Mini Pies",
};

export function clampPieces(pieces: number): number {
  if (!Number.isFinite(pieces)) return PACK_MIN;
  const snapped = Math.round(pieces / PACK_STEP) * PACK_STEP;
  return Math.min(PACK_MAX, Math.max(PACK_MIN, snapped));
}

/**
 * Split `total` pieces across kinds by percentage using the largest-remainder
 * method, so the parts always add up to exactly `total` (no off-by-one pieces).
 * Ties break in `MINIS_ORDER`, keeping the result deterministic.
 */
export function splitPieces(total: number, mix: Record<MinisKind, number> = SUGGESTED_MIX): Record<MinisKind, number> {
  const weightSum = MINIS_ORDER.reduce((s, k) => s + mix[k], 0);
  // Each weight must be finite and non-negative: a lone negative weight can leave the
  // sum positive while handing that kind a negative piece count.
  const weightsValid = MINIS_ORDER.every((k) => Number.isFinite(mix[k]) && mix[k] >= 0);
  if (!weightsValid || !(weightSum > 0) || !Number.isInteger(total) || total < 0) {
    throw new Error("splitPieces: total must be a non-negative integer and mix weights must be positive");
  }
  const exact = MINIS_ORDER.map((k) => ({ k, value: (total * mix[k]) / weightSum }));
  const result = Object.fromEntries(exact.map(({ k, value }) => [k, Math.floor(value)])) as Record<MinisKind, number>;
  let remaining = total - MINIS_ORDER.reduce((s, k) => s + result[k], 0);
  const byRemainder = [...exact].sort((a, b) => {
    const diff = b.value - Math.floor(b.value) - (a.value - Math.floor(a.value));
    return Math.abs(diff) > 1e-9 ? diff : MINIS_ORDER.indexOf(a.k) - MINIS_ORDER.indexOf(b.k);
  });
  for (const { k } of byRemainder) {
    if (remaining <= 0) break;
    result[k] += 1;
    remaining -= 1;
  }
  return result;
}

export interface PackEstimate {
  pieces: number;
  /** Guests fed at the chosen appetite. */
  guests: number;
  /** Range across nibbles → main event. */
  guestsMin: number;
  guestsMax: number;
  split: Record<MinisKind, number>;
  /** 100+ pieces: label as "100+" and nudge towards a custom quote. */
  isLarge: boolean;
  /** At the slider's top end the team should quote it individually. */
  needsCustomQuote: boolean;
}

export function estimatePack(piecesInput: number, appetite: PackAppetite = "standard"): PackEstimate {
  const pieces = clampPieces(piecesInput);
  return {
    pieces,
    guests: Math.floor(pieces / PIECES_PER_GUEST[appetite]),
    guestsMin: Math.floor(pieces / PIECES_PER_GUEST.hearty),
    guestsMax: Math.floor(pieces / PIECES_PER_GUEST.light),
    split: splitPieces(pieces),
    isLarge: pieces >= 100,
    needsCustomQuote: pieces >= PACK_MAX,
  };
}
