/**
 * Typed environment access. Everything optional is genuinely optional: the site
 * must boot without a database or Stripe (and say so) rather than crash or
 * pretend. Only *invalid* values and the demo-in-production combination throw.
 *
 * Names only appear in docs; values are never logged from here.
 */
import { z } from "zod";
import { assertDemoAllowed } from "./data/demo-catalogue";

/** `.env.example` ships blank placeholders (`DATABASE_URL=`); a blank means "not set". */
const blankToUndefined = (v: unknown): unknown => (typeof v === "string" && v.trim() === "" ? undefined : v);

const optionalString = z.preprocess(blankToUndefined, z.string().optional());
const optionalUrl = z.preprocess(blankToUndefined, z.url().optional());

/**
 * Strict flag: "1"/"true"/"0"/"false"/blank only. A typo such as "yes" on a
 * payment or demo-data switch should fail loudly at boot, not silently flip.
 */
const flag = z.preprocess(
  (v) => (typeof v === "string" ? v.trim().toLowerCase() : v),
  z
    .union([z.literal(""), z.literal("1"), z.literal("true"), z.literal("0"), z.literal("false"), z.undefined()])
    .transform((v) => v === "1" || v === "true"),
);

const envSchema = z.object({
  NODE_ENV: z.enum(["development", "test", "production"]).default("development"),
  DATABASE_URL: optionalString,
  DIRECT_URL: optionalString,
  STRIPE_SECRET_KEY: optionalString,
  STRIPE_WEBHOOK_SECRET: optionalString,
  ALLOW_PAY_AT_PICKUP: flag,
  WAITLIST_WEBHOOK_URL: optionalUrl,
  UPSTASH_REDIS_REST_URL: optionalUrl,
  UPSTASH_REDIS_REST_TOKEN: optionalString,
  RATE_LIMIT_SALT: optionalString,
  NEXT_PUBLIC_SITE_URL: z.preprocess(blankToUndefined, z.url().default("https://doughboss.com.au")),
  DOUGHBOSS_DEMO_DATA: flag,
});

export type Env = z.output<typeof envSchema>;

export function parseEnv(source: Record<string, string | undefined> = process.env): Env {
  const parsed = envSchema.safeParse(source);
  if (!parsed.success) {
    // Names only: never echo a value that might be a secret.
    const bad = parsed.error.issues.map((i) => i.path.join(".") || "(root)").join(", ");
    throw new Error(`Invalid environment configuration: ${bad}`);
  }
  // Fake prices in front of customers is the one combination we refuse outright.
  if (parsed.data.DOUGHBOSS_DEMO_DATA) assertDemoAllowed({ NODE_ENV: parsed.data.NODE_ENV });
  return parsed.data;
}

let cached: Env | undefined;

export function getEnv(): Env {
  cached ??= parseEnv();
  return cached;
}

/** Tests mutate process.env between cases; production code never needs this. */
export function resetEnvCache(): void {
  cached = undefined;
}
