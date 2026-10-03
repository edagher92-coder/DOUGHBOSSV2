import { describe, expect, it, afterEach } from "vitest";
import { STORES } from "@/lib/data/catalogue";
import { buildLocalBusinessJsonLd, siteUrl, siteMetadata } from "@/lib/seo";
import { serialiseJsonLd } from "@/components/seo/JsonLd";
import sitemap from "@/app/sitemap";
import robots from "@/app/robots";
import manifest from "@/app/manifest";

const BASE = "https://doughboss.com.au";
type Node = Record<string, unknown> & { openingHoursSpecification: Array<{ dayOfWeek: string[]; opens: string; closes: string }> };
const bakeries = (g = buildLocalBusinessJsonLd(STORES, BASE)) => g["@graph"].filter((n) => n["@type"] === "Bakery") as Node[];
const bySlug = (slug: string) => bakeries().find((b) => b["@id"] === `${BASE}/#store-${slug}`)!;

describe("siteUrl", () => {
  const original = process.env.NEXT_PUBLIC_SITE_URL;
  afterEach(() => {
    if (original === undefined) delete process.env.NEXT_PUBLIC_SITE_URL;
    else process.env.NEXT_PUBLIC_SITE_URL = original;
  });
  it("defaults and strips trailing slashes", () => {
    delete process.env.NEXT_PUBLIC_SITE_URL;
    expect(siteUrl()).toBe(BASE);
    process.env.NEXT_PUBLIC_SITE_URL = "https://example.test//";
    expect(siteUrl()).toBe("https://example.test");
  });
});

describe("local business JSON-LD", () => {
  it("has three Bakery nodes plus a WebSite, matching store data", () => {
    const g = buildLocalBusinessJsonLd(STORES, BASE);
    expect(bakeries(g)).toHaveLength(3);
    expect(g["@graph"].some((n) => n["@type"] === "WebSite")).toBe(true);
    for (const s of STORES) {
      const b = bySlug(s.slug);
      expect(b.telephone).toBe(s.phone);
      expect(b.name).toBe(`Dough Boss ${s.name}`);
      expect(b.address).toMatchObject({
        streetAddress: [s.addressLine1, s.addressLine2].filter(Boolean).join(", "),
        addressLocality: s.suburb,
        addressRegion: s.state,
        postalCode: s.postcode,
        addressCountry: "AU",
      });
      expect(b.servesCuisine).toBe("Lebanese");
    }
    expect(bySlug("revesby").telephone).toBe("+61297742286");
    expect(bySlug("bankstown").telephone).toBe("+61287646783");
    expect(bySlug("roselands").telephone).toBe("+61466353133");
  });

  it("derives hours only from store.hours", () => {
    expect(bySlug("bankstown").openingHoursSpecification).toEqual([
      expect.objectContaining({ dayOfWeek: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"], opens: "07:00", closes: "14:00" }),
    ]);
    const rev = bySlug("revesby").openingHoursSpecification;
    expect(rev).toHaveLength(1);
    expect(rev[0]).toMatchObject({ opens: "06:30", closes: "14:30" });
    expect(rev[0]?.dayOfWeek).toHaveLength(7);
    const ros = bySlug("roselands").openingHoursSpecification;
    expect(ros).toHaveLength(1);
    expect(ros[0]).toMatchObject({ opens: "08:00", closes: "15:00" });
  });

  it("never emits forbidden keys", () => {
    const json = JSON.stringify(buildLocalBusinessJsonLd(STORES, BASE));
    for (const key of ["priceRange", "geo", "aggregateRating", "review", "image", "menu"]) {
      expect(json).not.toContain(`"${key}"`);
    }
  });

  it("serialisation cannot break out of the script element", () => {
    const evil = { ...STORES[0]!, name: "</script><script>alert(1)</script>\u2028" };
    const out = serialiseJsonLd(buildLocalBusinessJsonLd([evil], BASE));
    expect(out).not.toContain("</script");
    expect(out).not.toMatch(new RegExp("[<>&\\u2028\\u2029]"));
    expect(JSON.parse(out)["@graph"][1].name).toContain("</script><script>alert(1)</script>");
  });
});

describe("metadata routes", () => {
  it("siteMetadata has canonical, template and icon", () => {
    expect(siteMetadata.alternates?.canonical).toBe("/");
    expect(siteMetadata.metadataBase?.href).toMatch(/^https:\/\//);
    expect(siteMetadata.icons).toBeDefined();
  });
  it("sitemap lists home only", () => {
    const s = sitemap();
    expect(s).toHaveLength(1);
    expect(s[0]?.url).toBe(`${siteUrl()}/`);
  });
  it("robots allows all, blocks api/order, points at sitemap", () => {
    const r = robots();
    expect(r.rules).toEqual([{ userAgent: "*", allow: "/", disallow: ["/api/", "/order/"] }]);
    expect(r.sitemap).toBe(`${siteUrl()}/sitemap.xml`);
  });
  it("manifest shape", () => {
    expect(manifest()).toMatchObject({
      name: "Dough Boss",
      short_name: "Dough Boss",
      theme_color: "#070707",
      background_color: "#070707",
      display: "standalone",
    });
  });
});
