import { ComingSoonSection } from "@/components/teaser/ComingSoonSection";
import { SiteFooter } from "@/components/layout/SiteFooter";
import { SiteHeader } from "@/components/layout/SiteHeader";
import { STORES } from "@/lib/data/catalogue";

// Placeholder composition: replaced when the hero and menu slices land. The teaser section is generic by direction (docs/site/teaser-direction.md).
export default function HomePage() {
  return (
    <>
      <SiteHeader stores={STORES} />
      <main id="main">
        <section id="top" className="container-page py-24">
          <p className="eyebrow">Dough Boss</p>
          <h1 className="mt-4 font-serif text-5xl font-bold sm:text-7xl">Crisp base. Pillowy crust. Oven-hot.</h1>
        </section>
        <ComingSoonSection stores={STORES} />
      </main>
      <SiteFooter stores={STORES} />
    </>
  );
}
