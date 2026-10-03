/**
 * Oracle for the PHP claims ledger (doughboss-growth, WP-02).
 *
 * Runs the real TypeScript implementation (src/content/ledger.ts) over a fixed list of cases and writes the
 * results to doughboss-growth/tests/fixtures/ledger-oracle.json. The PHP port must return identical results
 * for every case (doughboss-growth/tests/test-ledger.php). The fixture records the sha256 of ledger.ts so the
 * PHP test fails when the oracle is stale.
 *
 * Regenerate from the repository root with:
 *   NODE_PATH=web/node_modules web/node_modules/.bin/tsx web/scripts/wp-oracle/export-ledger-fixtures.ts
 *
 * Pass --check to compare instead of write (exit 1 when the file on disk differs).
 */
import { createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { assertLedger, claimText, publishable, type Claim } from "../../src/content/ledger";

const here = dirname(fileURLToPath(import.meta.url));
const webRoot = resolve(here, "../..");
const ledgerPath = resolve(webRoot, "src/content/ledger.ts");
const outPath = resolve(webRoot, "../doughboss-growth/tests/fixtures/ledger-oracle.json");

type Source = NonNullable<Claim["source"]>;
const site = (ref = "doughboss.com.au/catering/"): Source => ({ kind: "owner-site", ref, retrieved: "2026-10-02" });
const owner = (ref = "chat with Elie"): Source => ({ kind: "owner-confirmed", ref, confirmedOn: "2026-10-02" });
const web = (ref = "https://example.org/page"): Source => ({ kind: "public-web", ref, retrieved: "2026-10-02" });
const claim = (id: string, text: string, confirmed: boolean, source?: Source, note?: string): Claim => {
  const c: Claim = { id, text, confirmed };
  if (source !== undefined) c.source = source;
  if (note !== undefined) c.note = note;
  return c;
};

const cases: Array<{ name: string; claims: Claim[] }> = [
  {
    name: "valid-mixed",
    claims: [
      claim("baked-fresh", "Baked fresh every morning", true, site()),
      claim("owner-said", "Family owned", true, owner()),
      claim("web-listed", "Listed on a public directory", true, web()),
      claim("still-a-gap", "Delivery area wording", false, undefined, "needs Elie"),
    ],
  },
  { name: "empty-ledger", claims: [] },
  {
    name: "duplicate-id",
    claims: [claim("same-id", "First", true, site()), claim("same-id", "Second", true, site())],
  },
  {
    name: "duplicate-id-first-unconfirmed-second-confirmed",
    claims: [claim("dup", "Unconfirmed first", false), claim("dup", "Confirmed second", true, site())],
  },
  { name: "non-kebab-uppercase", claims: [claim("Baked-Fresh", "Text", false)] },
  { name: "non-kebab-underscore", claims: [claim("baked_fresh", "Text", false)] },
  { name: "non-kebab-leading-hyphen", claims: [claim("-baked", "Text", false)] },
  { name: "non-kebab-trailing-hyphen", claims: [claim("baked-", "Text", false)] },
  { name: "non-kebab-double-hyphen", claims: [claim("baked--fresh", "Text", false)] },
  { name: "non-kebab-space", claims: [claim("baked fresh", "Text", false)] },
  { name: "non-kebab-empty-id", claims: [claim("", "Text", false)] },
  { name: "non-kebab-trailing-newline", claims: [claim("baked\n", "Text", false)] },
  { name: "kebab-digits-ok", claims: [claim("shop-2-hours", "Text", false)] },
  { name: "empty-text", claims: [claim("empty", "", false)] },
  { name: "whitespace-only-text", claims: [claim("blank", "  \t\n ", false)] },
  { name: "nbsp-only-text", claims: [claim("nbsp", "  ", false)] },
  { name: "ideographic-space-only-text", claims: [claim("ideo", "　", false)] },
  { name: "bom-only-text", claims: [claim("bom", "﻿", false)] },
  { name: "zero-width-space-text-is-not-empty", claims: [claim("zwsp", "​", false)] },
  { name: "placeholder-confirm-bracket", claims: [claim("p1", "Open [CONFIRM: hours]", false)] },
  { name: "placeholder-confirm-lowercase", claims: [claim("p2", "Open [confirm hours]", false)] },
  { name: "placeholder-todo", claims: [claim("p3", "TODO write this", false)] },
  { name: "placeholder-todo-lowercase", claims: [claim("p4", "todo later", false)] },
  { name: "placeholder-tbc", claims: [claim("p5", "Price TBC", false)] },
  { name: "placeholder-lorem", claims: [claim("p6", "Lorem Ipsum dolor", false)] },
  { name: "placeholder-xxx", claims: [claim("p7", "Call xxx", false)] },
  { name: "placeholder-near-miss-xx", claims: [claim("p8", "Call xx and confirm", false)] },
  { name: "confirmed-without-source", claims: [claim("nosrc", "Baked fresh", true)] },
  { name: "unconfirmed-without-source", claims: [claim("gap", "Baked fresh", false)] },
  {
    name: "empty-ref-confirmed",
    claims: [claim("emptyref", "Baked fresh", true, { kind: "owner-site", ref: "", retrieved: "2026-10-02" })],
  },
  {
    name: "whitespace-ref-confirmed",
    claims: [claim("wsref", "Baked fresh", true, { kind: "owner-confirmed", ref: "  \t", confirmedOn: "2026-10-02" })],
  },
  {
    name: "nbsp-ref-confirmed",
    claims: [claim("nbspref", "Baked fresh", true, { kind: "public-web", ref: " ", retrieved: "2026-10-02" })],
  },
  {
    name: "empty-ref-unconfirmed",
    claims: [claim("emptyref-gap", "Baked fresh", false, { kind: "owner-site", ref: "", retrieved: "2026-10-02" })],
  },
  {
    name: "unconfirmed-with-source-not-publishable",
    claims: [claim("held-back", "Baked fresh", false, site())],
  },
  {
    name: "several-problems-one-claim",
    claims: [claim("Bad_Id", "  ", true, { kind: "owner-site", ref: " ", retrieved: "2026-10-02" })],
  },
  {
    name: "several-claims-several-problems",
    claims: [
      claim("ok-one", "Fine", true, site()),
      claim("Bad Id", "TODO", true),
      claim("ok-one", "", false),
    ],
  },
  {
    name: "unicode-text-valid",
    claims: [claim("arabic-greeting", "أهلاً وسهلاً", true, owner()), claim("accents", "Crème brûlée", true, site())],
  },
];

function problemsOf(claims: Claim[]): string[] {
  try {
    assertLedger(claims);
    return [];
  } catch (e) {
    const message = (e as Error).message;
    const header = "Claims ledger is invalid:\n- ";
    if (!message.startsWith(header)) throw new Error(`unexpected assertLedger message: ${message}`);
    return message.slice(header.length).split("\n- ");
  }
}

const results = cases.map(({ name, claims }) => {
  const ids = Array.from(new Set([...claims.map((c) => c.id), "does-not-exist"]));
  const texts: Record<string, string | null> = {};
  for (const id of ids) texts[id] = claimText(claims, id) ?? null;
  const problems = problemsOf(claims);
  return {
    name,
    claims,
    expect: {
      valid: problems.length === 0,
      problems,
      publishable_ids: publishable(claims).map((c) => c.id),
      texts,
    },
  };
});

const sha256 = createHash("sha256").update(readFileSync(ledgerPath, "utf8").replace(/\r\n/g, "\n")).digest("hex");
const fixture = {
  generated_by: "web/scripts/wp-oracle/export-ledger-fixtures.ts",
  oracle_source: { file: "web/src/content/ledger.ts", sha256, line_endings: "normalised to LF before hashing" },
  cases: results,
};
const output = JSON.stringify(fixture, null, "\t") + "\n";

if (process.argv.includes("--check")) {
  const current = existsSync(outPath) ? readFileSync(outPath, "utf8") : "";
  if (current !== output) {
    console.error("ledger oracle fixture is stale: run the export script without --check");
    process.exit(1);
  }
  console.log(`ledger oracle fixture is current (${results.length} cases)`);
} else {
  writeFileSync(outPath, output);
  console.log(`wrote ${outPath} (${results.length} cases, ledger.ts sha256 ${sha256.slice(0, 12)})`);
}
