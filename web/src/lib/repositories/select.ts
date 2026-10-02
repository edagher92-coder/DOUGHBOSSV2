/**
 * Which storage backs a write path (waitlist, orders)?
 *
 *   database configured        -> prisma
 *   no database, not production -> memory (local dev and tests only)
 *   no database, production     -> refuse
 *
 * A production signup or order must never be "saved" into memory that vanishes
 * on the next cold start while the customer is told it worked.
 */
import { StorageUnavailableError } from "../errors";
import { getEnv } from "../env";

export type Backend = "prisma" | "memory";

export function selectBackend(): Backend {
  const env = getEnv();
  if (env.DATABASE_URL) return "prisma";
  if (env.NODE_ENV !== "production") return "memory";
  throw new StorageUnavailableError("No database is configured, so this cannot be saved. Please try again later or call the store.");
}
