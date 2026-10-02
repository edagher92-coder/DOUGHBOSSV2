import { ComingSoonSection } from "@/components/teaser/ComingSoonSection";
import { SiteFooter } from "@/components/layout/SiteFooter";
import { SiteHeader } from "@/components/layout/SiteHeader";
import { getStores } from "@/lib/data";
import type { Store } from "@/types/menu";

// Store data comes from the data layer at request time (database when DATABASE_URL is set), never frozen at build.
export const dynamic = "force-dynamic";

export const STORES_UNAVAILABLE_MESSAGE = "Store details are unavailable right now. Please try again shortly.";

/** getStores() is the single data path and fails closed: on error we say so rather than show stale static data. */
async function loadStores(): Promise<Store[] | null> {
  try {
    return await getStores();
  } catch (err) {
    console.error("[doughboss] home: stores unavailable:", err instanceof Error ? `${err.name}: ${err.message}` : err);
    return null;
  }
}

// Placeholder composition: replaced when the hero and menu slices land. The teaser section is generic by direction (docs/site/teaser-direction.md).
export default async function HomePage() {
  const stores = await loadStores();
  return (
    <>
      <SiteHeader stores={stores ?? []} />
      <main id="main">
        <section id="top" className="container-page py-24">
          <p className="eyebrow">Dough Boss</p>
          <h1 className="mt-4 font-serif text-5xl font-bold sm:text-7xl">Crisp base. Pillowy crust. Oven-hot.</h1>
        </section>
        <ComingSoonSection stores={stores ?? []} />
      </main>
      {stores ? (
        <SiteFooter stores={stores} />
      ) : (
        <footer id="locations" className="border-t border-white/10 bg-ink-950">
          <div className="container-page py-16">
            <p className="eyebrow">Find us</p>
            <p role="status" data-testid="stores-unavailable" className="mt-4 text-flour/80">
              {STORES_UNAVAILABLE_MESSAGE}
            </p>
          </div>
        </footer>
      )}
    </>
  );
}
