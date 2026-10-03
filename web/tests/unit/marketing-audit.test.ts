/**
 * Cross-pack guards from the marketing audit (docs/marketing/00-marketing-audit.md).
 *
 * Each pack (SEO, Google Ads, Meta, outreach) has its own test file. These checks look
 * across all of them at once, because the audit found defects that no single pack test
 * could see: a stale count in one file, an event name that src removed, an internal
 * marker inside a customer-facing block, an affiliation claim in one headline, and
 * customer copy that must stay free of the unannounced product (teaser-direction.md).
 */
import { readFileSync, readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { describe, expect, it } from "vitest";
import { STORES } from "@/lib/data/catalogue";
import { EVENT_NAMES } from "@/lib/analytics/events";
import { parseCsvObjects } from "./helpers/csv";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, "..", "..");
const read = (rel: string): string => readFileSync(join(ROOT, rel), "utf8");
const AUDIT_DOC = "docs/marketing/00-marketing-audit.md";

/** Every file under a directory, recursively, as repo-relative paths. */
function filesUnder(rel: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(join(ROOT, rel), { withFileTypes: true })) {
    const child = `${rel}/${entry.name}`;
    if (entry.isDirectory()) out.push(...filesUnder(child));
    else out.push(child);
  }
  return out;
}

const MARKETING_FILES = [...filesUnder("marketing"), ...filesUnder("docs/marketing")].filter(
  (f) => /\.(md|csv|json|html)$/.test(f) && f !== AUDIT_DOC,
);

// ── Customer-facing surfaces, collected from every pack ───────────────────

interface Surface {
  where: string;
  text: string;
}

function taggedBlocks(rel: string, tags: readonly string[]): Surface[] {
  const md = read(rel);
  const out: Surface[] = [];
  for (const m of md.matchAll(/^```([a-z0-9-]+)\n([\s\S]*?)\n```/gm)) {
    const tag = m[1] ?? "";
    if (tags.some((t) => (t.endsWith("*") ? tag.startsWith(t.slice(0, -1)) : tag === t))) {
      out.push({ where: `${rel} [${tag}]`, text: m[2] ?? "" });
    }
  }
  return out;
}

function onePagerCustomerPart(): Surface {
  const md = read("marketing/outreach/offer-one-pager.md");
  const start = md.indexOf("\n---\n");
  const end = md.indexOf("\n## Internal checklist");
  expect(start).toBeGreaterThan(-1);
  expect(end).toBeGreaterThan(start);
  return { where: "marketing/outreach/offer-one-pager.md (customer part)", text: md.slice(start + 5, end) };
}

function jsonStrings(value: unknown, out: string[] = []): string[] {
  if (typeof value === "string") out.push(value);
  else if (Array.isArray(value)) for (const v of value) jsonStrings(v, out);
  else if (value && typeof value === "object") for (const v of Object.values(value)) jsonStrings(v, out);
  return out;
}

