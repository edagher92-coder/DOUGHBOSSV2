import { existsSync, readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { describe, expect, it } from "vitest";
import { STORES } from "@/lib/data/catalogue";
import { LEAD_STATUSES } from "@/types/marketing";
import { parseCsvObjects } from "./helpers/csv";

/**
 * Guards for the DRAFT corporate-outreach assets in marketing/outreach/ and
 * docs/marketing/04-corporate-outreach.md. They fail loudly when customer-facing
 * text breaks the claims rule, a length limit, the compliance elements, the
 * route contract or the UTM convention, or when the CRM stages drift from the
 * LeadStatus enum.
 *
 * Customer-facing text lives ONLY in fenced blocks tagged subject / preview /
 * body / store-line (sequence-email.md), linkedin-note / linkedin-followup
 * (linkedin.md), phone (phone-script.md) and the part of offer-one-pager.md
 * above its "## Internal checklist" heading. Everything else is internal and
 * may carry [CONFIRM] markers.
 */

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, "..", "..");
const OUTREACH = join(ROOT, "marketing", "outreach");
const readOutreach = (name: string): string => readFileSync(join(OUTREACH, name), "utf8");

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
/** The live WordPress catering page, allowed as the interim route while the new routes are built. */
const INTERIM_PATHS = new Set(["/catering/"]);
const SITE_HOST = "doughboss.com.au";
const UTM_MEDIUMS = new Set(["cpc", "paid-social", "email", "organic-social", "referral", "gbp", "qr"]);
const STORE_SLUGS = new Set(["revesby", "bankstown", "roselands"]);

const SUBJECT_MAX = 60;
const LINKEDIN_NOTE_MAX = 300;
const BODY_WORDS_MAX = 130;

/** Terms that must never appear in any customer-facing outreach text. */
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
  { label: "free tasting", re: /\bfree\s+tastings?\b/i },
  { label: "free", re: /\bfree\b/i },
  { label: "discount", re: /\bdiscounts?\b/i },
  { label: "% off", re: /%\s*off\b|\d\s*%|per\s*cent/i },
  { label: "authentic", re: /\bauthentic\b/i },
  { label: "fresh daily", re: /\bfresh\s+daily\b/i },
  { label: "fresh", re: /\bfresh(ly)?\b/i },
  { label: "baked in-house / frozen", re: /\bin-house\b|\bfrozen\b|\bbaked\b/i },
  { label: "organic", re: /\borganic\b/i },
  { label: "dietary claim", re: /\b(gluten|vegan|vegetarian|allergen[- ]free|dairy[- ]free)\b/i },
  { label: "price", re: /\$|\bfrom\s+\d|\bprices?\b|\bpricing\b|\bcost(s)?\b|\bper[- ]head\b/i },
  { label: "delivery promise", re: /\bdeliver(y|ed|s|ing)?\b/i },
  { label: "speed or lead-time promise", re: /\b(same[- ]day|next[- ]day|within|instant|fast|express|24\s*hours?|asap)\b/i },
  { label: "rating or review claim", re: /\b(rated|reviews?|stars?|five-star|top-rated)\b/i },
  { label: "capacity or minimum", re: /\b(minimum|min\.|up to|any size|any number|unlimited)\b/i },
  { label: "exclamation mark", re: /!/ },
  { label: "emoji", re: /\p{Extended_Pictographic}/u },
  { label: "dash punctuation", re: /[–—]/ },
  { label: "placeholder marker", re: /\[CONFIRM|\bTODO\b|\bTBC\b|lorem ipsum/i },
];

const bannedIn = (text: string): string[] => BANNED.filter((b) => b.re.test(text)).map((b) => b.label);

/** Placeholders customer-facing text may contain. */
const ALLOWED_PLACEHOLDERS = new Set([
  "SENDER_NAME",
  "SENDER_ROLE",
  "BUSINESS_NAME",
  "BUSINESS_ADDRESS",
  "BUSINESS_LEGAL_ENTITY_AND_ABN",
  "SENDER_PHONE",
  "UNSUBSCRIBE_ADDRESS",
  "RECIPIENT_NAME",
  "ORGANISATION",
  "PUBLISHED_SOURCE",
  "NEAREST_STORE_LINE",
  "OPTIONAL_TASTING_LINE",
  "SEGMENT",
  "OFFER",
  "AREA",
  "FIRST_NAME",
  "SUBURB_OR_AREA",
]);

