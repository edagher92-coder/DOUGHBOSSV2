/**
 * Typed server errors. The codes are stable strings so API routes and tests can
 * branch on them without matching message text.
 */

/** A write path (waitlist, orders) has no durable storage to write to. */
export class StorageUnavailableError extends Error {
  readonly code = "STORAGE_UNAVAILABLE" as const;

  constructor(message = "Storage is not available: no database is configured.") {
    super(message);
    this.name = "StorageUnavailableError";
  }
}

/**
 * A read of menu or store data failed. Callers must report "unavailable" — an
 * empty menu would read as "we sell nothing", which is a different (false) claim.
 */
export class CatalogueUnavailableError extends Error {
  readonly code = "CATALOGUE_UNAVAILABLE" as const;

  constructor(message = "Menu data is unavailable.", options?: { cause?: unknown }) {
    super(message, options);
    this.name = "CatalogueUnavailableError";
  }
}

export const isStorageUnavailable = (e: unknown): e is StorageUnavailableError => e instanceof StorageUnavailableError;
export const isCatalogueUnavailable = (e: unknown): e is CatalogueUnavailableError =>
  e instanceof CatalogueUnavailableError;
