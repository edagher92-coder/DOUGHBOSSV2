import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { describe, expect, it } from "vitest";
import { STORES } from "@/lib/data/catalogue";
import { parseCsvObjects } from "./helpers/csv";

/**
 * Guards for the DRAFT Google Ads build in marketing/google-ads/.
 * They fail loudly when ad copy breaks the claims rule, a limit, the route
 * contract, the paused-launch rule or the negative-keyword safety rule.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const DIR = join(HERE, "..", "..", "marketing", "google-ads");
const read = (name: string): string => readFileSync(join(DIR, name), "utf8");

// ── Contract constants ────────────────────────────────────────────────────

const ROUTE_CONTRACT = new Set([
  "/",
  "/#order",
  "/#minis",
  "/#locations",
  "/catering",
  "/catering/corporate",
  "/catering/office-breakfast",
  "/catering/events",
  "/catering/minis",
  "/locations/revesby",
  "/locations/bankstown",
  "/locations/roselands",
]);
const SITE_HOST = "doughboss.com.au";

const HEADLINE_MAX = 30;
const DESCRIPTION_MAX = 90;

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
  { label: "exclamation mark", re: /!/ },
  { label: "emoji", re: /\p{Extended_Pictographic}/u },
];

function bannedIn(text: string): string[] {
  return BANNED.filter((b) => b.re.test(text)).map((b) => b.label);
}

/** Digits are allowed only where they come straight from typed store data (phones, address numbers, "7 days"). */
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
  t = t.replace(/\b7 days\b/gi, " ");
  return t.match(/\d+/g) ?? [];
}

const pathOf = (url: string): string => {
  const u = new URL(url);
  expect(u.hostname).toBe(SITE_HOST);
  expect(u.search).toBe("");
  return `${u.pathname}${u.hash}`;
};

// ── Data loading ──────────────────────────────────────────────────────────

interface AdGroupJson {
  name: string;
  status: string;
  hold: boolean;
  hold_reason: string | null;
  launch_wave: number;
  final_url: string;
  path1: string;
  path2: string;
  tracking_template_override?: string;
}
interface CampaignJson {
  name: string;
  status: string;
  phase: string;
  tracking_template: string;
  utm_campaign: string;
  keyword_match_types_at_launch: string[];
  broad_match: string;
  networks: { search: boolean; search_partners: boolean; display: boolean };
  location_targeting: { positive_geo_target_type: string; negative_geo_target_type: string; radius_km: string };
  budget: { daily_budget_aud: number | null; inputs_to_confirm: string[] };
  bidding: { strategy: string; max_cpc_aud: string };
  ad_groups: AdGroupJson[];
}
interface CampaignsFile {
  campaigns: CampaignJson[];
}

const campaignsFile = JSON.parse(read("campaigns.json")) as CampaignsFile;
const rsa = parseCsvObjects(read("rsa.csv"));
const keywords = parseCsvObjects(read("keywords.csv"));
const negatives = parseCsvObjects(read("negatives.csv"));

const campaignNames = new Set(campaignsFile.campaigns.map((c) => c.name));
const adGroupToCampaign = new Map<string, string>();
for (const c of campaignsFile.campaigns) for (const g of c.ad_groups) adGroupToCampaign.set(g.name, c.name);

// ── campaigns.json ────────────────────────────────────────────────────────

