"use server";

import { headers } from "next/headers";
import { isStorageUnavailable } from "@/lib/errors";
import { notifyWaitlistSignup } from "@/lib/notify";
import { clientKey, getRateLimiter } from "@/lib/rate-limit";
import { getWaitlistRepository } from "@/lib/repositories/waitlist";
import { WAITLIST_CONSENT_TEXT, fieldErrors, waitlistSchema } from "@/lib/validations";
import type { WaitlistResult } from "@/types/actions";

const LIMIT = { limit: 5, windowMs: 10 * 60_000 } as const;

/**
 * Join the "VIP first look" list for the generic coming-soon teaser. The server is the
 * only gate: the result is `ok: true` only after the signup is durably stored.
 * A filled honeypot (`company`) fails validation like any other bad input.
 */
export async function joinWaitlist(input: unknown): Promise<WaitlistResult> {
  const parsed = waitlistSchema.safeParse(input);
  if (!parsed.success) {
    return { ok: false, code: "VALIDATION", message: "Please check the highlighted fields.", fieldErrors: fieldErrors(parsed.error) };
  }
  const data = parsed.data;

  const limit = await getRateLimiter().check(`waitlist:${clientKey(await headers())}`, LIMIT);
  if (!limit.ok) {
    return {
      ok: false,
      code: "RATE_LIMITED",
      message: "Too many attempts. Please try again in a few minutes.",
      retryAfterSeconds: Math.ceil(limit.retryAfterMs / 1000),
    };
  }

  try {
    const repo = getWaitlistRepository();
    const { created, id } = await repo.upsert({
      name: data.name,
      email: data.email,
      phone: data.phone,
      storeSlug: data.storeSlug,
      consentAt: new Date(),
      consentText: WAITLIST_CONSENT_TEXT,
    });
    // Best effort: the signup is already saved, so a dead webhook never fails the visitor.
    // Only a genuinely new signup is announced: for an existing address the submitted
    // details are unverified and were not stored.
    if (created) {
      const notified = await notifyWaitlistSignup({
        name: data.name,
        email: data.email,
        phone: data.phone,
        storeSlug: data.storeSlug,
        source: "coming-soon-teaser",
        createdAt: new Date().toISOString(),
      });
      if (notified.delivered) await repo.markNotified(id).catch(() => undefined);
    }
    // Deliberately identical for new and existing addresses: the response must not reveal
    // whether an email is already subscribed.
    return { ok: true };
  } catch (err) {
    if (isStorageUnavailable(err)) return { ok: false, code: "STORAGE_UNAVAILABLE", message: err.message };
    return { ok: false, code: "UNKNOWN", message: "Something went wrong on our side. Please try again." };
  }
}
