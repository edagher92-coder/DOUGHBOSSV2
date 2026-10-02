/**
 * Guards for the local and off-site SEO deliverables (docs/marketing/02-seo-external.md and the
 * marketing/ data files). Every check fails loudly, because a wrong name, number, phone, hour or
 * unsupported claim on a public listing is a public claim about the shop.
 */
import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { STORES } from "@/lib/data/catalogue";
import { buildLocalBusinessJsonLd } from "@/lib/seo";
import { parseCsvObjects } from "./helpers/csv";

const ROOT = fileURLToPath(new URL("../../", import.meta.url));
const read = (rel: string): string => readFileSync(`${ROOT}${rel}`, "utf8");
const BASE = "https://doughboss.com.au";

// ---------------------------------------------------------------------------
// Shared banned-terms list. Applied to every customer-facing string we wrote.
// Customer-facing = tagged fenced blocks in the markdown files plus the JSON-LD values.
// ---------------------------------------------------------------------------
interface Banned {
  name: string;
  re: RegExp;
}
const BANNED: Banned[] = [
  // superlatives and comparatives
  { name: "best", re: /\bbest\b/i },
  { name: "better", re: /\bbetter\b/i },
  { name: "number one", re: /number one|#\s?1\b|\bno\.\s?1\b/i },
  { name: "cheapest/cheap", re: /\bcheap(est)?\b/i },
  { name: "leading", re: /\bleading\b/i },
  { name: "award", re: /\baward(s|ed|-winning)?\b/i },
  { name: "famous", re: /\bfamous\b/i },
  { name: "finest/greatest/biggest", re: /\b(finest|greatest|biggest|unrivalled|unrivaled|unmatched)\b/i },
  { name: "favourite", re: /\bfavou?rite\b/i },
  { name: "most popular", re: /most popular|top[- ]rated|\bpopular\b/i },
  { name: "premium", re: /\bpremium\b/i },
  // quality, process and dietary claims that need a confirmed ledger entry
  { name: "fresh", re: /\bfresh(ly)?\b/i },
  { name: "authentic", re: /\bauthentic(ity)?\b/i },
  { name: "artisan/handmade/gourmet/traditional", re: /\b(artisan(al)?|hand-?made|hand-?crafted|home-?made|gourmet|traditional|stone-baked)\b/i },
  { name: "natural/organic", re: /\b(natural|organic)\b/i },
  { name: "in-house / never frozen / baked daily", re: /in-house|never frozen|baked (daily|fresh)|made (daily|to order)/i },
  { name: "since 2009", re: /since 2009/i },
  { name: "halal", re: /\bhalal\b/i },
  { name: "dietary claims", re: /gluten[- ]?free|\bvegan\b|vegetarian|dairy[- ]?free|nut[- ]?free|allergen[- ]?free|free from/i },
  { name: "health claims", re: /\bhealth(y|ier)\b|nutritious|low[- ]fat/i },
  // prices and offers
  { name: "dollar sign", re: /\$/ },
  { name: "free", re: /\bfree\b/i },
  { name: "discount/offer/voucher", re: /\b(discount(s|ed)?|voucher(s)?|coupon(s)?|deal(s)?|special offer|sale|bonus)\b|% off|per ?cent off/i },
  { name: "from price", re: /\bfrom \d+(\.\d+)? ?(per|a|each|dollars)\b/i },
  // delivery, timing, capacity and minimum promises
  { name: "delivery", re: /\bdeliver(y|ies|ed|s|ing)?\b/i },
  { name: "same/next day, within N", re: /same[- ]day|next[- ]day|overnight|within \d|\d+\s?(km|kilomet(re|er)s?)\b/i },
  { name: "minimum order / lead time / capacity", re: /minimum order|\bmin\.? ?\d|\blead time\b|\bcapacity\b|\bradius\b|\bguarantee(d|s)?\b/i },
  // ratings and review counts
  { name: "stars/ratings", re: /\bstars?\b|\brated\b|\d[\d,.]*\+?\s*(reviews|ratings)|\d(\.\d)?\s*(\/|out of)\s*5|[★☆⭐]/i },
  // voice
  { name: "exclamation mark", re: /!/ },
  { name: "em or en dash", re: /[\u2013\u2014]| -- / },
  { name: "emoji", re: /\p{Extended_Pictographic}/u },
  // placeholders and tenant separation
  { name: "placeholder marker", re: /\[CONFIRM|\bTBC\b|\bTODO\b|lorem ipsum/i },
  { name: "other tenant", re: /snow ?flow|slushie|slushy/i },
];

function bannedHits(text: string): string[] {
  return BANNED.filter((b) => b.re.test(text)).map((b) => b.name);
}

function expectClean(label: string, text: string): void {
  const hits = bannedHits(text);
  expect(hits, `${label} contains banned terms: ${hits.join(", ")}\n---\n${text}`).toEqual([]);
}

/** Pull fenced blocks whose info string is one of `tags`. */
function blocks(markdown: string, tags: readonly string[]): { tag: string; text: string }[] {
  const out: { tag: string; text: string }[] = [];
  const re = /```([a-z0-9-]+)\n([\s\S]*?)\n```/g;
  for (let m = re.exec(markdown); m !== null; m = re.exec(markdown)) {
    const tag = m[1] ?? "";
    const text = m[2] ?? "";
    if (tags.includes(tag) || tags.some((t) => t.endsWith("*") && tag.startsWith(t.slice(0, -1)))) out.push({ tag, text });
  }
  return out;
}

const CUSTOMER_TAGS = [
  "gbp-description",
  "gbp-service",
  "gbp-post",
  "faq-answer",
  "review-sms",
  "review-email",
  "review-card",
  "review-counter",
  "review-reply-*",
  "seo-title",
  "seo-meta",
] as const;

const SLUGS = ["revesby", "bankstown", "roselands"] as const;
const GBP_FILES = SLUGS.map((s) => `marketing/gbp/${s}.md`);
const REVIEW_FILES = readdirSync(`${ROOT}marketing/reviews`)
  .filter((f) => f.endsWith(".md"))
  .map((f) => `marketing/reviews/${f}`);
const CUSTOMER_FILES = [...GBP_FILES, ...REVIEW_FILES, "marketing/wordpress/seo-plugin-checklist.md"];

// ---------------------------------------------------------------------------
describe("banned-terms list (self-test, so the guard cannot silently stop biting)", () => {
  const mustFlag: Record<string, string> = {
    "best Lebanese bakery in Sydney": "best",
    "Fresh daily from the oven": "fresh",
    "Authentic manoush": "authentic",
    "Halal certified": "halal",
    "Gluten-free options": "dietary claims",
    "From $12 a head": "dollar sign",
    "Free delivery": "free",
    "Delivered to Bankstown and nearby": "delivery",
    "Minimum order applies": "minimum order / lead time / capacity",
    "Rated 4.8 stars": "stars/ratings",
    "Come and see us!": "exclamation mark",
    "Student voucher inside": "discount/offer/voucher",
    "Baked in-house, never frozen": "in-house / never frozen / baked daily",
    "We cater since 2009": "since 2009",
    "[CONFIRM: price]": "placeholder marker",
  };
  for (const [sample, expected] of Object.entries(mustFlag)) {
    it(`flags "${sample}"`, () => {
      expect(bannedHits(sample)).toContain(expected);
    });
  }
  it("flags an emoji", () => {
    expect(bannedHits("Great pies \u{1F600}")).toContain("emoji");
  });
  it("lets plain factual copy through", () => {
    expect(bannedHits("Dough Boss Revesby is a Lebanese bakery at 12/25 Selems Parade, Revesby.")).toEqual([]);
  });
});

// ---------------------------------------------------------------------------
describe("marketing/nap.json equals the typed store data", () => {
  interface NapStore {
    slug: string;
    name: string;
    addressLine1: string;
    addressLine2?: string;
    suburb: string;
    state: string;
    postcode: string;
    phone: string;
    phoneDisplay: string;
    timezone: string;
    mapsUrl: string;
    hours: { dayOfWeek: number; opensMin: number; closesMin: number }[];
    hoursSummary: string;
    note?: string;
    listing: {
      listingName: string;
      canonicalAddress: string;
      phoneE164: string;
      phoneDisplay: string;
      hours24: string;
      gbpWebsiteUrlFinal: string;
      gbpWebsiteUrlInterim: string;
    };
    provenance: { source: string; confirmedByOwner: boolean; retrieved: string };
  }
  interface Nap {
    business: { listingName: string; listingNameStatus: string; website: string };
    publicHolidayHours: { known: boolean; note: string };
    stores: NapStore[];
  }
  const nap = JSON.parse(read("marketing/nap.json")) as Nap;

  const hhmm = (min: number): string =>
    `${String(Math.floor(min / 60)).padStart(2, "0")}:${String(min % 60).padStart(2, "0")}`;
  const hours24 = (windows: { dayOfWeek: number; opensMin: number; closesMin: number }[]): string => {
    const days = [...new Set(windows.map((w) => w.dayOfWeek))].sort();
    const first = windows[0];
    if (!first) return "";
    const same = windows.every((w) => w.opensMin === first.opensMin && w.closesMin === first.closesMin);
    expect(same, "this test only handles one window shared by all open days").toBe(true);
    const label = days.length === 7 ? "Mon-Sun" : days.join(",") === "1,2,3,4,5" ? "Mon-Fri" : days.join(",");
    return `${label} ${hhmm(first.opensMin)}-${hhmm(first.closesMin)}`;
  };

  it("has the same stores, in the same order, as STORES", () => {
    expect(nap.stores.map((s) => s.slug)).toEqual(STORES.map((s) => s.slug));
  });

  for (const store of STORES) {
    it(`${store.slug}: every field equals STORES`, () => {
      const n = nap.stores.find((s) => s.slug === store.slug);
      expect(n, `nap.json has no store ${store.slug}`).toBeDefined();
      if (!n) return;
      expect(n.name).toBe(store.name);
      expect(n.addressLine1).toBe(store.addressLine1);
      expect(n.addressLine2).toBe(store.addressLine2);
      expect(n.suburb).toBe(store.suburb);
      expect(n.state).toBe(store.state);
      expect(n.postcode).toBe(store.postcode);
      expect(n.phone).toBe(store.phone);
      expect(n.phoneDisplay).toBe(store.phoneDisplay);
      expect(n.timezone).toBe(store.timezone);
      expect(n.mapsUrl).toBe(store.mapsUrl);
      expect(n.hours).toEqual(store.hours);
      expect(n.hoursSummary).toBe(store.hoursSummary);
      expect(n.note).toBe(store.note);
    });

    it(`${store.slug}: listing block is derived from the same data`, () => {
      const n = nap.stores.find((s) => s.slug === store.slug);
      if (!n) throw new Error(`nap.json has no store ${store.slug}`);
      expect(n.listing.listingName).toBe("Dough Boss");
      expect(n.listing.phoneE164).toBe(store.phone);
      expect(n.listing.phoneDisplay).toBe(store.phoneDisplay);
      expect(n.listing.canonicalAddress).toBe(
        `${store.addressLine1}, ${store.suburb} ${store.state} ${store.postcode}`,
      );
      expect(n.listing.hours24).toBe(hours24(store.hours));
      const campaign = `utm_campaign=${store.slug}-profile`;
      for (const u of [n.listing.gbpWebsiteUrlFinal, n.listing.gbpWebsiteUrlInterim]) {
        expect(u.startsWith(BASE)).toBe(true);
        expect(u).toContain("utm_source=gbp");
        expect(u).toContain("utm_medium=gbp");
        expect(u).toContain(campaign);
        expect(u).toBe(u.toLowerCase());
        expect(u).not.toMatch(/\s/);
      }
      expect(n.listing.gbpWebsiteUrlFinal).toContain(`/locations/${store.slug}?`);
    });

    it(`${store.slug}: provenance says it is not yet owner-confirmed`, () => {
      const n = nap.stores.find((s) => s.slug === store.slug);
      expect(n?.provenance.confirmedByOwner).toBe(false);
      expect(n?.provenance.source.length).toBeGreaterThan(20);
      expect(n?.provenance.retrieved).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });
  }

  it("uses the signage name rule and flags what is unconfirmed", () => {
    expect(nap.business.listingName).toBe("Dough Boss");
    expect(nap.business.listingNameStatus).toContain("[CONFIRM: exact signage wording per store]");
    expect(nap.business.website).toBe(BASE);
    expect(nap.publicHolidayHours.known).toBe(false);
    expect(nap.publicHolidayHours.note.toLowerCase()).toContain("public-holiday");
  });
});

// ---------------------------------------------------------------------------
describe("Google Business Profile drafts", () => {
  const nap = JSON.parse(read("marketing/nap.json")) as {
    stores: { slug: string; addressLine1: string; postcode: string; phoneDisplay: string; listing: { canonicalAddress: string } }[];
  };

  const fmt = (min: number): string => {
    const h24 = Math.floor(min / 60);
    const m = min % 60;
    const suffix = h24 >= 12 ? "pm" : "am";
    const h12 = h24 % 12 === 0 ? 12 : h24 % 12;
    return `${h12}:${String(m).padStart(2, "0")}${suffix}`;
  };
  const DAY_NAMES = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"] as const;

  for (const slug of SLUGS) {
    const md = read(`marketing/gbp/${slug}.md`);
    const store = STORES.find((s) => s.slug === slug);
    const napStore = nap.stores.find((s) => s.slug === slug);

    describe(slug, () => {
      it("has exactly one description of 750 characters or fewer, matching the stated count", () => {
        const d = blocks(md, ["gbp-description"]);
        expect(d).toHaveLength(1);
        const text = d[0]?.text ?? "";
        expect(text.length).toBeGreaterThan(100);
        expect(text.length).toBeLessThanOrEqual(750);
        const stated = /Character count: (\d+) of 750/.exec(md);
        expect(stated, "the file must state its own character count").not.toBeNull();
        expect(Number(stated?.[1])).toBe(text.length);
      });

      it("description is built from store data and carries no link", () => {
        const text = blocks(md, ["gbp-description"])[0]?.text ?? "";
        expect(text).toContain(store?.addressLine1 ?? "__missing__");
        expect(text).toContain(napStore?.phoneDisplay ?? "__missing__");
        expect(text).not.toMatch(/https?:|www\.|\.com/i);
        expectClean(`${slug} description`, text);
      });

      it("has eight or more Google Posts, each 1,500 characters or fewer with one call to action", () => {
        const posts = blocks(md, ["gbp-post"]);
        expect(posts.length).toBeGreaterThanOrEqual(8);
        for (const p of posts) {
          expect(p.text.length).toBeGreaterThan(40);
          expect(p.text.length).toBeLessThanOrEqual(1500);
          expect(p.text).not.toMatch(/https?:|www\./i);
        }
        const ctas = md.match(/- CTA button: /g) ?? [];
        expect(ctas.length).toBe(posts.length);
      });

      it("every customer-facing block is free of banned terms", () => {
        for (const b of blocks(md, [...CUSTOMER_TAGS])) expectClean(`${slug} ${b.tag}`, b.text);
      });

      it("has services and FAQ answers", () => {
        expect(blocks(md, ["gbp-service"]).length).toBeGreaterThanOrEqual(4);
        expect(blocks(md, ["faq-answer"]).length).toBeGreaterThanOrEqual(6);
      });

      it("opening hours table matches the typed store hours", () => {
        const rows = [...md.matchAll(/^\| (Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday) \| (.+) \|$/gm)];
        expect(rows).toHaveLength(7);
        for (const r of rows) {
          const day = DAY_NAMES.indexOf(r[1] as (typeof DAY_NAMES)[number]);
          const w = store?.hours.find((h) => h.dayOfWeek === day);
          const expected = w ? `${fmt(w.opensMin)} – ${fmt(w.closesMin)}` : "Closed";
          expect(r[2], `${slug} ${r[1]}`).toBe(expected);
        }
      });

      it("uses the canonical address, phone and a Place ID gap", () => {
        expect(md).toContain(store?.addressLine1 ?? "__missing__");
        expect(md).toContain(store?.postcode ?? "__missing__");
        expect(md).toContain(store?.phoneDisplay ?? "__missing__");
        expect(md).toContain("[CONFIRM: Place ID");
        expect(md).toContain("[CONFIRM: exact signage wording");
      });

      it("UTM on every profile link: gbp / gbp / <store>-profile, lower-case, no spaces", () => {
        const urls = md.match(/https:\/\/doughboss\.com\.au[^\s|)`]*/g) ?? [];
        const tagged = urls.filter((u) => u.includes("utm_"));
        expect(tagged.length).toBeGreaterThanOrEqual(10);
        for (const u of tagged) {
          expect(u, u).toContain("utm_source=gbp");
          expect(u, u).toContain("utm_medium=gbp");
          expect(u, u).toContain(`utm_campaign=${slug}-profile`);
          expect(u, u).toMatch(/utm_content=[a-z0-9-]+/);
          expect(u, u).toBe(u.toLowerCase());
        }
        // Fragments must come after the query string.
        for (const u of urls) {
          if (u.includes("#") && u.includes("?")) expect(u.indexOf("?")).toBeLessThan(u.indexOf("#"));
        }
        // Final links use only routes from the route contract.
        const allowed = [
          "/",
          "/catering",
          "/catering/corporate",
          "/catering/office-breakfast",
          "/catering/events",
          "/catering/minis",
          "/locations/revesby",
          "/locations/bankstown",
          "/locations/roselands",
        ];
        for (const line of md.split("\n").filter((l) => /final/i.test(l) && l.includes("https://doughboss.com.au"))) {
          for (const u of line.match(/https:\/\/doughboss\.com\.au[^\s|)`]*/g) ?? []) {
            const path = new URL(u).pathname;
            expect(allowed, `route ${path} is not in the route contract (${u})`).toContain(path);
          }
        }
      });

      it("categories cite Google and name Bakery as primary", () => {
        expect(md).toContain("https://support.google.com/business/answer/7249669");
        expect(md).toContain("https://support.google.com/business/answer/3038177");
        expect(md).toMatch(/\| Primary \| Bakery \|/);
      });

      it("never ticks halal, delivery or accessibility without a gate", () => {
        expect(md).toMatch(/Halal \| Never tick without confirmation/);
        expect(md).toMatch(/Delivery \| Do not tick/);
        expect(md).toMatch(/Wheelchair-accessible entrance[^\n]*Never tick without an on-site check/);
      });
    });
  }

  it("drafts are all marked DRAFT and nothing claims to be submitted", () => {
    for (const f of GBP_FILES) {
      const md = read(f);
      expect(md).toContain("DRAFT. Nothing in this file has been submitted to Google.");
    }
  });
});

