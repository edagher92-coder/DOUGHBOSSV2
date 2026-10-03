/**
 * The claims ledger — how public marketing copy stays honest.
 *
 * Every factual statement a page can make (hours, "baked in-house", dietary
 * options, delivery area, minimum order…) is a Claim with a source. Pages
 * render only claims that are `confirmed` AND carry a source. Anything else is
 * a gap for Elie to fill, tracked in the ledger but invisible to customers.
 *
 * Why this exists: a wrong hour, price, dietary claim or "best in Sydney" in
 * an ad or landing page is a customer harm and an Australian Consumer Law
 * (misleading conduct) risk. This makes "never invent a fact" a mechanical
 * rule instead of a good intention: unsourced copy cannot ship.
 */

export type ClaimSource =
  /** Copied from a page the owner already publishes (cite repo/path or URL). */
  | { kind: "owner-site"; ref: string; retrieved: string }
  /** Elie confirmed it directly (say where: chat, email, call). */
  | { kind: "owner-confirmed"; ref: string; confirmedOn: string }
  /** A public third-party page (cite URL + retrieval date). Use sparingly. */
  | { kind: "public-web"; ref: string; retrieved: string };

export interface Claim {
  /** Stable kebab-case id, unique across the ledger. */
  id: string;
  /** The exact customer-facing wording. Never contains placeholder markers. */
  text: string;
  source?: ClaimSource;
  /** false = a gap/idea awaiting confirmation; never rendered. */
  confirmed: boolean;
  /** For the team: what is needed to confirm it. Never rendered. */
  note?: string;
}

const PLACEHOLDER = /\[CONFIRM|TODO|TBC|lorem ipsum|xxx/i;

/** Claims safe to show customers: confirmed, sourced, in original order. */
export function publishable(claims: readonly Claim[]): Claim[] {
  return claims.filter((c) => c.confirmed && c.source !== undefined);
}

/** Look up one publishable claim's text, or undefined when it is not (yet) publishable. */
export function claimText(claims: readonly Claim[], id: string): string | undefined {
  return publishable(claims).find((c) => c.id === id)?.text;
}

/** Throws a readable error listing every violation, so a bad ledger fails CI loudly. */
export function assertLedger(claims: readonly Claim[], label = "ledger"): void {
  const problems: string[] = [];
  const seen = new Set<string>();
  for (const c of claims) {
    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(c.id)) problems.push(`${c.id}: id must be kebab-case`);
    if (seen.has(c.id)) problems.push(`${c.id}: duplicate id`);
    seen.add(c.id);
    if (c.text.trim().length === 0) problems.push(`${c.id}: empty text`);
    if (PLACEHOLDER.test(c.text)) problems.push(`${c.id}: text contains a placeholder marker`);
    if (c.confirmed && !c.source) problems.push(`${c.id}: confirmed but has no source`);
    if (c.source && !c.source.ref.trim()) problems.push(`${c.id}: source has an empty ref`);
  }
  if (problems.length > 0) {
    throw new Error(`Claims ${label} is invalid:\n- ${problems.join("\n- ")}`);
  }
}
