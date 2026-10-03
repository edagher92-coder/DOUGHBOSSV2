/**
 * Human-readable order numbers, e.g. DB-REV-4F7K2Q.
 *
 * The suffix uses Crockford base32 (no I, L, O, U) so it survives being read
 * over the counter or the phone. Six characters is 32^6 (about 1.07 billion)
 * values; a collision is caught by the database's unique index and retried.
 * The suffix is not a secret: tracking also requires the customer's email.
 */
import { randomInt } from "node:crypto";
import type { StoreSlug } from "@/types/menu";

export const CROCKFORD = "0123456789ABCDEFGHJKMNPQRSTVWXYZ";

/** Three-letter store codes. Typed as a full Record so a new store slug fails the build until it has a code. */
export const STORE_CODES: Record<StoreSlug, string> = {
  revesby: "REV",
  bankstown: "BAN",
  roselands: "ROS",
};

/** Returns an integer in [0, max). */
export type Rng = (max: number) => number;

export function generateOrderNumber(storeSlug: StoreSlug, rng: Rng = randomInt): string {
  let suffix = "";
  for (let i = 0; i < 6; i++) {
    const n = rng(CROCKFORD.length);
    const ch = Number.isInteger(n) ? CROCKFORD[n] : undefined;
    if (ch === undefined) throw new RangeError(`rng returned ${n}, outside 0..${CROCKFORD.length - 1}`);
    suffix += ch;
  }
  return `DB-${STORE_CODES[storeSlug]}-${suffix}`;
}