function customerSurfaces(): Surface[] {
  const s: Surface[] = [];
  for (const store of ["revesby", "bankstown", "roselands"]) {
    s.push(...taggedBlocks(`marketing/gbp/${store}.md`, ["gbp-description", "gbp-service", "gbp-post", "faq-answer"]));
  }
  for (const f of readdirSync(join(ROOT, "marketing/reviews")).filter((n) => n.endsWith(".md"))) {
    s.push(...taggedBlocks(`marketing/reviews/${f}`, ["review-*"]));
  }
  s.push(...taggedBlocks("marketing/wordpress/seo-plugin-checklist.md", ["seo-title", "seo-meta"]));
  s.push(...taggedBlocks("marketing/outreach/sequence-email.md", ["subject", "preview", "body", "store-line"]));
  s.push(...taggedBlocks("marketing/outreach/linkedin.md", ["linkedin-note", "linkedin-followup"]));
  s.push(...taggedBlocks("marketing/outreach/phone-script.md", ["phone"]));
  s.push(onePagerCustomerPart());

  for (const r of parseCsvObjects(read("marketing/google-ads/rsa.csv"))) {
    s.push({ where: `rsa.csv ${r.ad_group} ${r.type}`, text: r.text ?? "" });
  }
  const ext = JSON.parse(read("marketing/google-ads/extensions.json")) as {
    sitelinks: { id: string; title: string; description1: string; description2: string }[];
    callouts: { text: string }[];
    structured_snippets: { header: string; values: string[] }[];
  };
  for (const l of ext.sitelinks) s.push({ where: `extensions.json ${l.id}`, text: `${l.title}\n${l.description1}\n${l.description2}` });
  for (const c of ext.callouts) s.push({ where: "extensions.json callout", text: c.text });
  for (const sn of ext.structured_snippets) s.push({ where: `extensions.json snippet ${sn.header}`, text: sn.values.join("\n") });

  for (const r of parseCsvObjects(read("marketing/meta/copy.csv"))) {
    s.push({ where: `meta copy.csv ${r.ad}`, text: [r.primary_text, r.headline, r.description, r.cta].join("\n") });
  }

  for (const f of readdirSync(join(ROOT, "marketing/wordpress")).filter((n) => n.startsWith("jsonld-"))) {
    const html = read(`marketing/wordpress/${f}`);
    const m = /<script type="application\/ld\+json">\n([\s\S]*?)\n<\/script>/.exec(html);
    expect(m, `${f} has a JSON-LD block`).not.toBeNull();
    const strings = jsonStrings(JSON.parse(m?.[1] ?? "{}")).filter((x) => !/^https?:\/\//.test(x) && !x.startsWith("@"));
    s.push({ where: `marketing/wordpress/${f}`, text: strings.join("\n") });
  }
  return s;
}

const SURFACES = customerSurfaces();

describe("customer-facing copy across every pack", () => {
  it("collects a meaningful number of surfaces (so the checks below cannot pass vacuously)", () => {
    expect(SURFACES.length).toBeGreaterThan(450);
  });

  it("never names or hints at the unannounced product (docs/site/teaser-direction.md rules 1 and 2)", () => {
    const PRODUCT = /\bminis?\b|\bmini[- ]?(pizzas?|manoush|bites?|pies?|packs?)\b|\bparty[- ]bites?\b|\bbites?[- ]packs?\b|\bpack[- ]sizes?\b/i;
    for (const { where, text } of SURFACES) expect(text, where).not.toMatch(PRODUCT);
  });

  it("carries no internal marker that a copy-paste would publish", () => {
    const INTERNAL = /\[CONFIRM|\[VERIFY|\bTODO\b|\bTBC\b|\bGATED\b|\bPARKED\b|\bHELD\b|\bHOLD\b/;
    for (const { where, text } of SURFACES) expect(text, where).not.toMatch(INTERNAL);
  });

  it("makes no affiliation or certification claim (ACL s 29(1)(h); brand ownership is still open)", () => {
    const AFFILIATION = /\bofficial\b|\bendorsed\b|\baccredited\b|\bcertified\b|\bapproved supplier\b/i;
    for (const { where, text } of SURFACES) expect(text, where).not.toMatch(AFFILIATION);
  });

  it("uses only the three typed store phone numbers (no unconfirmed catering line, no tracking number)", () => {
    const digits = (x: string): string => x.replace(/\D/g, "").replace(/^61/, "0");
    const allowed = new Set(STORES.map((st) => digits(st.phone)));
    const PHONE = /\(0\d\)\s?\d{4}\s?\d{4}|\b04\d{2}\s?\d{3}\s?\d{3}\b|\+61\d{9}\b|\b1[38]00\s?\d{3}\s?\d{3}\b/g;
    let seen = 0;
    for (const { where, text } of SURFACES) {
      for (const m of text.match(PHONE) ?? []) {
        seen++;
        expect(allowed.has(digits(m)), `${where}: ${m} is not a typed store phone`).toBe(true);
      }
    }
    expect(seen).toBeGreaterThan(20);
  });
});

// ── Safety: nothing is switched on ────────────────────────────────────────

describe("ad build files", () => {
  const ALLOWED_STATUS = new Set(["paused", "not-created", "not-linked"]);

  function statuses(value: unknown, out: string[] = []): string[] {
    if (Array.isArray(value)) for (const v of value) statuses(v, out);
    else if (value && typeof value === "object") {
      for (const [k, v] of Object.entries(value)) {
        if (k === "status") out.push(String(v));
        statuses(v, out);
      }
    }
    return out;
  }

  for (const f of ["marketing/google-ads/campaigns.json", "marketing/google-ads/extensions.json", "marketing/meta/campaigns.json"]) {
    it(`${f}: every object is paused, not created or not linked; nothing is enabled`, () => {
      const all = statuses(JSON.parse(read(f)));
      expect(all.length).toBeGreaterThan(5);
      for (const st of all) expect(ALLOWED_STATUS.has(st), `${f} has status "${st}"`).toBe(true);
    });
  }

  it("no marketing file carries a credential or token", () => {
    const SECRET =
      /EAA[A-Za-z0-9]{30,}|AIza[0-9A-Za-z_-]{35}|\bsk_(live|test)_[0-9A-Za-z]{10,}|\bxox[abp]-[0-9A-Za-z-]{10,}|\bghp_[A-Za-z0-9]{30,}|-----BEGIN [A-Z ]*PRIVATE KEY-----|\bya29\.[0-9A-Za-z_-]{20,}/;
    for (const f of MARKETING_FILES) expect(read(f), f).not.toMatch(SECRET);
  });
});

// ── Measurement docs stay aligned with src/lib/analytics/events.ts ────────

describe("event names in the measurement plans", () => {
  const conversion = read("marketing/google-ads/conversion-plan.md");
  const tracking = read("marketing/meta/tracking.md");
  const KNOWN = new Set<string>([...EVENT_NAMES, "purchase" /* server-only, see events.ts */, "deposit_paid" /* plugin status */]);
  const SNAKE = /^[a-z]+(?:_[a-z]+)+$/;
  const backticked = (s: string): string[] => [...s.matchAll(/`([^`]+)`/g)].map((m) => m[1] ?? "").filter((t) => SNAKE.test(t));

  it("every event in events.ts is accounted for in both the Google and the Meta plan", () => {
    for (const name of EVENT_NAMES) {
      expect(conversion, `conversion-plan.md names ${name}`).toContain(`\`${name}\``);
      expect(tracking, `tracking.md names ${name}`).toContain(`\`${name}\``);
    }
  });

  it("tracking.md event map uses only event names that exist", () => {
    const section = tracking.slice(tracking.indexOf("## 3. Event map"), tracking.indexOf("## 4."));
    const rows = section.split("\n").filter((l) => l.startsWith("| ") && !l.startsWith("| ---") && !l.includes("Meta event"));
    expect(rows.length).toBeGreaterThan(8);
    for (const r of rows) {
      const first = r.split("|")[1] ?? "";
      for (const t of backticked(first)) expect(KNOWN.has(t), `tracking.md maps unknown event ${t}`).toBe(true);
    }
  });

  it("conversion-plan.md sources and its GA4-only list use only event names that exist", () => {
    const line = conversion.split("\n").find((l) => l.startsWith("Not imported to Google Ads"));
    expect(line).toBeDefined();
    for (const t of backticked(line ?? "")) expect(KNOWN.has(t), `GA4-only list names unknown event ${t}`).toBe(true);
    for (const r of conversion.split("\n").filter((l) => /^\| \d+ \|/.test(l))) {
      // The Source cell names the event, then its parameters in "(params ...)"; only the event is checked.
      const source = (r.split("|")[3] ?? "").replace(/\(params[^)]*\)/g, "");
      for (const t of backticked(source)) expect(KNOWN.has(t), `conversion source names unknown event ${t}`).toBe(true);
    }
  });

  it("no marketing file still names an event, enum or form value that src removed for the teaser", () => {
    const REMOVED = /\bpack_size_change\b|\bminis_waitlist\b|\bMINIS_PARTY\b|\bMinisInterest\b|\bMINIS_INTERESTS\b/;
    for (const f of MARKETING_FILES) expect(read(f), f).not.toMatch(REMOVED);
  });
});