// ---------------------------------------------------------------------------
describe("customer-facing text across all files", () => {
  it("every tagged block is clean", () => {
    let count = 0;
    for (const f of CUSTOMER_FILES) {
      for (const b of blocks(read(f), [...CUSTOMER_TAGS])) {
        expectClean(`${f} [${b.tag}]`, b.text);
        count++;
      }
    }
    expect(count).toBeGreaterThan(60);
  });

  it("SMS template is short enough for one message once the link is added", () => {
    const sms = blocks(read("marketing/reviews/request-scripts.md"), ["review-sms"]);
    expect(sms).toHaveLength(1);
    expect((sms[0]?.text ?? "").length).toBeLessThanOrEqual(150);
    expect(sms[0]?.text).toContain("Reply STOP");
    expect(sms[0]?.text).toContain("Dough Boss");
  });

  it("email template identifies the sender and has an unsubscribe, with no incentive", () => {
    const email = blocks(read("marketing/reviews/request-scripts.md"), ["review-email"])[0]?.text ?? "";
    expect(email).toContain("{{legal_entity}}");
    expect(email).toContain("{{abn}}");
    expect(email).toContain("{{unsubscribe_link}}");
    expect(email).toContain("{{review_link}}");
  });

  it("review reply templates exist for positive, neutral, negative, allergen and urgent cases", () => {
    const tags = new Set(blocks(read("marketing/reviews/response-policy.md"), ["review-reply-*"]).map((b) => b.tag));
    for (const t of ["positive", "neutral", "negative", "allergen", "urgent"]) {
      expect(tags.has(`review-reply-${t}`), `missing template ${t}`).toBe(true);
    }
  });

  it("allergen reply never says an item is safe or free of an allergen", () => {
    const a = blocks(read("marketing/reviews/response-policy.md"), ["review-reply-allergen"])
      .map((b) => b.text)
      .join("\n");
    expect(a).toMatch(/cannot promise/i);
    expect(a).not.toMatch(/\bsafe\b|allergen[- ]free|free of/i);
  });

  it("review short links file marks all three Place IDs as gaps", () => {
    const md = read("marketing/reviews/short-links-and-qr.md");
    expect((md.match(/\[CONFIRM: Place ID\]/g) ?? []).length).toBeGreaterThanOrEqual(3);
    for (const s of SLUGS) expect(md).toContain(`https://doughboss.com.au/r/${s}`);
    expect(md).toContain("https://developers.google.com/maps/documentation/places/web-service/place-id");
  });

  it("review scripts forbid incentives and gating in plain words", () => {
    const md = read("marketing/reviews/request-scripts.md");
    expect(md).toMatch(/No incentives/i);
    expect(md).toMatch(/No review gating/i);
    expect(md).toMatch(/Spam Act/);
  });

  it("title tags are 60 characters or fewer and meta descriptions 155 or fewer", () => {
    const md = read("marketing/wordpress/seo-plugin-checklist.md");
    const titles = blocks(md, ["seo-title"]);
    const metas = blocks(md, ["seo-meta"]);
    expect(titles.length).toBeGreaterThanOrEqual(7);
    expect(metas).toHaveLength(titles.length);
    for (const t of titles) expect(t.text.length, t.text).toBeLessThanOrEqual(60);
    for (const m of metas) expect(m.text.length, m.text).toBeLessThanOrEqual(155);
  });
});

