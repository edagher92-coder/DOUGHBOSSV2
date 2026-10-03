/**
 * Site-wide metadata and local-business structured data.
 *
 * Everything in the JSON-LD is derived from the typed Store data. Fields we
 * have no verified source for (geo, priceRange, images, ratings, reviews) are
 * deliberately absent: a wrong schema value is a public claim about the shop.
 */
import type { Metadata } from "next";
import type { Store, StoreHoursWindow } from "@/types/menu";

const DEFAULT_SITE_URL = "https://doughboss.com.au";
const INSTAGRAM_URL = "https://instagram.com/doughboss";

export function siteUrl(): string {
  const raw = process.env.NEXT_PUBLIC_SITE_URL?.trim();
  // Trailing slashes would double up when paths are appended.
  return (raw ? raw : DEFAULT_SITE_URL).replace(/\/+$/, "");
}

const TITLE = "Dough Boss | Lebanese bakery and pizzeria, Sydney";
const DESCRIPTION =
  "Lebanese manoush, pizza and pies, baked fresh at Dough Boss in Revesby, Bankstown and Roselands. Order for pickup.";

export const siteMetadata: Metadata = {
  metadataBase: new URL(siteUrl()),
  title: { default: TITLE, template: "%s | Dough Boss" },
  description: DESCRIPTION,
  applicationName: "Dough Boss",
  alternates: { canonical: "/" },
  openGraph: {
    type: "website",
    siteName: "Dough Boss",
    locale: "en_AU",
    title: TITLE,
    description: DESCRIPTION,
    url: "/",
  },
  twitter: { card: "summary", title: TITLE, description: DESCRIPTION },
  robots: { index: true, follow: true },
  icons: { icon: [{ url: "/favicon.svg", type: "image/svg+xml" }] },
};

const DAY_NAMES = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"] as const;
/** Monday first reads naturally for a trading-hours list. */
const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0] as const;

const pad = (n: number) => String(n).padStart(2, "0");
const clock = (minutes: number) => `${pad(Math.floor(minutes / 60) % 24)}:${pad(minutes % 60)}`;

export interface OpeningHoursSpecification {
  "@type": "OpeningHoursSpecification";
  dayOfWeek: string[];
  opens: string;
  closes: string;
}

/** Group days sharing an identical window so "every day 06:30-14:30" is one spec. */
export function buildOpeningHours(hours: StoreHoursWindow[]): OpeningHoursSpecification[] {
  const groups = new Map<string, { opens: number; closes: number; days: Set<number> }>();
  for (const w of hours) {
    const key = `${w.opensMin}-${w.closesMin}`;
    const g = groups.get(key) ?? { opens: w.opensMin, closes: w.closesMin, days: new Set<number>() };
    g.days.add(w.dayOfWeek);
    groups.set(key, g);
  }
  return [...groups.values()]
    .map((g) => ({
      "@type": "OpeningHoursSpecification" as const,
      dayOfWeek: DAY_ORDER.filter((d) => g.days.has(d)).map((d) => DAY_NAMES[d] ?? ""),
      opens: clock(g.opens),
      closes: clock(g.closes),
    }))
    .sort((a, b) => a.opens.localeCompare(b.opens) || a.closes.localeCompare(b.closes));
}

export interface JsonLdGraph {
  "@context": "https://schema.org";
  "@graph": Array<Record<string, unknown>>;
}

export function buildLocalBusinessJsonLd(stores: Store[], baseUrl: string = siteUrl()): JsonLdGraph {
  const base = baseUrl.replace(/\/+$/, "");
  const orgId = `${base}/#organization`;

  const bakeries = stores.map((s) => ({
    "@type": "Bakery",
    "@id": `${base}/#store-${s.slug}`,
    name: `Dough Boss ${s.name}`,
    url: base,
    telephone: s.phone,
    address: {
      "@type": "PostalAddress",
      streetAddress: [s.addressLine1, s.addressLine2].filter(Boolean).join(", "),
      addressLocality: s.suburb,
      addressRegion: s.state,
      postalCode: s.postcode,
      addressCountry: "AU",
    },
    openingHoursSpecification: buildOpeningHours(s.hours),
    servesCuisine: "Lebanese",
    sameAs: [INSTAGRAM_URL],
    parentOrganization: { "@type": "Organization", "@id": orgId, name: "Dough Boss" },
  }));

  return {
    "@context": "https://schema.org",
    "@graph": [
      { "@type": "WebSite", "@id": `${base}/#website`, url: base, name: "Dough Boss", inLanguage: "en-AU" },
      ...bakeries,
    ],
  };
}