describe("campaigns.json", () => {
  it("parses and every campaign and ad group is PAUSED", () => {
    expect(campaignsFile.campaigns.length).toBeGreaterThanOrEqual(4);
    for (const c of campaignsFile.campaigns) {
      expect(c.status, c.name).toBe("paused");
      expect(c.ad_groups.length, c.name).toBeGreaterThan(0);
      for (const g of c.ad_groups) expect(g.status, g.name).toBe("paused");
    }
  });

  it("has unique campaign and ad group names", () => {
    expect(campaignNames.size).toBe(campaignsFile.campaigns.length);
    expect(adGroupToCampaign.size).toBe(campaignsFile.campaigns.reduce((n, c) => n + c.ad_groups.length, 0));
  });

  it("runs search only, phrase and exact only, presence-only locations", () => {
    for (const c of campaignsFile.campaigns) {
      expect(c.networks, c.name).toEqual({ search: true, search_partners: false, display: false });
      expect(c.broad_match, c.name).toBe("off");
      expect([...c.keyword_match_types_at_launch].sort(), c.name).toEqual(["exact", "phrase"]);
      expect(c.location_targeting.positive_geo_target_type, c.name).toBe("PRESENCE");
      expect(c.location_targeting.negative_geo_target_type, c.name).toBe("PRESENCE");
    }
  });

  it("invents no budget, radius or CPC (NUMBERS RULE): all are [CONFIRM] gaps", () => {
    for (const c of campaignsFile.campaigns) {
      expect(c.budget.daily_budget_aud, c.name).toBeNull();
      expect(c.budget.inputs_to_confirm.length, c.name).toBeGreaterThan(0);
      for (const i of c.budget.inputs_to_confirm) expect(i).toMatch(/^\[CONFIRM: /);
      expect(c.location_targeting.radius_km, c.name).toMatch(/^\[CONFIRM: radius from delivery area\]$/);
      expect(c.bidding.max_cpc_aud, c.name).toMatch(/^\[CONFIRM: /);
      expect(c.bidding.strategy, c.name).toBe("MANUAL_CPC");
    }
  });

  it("follows the UTM convention in every tracking template", () => {
    const slug = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
    const templates: { label: string; template: string; campaign: string | null }[] = [];
    for (const c of campaignsFile.campaigns) {
      templates.push({ label: c.name, template: c.tracking_template, campaign: c.utm_campaign });
      for (const g of c.ad_groups) {
        if (g.tracking_template_override) templates.push({ label: g.name, template: g.tracking_template_override, campaign: null });
      }
    }
    for (const t of templates) {
      expect(t.template, t.label).toContain("{lpurl}?");
      const params = new URLSearchParams(t.template.split("?")[1] ?? "");
      expect(params.get("utm_source"), t.label).toBe("google");
      expect(params.get("utm_medium"), t.label).toBe("cpc");
      expect(params.get("utm_campaign") ?? "", t.label).toMatch(slug);
      expect(params.get("utm_content") ?? "", t.label).toMatch(slug);
      expect(params.get("utm_term"), t.label).toBe("{keyword}");
      expect(t.template, t.label).toBe(t.template.toLowerCase());
      if (t.campaign) expect(params.get("utm_campaign"), t.label).toBe(t.campaign);
    }
  });

  it("keeps ad group final URLs inside the route contract", () => {
    for (const c of campaignsFile.campaigns) {
      for (const g of c.ad_groups) {
        expect(ROUTE_CONTRACT.has(pathOf(g.final_url)), `${g.name}: ${g.final_url}`).toBe(true);
        expect(g.path1.length, g.name).toBeLessThanOrEqual(15);
        expect(g.path2.length, g.name).toBeLessThanOrEqual(15);
        expect([1, 2, 3]).toContain(g.launch_wave);
        if (g.hold) expect(g.hold_reason, g.name).toBeTruthy();
      }
    }
  });

  it("keeps the Minis campaign in the later phase and on hold", () => {
    const minis = campaignsFile.campaigns.find((c) => c.name.includes("Minis"));
    expect(minis).toBeDefined();
    expect(minis?.phase).toBe("later");
    for (const g of minis?.ad_groups ?? []) expect(g.hold, g.name).toBe(true);
  });

  it("references only ad groups that exist in keywords.csv and rsa.csv, and vice versa", () => {
    const kwGroups = new Set(keywords.map((k) => k.ad_group));
    const rsaGroups = new Set(rsa.map((r) => r.ad_group));
    for (const name of adGroupToCampaign.keys()) {
      expect(kwGroups.has(name ?? ""), `keywords.csv has ${name}`).toBe(true);
      expect(rsaGroups.has(name ?? ""), `rsa.csv has ${name}`).toBe(true);
    }
    for (const g of kwGroups) expect(adGroupToCampaign.has(g ?? ""), `campaigns.json has ${g}`).toBe(true);
    for (const g of rsaGroups) expect(adGroupToCampaign.has(g ?? ""), `campaigns.json has ${g}`).toBe(true);
  });
});

// ── rsa.csv ───────────────────────────────────────────────────────────────

describe("rsa.csv", () => {
  const byGroup = new Map<string, Record<string, string>[]>();
  for (const r of rsa) {
    const list = byGroup.get(r.ad_group ?? "") ?? [];
    list.push(r);
    byGroup.set(r.ad_group ?? "", list);
  }

  it("has the expected header", () => {
    const header = read("rsa.csv").split("\n")[0];
    expect(header).toBe("campaign,ad_group,type,text,pin_position,char_count");
  });

  it("limits headlines to 30 and descriptions to 90, and char_count is the real length", () => {
    for (const r of rsa) {
      const text = r.text ?? "";
      const type = r.type;
      expect(["headline", "description"], `${r.ad_group}: ${text}`).toContain(type);
      expect(Number(r.char_count), `${r.ad_group}: ${text}`).toBe(text.length);
      expect(text.length, `${r.ad_group}: ${text}`).toBeLessThanOrEqual(type === "headline" ? HEADLINE_MAX : DESCRIPTION_MAX);
      expect(text.trim(), text).toBe(text);
    }
  });

  it("has exactly 15 headlines and 4 descriptions per ad group, with no duplicates", () => {
    expect(byGroup.size).toBe(adGroupToCampaign.size);
    for (const [group, rows] of byGroup) {
      const heads = rows.filter((r) => r.type === "headline").map((r) => (r.text ?? "").toLowerCase());
      const descs = rows.filter((r) => r.type === "description").map((r) => (r.text ?? "").toLowerCase());
      expect(heads.length, group).toBe(15);
      expect(descs.length, group).toBe(4);
      expect(new Set(heads).size, `${group}: duplicate headline`).toBe(15);
      expect(new Set(descs).size, `${group}: duplicate description`).toBe(4);
    }
  });

  it("matches each row's campaign to the campaign that owns the ad group", () => {
    for (const r of rsa) expect(adGroupToCampaign.get(r.ad_group ?? ""), r.ad_group).toBe(r.campaign);
  });

  it("pins sparingly: positions 1 to 3 only, at most two pinned headlines and one pinned description per ad group", () => {
    for (const [group, rows] of byGroup) {
      for (const r of rows) expect(["", "1", "2", "3"], `${group}: ${r.text}`).toContain(r.pin_position);
      const pinnedH = rows.filter((r) => r.type === "headline" && r.pin_position !== "");
      const pinnedD = rows.filter((r) => r.type === "description" && r.pin_position !== "");
      expect(pinnedH.length, group).toBeLessThanOrEqual(2);
      expect(pinnedD.length, group).toBeLessThanOrEqual(1);
      for (const r of pinnedD) expect(["1", "2"], group).toContain(r.pin_position);
      for (const r of pinnedH) expect(r.pin_position, group).toBe("1");
    }
  });

  it("contains no banned term, price, delivery promise or unsourced number (claims rule)", () => {
    for (const r of rsa) {
      const text = r.text ?? "";
      expect(bannedIn(text), `${r.ad_group}: ${text}`).toEqual([]);
      expect(digitsOutsideStoreData(text), `${r.ad_group}: ${text}`).toEqual([]);
    }
  });

  it("never mentions a store phone number that is not in the typed store data", () => {
    const known = new Set(STORES.map((s) => s.phoneDisplay));
    for (const r of rsa) {
      const phones = (r.text ?? "").match(/\(0\d\) \d{4} \d{4}|\b04\d\d \d{3} \d{3}\b/g) ?? [];
      for (const p of phones) expect(known.has(p), `${r.ad_group}: ${p}`).toBe(true);
    }
  });
});

// ── keywords.csv ──────────────────────────────────────────────────────────

describe("keywords.csv", () => {
  it("has the expected header and only phrase or exact match", () => {
    expect(read("keywords.csv").split("\n")[0]).toBe("campaign,ad_group,keyword,match_type,final_url,notes");
    for (const k of keywords) expect(["phrase", "exact"], `${k.ad_group}: ${k.keyword}`).toContain(k.match_type);
  });

  it("uses only final URLs inside the route contract, matching the ad group URL", () => {
    const groupUrl = new Map<string, string>();
    for (const c of campaignsFile.campaigns) for (const g of c.ad_groups) groupUrl.set(g.name, g.final_url);
    for (const k of keywords) {
      expect(ROUTE_CONTRACT.has(pathOf(k.final_url ?? "")), `${k.keyword}: ${k.final_url}`).toBe(true);
      expect(k.final_url, `${k.ad_group}: ${k.keyword}`).toBe(groupUrl.get(k.ad_group ?? ""));
      expect(adGroupToCampaign.get(k.ad_group ?? ""), k.ad_group).toBe(k.campaign);
    }
  });

  it("is lower case, free of punctuation Google ignores, and has no duplicate rows", () => {
    const seen = new Set<string>();
    for (const k of keywords) {
      const kw = k.keyword ?? "";
      expect(kw, kw).toBe(kw.toLowerCase());
      expect(kw, kw).not.toMatch(/["\[\]!@%,.;*+?]/);
      expect(kw.trim().split(/\s+/).length, kw).toBeLessThanOrEqual(10);
      const key = `${k.ad_group}|${kw}|${k.match_type}`;
      expect(seen.has(key), `duplicate ${key}`).toBe(false);
      seen.add(key);
    }
  });

  it("gives every ad group between 2 and 14 keyword rows (tight themes)", () => {
    const counts = new Map<string, number>();
    for (const k of keywords) counts.set(k.ad_group ?? "", (counts.get(k.ad_group ?? "") ?? 0) + 1);
    for (const [group, n] of counts) {
      expect(n, group).toBeGreaterThanOrEqual(2);
      expect(n, group).toBeLessThanOrEqual(14);
    }
  });

  it("does not target the parked dietary or halal cluster", () => {
    for (const k of keywords) expect(k.keyword, k.keyword).not.toMatch(/\b(halal|gluten|vegan|vegetarian|coeliac|iftar|eid)\b/);
  });

  it("does not bid on competitor names", () => {
    for (const k of keywords) expect(k.keyword, k.keyword).not.toMatch(/ooshman|el jannah|al aseel|feedwell|sammy/);
  });
});

// ── negatives.csv ─────────────────────────────────────────────────────────

const tokens = (s: string): string[] =>
  s
    .toLowerCase()
    .replace(/['’]/g, "")
    .split(/[^a-z0-9]+/)
    .filter((t) => t.length > 0);

/** Would this negative stop an ad showing for this exact keyword? Mirrors Google's negative match rules. */
function blocks(negative: string, matchType: string, keyword: string): boolean {
  const n = tokens(negative);
  const k = tokens(keyword);
  if (n.length === 0) return false;
  if (matchType === "exact") return n.length === k.length && n.every((t, i) => t === k[i]);
  if (matchType === "phrase") {
    for (let i = 0; i + n.length <= k.length; i++) {
      if (n.every((t, j) => t === k[i + j])) return true;
    }
    return false;
  }
  return n.every((t) => k.includes(t)); // broad negative
}

describe("negatives.csv", () => {
  it("has the expected header, valid levels and match types, and resolvable scopes", () => {
    expect(read("negatives.csv").split("\n")[0]).toBe("level,scope,keyword,match_type,reason");
    for (const n of negatives) {
      expect(["account", "campaign", "ad_group"], n.keyword).toContain(n.level);
      expect(["phrase", "exact", "broad"], n.keyword).toContain(n.match_type);
      expect((n.reason ?? "").length, n.keyword).toBeGreaterThan(5);
      if (n.level === "campaign") expect(campaignNames.has(n.scope ?? ""), `unknown campaign ${n.scope}`).toBe(true);
      if (n.level === "ad_group") expect(adGroupToCampaign.has(n.scope ?? ""), `unknown ad group ${n.scope}`).toBe(true);
      if (n.level === "account") expect(n.scope).toBe("account");
    }
  });

  it("has no duplicate rows", () => {
    const seen = new Set<string>();
    for (const n of negatives) {
      const key = `${n.level}|${n.scope}|${n.keyword}|${n.match_type}`;
      expect(seen.has(key), `duplicate ${key}`).toBe(false);
      seen.add(key);
    }
  });

  it("never blocks a target keyword in its own scope", () => {
    const offenders: string[] = [];
    for (const n of negatives) {
      const inScope = keywords.filter((k) => {
        if (n.level === "account") return true;
        if (n.level === "campaign") return k.campaign === n.scope;
        return k.ad_group === n.scope;
      });
      for (const k of inScope) {
        if (blocks(n.keyword ?? "", n.match_type ?? "", k.keyword ?? "")) {
          offenders.push(`negative "${n.keyword}" (${n.match_type}, ${n.level}: ${n.scope}) blocks target "${k.keyword}" in ${k.ad_group}`);
        }
      }
    }
    expect(offenders).toEqual([]);
  });

  it("keeps the brand campaign free of campaign-level negatives that would hide brand queries", () => {
    const brand = campaignsFile.campaigns.find((c) => c.name.endsWith("| Brand"));
    expect(brand).toBeDefined();
    for (const n of negatives.filter((x) => x.level === "campaign" && x.scope === brand?.name)) {
      expect(n.keyword, "brand negative").not.toMatch(/dough|boss|catering|bankstown|revesby|roselands/);
    }
  });

  it("marks every temporary dietary or halal negative TEMP so it is revisited", () => {
    for (const n of negatives) {
      if (/^(halal|gluten free|coeliac|vegan|vegetarian|dairy free|nut free)$/.test(n.keyword ?? "")) {
        expect(n.reason, n.keyword).toMatch(/^TEMP:/);
      }
    }
  });

  it("the blocking check itself detects a conflict (self-test)", () => {
    expect(blocks("catering", "phrase", "office catering revesby")).toBe(true);
    expect(blocks("office catering", "phrase", "catering office")).toBe(false);
    expect(blocks("catering office", "broad", "office catering")).toBe(true);
    expect(blocks("office catering", "exact", "office catering")).toBe(true);
    expect(blocks("office catering", "exact", "office catering revesby")).toBe(false);
  });
});

// ── extensions.json ───────────────────────────────────────────────────────

interface Sitelink {
  id: string;
  title: string;
  description1: string;
  description2: string;
  final_url: string;
  scope: string[];
  status: string;
}
interface Snippet {
  header: string;
  values: string[];
}
interface CallAsset {
  store: string;
  phone_e164: string;
  phone_display: string;
  schedule: unknown;
  status: string;
  attach_to: string[];
}
interface ExtensionsFile {
  limits: Record<string, number>;
  sitelinks: Sitelink[];
  callouts: { text: string; scope: string }[];
  structured_snippets: Snippet[];
  call_assets: CallAsset[];
  location_assets: { status: string; prerequisites: string[] };
  lead_form: { recommendation: string; reasons: string[]; revisit_when: string };
}
const ext = JSON.parse(read("extensions.json")) as ExtensionsFile;

describe("extensions.json", () => {
  it("keeps sitelinks inside the length limits, route contract and claims rule", () => {
    expect(ext.sitelinks.length).toBeGreaterThanOrEqual(4);
    const ids = new Set<string>();
    for (const s of ext.sitelinks) {
      expect(ids.has(s.id), `duplicate ${s.id}`).toBe(false);
      ids.add(s.id);
      expect(s.title.length, s.title).toBeLessThanOrEqual(25);
      expect(s.description1.length, s.description1).toBeLessThanOrEqual(35);
      expect(s.description2.length, s.description2).toBeLessThanOrEqual(35);
      expect(s.description1.length, s.id).toBeGreaterThan(0);
      expect(s.description2.length, s.id).toBeGreaterThan(0);
      expect(ROUTE_CONTRACT.has(pathOf(s.final_url)), s.final_url).toBe(true);
      expect(s.status, s.id).toBe("paused");
      for (const scope of s.scope) expect(campaignNames.has(scope), `${s.id}: ${scope}`).toBe(true);
      for (const text of [s.title, s.description1, s.description2]) {
        expect(bannedIn(text), text).toEqual([]);
        expect(digitsOutsideStoreData(text), text).toEqual([]);
      }
    }
  });

  it("keeps callouts at 25 characters or fewer and claims-safe", () => {
    expect(ext.callouts.length).toBeGreaterThanOrEqual(4);
    const seen = new Set<string>();
    for (const c of ext.callouts) {
      expect(c.text.length, c.text).toBeLessThanOrEqual(25);
      expect(seen.has(c.text.toLowerCase()), `duplicate ${c.text}`).toBe(false);
      seen.add(c.text.toLowerCase());
      expect(bannedIn(c.text), c.text).toEqual([]);
      expect(digitsOutsideStoreData(c.text), c.text).toEqual([]);
    }
  });

  it("keeps structured snippet values within limits", () => {
    for (const s of ext.structured_snippets) {
      expect(s.values.length, s.header).toBeGreaterThanOrEqual(3);
      expect(s.values.length, s.header).toBeLessThanOrEqual(10);
      for (const v of s.values) {
        expect(v.length, v).toBeLessThanOrEqual(25);
        expect(bannedIn(v), v).toEqual([]);
      }
    }
  });

  it("uses the stores' real phone numbers and opening hours from typed store data", () => {
    const DAY: Record<string, number> = { sun: 0, mon: 1, tue: 2, wed: 3, thu: 4, fri: 5, sat: 6 };
    const hhmm = (min: number): string => `${String(Math.floor(min / 60)).padStart(2, "0")}:${String(min % 60).padStart(2, "0")}`;
    const storeCalls = ext.call_assets.filter((c) => STORES.some((s) => s.slug === c.store));
    expect(storeCalls.map((c) => c.store).sort()).toEqual(STORES.map((s) => s.slug).sort());
    for (const call of storeCalls) {
      const store = STORES.find((s) => s.slug === call.store);
      expect(store, call.store).toBeDefined();
      expect(call.phone_e164, call.store).toBe(store?.phone);
      expect(call.phone_display, call.store).toBe(store?.phoneDisplay);
      const sched = call.schedule as { days: string[]; start: string; end: string };
      expect(sched.days.map((d) => DAY[d]).sort(), call.store).toEqual((store?.hours ?? []).map((h) => h.dayOfWeek).sort());
      for (const h of store?.hours ?? []) {
        expect(hhmm(h.opensMin), call.store).toBe(sched.start);
        expect(hhmm(h.closesMin), call.store).toBe(sched.end);
      }
      expect(call.status).toBe("paused");
    }
  });

  it("does not attach an unconfirmed catering number to anything live", () => {
    const desk = ext.call_assets.find((c) => c.store === "catering-desk");
    expect(desk?.status).toBe("not-created");
    expect(desk?.phone_e164).toMatch(/^\[CONFIRM: /);
  });

  it("sitelink phone lines match the typed store phone numbers", () => {
    for (const s of ext.sitelinks) {
      for (const line of [s.description1, s.description2]) {
        const phones = line.match(/\(0\d\) \d{4} \d{4}|\b04\d\d \d{3} \d{3}\b/g) ?? [];
        for (const p of phones) expect(STORES.some((st) => st.phoneDisplay === p), `${s.id}: ${p}`).toBe(true);
      }
    }
  });

  it("recommends against a lead form at launch, with reasons, and leaves location assets unlinked", () => {
    expect(ext.lead_form.recommendation).toBe("do_not_use_at_launch");
    expect(ext.lead_form.reasons.length).toBeGreaterThanOrEqual(3);
    expect(ext.lead_form.revisit_when.length).toBeGreaterThan(10);
    expect(ext.location_assets.status).toBe("not-linked");
    expect(ext.location_assets.prerequisites.every((p) => p.startsWith("[CONFIRM: "))).toBe(true);
  });
});