// ---------------------------------------------------------------------------
describe("marketing/citations.csv", () => {
  const COLUMNS = [
    "directory",
    "url",
    "type",
    "why",
    "cost",
    "claim_or_create_url",
    "nap_fields_required",
    "status",
    "notes",
  ];
  const text = read("marketing/citations.csv");
  const rows = parseCsvObjects(text);

  it("has exactly the agreed columns, in order", () => {
    const header = text.split(/\r?\n/)[0] ?? "";
    expect(header.split(",")).toEqual(COLUMNS);
  });

  it("has 25 or more rows", () => {
    expect(rows.length).toBeGreaterThanOrEqual(25);
  });

  it("has unique urls and unique directory names", () => {
    const urls = rows.map((r) => r.url);
    expect(new Set(urls).size).toBe(urls.length);
    const names = rows.map((r) => r.directory);
    expect(new Set(names).size).toBe(names.length);
  });

  it("every status is todo", () => {
    for (const r of rows) expect(r.status, r.directory).toBe("todo");
  });

  it("every row is complete and uses https", () => {
    for (const r of rows) {
      for (const c of COLUMNS) expect(r[c]?.trim().length, `${r.directory}: ${c} is empty`).toBeGreaterThan(0);
      expect(r.url, r.directory).toMatch(/^https:\/\/[a-z0-9.-]+\.[a-z]{2,}/i);
      expect(r.claim_or_create_url, r.directory).toMatch(/^https:\/\//);
    }
  });

  it("covers the core ecosystems", () => {
    const names = rows.map((r) => (r.directory ?? "").toLowerCase());
    for (const must of ["google business profile", "apple business connect", "bing places", "facebook page", "instagram"]) {
      expect(names.some((n) => n.includes(must)), `missing ${must}`).toBe(true);
    }
    const types = new Set(rows.map((r) => r.type));
    for (const t of ["search-map", "social", "directory", "review-site", "chamber", "council"]) {
      expect(types.has(t), `missing type ${t}`).toBe(true);
    }
  });

  it("states no invented prices", () => {
    for (const r of rows) expect(r.cost, `${r.directory} cost`).not.toMatch(/\$\s?\d/);
  });
});

// ---------------------------------------------------------------------------
describe("marketing/links/link-targets.csv", () => {
  const COLUMNS = ["target", "url", "type", "why_relevant", "angle", "asset_needed", "priority", "source_url"];
  const text = read("marketing/links/link-targets.csv");
  const rows = parseCsvObjects(text);

  it("has exactly the agreed columns, in order", () => {
    expect((text.split(/\r?\n/)[0] ?? "").split(",")).toEqual(COLUMNS);
  });

  it("has 20 or more rows with unique targets and urls", () => {
    expect(rows.length).toBeGreaterThanOrEqual(20);
    expect(new Set(rows.map((r) => r.url)).size).toBe(rows.length);
    expect(new Set(rows.map((r) => r.target)).size).toBe(rows.length);
  });

  it("every row is complete, with a valid priority and https urls", () => {
    for (const r of rows) {
      for (const c of COLUMNS) expect(r[c]?.trim().length, `${r.target}: ${c} is empty`).toBeGreaterThan(0);
      expect(["high", "medium", "low"], r.target).toContain(r.priority);
      expect(r.url, r.target).toMatch(/^https:\/\//);
      expect(r.source_url, r.target).toMatch(/^https:\/\//);
    }
  });

  it("contains no paid-link or scheme types", () => {
    for (const r of rows) {
      expect(r.type, r.target).not.toMatch(/paid|sponsored-post|link-?exchange|pbn|scheme/i);
      expect(`${r.angle} ${r.why_relevant}`.toLowerCase(), r.target).not.toMatch(/buy (a )?link|link exchange|guest post for pay/);
    }
  });

  it("covers the required source families", () => {
    const types = new Set(rows.map((r) => r.type));
    for (const t of ["chamber-directory", "council-event", "university", "local-press", "media-callouts", "trade-press", "partner-cross-link"]) {
      expect(types.has(t), `missing type ${t}`).toBe(true);
    }
  });

  it("digital PR file has five angles, each with the five required parts", () => {
    const md = read("marketing/links/digital-pr-angles.md");
    const sections = md.split(/^## Angle \d+:/m).slice(1);
    expect(sections).toHaveLength(5);
    for (const s of sections) {
      for (const label of ["- Hook:", "- Who it is for:", "- Facts needed to pitch:", "- Facts we can currently support:", "- What Elie must confirm:"]) {
        expect(s, `section missing ${label}`).toContain(label);
      }
    }
    expect(md).toContain("[CONFIRM: what Snow Boss is and sells");
    expect(md).toMatch(/No paid links/);
  });
});

// ---------------------------------------------------------------------------
describe("WordPress JSON-LD artefacts", () => {
  const dir = `${ROOT}marketing/wordpress`;
  const generated = buildLocalBusinessJsonLd(STORES, BASE);

  const extract = (file: string): unknown => {
    const html = readFileSync(`${dir}/${file}`, "utf8");
    const matches = [...html.matchAll(/<script type="application\/ld\+json">\n([\s\S]*?)\n<\/script>/g)];
    expect(matches, `${file} must contain exactly one ld+json script block`).toHaveLength(1);
    return JSON.parse(matches[0]?.[1] ?? "");
  };
  interface Graph {
    "@context": string;
    "@graph": Record<string, unknown>[];
  }
  const graphOf = (file: string): Graph => extract(file) as Graph;
  const bakeriesIn = (g: Graph) => g["@graph"].filter((n) => n["@type"] === "Bakery");

  it("all-stores file parses, has three Bakery nodes and one WebSite, and equals src/lib/seo.ts output", () => {
    const g = graphOf("jsonld-all-stores.html");
    expect(g["@context"]).toBe("https://schema.org");
    expect(bakeriesIn(g)).toHaveLength(3);
    expect(g["@graph"].filter((n) => n["@type"] === "WebSite")).toHaveLength(1);
    expect(g).toEqual(generated);
  });

  it("bakeries-only file parses, has three Bakery nodes and no WebSite", () => {
    const g = graphOf("jsonld-all-stores-bakeries-only.html");
    expect(bakeriesIn(g)).toHaveLength(3);
    expect(g["@graph"].some((n) => n["@type"] === "WebSite")).toBe(false);
    expect(g["@graph"]).toEqual(generated["@graph"].filter((n) => n["@type"] === "Bakery"));
  });

  for (const s of STORES) {
    it(`${s.slug} file parses and carries one Bakery node that matches the store`, () => {
      const g = graphOf(`jsonld-${s.slug}.html`);
      expect(g["@graph"]).toHaveLength(1);
      const b = bakeriesIn(g)[0] as Record<string, unknown> & { address: Record<string, string> };
      expect(b["@id"]).toBe(`${BASE}/#store-${s.slug}`);
      expect(b.name).toBe(`Dough Boss ${s.name}`);
      expect(b.telephone).toBe(s.phone);
      expect(b.address).toMatchObject({
        streetAddress: [s.addressLine1, s.addressLine2].filter(Boolean).join(", "),
        addressLocality: s.suburb,
        addressRegion: s.state,
        postalCode: s.postcode,
        addressCountry: "AU",
      });
    });
  }

  it("makes no unverified claim: no geo, price range, images, ratings or reviews", () => {
    for (const f of ["jsonld-all-stores.html", ...SLUGS.map((s) => `jsonld-${s}.html`)]) {
      const raw = readFileSync(`${dir}/${f}`, "utf8");
      for (const key of ["geo", "priceRange", "image", "aggregateRating", "review", "hasMap"]) {
        expect(raw, `${f} must not contain "${key}"`).not.toContain(`"${key}"`);
      }
    }
  });

  it("every string value in the JSON-LD passes the banned-terms list", () => {
    const strings: string[] = [];
    const walk = (v: unknown): void => {
      if (typeof v === "string") strings.push(v);
      else if (Array.isArray(v)) v.forEach(walk);
      else if (v && typeof v === "object") Object.values(v).forEach(walk);
    };
    walk(graphOf("jsonld-all-stores.html"));
    for (const s of strings.filter((x) => !/^https?:\/\//.test(x) && !x.startsWith("@"))) {
      // "Lebanese" is a cuisine label from the typed data, not a claim; everything else must be clean.
      expectClean(`JSON-LD value "${s}"`, s);
    }
  });

  it("install guide and checklist exist and cover the required steps", () => {
    const guide = read("marketing/wordpress/install-guide.md");
    expect(guide).toContain("Rich Results Test");
    expect(guide).toContain("Roll back");
    expect(guide).toContain("Done when");
    const check = read("marketing/wordpress/seo-plugin-checklist.md");
    expect(check).toMatch(/Yoast/);
    expect(check).toMatch(/Rank Math/);
    expect(check).toContain("noindex");
    expect(check).toContain("Bing Webmaster");
  });
});

// ---------------------------------------------------------------------------
describe("docs/marketing/02-seo-external.md", () => {
  const md = read("docs/marketing/02-seo-external.md");

  it("has both tracks, with owner, effort, expected effect and done-when columns", () => {
    expect(md).toContain("## 4. Track A: start now on the existing WordPress site");
    expect(md).toContain("## 5. Track B: when the new app is live");
    const headers = md.match(/^\| ID \| Task \| Owner \| Effort \(estimate\) \| Expected effect \| Done when \|$/gm) ?? [];
    expect(headers).toHaveLength(2);
  });

  it("every task row in both tables is filled in", () => {
    const rows = md.split("\n").filter((l) => /^\| [AB]\d+ \|/.test(l));
    expect(rows.length).toBeGreaterThanOrEqual(19);
    for (const r of rows) {
      const cells = r.split("|").slice(1, -1).map((c) => c.trim());
      expect(cells, r).toHaveLength(6);
      expect(cells[0], r).toMatch(/^[AB]\d+$/);
      for (const c of cells.slice(1)) expect(c.length, r).toBeGreaterThan(2);
    }
  });

  it("states no search volume, CPC, ranking target or budget", () => {
    expect(md).not.toMatch(/\bCPC of\b|budget of \$|\bsearch volume of \d|\branks? (first|#?1)\b/i);
    expect(md).toContain("No numbers were invented");
  });

  it("keeps the draft-only and tenant-separation statements", () => {
    expect(md).toContain("Nothing in this plan has been launched, submitted, posted, sent or bought");
    expect(md).toContain("No Snow Flow or Slushie Co material");
  });

  it("mentions the interim and final URL approach and the redirect rules", () => {
    expect(md).toContain("B1: Redirect map");
    expect(md).toContain("one for one");
    expect(md).toContain("generally at least one year");
  });
});