// ── Markdown parsing helpers ──────────────────────────────────────────────

interface Block {
  tag: string;
  text: string;
}

function fencedBlocks(markdown: string): Block[] {
  const out: Block[] = [];
  const re = /^```([\w-]*)\n([\s\S]*?)^```/gm;
  for (const m of markdown.matchAll(re)) out.push({ tag: m[1] ?? "", text: (m[2] ?? "").replace(/\n$/, "") });
  return out;
}

const blocksTagged = (markdown: string, ...tags: string[]): Block[] =>
  fencedBlocks(markdown).filter((b) => tags.includes(b.tag));

function placeholdersIn(text: string): string[] {
  return [...text.matchAll(/\{([A-Z_]+)\}/g)].map((m) => m[1] ?? "");
}

/** Replace every placeholder with a deliberately long value, to prove length limits hold when filled. */
function fillWorstCase(text: string, width = 24): string {
  return text.replace(/\{[A-Z_]+\}/g, "x".repeat(width));
}

/** Split a sequence file into one section per "## " heading. */
function sections(markdown: string): { title: string; body: string }[] {
  return markdown
    .split(/^## /m)
    .slice(1)
    .map((chunk) => {
      const nl = chunk.indexOf("\n");
      return { title: chunk.slice(0, nl).trim(), body: chunk.slice(nl + 1) };
    });
}

const URL_RE = /https:\/\/[^\s)"'<>`]+/g;

/** Digits are allowed only where they come straight from typed store data, hours, or URLs. */
function digitsOutsideStoreData(text: string): string[] {
  let t = text
    .replace(URL_RE, " ")
    .replace(/^\s*\d+\.\s/gm, " ") // numbered-list markers
    .replace(/\b\d{1,2}(:\d{2})?\s*(am|pm)\b/gi, " ") // shop hours
    .replace(/\b7 days\b/gi, " ");
  for (const s of STORES) {
    t = t.split(s.phoneDisplay).join(" ");
    t = t.split(s.postcode).join(" ");
  }
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

/** Check one URL against the route contract and the UTM convention (placeholders already filled). */
function assertUrlOk(raw: string): void {
  const filled = raw
    .replace("{SEGMENT}", "corporate")
    .replace("{OFFER}", "office-breakfast")
    .replace("{AREA}", "bankstown")
    .replace(/[.,;]+$/, "");
  const u = new URL(filled);
  expect(u.hostname, raw).toBe(SITE_HOST);
  const path = u.pathname;
  const ok = ROUTE_CONTRACT.has(path) || INTERIM_PATHS.has(path);
  expect(ok, `route not in contract: ${path}`).toBe(true);
  const p = u.searchParams;
  for (const key of ["utm_source", "utm_medium", "utm_campaign", "utm_content"]) {
    const v = p.get(key);
    expect(v, `${raw} is missing ${key}`).toBeTruthy();
    expect(v, `${key} must be lower-case, hyphenated`).toMatch(/^[a-z0-9]+(?:-[a-z0-9]+)*$/);
  }
  expect(UTM_MEDIUMS.has(p.get("utm_medium") ?? ""), `utm_medium ${p.get("utm_medium")} not allowed`).toBe(true);
  // utm_campaign is <segment>-<offer>-<area>: at least three hyphen-separated parts.
  expect((p.get("utm_campaign") ?? "").split("-").length).toBeGreaterThanOrEqual(3);
}

// ── Load files ────────────────────────────────────────────────────────────

const sequence = readOutreach("sequence-email.md");
const linkedin = readOutreach("linkedin.md");
const phone = readOutreach("phone-script.md");
const onePager = readOutreach("offer-one-pager.md");
const tasting = readOutreach("tasting-offer.md");
const crm = readOutreach("crm-pipeline.md");
const checklist = readOutreach("compliance-checklist.md");
const playbook = readFileSync(join(ROOT, "docs", "marketing", "04-corporate-outreach.md"), "utf8");
const segmentsCsv = readOutreach("segments.csv");

/** The customer-facing part of the one-pager: after the first rule, before the internal checklist. */
function onePagerCustomerPart(): string {
  const start = onePager.indexOf("\n---\n");
  const end = onePager.indexOf("\n## Internal checklist");
  expect(start, "one-pager needs a '---' rule starting the customer part").toBeGreaterThan(-1);
  expect(end, "one-pager needs an '## Internal checklist' heading").toBeGreaterThan(start);
  return onePager.slice(start + 5, end);
}

interface Email {
  title: string;
  subject: string;
  preview: string;
  body: string;
}

function emails(): Email[] {
  return sections(sequence)
    .filter((s) => /^(Touch \d|Re-engagement)/.test(s.title))
    .map((s) => {
      const get = (tag: string): string => {
        const found = blocksTagged(s.body, tag);
        expect(found.length, `${s.title}: expected exactly one ${tag} block`).toBe(1);
        return found[0]?.text ?? "";
      };
      return { title: s.title, subject: get("subject"), preview: get("preview"), body: get("body") };
    });
}

// ── Files exist ───────────────────────────────────────────────────────────

describe("outreach file set", () => {
  it.each([
    "segments.csv",
    "sequence-email.md",
    "linkedin.md",
    "phone-script.md",
    "offer-one-pager.md",
    "tasting-offer.md",
    "crm-pipeline.md",
    "compliance-checklist.md",
  ])("marketing/outreach/%s exists and is not empty", (name) => {
    expect(existsSync(join(OUTREACH, name))).toBe(true);
    expect(readOutreach(name).trim().length).toBeGreaterThan(200);
  });

  it("the playbook exists and links every outreach file", () => {
    for (const name of [
      "segments.csv",
      "sequence-email.md",
      "linkedin.md",
      "phone-script.md",
      "offer-one-pager.md",
      "tasting-offer.md",
      "crm-pipeline.md",
      "compliance-checklist.md",
    ]) {
      expect(playbook, `playbook should reference ${name}`).toContain(name);
    }
  });

  it("every file spells the principal's name Elie, never Eli", () => {
    for (const text of [playbook, sequence, linkedin, phone, onePager, tasting, crm, checklist, segmentsCsv]) {
      expect(/\bEli\b/.test(text)).toBe(false);
    }
  });

  it("no outreach file borrows another business's agents, skills, accounts or voice", () => {
    for (const text of [playbook, sequence, linkedin, phone, onePager, tasting, crm, checklist, segmentsCsv]) {
      expect(/snow\s?flow|slushie|snowflow-ads|sales-hunter|daily-sales-engine/i.test(text)).toBe(false);
    }
  });
});

// ── segments.csv ──────────────────────────────────────────────────────────

describe("segments.csv", () => {
  const rows = parseCsvObjects(segmentsCsv);

  it("has the required header", () => {
    const header = segmentsCsv.split(/\r?\n/)[0]?.split(",") ?? [];
    expect(header).toEqual([
      "segment",
      "example_organisation_type",
      "nearest_store",
      "why_they_buy_catering",
      "best_offer",
      "first_channel",
      "priority",
      "source_url",
    ]);
  });

  it("parses with 12 or more rows", () => {
    expect(rows.length).toBeGreaterThanOrEqual(12);
  });

  it("every row has valid, filled fields", () => {
    for (const r of rows) {
      expect(STORE_SLUGS.has(r.nearest_store ?? ""), `bad nearest_store: ${r.nearest_store}`).toBe(true);
      expect(r.source_url, `source_url for ${r.segment}`).toMatch(/^https:\/\/[^\s]+$/);
      expect(() => new URL(r.source_url ?? "")).not.toThrow();
      expect(r.priority).toMatch(/^P[123]$/);
      for (const field of ["segment", "example_organisation_type", "why_they_buy_catering", "best_offer", "first_channel"]) {
        expect((r[field] ?? "").trim().length, `${r.segment}: ${field} is empty`).toBeGreaterThan(0);
      }
    }
  });

  it("segment names are unique", () => {
    const names = rows.map((r) => r.segment);
    expect(new Set(names).size).toBe(names.length);
  });

  it("covers all three stores and at least one priority-one segment", () => {
    expect(new Set(rows.map((r) => r.nearest_store))).toEqual(STORE_SLUGS);
    expect(rows.some((r) => r.priority === "P1")).toBe(true);
  });

  it("holds public organisation types only: no email addresses and no personal data", () => {
    expect(segmentsCsv.includes("@")).toBe(false);
    expect(/\b0[2-9]\d{2}\s?\d{3}\s?\d{3}\b|\(0\d\)\s?\d{4}\s?\d{4}/.test(segmentsCsv)).toBe(false);
  });
});

// ── sequence-email.md ─────────────────────────────────────────────────────

describe("sequence-email.md", () => {
  const all = emails();

  it("has four touches plus a re-engagement note", () => {
    expect(all.filter((e) => e.title.startsWith("Touch ")).length).toBe(4);
    expect(all.filter((e) => e.title.startsWith("Re-engagement")).length).toBe(1);
  });

  it("every email subject is 60 characters or fewer, with placeholders filled worst-case", () => {
    for (const e of all) {
      expect(e.subject.trim().length, `${e.title}: subject too long`).toBeLessThanOrEqual(SUBJECT_MAX);
      expect(fillWorstCase(e.subject).length, `${e.title}: subject too long once filled`).toBeLessThanOrEqual(SUBJECT_MAX);
      expect(e.subject.includes("\n"), `${e.title}: subject must be a single line`).toBe(false);
    }
  });

  it("every preview line is short and non-empty", () => {
    for (const e of all) {
      expect(e.preview.trim().length).toBeGreaterThan(10);
      expect(fillWorstCase(e.preview, 12).length, `${e.title}: preview too long`).toBeLessThanOrEqual(110);
    }
  });

  it("every email contains the sender-identification placeholders", () => {
    for (const e of all) {
      for (const ph of ["{SENDER_NAME}", "{SENDER_ROLE}", "{BUSINESS_NAME}", "{BUSINESS_ADDRESS}"]) {
        expect(e.body, `${e.title} is missing ${ph}`).toContain(ph);
      }
    }
  });

  it("every email has a working unsubscribe line with a reachable address", () => {
    for (const e of all) {
      expect(/unsubscribe/i.test(e.body), `${e.title} needs an unsubscribe line`).toBe(true);
      expect(e.body, `${e.title} needs {UNSUBSCRIBE_ADDRESS}`).toContain("{UNSUBSCRIBE_ADDRESS}");
      // Sender identification must come before, not instead of, the unsubscribe statement.
      expect(e.body.indexOf("{SENDER_NAME}")).toBeLessThan(e.body.toLowerCase().indexOf("unsubscribe"));
    }
  });

  it("every cold touch tells the recipient where the address was found", () => {
    for (const e of all.filter((x) => x.title.startsWith("Touch "))) {
      expect(e.body, `${e.title} needs {PUBLISHED_SOURCE}`).toContain("{PUBLISHED_SOURCE}");
    }
  });

  it("bodies are short, plain and ask one thing (word count before the footer)", () => {
    for (const e of all) {
      const cut = e.body.indexOf("\n{SENDER_NAME}\n{SENDER_ROLE}");
      expect(cut, `${e.title}: footer not found`).toBeGreaterThan(-1);
      const words = e.body.slice(0, cut).split(/\s+/).filter(Boolean).length;
      expect(words, `${e.title}: ${words} words`).toBeLessThanOrEqual(BODY_WORDS_MAX);
    }
  });

  it("no customer-facing email text carries a banned term", () => {
    for (const e of all) {
      for (const [part, text] of [
        ["subject", e.subject],
        ["preview", e.preview],
        ["body", e.body],
      ] as const) {
        expect(bannedIn(text), `${e.title} ${part}`).toEqual([]);
      }
    }
    for (const b of blocksTagged(sequence, "store-line")) expect(bannedIn(b.text), b.text).toEqual([]);
  });

  it("uses only known placeholders", () => {
    for (const b of blocksTagged(sequence, "subject", "preview", "body", "store-line")) {
      for (const ph of placeholdersIn(b.text)) expect(ALLOWED_PLACEHOLDERS.has(ph), `unknown placeholder {${ph}}`).toBe(true);
    }
  });

  it("every link is on the real site, in the route contract, and carries the UTM convention", () => {
    let links = 0;
    for (const b of blocksTagged(sequence, "body")) {
      for (const url of b.text.match(URL_RE) ?? []) {
        assertUrlOk(url);
        links++;
      }
    }
    expect(links).toBeGreaterThanOrEqual(2);
  });

  it("no email has more than one link (one clear ask)", () => {
    for (const e of all) expect((e.body.match(URL_RE) ?? []).length, e.title).toBeLessThanOrEqual(1);
  });

  it("the tasting slot is a placeholder only: no customer-facing text promises a tasting", () => {
    for (const e of all) {
      expect(/\btasting|\bsample|\btry some\b/i.test(`${e.subject} ${e.preview} ${e.body.replace("{OPTIONAL_TASTING_LINE}", "")}`)).toBe(false);
    }
  });

  it("digits appear only where they come from typed store data", () => {
    for (const b of blocksTagged(sequence, "subject", "preview", "body", "store-line")) {
      expect(digitsOutsideStoreData(b.text), b.text.slice(0, 60)).toEqual([]);
    }
  });

  it("store lines match the typed store data exactly", () => {
    const lines = blocksTagged(sequence, "store-line").map((b) => b.text);
    expect(lines.length).toBe(3);
    for (const s of STORES) {
      const line = lines.find((l) => l.includes(s.addressLine1));
      expect(line, `no store line for ${s.slug}`).toBeDefined();
      expect(line).toContain(s.phoneDisplay);
      expect(line).toContain(s.postcode);
    }
  });

  it("the internal consent rationale cites the Spam Act clause and section it relies on", () => {
    expect(sequence).toMatch(/Schedule 2 clause 4/);
    expect(sequence).toMatch(/section 17/i);
    expect(sequence).toMatch(/section 18/i);
    expect(sequence).toContain("legislation.gov.au");
  });
});

// ── linkedin.md ───────────────────────────────────────────────────────────

describe("linkedin.md", () => {
  const notes = blocksTagged(linkedin, "linkedin-note");
  const followups = blocksTagged(linkedin, "linkedin-followup");

  it("has at least three connection notes and two follow-ups", () => {
    expect(notes.length).toBeGreaterThanOrEqual(3);
    expect(followups.length).toBeGreaterThanOrEqual(2);
  });

  it("every connection note is 300 characters or fewer, as written and with placeholders filled worst-case", () => {
    for (const n of notes) {
      expect(n.text.length, n.text).toBeLessThanOrEqual(LINKEDIN_NOTE_MAX);
      expect(fillWorstCase(n.text, 20).length, `too long once filled: ${n.text}`).toBeLessThanOrEqual(LINKEDIN_NOTE_MAX);
    }
  });

  it("connection notes carry no pitch and no link", () => {
    for (const n of notes) {
      expect(n.text.match(URL_RE)).toBeNull();
      expect(/quote|order|offer/i.test(n.text), n.text).toBe(false);
    }
  });

  it("every connection note and follow-up identifies the sender and the business", () => {
    for (const b of [...notes, ...followups]) {
      expect(b.text).toContain("{SENDER_NAME}");
      expect(b.text).toContain("{BUSINESS_NAME}");
    }
  });

  it("every follow-up gives the reader a way out or a referral", () => {
    for (const f of followups) expect(/isn't (relevant|your area)|point me to the right person/i.test(f.text), f.text).toBe(true);
  });

  it("no customer-facing LinkedIn text carries a banned term", () => {
    for (const b of [...notes, ...followups]) expect(bannedIn(b.text), b.text).toEqual([]);
  });

  it("uses only known placeholders and plain digits-free text", () => {
    for (const b of [...notes, ...followups]) {
      for (const ph of placeholdersIn(b.text)) expect(ALLOWED_PLACEHOLDERS.has(ph), `unknown placeholder {${ph}}`).toBe(true);
      expect(digitsOutsideStoreData(b.text), b.text).toEqual([]);
    }
  });

  it("states the no-automation rule and cites LinkedIn's own policy", () => {
    expect(linkedin).toMatch(/no automation/i);
    expect(linkedin).toContain("linkedin.com/help");
  });
});

// ── phone-script.md ───────────────────────────────────────────────────────

describe("phone-script.md", () => {
  const spoken = blocksTagged(phone, "phone");

  it("has opening, discovery, gatekeeper, voicemail and close material", () => {
    expect(spoken.length).toBeGreaterThanOrEqual(10);
    for (const heading of ["## Opening", "## Discovery questions", "## Handling a gatekeeper", "## Voicemail", "## Close"]) {
      expect(phone, `missing ${heading}`).toContain(heading);
    }
  });

  it("the opening and the voicemail identify the caller and the business", () => {
    const parts = sections(phone);
    for (const title of ["Opening", "Voicemail"]) {
      const s = parts.find((p) => p.title === title);
      expect(s, title).toBeDefined();
      const text = blocksTagged(s?.body ?? "", "phone")
        .map((b) => b.text)
        .join("\n");
      expect(text).toContain("{SENDER_NAME}");
      expect(text).toContain("{BUSINESS_NAME}");
    }
  });

  it("no spoken text carries a banned term", () => {
    for (const b of spoken) expect(bannedIn(b.text), b.text).toEqual([]);
  });

  it("uses only known placeholders", () => {
    for (const b of spoken) {
      for (const ph of placeholdersIn(b.text)) expect(ALLOWED_PLACEHOLDERS.has(ph), `unknown placeholder {${ph}}`).toBe(true);
    }
  });

  it("documents business hours, caller identification, Do Not Call and the stop rule", () => {
    expect(phone).toMatch(/weekdays/i);
    expect(phone).toMatch(/9am and 8pm|9am to 8pm/);
    expect(phone).toMatch(/Do Not Call/);
    expect(phone).toMatch(/caller identification/i);
    expect(phone).toMatch(/Stop the call the moment/i);
    expect(phone).toContain("donotcall.gov.au");
  });
});

// ── offer-one-pager.md ────────────────────────────────────────────────────

describe("offer-one-pager.md", () => {
  it("the customer-facing part carries no banned term, no placeholder marker and no price", () => {
    const part = onePagerCustomerPart();
    expect(bannedIn(part)).toEqual([]);
    expect(part).not.toMatch(/\[CONFIRM/);
  });

  it("uses only known placeholders and digits from typed store data", () => {
    const part = onePagerCustomerPart();
    for (const ph of placeholdersIn(part)) expect(ALLOWED_PLACEHOLDERS.has(ph), `unknown placeholder {${ph}}`).toBe(true);
    expect(digitsOutsideStoreData(part)).toEqual([]);
  });

  it("lists each shop with the typed address and phone", () => {
    const part = onePagerCustomerPart();
    for (const s of STORES) {
      expect(part, s.slug).toContain(s.addressLine1);
      expect(part, s.slug).toContain(s.phoneDisplay);
      expect(part, s.slug).toContain(s.postcode);
    }
  });

  it("the enquiry link is on the real site and follows the UTM convention", () => {
    const urls = onePagerCustomerPart().match(URL_RE) ?? [];
    expect(urls.length).toBeGreaterThanOrEqual(1);
    for (const u of urls) assertUrlOk(u);
  });

  it("keeps every unconfirmed item in the internal checklist, with a [CONFIRM] marker", () => {
    const internal = onePager.slice(onePager.indexOf("\n## Internal checklist"));
    expect((internal.match(/\[CONFIRM/g) ?? []).length).toBeGreaterThanOrEqual(10);
    for (const topic of ["minimum order", "delivery area", "lead time", "halal", "allergen", "price"]) {
      expect(internal.toLowerCase(), topic).toContain(topic);
    }
  });
});

// ── tasting-offer.md ──────────────────────────────────────────────────────

describe("tasting-offer.md", () => {
  it("is an internal memo gated on Elie's approval of cost and terms", () => {
    expect(tasting).toContain("[CONFIRM: Elie approves cost and terms]");
    expect(tasting).toMatch(/INTERNAL/);
    expect(tasting).toMatch(/hypothesis/i);
  });

  it("gives formulas, not invented numbers", () => {
    expect(tasting).toMatch(/Cost per won account/);
    expect(/\$\s?\d/.test(tasting)).toBe(false);
  });

  it("no customer-facing file promises a tasting", () => {
    const customer = [
      ...blocksTagged(sequence, "subject", "preview", "body"),
      ...blocksTagged(linkedin, "linkedin-note", "linkedin-followup"),
      ...blocksTagged(phone, "phone"),
    ]
      .map((b) => b.text.replace("{OPTIONAL_TASTING_LINE}", ""))
      .join("\n");
    expect(/\btasting|\bsample/i.test(`${customer}\n${onePagerCustomerPart()}`)).toBe(false);
  });
});

// ── crm-pipeline.md ───────────────────────────────────────────────────────

describe("crm-pipeline.md", () => {
  /** First-column backticked stage names of the table under "## Stages", in order. */
  function stageRows(): string[] {
    const s = sections(crm).find((x) => x.title === "Stages");
    expect(s, "crm-pipeline.md needs a '## Stages' section").toBeDefined();
    const names: string[] = [];
    for (const line of (s?.body ?? "").split("\n")) {
      const m = /^\|\s*`([A-Z_]+)`\s*\|/.exec(line);
      if (m?.[1]) names.push(m[1]);
    }
    return names;
  }

  it("stages equal LEAD_STATUSES exactly, in order", () => {
    expect(stageRows()).toEqual([...LEAD_STATUSES]);
  });

  it("every stage has a first-response or follow-up SLA marked [CONFIRM] (no invented times)", () => {
    const s = sections(crm).find((x) => x.title === "Stages");
    const rows = (s?.body ?? "").split("\n").filter((l) => /^\|\s*`[A-Z_]+`\s*\|/.test(l));
    expect(rows.length).toBe(LEAD_STATUSES.length);
    for (const row of rows) {
      if (row.startsWith("| `LOST`")) continue;
      expect(row, row).toContain("[CONFIRM");
    }
  });

  it("tracks the lead-scoring fields captured by the enquiry form", () => {
    for (const field of ["guestBand", "wantsCorporateAccount", "eventType", "consentAt", "consentText"]) {
      expect(crm, field).toContain(field);
    }
  });

  it("explains how enquiry-form and outbound leads share one pipeline", () => {
    expect(crm).toMatch(/one pipeline/i);
    expect(crm).toMatch(/inbound/i);
    expect(crm).toMatch(/outbound/i);
  });

  it("covers the Square handoff and marks the unverified parts [CONFIRM]", () => {
    expect(crm).toMatch(/Square customer/i);
    expect(crm).toMatch(/invoice/i);
    expect(crm).toMatch(/estimate/i);
    expect(crm).toContain("docs/square/capabilities-au.md");
    expect(crm).toMatch(/\[CONFIRM: tier on Dough Boss's account\]/);
  });
});

// ── compliance-checklist.md ───────────────────────────────────────────────

describe("compliance-checklist.md", () => {
  it("is a one-page checklist covering consent, sender identification, unsubscribe, claims, calls and privacy", () => {
    const boxes = checklist.match(/^- \[ \]/gm) ?? [];
    expect(boxes.length).toBeGreaterThanOrEqual(20);
    expect(checklist.split("\n").length).toBeLessThanOrEqual(100);
    for (const topic of ["Spam Act", "unsubscribe", "Do Not Call", "ABN", "claims", "Privacy"]) {
      expect(checklist.toLowerCase(), topic).toContain(topic.toLowerCase());
    }
  });

  it("requires Elie's explicit yes before anything is sent", () => {
    expect(checklist).toMatch(/explicit yes/i);
  });
});

// ── 04-corporate-outreach.md ──────────────────────────────────────────────

describe("04-corporate-outreach.md", () => {
  it("has the required playbook sections", () => {
    for (const heading of [
      "Ideal customer profiles and buying roles",
      "The offer ladder",
      "First-touch strategy",
      "Prospecting method",
      "Lead scoring",
      "Follow-up cadence",
      "Objection handling",
      "Metrics",
    ]) {
      expect(playbook, heading).toContain(heading);
    }
  });

  it("names every buying role in the brief", () => {
    for (const role of ["Office manager", "Executive assistant", "People and culture", "Event coordinator", "community"]) {
      expect(playbook.toLowerCase(), role).toContain(role.toLowerCase());
    }
  });

  it("covers the whole offer ladder and the standing-order volume play", () => {
    for (const rung of ["Office breakfast", "Team-lunch grazing", "Event platters", "Standing orders"]) {
      expect(playbook, rung).toContain(rung);
    }
  });

  it("keeps the tasting as a hypothesis behind an approval gate", () => {
    expect(playbook).toContain("[CONFIRM: Elie approves cost and terms]");
    expect(playbook).toMatch(/hypothesis/i);
  });

  it("scores leads with the enquiry form's fields and every guest band", () => {
    expect(playbook).toContain("wantsCorporateAccount");
    for (const band of ["UP_TO_25", "FROM_26_TO_50", "FROM_51_TO_100", "FROM_101_TO_250", "OVER_250"]) {
      expect(playbook, band).toContain(band);
    }
  });

  it("invents no budget, price or rate: no dollar figures and no target percentages", () => {
    expect(/\$\s?\d/.test(playbook)).toBe(false);
    expect(/\b\d+(\.\d+)?\s?%/.test(playbook)).toBe(false);
  });

  it("names the hosts it could not read", () => {
    expect(playbook).toContain("www.acma.gov.au");
    expect(playbook).toContain("503");
  });

  it("every cited segment priority exists in segments.csv", () => {
    const rows = parseCsvObjects(segmentsCsv);
    for (const p of new Set(rows.map((r) => r.priority))) expect(playbook).toContain(p);
  });
});
