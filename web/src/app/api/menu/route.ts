/**
 * GET /api/menu -> { mode, catalogue }
 *
 * Never an empty 200: if the menu cannot be read the answer is 503, because an
 * empty 200 would tell the client "there is nothing on the menu".
 */
import { getCatalogue, getDataMode } from "@/lib/data";

export const dynamic = "force-dynamic";

const NO_STORE = "no-store";
const SHARED_CACHE = "public, s-maxage=60, stale-while-revalidate=300";

export async function GET(_request?: Request): Promise<Response> {
  try {
    const mode = getDataMode();
    const catalogue = await getCatalogue();
    return Response.json(
      { mode, catalogue },
      // Demo data is fake and per-developer; it must never sit in a shared cache.
      { headers: { "Cache-Control": mode === "demo" ? NO_STORE : SHARED_CACHE } },
    );
  } catch (err) {
    console.error("[doughboss] /api/menu failed:", err instanceof Error ? `${err.name}: ${err.message}` : err);
    return Response.json({ error: "MENU_UNAVAILABLE" }, { status: 503, headers: { "Cache-Control": NO_STORE } });
  }
}
