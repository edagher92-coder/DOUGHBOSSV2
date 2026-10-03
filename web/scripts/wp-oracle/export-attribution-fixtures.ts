/**
 * Oracle for the attribution sanitisers of the DoughBoss Growth companion (WP-04).
 *
 * Runs the real TypeScript implementation (src/lib/attribution-schema.ts, sanitiseAttribution) over a fixed list of
 * cases and writes the results to doughboss-growth/tests/fixtures/attribution-cases.json. Both the PHP port
 * (includes/attribution/class-doughboss-growth-attribution.php, tests/test-attribution.php) and the ES5 browser port
 * (public/js/dbgr-attribution.js, tests/attribution.test.js) must return identical results for every case. The fixture
 * records the sha256 of attribution-schema.ts so the tests fail when the oracle is stale.
 *
 * One deliberate difference, recorded per case: the companion ALSO requires referrerHost to be a plain host name
 * (letters, digits, dots and hyphens, starting and ending with a letter or digit). attribution-schema.ts only checks
 * the length of referrerHost, so it accepts a value such as "a<newline>b" or "/path with space". `ts_expected` is what
 * the TypeScript schema returns; `expected` is what the companion must return (the TypeScript result with the host rule
 * applied, computed here by hostRule()). The two differ only where `host_rule_differs` is true, and the PHP and node tests
 * assert that this set of cases is exactly the one listed in `host_rule_cases`.
 *
 * Lengths: zod 4 measures string length in Unicode code points (an emoji outside the Basic Multilingual Plane counts once), not
 * UTF-16 units; the fixture records the zod version it was generated with. Lone surrogates are not used: JSON text cannot
 * carry them into PHP.
 *
 * Regenerate from the repository root with:
 *   NODE_PATH=web/node_modules web/node_modules/.bin/tsx web/scripts/wp-oracle/export-attribution-fixtures.ts
 *
 * Pass --check to compare instead of write (exit 1 when the file on disk differs).
 */
import { createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";
import { sanitiseAttribution } from "../../src/lib/attribution-schema";

const here = dirname(fileURLToPath(import.meta.url));
const webRoot = resolve(here, "../..");
const schemaPath = resolve(webRoot, "src/lib/attribution-schema.ts");
const outPath = resolve(webRoot, "../doughboss-growth/tests/fixtures/attribution-cases.json");

const zodVersion: string = (createRequire(import.meta.url)("zod/package.json") as { version: string }).version;

const HOST_RULE = /^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/;

/** The companion's extra rule, applied on top of the TypeScript result. */
function hostRule(result: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = { ...result };
  const host = out.referrerHost;
  if (typeof host === "string" && !HOST_RULE.test(host)) delete out.referrerHost;
  return out;
}

const repeat = (text: string, times: number) => text.repeat(times);

type Case = { name: string; input: unknown; note?: string };

const cases: Case[] = [
  // ---- shape and containers ----
  { name: "empty-object", input: {} },
  { name: "null-input", input: null },
  { name: "string-input", input: "utmSource=a" },
  { name: "number-input", input: 5 },
  { name: "empty-array", input: [] },
  { name: "array-input", input: ["a", "b"] },
  {
    name: "all-fields-valid",
    input: {
      utmSource: "google",
      utmMedium: "cpc",
      utmCampaign: "eofy_catering",
      utmTerm: "office catering sydney",
      utmContent: "ad-1",
      gclid: "Cj0KCQjw",
      gbraid: "0AAAAAB",
      wbraid: "CjkKCAj",
      fbclid: "IwAR0",
      msclkid: "abcdef0123",
      referrerHost: "www.google.com",
      landingPath: "/catering/corporate",
      firstSeenAt: "2026-10-02T01:02:03.456Z",
    },
  },
  { name: "unknown-keys-dropped", input: { ip: "203.0.113.9", userAgent: "x", email: "a@b.co", utm_source: "snake", utmSource: "kept" } },
  { name: "key-order-follows-the-schema", input: { landingPath: "/a", fbclid: "f", utmSource: "s", referrerHost: "h.example" } },
  { name: "null-value-dropped", input: { utmSource: null, utmMedium: "m" } },
  // ---- type confusion ----
  { name: "number-value", input: { utmSource: 123 } },
  { name: "boolean-value", input: { utmSource: true } },
  { name: "array-value", input: { utmSource: ["a"] } },
  { name: "object-value", input: { utmSource: { a: 1 } } },
  // ---- trim ----
  { name: "trimmed", input: { utmSource: "  spaced  " } },
  { name: "trim-tab-newline", input: { utmMedium: "\t\n x \r\n" } },
  { name: "trim-nbsp-bom-ideographic", input: { utmCampaign: " ﻿　camp　﻿ " } },
  { name: "trim-line-separators", input: { utmTerm: "  term " } },
  { name: "trim-en-quad-and-narrow-nbsp", input: { utmContent: "    c " } },
  { name: "next-line-is-not-whitespace", input: { utmSource: "\u0085x" } },
  { name: "empty-string", input: { utmSource: "" } },
  { name: "whitespace-only", input: { utmSource: " \t  " } },
  // ---- control characters ----
  { name: "control-nul-inside", input: { utmSource: "a\u0000b" } },
  { name: "control-newline-inside", input: { utmSource: "a\nb" } },
  { name: "control-tab-inside", input: { utmSource: "a\tb" } },
  { name: "control-escape", input: { utmSource: "a\u001bb" } },
  { name: "control-unit-separator", input: { gclid: "a\u001fb" } },
  { name: "control-delete", input: { utmSource: "a\u007fb" } },
  { name: "c1-control-allowed", input: { utmSource: "a\u0080b\u009fc" } },
  { name: "control-in-one-field-others-survive", input: { utmSource: "good", utmMedium: "bad\u0000", gclid: "ok" } },
  // ---- the 120 cap, counted in Unicode code points (zod 4 counts code points; see zod_version in the fixture) ----
  { name: "cap-120-exact", input: { utmCampaign: repeat("a", 120) } },
  { name: "cap-121-dropped", input: { utmCampaign: repeat("a", 121) } },
  { name: "cap-120-after-trim", input: { utmCampaign: ` ${repeat("a", 120)} ` } },
  { name: "cap-multibyte-120-chars", input: { utmCampaign: repeat("\u00e9", 120) } },
  { name: "cap-multibyte-121-dropped", input: { utmCampaign: repeat("\u00e9", 121) } },
  { name: "cap-bmp-cjk-120", input: { utmCampaign: repeat("\u4e2d", 120) } },
  { name: "cap-bmp-cjk-121", input: { utmCampaign: repeat("\u4e2d", 121) } },
  { name: "cap-astral-120-code-points-kept", input: { utmCampaign: repeat("\u{1f355}", 120) }, note: "240 UTF-16 units but 120 code points: kept, because zod 4 counts code points" },
  { name: "cap-astral-121-code-points-dropped", input: { utmCampaign: repeat("\u{1f355}", 121) } },
  { name: "cap-astral-plus-ascii-120-code-points", input: { utmCampaign: repeat("\u{1f355}", 118) + "ab" } },
  { name: "cap-astral-plus-ascii-121-code-points", input: { utmCampaign: repeat("\u{1f355}", 118) + "abc" } },
  { name: "click-id-121-dropped", input: { gclid: repeat("g", 121), fbclid: repeat("f", 120) } },
  // ---- referrer host: host only, not a URL ----
  { name: "host-simple", input: { referrerHost: "www.google.com" } },
  { name: "host-trimmed", input: { referrerHost: "  l.facebook.com " } },
  { name: "host-mixed-case-kept", input: { referrerHost: "WWW.Example.COM" } },
  { name: "host-punycode", input: { referrerHost: "xn--bcher-kva.example" } },
  { name: "host-single-label", input: { referrerHost: "localhost" } },
  { name: "host-253-ok", input: { referrerHost: `${repeat("a", 63)}.${repeat("b", 63)}.${repeat("c", 63)}.${repeat("d", 61)}` } },
  { name: "host-254-dropped", input: { referrerHost: `${repeat("a", 63)}.${repeat("b", 63)}.${repeat("c", 63)}.${repeat("d", 62)}` } },
  { name: "host-empty", input: { referrerHost: "" } },
  { name: "host-with-control-char", input: { referrerHost: "a\u0000b.com" }, note: "TS keeps it; the companion drops it" },
  { name: "host-with-newline", input: { referrerHost: "a.com\nb.com" }, note: "TS keeps it; the companion drops it" },
  { name: "host-with-space-inside", input: { referrerHost: "a b.com" }, note: "TS keeps it; the companion drops it" },
  { name: "host-with-scheme", input: { referrerHost: "https://a.com" }, note: "TS keeps it; the companion drops it" },
  { name: "host-with-path", input: { referrerHost: "a.com/path" }, note: "TS keeps it; the companion drops it" },
  { name: "host-with-port", input: { referrerHost: "a.com:8080" }, note: "TS keeps it; the companion drops it" },
  { name: "host-with-userinfo", input: { referrerHost: "user@a.com" }, note: "TS keeps it; the companion drops it" },
  { name: "host-ipv6-bracketed", input: { referrerHost: "[::1]" }, note: "TS keeps it; the companion drops it" },
  { name: "host-leading-dot", input: { referrerHost: ".a.com" }, note: "TS keeps it; the companion drops it" },
  { name: "host-trailing-hyphen", input: { referrerHost: "a.com-" }, note: "TS keeps it; the companion drops it" },
  { name: "host-non-ascii", input: { referrerHost: "bücher.example" }, note: "TS keeps it; the companion drops it (browsers send punycode)" },
  { name: "host-html-injection", input: { referrerHost: "<script>.com" }, note: "TS keeps it; the companion drops it" },
  // ---- landing path: path only, never a query or a URL ----
  { name: "path-simple", input: { landingPath: "/catering/corporate" } },
  { name: "path-root", input: { landingPath: "/" } },
  { name: "path-trimmed", input: { landingPath: "  /catering/events  " } },
  { name: "path-with-query-rejected", input: { landingPath: "/a?b=1" } },
  { name: "path-with-empty-query-rejected", input: { landingPath: "/a?" } },
  { name: "path-with-fragment-rejected", input: { landingPath: "/catering#top" } },
  { name: "path-absolute-url-rejected", input: { landingPath: "https://evil.example/x" } },
  { name: "path-protocol-relative-slashes-kept", input: { landingPath: "//evil.example/x" } },
  { name: "path-no-leading-slash", input: { landingPath: "catering" } },
  { name: "path-empty", input: { landingPath: "" } },
  { name: "path-inner-space-rejected", input: { landingPath: "/a b" } },
  { name: "path-inner-tab-rejected", input: { landingPath: "/a\tb" } },
  { name: "path-inner-nbsp-rejected", input: { landingPath: "/a b" } },
  { name: "path-inner-line-separator-rejected", input: { landingPath: "/a b" } },
  { name: "path-percent-encoded-kept", input: { landingPath: "/caf%C3%A9/menu" } },
  { name: "path-unicode-kept", input: { landingPath: "/café/menu" } },
  { name: "path-nul-kept-like-ts", input: { landingPath: "/a\u0000b" }, note: "neither TS nor the companion checks control characters in a path; only whitespace, ? and # are refused" },
  { name: "path-200-ok", input: { landingPath: `/${repeat("p", 199)}` } },
  { name: "path-201-dropped", input: { landingPath: `/${repeat("p", 200)}` } },
  { name: "path-astral-200-code-points-kept", input: { landingPath: `/${repeat("\u{1f355}", 199)}` } },
  { name: "path-astral-201-code-points-dropped", input: { landingPath: `/${repeat("\u{1f355}", 200)}` } },
  { name: "path-trailing-newline-trimmed", input: { landingPath: "/x\n" } },
  // ---- first seen: strict ISO date-time in UTC ----
  { name: "datetime-seconds", input: { firstSeenAt: "2026-10-02T10:00:00Z" } },
  { name: "datetime-milliseconds", input: { firstSeenAt: "2026-10-02T10:00:00.123Z" } },
  { name: "datetime-long-fraction", input: { firstSeenAt: "2026-10-02T10:00:00.1234567Z" } },
  { name: "datetime-no-seconds", input: { firstSeenAt: "2026-10-02T10:00Z" } },
  { name: "datetime-offset-rejected", input: { firstSeenAt: "2026-10-02T10:00:00+10:00" } },
  { name: "datetime-local-rejected", input: { firstSeenAt: "2026-10-02T10:00:00" } },
  { name: "datetime-date-only", input: { firstSeenAt: "2026-10-02" } },
  { name: "datetime-lowercase-rejected", input: { firstSeenAt: "2026-10-02t10:00:00z" } },
  { name: "datetime-impossible-day", input: { firstSeenAt: "2026-02-30T10:00:00Z" } },
  { name: "datetime-leap-day-2028", input: { firstSeenAt: "2028-02-29T10:00:00Z" } },
  { name: "datetime-no-leap-day-2026", input: { firstSeenAt: "2026-02-29T10:00:00Z" } },
  { name: "datetime-no-leap-day-2100", input: { firstSeenAt: "2100-02-29T10:00:00Z" } },
  { name: "datetime-leap-day-2000", input: { firstSeenAt: "2000-02-29T10:00:00Z" } },
  { name: "datetime-hour-24", input: { firstSeenAt: "2026-10-02T24:00:00Z" } },
  { name: "datetime-second-60", input: { firstSeenAt: "2026-10-02T23:59:60Z" } },
  { name: "datetime-month-13", input: { firstSeenAt: "2026-13-02T10:00:00Z" } },
  { name: "datetime-leading-space-not-trimmed", input: { firstSeenAt: " 2026-10-02T10:00:00Z" } },
  { name: "datetime-trailing-newline-rejected", input: { firstSeenAt: "2026-10-02T10:00:00Z\n" } },
  { name: "datetime-number-rejected", input: { firstSeenAt: 1790899200 } },
  { name: "datetime-unicode-digits-rejected", input: { firstSeenAt: "2026-10-02T10:00:00٠Z" } },
  // ---- negative controls: hostile input that must never survive ----
  { name: "negative-all-bad", input: { utmSource: "a\u0000", utmMedium: repeat("m", 500), gclid: 7, referrerHost: "https://x", landingPath: "/p?q=1", firstSeenAt: "yesterday" } },
  { name: "negative-personal-data-in-a-param", input: { utmSource: "a@b.co" }, note: "the schema does not detect personal data in a value; the field is kept. The browser only reads what is in the URL and the capture never takes form fields." },
];

interface FixtureCase {
  name: string;
  input: unknown;
  ts_expected: Record<string, unknown>;
  expected: Record<string, unknown>;
  host_rule_differs: boolean;
  note?: string;
}

/** Build the fixture object. Pure: nothing is written. */
export function buildFixture() {
  const names = new Set<string>();
  const built: FixtureCase[] = cases.map((c) => {
    if (names.has(c.name)) throw new Error(`duplicate case name ${c.name}`);
    names.add(c.name);
    const ts = sanitiseAttribution(c.input) as Record<string, unknown>;
    const expected = hostRule(ts);
    const differs = JSON.stringify(ts) !== JSON.stringify(expected);
    const item: FixtureCase = { name: c.name, input: c.input, ts_expected: ts, expected, host_rule_differs: differs };
    if (c.note !== undefined) item.note = c.note;
    return item;
  });
  return {
    schema_version: 1,
    source: "web/src/lib/attribution-schema.ts",
    source_sha256: createHash("sha256").update(readFileSync(schemaPath)).digest("hex"),
    zod_version: zodVersion,
    generated_by: "web/scripts/wp-oracle/export-attribution-fixtures.ts",
    host_rule: "referrerHost must also match ^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$ (not in attribution-schema.ts)",
    host_rule_cases: built.filter((c) => c.host_rule_differs).map((c) => c.name),
    cases: built,
  };
}

/** Pretty JSON with every non-ASCII code unit escaped, one case per line. Deterministic. */
export function render(data: ReturnType<typeof buildFixture>): string {
  const esc = (value: unknown): string =>
    JSON.stringify(value).replace(/[\u007f-￿]/g, (ch) => `\\u${ch.charCodeAt(0).toString(16).padStart(4, "0")}`);
  const out: string[] = ["{"];
  out.push(`  "schema_version": ${data.schema_version},`);
  out.push(`  "source": ${esc(data.source)},`);
  out.push(`  "source_sha256": ${esc(data.source_sha256)},`);
  out.push(`  "zod_version": ${esc(data.zod_version)},`);
  out.push(`  "generated_by": ${esc(data.generated_by)},`);
  out.push(`  "host_rule": ${esc(data.host_rule)},`);
  out.push(`  "host_rule_cases": ${esc(data.host_rule_cases)},`);
  out.push('  "cases": [');
  data.cases.forEach((c, index) => {
    out.push(`    ${esc(c)}${index < data.cases.length - 1 ? "," : ""}`);
  });
  out.push("  ]");
  out.push("}");
  return `${out.join("\n")}\n`;
}

export const ATTRIBUTION_FIXTURE_PATH = outPath;

function main(): number {
  const text = render(buildFixture());
  if (process.argv.includes("--check")) {
    const onDisk = existsSync(outPath) ? readFileSync(outPath, "utf8") : null;
    if (onDisk === text) {
      process.stdout.write(`attribution-cases.json is up to date (${outPath})\n`);
      return 0;
    }
    process.stderr.write(`attribution-cases.json is ${onDisk === null ? "missing" : "STALE"}: regenerate with tsx web/scripts/wp-oracle/export-attribution-fixtures.ts\n`);
    return 1;
  }
  writeFileSync(outPath, text);
  process.stdout.write(`wrote ${outPath} (${data(text)} cases)\n`);
  return 0;
}

function data(text: string): number {
  return (text.match(/^ {4}\{"name"/gm) || []).length;
}

const invokedDirectly = process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (invokedDirectly) process.exit(main());
