/**
 * GET /api/stores -> { stores: [{ ...store, status }] }
 *
 * `status` is computed per request from the store's own hours and timezone, so
 * the short cache (15 s) is the most stale an "open now" label can be.
 */
import { getStores } from "@/lib/data";
import { getStoreStatus } from "@/lib/hours";
import type { Store, StoreStatus } from "@/types/menu";

export const dynamic = "force-dynamic";

/** Dates become ISO strings; the discriminated `state` is kept as-is. */
type SerialisedStoreStatus =
  | { state: "open"; label: string; closesAt: string }
  | { state: "closed"; label: string; opensAt: string | null };

function serialiseStatus(status: StoreStatus): SerialisedStoreStatus {
  return status.state === "open"
    ? { state: "open", label: status.label, closesAt: status.closesAt.toISOString() }
    : { state: "closed", label: status.label, opensAt: status.opensAt ? status.opensAt.toISOString() : null };
}

export async function GET(_request?: Request): Promise<Response> {
  try {
    const stores = await getStores();
    const now = new Date();
    const body = {
      stores: stores.map((store: Store) => ({ ...store, status: serialiseStatus(getStoreStatus(store, now)) })),
    };
    return Response.json(body, { headers: { "Cache-Control": "public, s-maxage=15, stale-while-revalidate=30" } });
  } catch (err) {
    console.error("[doughboss] /api/stores failed:", err instanceof Error ? `${err.name}: ${err.message}` : err);
    return Response.json({ error: "STORES_UNAVAILABLE" }, { status: 503, headers: { "Cache-Control": "no-store" } });
  }
}
