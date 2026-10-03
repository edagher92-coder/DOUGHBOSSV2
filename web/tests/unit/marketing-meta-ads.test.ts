import { existsSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { describe, expect, it } from "vitest";
import { STORES } from "@/lib/data/catalogue";
import { parseCsvObjects } from "./helpers/csv";

/**
 * Guards for the DRAFT Meta (Facebook and Instagram) build in marketing/meta/
 * and docs/marketing/03b-meta-ads.md. They fail loudly when ad copy breaks the
 * claims rule or a Meta limit, a URL leaves the route contract or loses its
 * UTMs, anything is not PAUSED, a budget or radius is invented, or another
 * business's ad identifiers leak into this build.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, "..", "..");
const META_DIR = join(ROOT, "marketing", "meta");
const DOC_PATH = join(ROOT, "docs", "marketing", "03b-meta-ads.md");
const read = (name: string): string => readFileSync(join(META_DIR, name), "utf8");

// ── Contract constants ────────────────────────────────────────────────────

const ROUTE_CONTRACT = new Set([
  "/",
  "/#order",
  "/#locations",
  "/catering",
  "/catering/corporate",
  "/catering/office-breakfast",
  "/catering/events",
  "/locations/revesby",
  "/locations/bankstown",
  "/locations/roselands",
]);
const SITE_HOST = "doughboss.com.au";
const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** Meta limits used by this build. Headline is the brief's hard cap; Meta's feed guide recommends shorter. */
const HEADLINE_MAX = 40;
const DESCRIPTION_MAX = 30;
const PRIMARY_TEXT_MAX = 300;
/** The opening message must land before Meta truncates the feed text behind "See more". */
const FRONT_LOAD_CHARS = 125;

/**
 * CTA button labels as Ads Manager shows them. Verified against the Marketing API
 * call_to_action_type list (GET_QUOTE, LEARN_MORE, CONTACT_US, SIGN_UP) on 2026-10-02.
 * Which of them is offered still depends on the objective, so check at staging.
 */
const VALID_CTAS = new Set(["Learn more", "Get quote", "Contact us", "Sign up"]);

/** Terms that must never appear in any customer-facing ad text. */
const BANNED: { label: string; re: RegExp }[] = [
  { label: "best", re: /\bbest\b/i },
  { label: "number one", re: /\bnumber\s*one\b/i },
  { label: "#1", re: /#\s*1\b/ },
  { label: "cheapest", re: /\bcheapest\b/i },
  { label: "cheap", re: /\bcheap\b/i },
  { label: "guaranteed", re: /\bguarantee[ds]?\b/i },
  { label: "award", re: /\bawards?\b|\baward-winning\b/i },
  { label: "famous", re: /\bfamous\b/i },
  { label: "leading", re: /\bleading\b|\bleader\b/i },
  { label: "premium", re: /\bpremium\b/i },
  { label: "halal", re: /\bhalal\b/i },
  { label: "free delivery", re: /\bfree\s+delivery\b/i },
  { label: "free", re: /\bfree\b/i },
  { label: "discount", re: /\bdiscounts?\b/i },
  { label: "% off", re: /%\s*off\b|\d\s*%/i },
  { label: "unlimited", re: /\bunlimited\b/i },
  { label: "authentic", re: /\bauthentic\b/i },
  { label: "fresh", re: /\bfresh(ly)?\b/i },
  { label: "price", re: /\$|\bfrom\s+\d|\bprices?\b|\bcost\b/i },
  { label: "delivery promise", re: /\bdeliver(y|ed|s|ing)?\b/i },
  { label: "speed or lead-time promise", re: /\b(same[- ]day|next[- ]day|within|instant|fast|quick(ly)?|express|24\s*hours?|asap)\b/i },
  { label: "rating or review claim", re: /\b(rated|reviews?|stars?|five-star|top-rated)\b/i },
  { label: "capacity or minimum", re: /\b(minimum|min\.|up to|any size|any number)\b/i },
  { label: "urgency", re: /\b(hurry|last chance|limited time|don'?t miss|today only)\b/i },
  { label: "exclamation mark", re: /!/ },
  { label: "emoji", re: /\p{Extended_Pictographic}/u },
  { label: "placeholder", re: /\[CONFIRM|\[VERIFY|TODO|TBC|lorem ipsum/i },
];

function bannedIn(text: string): string[] {
  return BANNED.filter((b) => b.re.test(text)).map((b) => b.label);
}

/** Digits are allowed only where they come straight from typed store data (phones, address numbers). */
function digitsOutsideStoreData(text: string): string[] {
  let t = text;
  for (const s of STORES) t = t.split(s.phoneDisplay).join(" ");
  const addressTokens = new Set<string>();
  for (const s of STORES) {
    for (const tok of s.addressLine1.split(/\s+/)) {
      const clean = tok.replace(/[^A-Za-z0-9/]/g, "");
      if (/\d/.test(clean)) addressTokens.add(clean);
    }
  }
  t = t
    .split(/\s+/)
    .map((tok) => tok.replace(/[^A-Za-z0-9/]/g, ""))
    .filter((clean) => !addressTokens.has(clean))
    .join(" ");
  return t.match(/\d+/g) ?? [];
}

// ── Data loading ──────────────────────────────────────────────────────────

interface AdJson {
  name: string;
  status: string;
}
interface AdSetJson {
  name: string;
  status: string;
  hold: boolean;
  hold_reason: string | null;
  launch_wave: number;
  utm_segment: string;
  utm_area: string;
  optimisation: { goal: string; conversion_event: string; conversion_location: string };
  audience: { mode: string; lookalikes: string };
  location_targeting: { radius_km: string; location_types: string };
  ads: AdJson[];
}
interface CampaignJson {
  name: string;
  status: string;
  phase: string;
  objective: string;
  special_ad_categories: string[];
  budget: { daily_budget_aud: number | null; inputs_to_confirm: string[]; formula: string };
  bidding: { strategy: string; cost_cap_aud: string };
  launch_gate: string[];
  ad_sets: AdSetJson[];
}
interface CampaignsFile {
  _status: string;
  account: Record<string, string>;
  campaigns: CampaignJson[];
}

const campaignsRaw = read("campaigns.json");
const campaignsFile = JSON.parse(campaignsRaw) as CampaignsFile;
const copyRaw = read("copy.csv");
const copy = parseCsvObjects(copyRaw);

const adSetToCampaign = new Map<string, string>();
const adToAdSet = new Map<string, string>();
for (const c of campaignsFile.campaigns) {
  for (const s of c.ad_sets) {
    adSetToCampaign.set(s.name, c.name);
    for (const a of s.ads) adToAdSet.set(a.name, s.name);
  }
}
const adSetByName = new Map<string, AdSetJson>();
for (const c of campaignsFile.campaigns) for (const s of c.ad_sets) adSetByName.set(s.name, s);

const parsedUrl = (url: string): URL => new URL(url);
const pathOf = (url: string): string => {
  const u = parsedUrl(url);
  return `${u.pathname}${u.hash}`;
};
const angleOf = (notes: string): string => /^angle=([a-z0-9-]+);/.exec(notes)?.[1] ?? "";

// ── campaigns.json ────────────────────────────────────────────────────────

describe("campaigns.json", () => {
  it("parses and every campaign, ad set and ad is PAUSED", () => {
    expect(campaignsFile._status).toMatch(/^DRAFT\./);
    expect(campaignsFile._status).toMatch(/PAUSED/);
    expect(campaignsFile.campaigns.length).toBeGreaterThanOrEqual(3);
    for (const c of campaignsFile.campaigns) {
      expect(c.status, c.name).toBe("paused");
      expect(c.ad_sets.length, c.name).toBeGreaterThan(0);
      for (const s of c.ad_sets) {
        expect(s.status, s.name).toBe("paused");
        expect(s.ads.length, s.name).toBeGreaterThan(0);
        for (const a of s.ads) expect(a.status, a.name).toBe("paused");
      }
    }
  });

  it("has unique campaign, ad set and ad names", () => {
    const campaignNames = campaignsFile.campaigns.map((c) => c.name);
    expect(new Set(campaignNames).size).toBe(campaignNames.length);
    const adSetCount = campaignsFile.campaigns.reduce((n, c) => n + c.ad_sets.length, 0);
    expect(adSetToCampaign.size).toBe(adSetCount);
    const adCount = campaignsFile.campaigns.reduce((n, c) => n + c.ad_sets.reduce((m, s) => m + s.ads.length, 0), 0);
    expect(adToAdSet.size).toBe(adCount);
  });

  it("optimises for the Lead event on the website, in the Leads objective, outside any special ad category", () => {
    for (const c of campaignsFile.campaigns) {
      expect(c.objective, c.name).toBe("OUTCOME_LEADS");
      expect(c.special_ad_categories, c.name).toEqual([]);
      for (const s of c.ad_sets) {
        expect(s.optimisation.conversion_event, s.name).toBe("Lead");
        expect(s.optimisation.conversion_location, s.name).toBe("WEBSITE");
      }
    }
  });

  it("invents no budget, radius or cost cap (NUMBERS RULE): all are [CONFIRM] gaps", () => {
    for (const c of campaignsFile.campaigns) {
      expect(c.budget.daily_budget_aud, c.name).toBeNull();
      expect(c.budget.formula, c.name).toMatch(/target_cost_per_lead/);
      expect(c.budget.inputs_to_confirm.length, c.name).toBeGreaterThan(0);
      for (const i of c.budget.inputs_to_confirm) expect(i).toMatch(/^\[CONFIRM: /);
      expect(c.bidding.cost_cap_aud, c.name).toMatch(/^\[CONFIRM/);
      expect(c.launch_gate.length, c.name).toBeGreaterThan(0);
      for (const s of c.ad_sets) {
        expect(s.location_targeting.radius_km, s.name).toMatch(/^\[CONFIRM: radius\]$/);
        expect(s.location_targeting.location_types, s.name).toMatch(/^\[CONFIRM/);
      }
    }
  });

  it("starts broad: Advantage+ audience or a consented custom audience, no lookalikes at launch", () => {
    for (const c of campaignsFile.campaigns) {
      for (const s of c.ad_sets) {
        expect(["advantage_plus_audience", "custom_audience"], s.name).toContain(s.audience.mode);
        expect(s.audience.lookalikes, s.name).toMatch(/^none/);
      }
    }
  });

  it("keeps the generic coming-soon list and warm retargeting in the later phase and on hold", () => {
    const soon = campaignsFile.campaigns.find((c) => c.name.includes("Coming Soon"));
    const warm = campaignsFile.campaigns.find((c) => c.name.includes("Warm Retarget"));
    for (const c of [soon, warm]) {
      expect(c).toBeDefined();
      expect(c?.phase).toBe("later");
      for (const s of c?.ad_sets ?? []) {
        expect(s.hold, s.name).toBe(true);
        expect(s.hold_reason, s.name).toBeTruthy();
      }
    }
  });

  it("explains every held ad set and uses launch waves 1 to 3", () => {
    for (const c of campaignsFile.campaigns) {
      for (const s of c.ad_sets) {
        expect([1, 2, 3], s.name).toContain(s.launch_wave);
        if (s.hold) expect(s.hold_reason, s.name).toBeTruthy();
      }
    }
  });

  it("names no real account, page or dataset ID: those are [CONFIRM] gaps for Dough Boss's own assets", () => {
    for (const key of ["ad_account_id", "facebook_page", "dataset_pixel_id", "legal_entity"]) {
      expect(campaignsFile.account[key], key).toMatch(/^\[CONFIRM: /);
    }
    expect(campaignsRaw).not.toMatch(/\bact_\d+/);
  });
});

// ── copy.csv ──────────────────────────────────────────────────────────────

describe("copy.csv", () => {
  it("has the expected header", () => {
    expect(copyRaw.split("\n")[0]).toBe("campaign,ad_set,ad,primary_text,headline,description,cta,final_url,notes");
  });

  it("has at least 12 distinct ads, with unique names and unique utm_content", () => {
    expect(copy.length).toBeGreaterThanOrEqual(12);
    expect(new Set(copy.map((r) => r.ad)).size).toBe(copy.length);
    const contents = copy.map((r) => parsedUrl(r.final_url ?? "").searchParams.get("utm_content"));
    expect(new Set(contents).size).toBe(copy.length);
    expect(new Set(copy.map((r) => r.primary_text)).size).toBe(copy.length);
    expect(new Set(copy.map((r) => r.headline)).size).toBeGreaterThanOrEqual(8);
  });

  it("covers at least 3 distinct angles, including corporate, events and the generic coming-soon list", () => {
    const angles = new Set(copy.map((r) => angleOf(r.notes ?? "")));
    expect(angles.has("")).toBe(false);
    expect(angles.size).toBeGreaterThanOrEqual(3);
    const campaigns = new Set(copy.map((r) => r.campaign));
    for (const needle of ["Corporate", "Events", "Coming Soon"]) {
      expect([...campaigns].some((c) => c?.includes(needle)), needle).toBe(true);
    }
    const all = copy.map((r) => r.ad ?? "").join("|");
    expect(all).toMatch(/office-breakfast/);
    expect(all).toMatch(/team-lunch/);
    expect(all).toMatch(/coming-soon/);
  });

  it("limits headline to 40, description to 30 and primary text to 300", () => {
    for (const r of copy) {
      const label = r.ad ?? "";
      expect((r.headline ?? "").length, `${label} headline`).toBeGreaterThan(0);
      expect((r.headline ?? "").length, `${label} headline`).toBeLessThanOrEqual(HEADLINE_MAX);
      expect((r.description ?? "").length, `${label} description`).toBeGreaterThan(0);
      expect((r.description ?? "").length, `${label} description`).toBeLessThanOrEqual(DESCRIPTION_MAX);
      expect((r.primary_text ?? "").length, `${label} primary_text`).toBeLessThanOrEqual(PRIMARY_TEXT_MAX);
      for (const f of [r.primary_text, r.headline, r.description] as const) expect((f ?? "").trim(), label).toBe(f);
    }
  });

  it("front-loads: the opening sentence completes inside the first 125 characters", () => {
    for (const r of copy) {
      const head = (r.primary_text ?? "").slice(0, FRONT_LOAD_CHARS);
      expect(head, r.ad).toMatch(/[.?]/);
      // The first sentence is not a stub: it carries a message of its own.
      const firstSentence = /^[^.?]+[.?]/.exec(r.primary_text ?? "")?.[0] ?? "";
      expect(firstSentence.length, r.ad).toBeGreaterThanOrEqual(20);
      expect(firstSentence.length, r.ad).toBeLessThanOrEqual(FRONT_LOAD_CHARS);
    }
  });

  it("uses only valid Meta call-to-action buttons", () => {
    for (const r of copy) expect(VALID_CTAS.has(r.cta ?? ""), `${r.ad}: ${r.cta}`).toBe(true);
    expect(new Set(copy.map((r) => r.cta)).size).toBeGreaterThanOrEqual(2);
  });

  it("contains no banned term, price, delivery promise or unsourced number (claims rule)", () => {
    for (const r of copy) {
      for (const [col, text] of [
        ["primary_text", r.primary_text ?? ""],
        ["headline", r.headline ?? ""],
        ["description", r.description ?? ""],
      ] as const) {
        expect(bannedIn(text), `${r.ad} ${col}: ${text}`).toEqual([]);
        expect(digitsOutsideStoreData(text), `${r.ad} ${col}: ${text}`).toEqual([]);
      }
    }
  });

  it("never mentions a phone number that is not in the typed store data", () => {
    const known = new Set(STORES.map((s) => s.phoneDisplay));
    for (const r of copy) {
      const text = `${r.primary_text} ${r.headline} ${r.description}`;
      const phones = text.match(/\(0\d\) \d{4} \d{4}|\b04\d\d \d{3} \d{3}\b/g) ?? [];
      for (const p of phones) expect(known.has(p), `${r.ad}: ${p}`).toBe(true);
    }
  });

  it("never names a store address that differs from the typed store data", () => {
    const addresses = new Map(STORES.map((s) => [s.name.split(" ")[0] ?? "", s.addressLine1]));
    for (const r of copy) {
      const text = r.primary_text ?? "";
      if (/12\/25/.test(text)) expect(text).toContain(addresses.get("Revesby"));
      if (/462/.test(text)) expect(text).toContain(addresses.get("Bankstown"));
    }
  });

  it("is addressed to adults: no copy speaks to children", () => {
    for (const r of copy) {
      const text = `${r.primary_text} ${r.headline}`;
      expect(text, r.ad).not.toMatch(/\b(kids?|children|little ones|treat yourself)\b/i);
    }
  });

  it("keeps the coming-soon ad generic: exactly one, with no product, size, dietary, ingredient, date or location claim (teaser-direction.md)", () => {
    const soon = copy.filter((x) => /coming soon/i.test(x.campaign ?? ""));
    expect(soon).toHaveLength(1);
    const r = soon[0];
    expect(r?.headline).toBe("Something exciting is coming");
    const text = `${r?.primary_text} ${r?.headline} ${r?.description}`;
    expect(text).not.toMatch(
      /\b(minis?|mini pizzas?|bites?|packs?|pizzas?|manoush|manakish|man'?oushe|pies?|platters?|trays?|menu|vegan|vegetarian|gluten|dairy|nut|halal|kosher|organic|ingredients?|opening|launch(es|ing)?|date|stores?|suburbs?|revesby|bankstown|roselands|catering|order(ing)?)\b/i,
    );
    expect(r?.cta).toBe("Sign up");
    // Consent is handled on the landing page, never promised or implied inside the ad.
    expect(text).not.toMatch(/\b(subscribe|newsletter|marketing|emails?|sms|text messages?)\b/i);
  });

  it("carries a lower-case, hyphenated UTM set on a route-contract URL on doughboss.com.au", () => {
    for (const r of copy) {
      const url = r.final_url ?? "";
      const u = parsedUrl(url);
      expect(u.protocol, r.ad).toBe("https:");
      expect(u.hostname, r.ad).toBe(SITE_HOST);
      expect(ROUTE_CONTRACT.has(pathOf(url)), `${r.ad}: ${url}`).toBe(true);
      expect(url, r.ad).toBe(url.toLowerCase());
      expect(u.searchParams.get("utm_source"), r.ad).toBe("meta");
      expect(u.searchParams.get("utm_medium"), r.ad).toBe("paid-social");
      expect(u.searchParams.get("utm_campaign") ?? "", r.ad).toMatch(SLUG);
      expect(u.searchParams.get("utm_content") ?? "", r.ad).toMatch(SLUG);
      // No stray parameters: exactly the four UTMs.
      expect([...u.searchParams.keys()].sort(), r.ad).toEqual(["utm_campaign", "utm_content", "utm_medium", "utm_source"]);
    }
  });

  it("follows the <segment>-<offer>-<area> utm_campaign convention", () => {
    const AREAS = ["bankstown", "revesby", "roselands", "all-stores"];
    for (const r of copy) {
      const campaign = parsedUrl(r.final_url ?? "").searchParams.get("utm_campaign") ?? "";
      expect(AREAS.some((a) => campaign.endsWith(`-${a}`)), `${r.ad}: ${campaign}`).toBe(true);
      expect(campaign.split("-").length, campaign).toBeGreaterThanOrEqual(3);
    }
  });
});

// ── campaigns.json and copy.csv agree ─────────────────────────────────────

describe("campaigns.json and copy.csv agree", () => {
  it("lists exactly the same ads, in the same ad sets and campaigns", () => {
    expect(new Set(copy.map((r) => r.ad))).toEqual(new Set(adToAdSet.keys()));
    for (const r of copy) {
      expect(adToAdSet.get(r.ad ?? ""), r.ad).toBe(r.ad_set);
      expect(adSetToCampaign.get(r.ad_set ?? ""), r.ad).toBe(r.campaign);
    }
  });

  it("gives every ad a utm_campaign that starts with its ad set's segment and ends with its area", () => {
    for (const r of copy) {
      const set = adSetByName.get(r.ad_set ?? "");
      const utm = parsedUrl(r.final_url ?? "").searchParams.get("utm_campaign") ?? "";
      expect(set, r.ad).toBeDefined();
      expect(set?.utm_segment, r.ad).toMatch(SLUG);
      expect(utm.startsWith(`${set?.utm_segment}-`), `${r.ad}: ${utm}`).toBe(true);
      expect(utm.endsWith(`-${set?.utm_area}`), `${r.ad}: ${utm}`).toBe(true);
    }
  });

  it("holds every coming-soon and warm-retarget ad, and keeps held ad sets out of wave 1", () => {
    for (const s of adSetByName.values()) if (s.hold) expect(s.launch_wave, s.name).toBeGreaterThanOrEqual(2);
    for (const r of copy.filter((x) => /coming soon|warm/i.test(x.campaign ?? ""))) {
      expect(adSetByName.get(r.ad_set ?? "")?.hold, r.ad).toBe(true);
    }
  });
});

// ── Separation, secrets and the internal documents ────────────────────────

const OWNED_TEXT_FILES = [
  join(META_DIR, "campaigns.json"),
  join(META_DIR, "copy.csv"),
  join(META_DIR, "audiences.md"),
  join(META_DIR, "creative-brief.md"),
  join(META_DIR, "tracking.md"),
  DOC_PATH,
];

describe("Meta build documents", () => {
  it("all owned files exist and are not empty", () => {
    for (const p of OWNED_TEXT_FILES) {
      expect(existsSync(p), p).toBe(true);
      expect(readFileSync(p, "utf8").trim().length, p).toBeGreaterThan(200);
    }
  });

  it("references no other business's ad identifiers, and contains no token or ad account ID", () => {
    for (const p of OWNED_TEXT_FILES) {
      const text = readFileSync(p, "utf8");
      expect(text, p).not.toMatch(/snow\s*flow|snowflow|slushie|slushy|meta_token|META_ADS_TOKEN/i);
      expect(text, p).not.toMatch(/\bact_\d{6,}/);
      expect(text, p).not.toMatch(/\bEAA[A-Za-z0-9]{20,}/);
      expect(text, p).not.toMatch(/access_token=[A-Za-z0-9]{10,}/);
    }
  });

  it("names no unannounced product anywhere in the Meta build (teaser-direction.md)", () => {
    for (const p of OWNED_TEXT_FILES) {
      const text = readFileSync(p, "utf8");
      expect(text, p).not.toMatch(/\bminis?\b|\bmini[- ]pizzas?\b|party (minis|bites)|bites? packs?/i);
    }
  });

  it("states no invented money figure (NUMBERS RULE): no dollar amounts in plans or briefs", () => {
    for (const p of OWNED_TEXT_FILES) {
      expect(readFileSync(p, "utf8"), p).not.toMatch(/\$\s?\d/);
    }
  });

  it("uses no emoji and no exclamation marks in the plan documents", () => {
    for (const p of OWNED_TEXT_FILES.filter((x) => x.endsWith(".md"))) {
      const text = readFileSync(p, "utf8");
      expect(text, p).not.toMatch(/\p{Extended_Pictographic}/u);
      // Allow "!" only inside code (negation such as !==) by checking prose lines.
      const prose = text.split("\n").filter((l) => !l.trim().startsWith("|") && !/[`{}]/.test(l));
      for (const line of prose) expect(line, p).not.toMatch(/!/);
    }
  });

  it("the plan states the PAUSED rule, the DRAFT-only rule and the claims rule, and cites Meta docs with dates", () => {
    const doc = readFileSync(DOC_PATH, "utf8");
    expect(doc).toMatch(/PAUSED/);
    expect(doc).toMatch(/DRAFT/);
    expect(doc).toMatch(/claims rule/i);
    expect(doc).toMatch(/https:\/\/developers\.facebook\.com\/docs\/marketing-api\/conversions-api\/deduplicate-pixel-and-server-events/);
    expect(doc).toMatch(/retrieved 2026-10-02/);
    for (const heading of [
      "Objective",
      "Campaign structure",
      "Geographic targeting",
      "Audience",
      "Frequency",
      "Creative testing",
      "Budget",
      "Weekly",
      "Diagnostics",
    ]) {
      expect(doc, heading).toContain(heading);
    }
  });

  it("tracking.md keeps event names and the event_id approach aligned with events.ts", () => {
    const tracking = readFileSync(join(META_DIR, "tracking.md"), "utf8");
    const events = readFileSync(join(ROOT, "src", "lib", "analytics", "events.ts"), "utf8");
    for (const name of ["generate_lead", "click_to_call", "get_directions", "begin_checkout", "order_placed"]) {
      expect(events, `events.ts defines ${name}`).toContain(name);
      expect(tracking, `tracking.md maps ${name}`).toContain(name);
    }
    expect(tracking).toContain("event_id");
    expect(tracking).toContain("Test Events");
    expect(tracking).toMatch(/Purchase/);
  });

  it("creative-brief.md says the AI hero images are illustrative and not photographs of the product", () => {
    const brief = readFileSync(join(META_DIR, "creative-brief.md"), "utf8");
    expect(brief).toMatch(/ILLUSTRATIVE/);
    expect(brief).toMatch(/must not be used/i);
  });
});
