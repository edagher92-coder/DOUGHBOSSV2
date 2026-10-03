/**
 * Marketing attribution — where a lead or order came from.
 *
 * Captured in the browser from the landing URL (UTM parameters, ad-platform
 * click IDs) and sent along with enquiries and orders so every dollar of ad
 * spend can be tied to a result. Everything here is UNTRUSTED input (anyone can
 * craft a URL), so each field is trimmed, stripped of control characters and
 * length-capped before it reaches a database, log, webhook or ad platform.
 *
 * Privacy: we keep the referrer HOST only and the landing PATH only (no query
 * string, which can carry personal data), and no IP address or user agent.
 */
import { z } from "zod";

const CONTROL_CHARS = /[\u0000-\u001f\u007f]/;

const param = z
  .string()
  .trim()
  .min(1)
  .max(120)
  .refine((s) => !CONTROL_CHARS.test(s), "control characters are not allowed");

/** Dot-separated DNS labels (letters, digits, inner hyphens; max 63 each). No scheme, path, query, fragment, credentials, port or whitespace. */
const BARE_HOSTNAME = /^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/;

export const attributionSchema = z.object({
  utmSource: param.optional(),
  utmMedium: param.optional(),
  utmCampaign: param.optional(),
  utmTerm: param.optional(),
  utmContent: param.optional(),
  /** Google Ads click ID, plus the iOS-privacy variants gbraid/wbraid. */
  gclid: param.optional(),
  gbraid: param.optional(),
  wbraid: param.optional(),
  /** Meta (Facebook/Instagram) and Microsoft Ads click IDs. */
  fbclid: param.optional(),
  msclkid: param.optional(),
  /** Host only, e.g. "www.google.com" — never a full URL. */
  referrerHost: z
    .string()
    .trim()
    .min(1)
    .max(253)
    .refine((s) => !CONTROL_CHARS.test(s), "control characters are not allowed")
    .regex(BARE_HOSTNAME, "must be a bare hostname (no scheme, path, query, port or credentials)")
    .optional(),
  /** Path only, e.g. "/catering/corporate" — never the query string. */
  landingPath: z
    .string()
    .trim()
    .max(200)
    .regex(/^\/[^?#\s]*$/, "must be a path without a query string")
    .optional(),
  firstSeenAt: z.iso.datetime().optional(),
});

export type Attribution = z.output<typeof attributionSchema>;

/**
 * Salvage whatever valid fields exist in untrusted input; drop the rest.
 * Never throws: a malformed attribution blob must not break an enquiry or order.
 */
export function sanitiseAttribution(raw: unknown): Attribution {
  if (typeof raw !== "object" || raw === null) return {};
  const out: Record<string, unknown> = {};
  for (const [key, schema] of Object.entries(attributionSchema.shape)) {
    const value = (raw as Record<string, unknown>)[key];
    if (value === undefined) continue;
    const parsed = schema.safeParse(value);
    if (parsed.success && parsed.data !== undefined) out[key] = parsed.data;
  }
  return out as Attribution;
}

/** True when there is at least one field worth storing. */
export function hasAttribution(a: Attribution | undefined): a is Attribution {
  return a !== undefined && Object.keys(a).length > 0;
}
