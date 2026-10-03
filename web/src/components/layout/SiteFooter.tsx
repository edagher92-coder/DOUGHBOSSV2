import { MapPin, Phone } from "lucide-react";
import type { Store } from "@/types/menu";

/**
 * Locations + contact. Server component: all content is static store data,
 * so it is crawlable and needs no JavaScript. Hours shown are the verified
 * summaries from the store data — public-holiday trading is NOT in our source,
 * hence the explicit note rather than an assumption.
 */
export function SiteFooter({ stores }: { stores: Store[] }) {
  return (
    <footer id="locations" className="border-t border-white/10 bg-ink-950">
      <div className="container-page py-16">
        <p className="eyebrow">Find us</p>
        <h2 className="mt-4 font-serif text-4xl font-bold leading-tight sm:text-5xl">Fresh from our ovens, close to you.</h2>

        <ul className="mt-10 grid gap-5 md:grid-cols-3">
          {stores.map((store) => (
            <li key={store.slug} className="card-surface flex flex-col gap-3 p-6">
              <h3 className="font-serif text-2xl font-semibold">{store.name}</h3>
              <address className="not-italic text-sm leading-relaxed text-flour/80">
                {store.addressLine1}
                {store.addressLine2 ? (
                  <>
                    <br />
                    {store.addressLine2}
                  </>
                ) : null}
                <br />
                {store.suburb} {store.state} {store.postcode}
              </address>
              <p className="text-sm text-flour/80">
                <span className="font-semibold text-flour">Hours: </span>
                {store.hoursSummary}
              </p>
              {store.note ? <p className="text-sm text-muted">{store.note}</p> : null}
              <div className="mt-auto flex flex-wrap gap-2 pt-3">
                <a
                  href={`tel:${store.phone}`}
                  className="inline-flex h-10 items-center gap-2 rounded-full border border-white/20 px-4 text-xs font-semibold uppercase tracking-[0.12em] transition-colors hover:border-gold-400/60"
                >
                  <Phone aria-hidden className="size-3.5" />
                  <span>
                    <span className="sr-only">Call {store.name} on </span>
                    {store.phoneDisplay}
                  </span>
                </a>
                <a
                  href={store.mapsUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex h-10 items-center gap-2 rounded-full border border-white/20 px-4 text-xs font-semibold uppercase tracking-[0.12em] transition-colors hover:border-gold-400/60"
                >
                  <MapPin aria-hidden className="size-3.5" />
                  Directions<span className="sr-only"> to {store.name} (opens in a new tab)</span>
                </a>
              </div>
            </li>
          ))}
        </ul>

        <p className="mt-6 text-xs text-muted">
          Trading hours can change on public holidays — call your store to check before you head over.
        </p>

        <div className="mt-12 flex flex-col gap-4 border-t border-white/10 pt-8 text-sm text-muted sm:flex-row sm:items-center sm:justify-between">
          <p>
            © Dough Boss · <a className="underline-offset-4 hover:text-flour hover:underline" href="mailto:hello@doughboss.com.au">hello@doughboss.com.au</a>
          </p>
          <p className="flex gap-5">
            <a className="underline-offset-4 hover:text-flour hover:underline" href="https://instagram.com/doughboss" target="_blank" rel="noopener noreferrer">
              Instagram<span className="sr-only"> (opens in a new tab)</span>
            </a>
            <a className="underline-offset-4 hover:text-flour hover:underline" href="https://instagram.com/snowboss" target="_blank" rel="noopener noreferrer">
              Snow Boss<span className="sr-only"> on Instagram (opens in a new tab)</span>
            </a>
          </p>
        </div>
      </div>
    </footer>
  );
}
