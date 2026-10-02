/**
 * The one place the app decides where menu and store data come from.
 *
 *   DOUGHBOSS_DEMO_DATA set  -> demo catalogue (refused in production)
 *   DATABASE_URL set         -> database; a failed read is an ERROR, never a fallback
 *   neither                  -> the production seed file, explicitly and logged once
 *
 * The middle rule is the silence rule: if the database is configured but down,
 * quietly serving static data would show stale prices as if they were live.
 */
import type { Catalogue, Store } from "@/types/menu";
import { CatalogueUnavailableError } from "../errors";
import { getEnv } from "../env";
import { getPrisma } from "../db";
import { PRODUCTION_CATALOGUE, STORES } from "./catalogue";
import { DEMO_CATALOGUE, assertDemoAllowed } from "./demo-catalogue";
import { loadCatalogue, loadStores } from "./db-catalogue";

export type DataMode = "demo" | "database" | "static";

let staticModeLogged = false;

/** Test hook: the static-mode notice is once-per-process in real life. */
export function resetDataModeLog(): void {
  staticModeLogged = false;
}

export function getDataMode(): DataMode {
  const env = getEnv();
  if (env.DOUGHBOSS_DEMO_DATA) {
    assertDemoAllowed({ NODE_ENV: env.NODE_ENV });
    return "demo";
  }
  if (env.DATABASE_URL) return "database";
  return "static";
}

function noteStaticMode(): void {
  if (staticModeLogged) return;
  staticModeLogged = true;
  console.warn(
    "[doughboss] DATABASE_URL is not set: serving the static production seed (src/lib/data/catalogue.ts). Unconfirmed prices stay unorderable.",
  );
}

/** Wrap anything that is not already our typed error so callers see one failure type. */
function asUnavailable(what: string, cause: unknown): CatalogueUnavailableError {
  if (cause instanceof CatalogueUnavailableError) return cause;
  return new CatalogueUnavailableError(`${what} could not be loaded.`, { cause });
}

export async function getStores(): Promise<Store[]> {
  switch (getDataMode()) {
    case "demo":
      // Demo only fakes prices; the stores are the real, source-cited ones.
      return STORES;
    case "database":
      try {
        return await loadStores(getPrisma());
      } catch (cause) {
        throw asUnavailable("Stores", cause);
      }
    case "static":
      noteStaticMode();
      return STORES;
  }
}

export async function getCatalogue(): Promise<Catalogue> {
  switch (getDataMode()) {
    case "demo":
      return DEMO_CATALOGUE;
    case "database":
      try {
        return await loadCatalogue(getPrisma());
      } catch (cause) {
        throw asUnavailable("The menu", cause);
      }
    case "static":
      noteStaticMode();
      return PRODUCTION_CATALOGUE;
  }
}
