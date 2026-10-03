"use client";

import { MapPin, ShoppingBag } from "lucide-react";
import type { Store } from "@/types/menu";
import { getStoreStatus } from "@/lib/hours";
import { useNow } from "@/lib/hooks";
import { cn } from "@/lib/utils";
import { useCartCount, useCartStore } from "@/store/useCartStore";

// Every href here must resolve to an id on the home page (tests/unit/page-anchors.test.ts).
// There is no ordering section on this page yet, so there is no "Order" link: a dead anchor is worse than none.
const NAV = [
  { href: "#coming-soon", label: "Coming soon" },
  { href: "#locations", label: "Locations" },
] as const;

/**
 * Sticky header. Everything time- or cart-dependent renders only after mount
 * (useNow → null first, cart count 0 until hydrated) so the server HTML and
 * first client render are identical. Both slots reserve their width, so
 * hydrating them causes no layout shift.
 */
export function SiteHeader({ stores }: { stores: Store[] }) {
  const now = useNow(30_000);
  const count = useCartCount();
  const storeSlug = useCartStore((s) => s.storeSlug);
  const openCart = useCartStore((s) => s.openCart);

  const store = stores.find((s) => s.slug === storeSlug);
  const status = store && now ? getStoreStatus(store, now) : null;

  return (
    <header className="sticky top-0 z-40 border-b border-white/10 bg-ink-950/80 backdrop-blur-xl">
      <div className="container-page flex h-16 items-center justify-between gap-4">
        <a href="#top" className="text-[1.05rem] font-extrabold tracking-[0.06em]" aria-label="Dough Boss — back to top">
          DOUGH BOSS<span className="text-ember-500">.</span>
        </a>

        <nav aria-label="Primary" className="hidden items-center gap-7 sm:flex">
          {NAV.map((item) => (
            <a key={item.href} href={item.href} className="text-xs font-semibold uppercase tracking-[0.18em] text-flour/70 transition-colors hover:text-flour">
              {item.label}
            </a>
          ))}
        </nav>

        <div className="flex items-center gap-2">
          <a
            href="#locations"
            className="hidden h-10 min-w-[11.5rem] items-center gap-2 rounded-full border border-white/15 px-4 text-xs text-flour/80 transition-colors hover:border-white/30 md:inline-flex"
            aria-label={store ? `Selected store: ${store.name}. ${status?.label ?? ""}` : "Choose a store"}
          >
            <MapPin aria-hidden className="size-3.5 shrink-0 text-ember-400" />
            <span className="truncate">
              {store ? (
                <>
                  <span className="font-semibold text-flour">{store.name}</span>
                  {status ? (
                    <span className={cn("ml-2", status.state === "open" ? "text-[#7ee0a4]" : "text-muted")}>
                      {status.state === "open" ? "Open" : "Closed"}
                    </span>
                  ) : null}
                </>
              ) : (
                "Choose a store"
              )}
            </span>
          </a>

          <button
            type="button"
            onClick={openCart}
            aria-label={`Open cart, ${count} ${count === 1 ? "item" : "items"}`}
            className="relative grid size-11 place-items-center rounded-full border border-white/20 transition-colors hover:border-gold-400/60"
          >
            <ShoppingBag aria-hidden className="size-5" />
            <span
              aria-hidden
              className={cn(
                "absolute -right-1 -top-1 grid min-w-5 place-items-center rounded-full bg-ember-500 px-1 text-[0.65rem] font-bold leading-5 text-ink-950 transition-opacity duration-200",
                count > 0 ? "opacity-100" : "opacity-0",
              )}
            >
              {count}
            </span>
          </button>
        </div>
      </div>
    </header>
  );
}