// ── Counts and cross-references agree with the files ──────────────────────

describe("counts stated in the docs match the data files", () => {
  const google = JSON.parse(read("marketing/google-ads/campaigns.json")) as {
    campaigns: { ad_groups: { launch_wave: number }[] }[];
  };
  const meta = JSON.parse(read("marketing/meta/campaigns.json")) as {
    campaigns: { ad_sets: { ads: unknown[] }[] }[];
  };
  const rows = (rel: string): number => parseCsvObjects(read(rel)).length;
  const groups = google.campaigns.flatMap((c) => c.ad_groups);
  const adSets = meta.campaigns.flatMap((c) => c.ad_sets);

  const actual = {
    googleCampaigns: google.campaigns.length,
    googleGroups: groups.length,
    googleWave1: groups.filter((g) => g.launch_wave === 1).length,
    googleWave2: groups.filter((g) => g.launch_wave === 2).length,
    googleKeywords: rows("marketing/google-ads/keywords.csv"),
    googleNegatives: rows("marketing/google-ads/negatives.csv"),
    metaCampaigns: meta.campaigns.length,
    metaAdSets: adSets.length,
    metaAds: adSets.reduce((n, s) => n + s.ads.length, 0),
    metaCopyRows: rows("marketing/meta/copy.csv"),
    segments: rows("marketing/outreach/segments.csv"),
    keywordThemes: rows("marketing/keywords.csv"),
    citations: rows("marketing/citations.csv"),
    linkTargets: rows("marketing/links/link-targets.csv"),
  };

  it("the Meta build and its copy file agree", () => {
    expect(actual.metaCopyRows).toBe(actual.metaAds);
  });

  const CLAIMS: { file: string; re: RegExp; key: keyof typeof actual }[] = [
    { file: "docs/marketing/03a-google-ads.md", re: /(\d+) campaigns, \d+ ad groups/g, key: "googleCampaigns" },
    { file: "docs/marketing/03a-google-ads.md", re: /\d+ campaigns, (\d+) ad groups/g, key: "googleGroups" },
    { file: "docs/marketing/03a-google-ads.md", re: /\| (\d+) keyword rows/g, key: "googleKeywords" },
    { file: "docs/marketing/03a-google-ads.md", re: /\| (\d+) negative keyword rows/g, key: "googleNegatives" },
    { file: "docs/marketing/03a-google-ads.md", re: /wave 1, (\d+) groups/g, key: "googleWave1" },
    { file: "docs/marketing/03a-google-ads.md", re: /wave 2, (\d+) groups/g, key: "googleWave2" },
    { file: "marketing/google-ads/launch-checklist.md", re: /\((\d+) campaigns, \d+ ad groups/g, key: "googleCampaigns" },
    { file: "marketing/google-ads/launch-checklist.md", re: /campaigns, (\d+) ad groups, 15 headlines/g, key: "googleGroups" },
    { file: "marketing/google-ads/launch-checklist.md", re: /each, (\d+) keyword rows/g, key: "googleKeywords" },
    { file: "marketing/google-ads/launch-checklist.md", re: /keyword rows, (\d+) negative rows/g, key: "googleNegatives" },
    { file: "docs/marketing/03b-meta-ads.md", re: /(\d+) campaigns, \d+ ad sets, \d+ ads/g, key: "metaCampaigns" },
    { file: "docs/marketing/03b-meta-ads.md", re: /\d+ campaigns, (\d+) ad sets, \d+ ads/g, key: "metaAdSets" },
    { file: "docs/marketing/03b-meta-ads.md", re: /\d+ campaigns, \d+ ad sets, (\d+) ads/g, key: "metaAds" },
    { file: "docs/marketing/03b-meta-ads.md", re: /\| (\d+) ads across/g, key: "metaCopyRows" },
    { file: "docs/marketing/04-corporate-outreach.md", re: /\b(\d+) (?:target )?segments\b/g, key: "segments" },
    { file: "docs/marketing/research/keyword-themes.md", re: /\((\d+) theme rows/g, key: "keywordThemes" },
    { file: "docs/marketing/02-seo-external.md", re: /lists (\d+) platforms/g, key: "citations" },
    { file: "docs/marketing/02-seo-external.md", re: /\| (\d+) directories and platforms/g, key: "citations" },
    { file: "docs/marketing/02-seo-external.md", re: /has (\d+) legitimate local opportunities/g, key: "linkTargets" },
    { file: "docs/marketing/02-seo-external.md", re: /\| (\d+) link targets/g, key: "linkTargets" },
  ];

  for (const c of CLAIMS) {
    it(`${c.file}: ${String(c.re)} equals the real ${c.key}`, () => {
      const found = [...read(c.file).matchAll(c.re)].map((m) => Number(m[1]));
      expect(found.length, `${c.file} no longer states ${c.key}; update this test with the new wording`).toBeGreaterThan(0);
      for (const n of found) expect(n, `${c.file} says ${n}, the files say ${actual[c.key]}`).toBe(actual[c.key]);
    });
  }
});

// ── Internal research stays labelled internal (teaser-direction.md rule 5) ─

describe("internal research documents", () => {
  for (const f of readdirSync(join(ROOT, "docs/marketing/research")).filter((n) => n.endsWith(".md"))) {
    it(`${f} is labelled internal and not customer-facing`, () => {
      const head = read(`docs/marketing/research/${f}`).split("\n").slice(0, 6).join("\n");
      expect(head).toMatch(/INTERNAL RESEARCH, not customer-facing/);
    });
  }
});

// ── No file silently picks a side on the Revesby pickup conflict ─────────

describe("open decisions are not silently decided", () => {
  it("every statement that pickup or online ordering is live carries its gate or the conflict", () => {
    const CLAIM = /(online ordering|pickup(?: ordering)?|online pickup) is live/i;
    const QUALIFIED = /GATED|gate|brief|conflict|only after|until|whether|confirms?/i;
    for (const f of MARKETING_FILES) {
      for (const line of read(f).split("\n").filter((l) => CLAIM.test(l))) {
        expect(QUALIFIED.test(line), `${f}: "${line.slice(0, 160)}" states pickup is live without its gate`).toBe(true);
      }
    }
  });
});
