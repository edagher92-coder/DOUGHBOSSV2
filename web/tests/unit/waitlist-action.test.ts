import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { joinWaitlist } from "@/app/actions/waitlist";
import { resetEnvCache } from "@/lib/env";
import { getWaitlistRepository, resetWaitlistRepository, type InMemoryWaitlistRepository } from "@/lib/repositories/waitlist";
import { resetRateLimiter } from "@/lib/rate-limit";
import { WAITLIST_CONSENT_TEXT } from "@/lib/validations";

vi.mock("next/headers", () => ({ headers: async () => new Headers({ "x-forwarded-for": "203.0.113.9" }) }));
const notify = vi.hoisted(() => vi.fn());
vi.mock("@/lib/notify", () => ({ notifyWaitlistSignup: notify }));

const input = (over: Record<string, unknown> = {}) => ({
  name: "Sam Test",
  email: "sam@example.test",
  consent: true,
  ...over,
});

beforeEach(() => {
  vi.stubEnv("DATABASE_URL", "");
  vi.stubEnv("NODE_ENV", "test");
  resetEnvCache();
  resetWaitlistRepository();
  resetRateLimiter();
  notify.mockReset();
  notify.mockResolvedValue({ delivered: true });
});
afterEach(() => {
  vi.unstubAllEnvs();
  resetEnvCache();
});

describe("joinWaitlist (enumeration and ownership)", () => {
  it("returns an identical response for a new and an already-subscribed email", async () => {
    const first = await joinWaitlist(input());
    const second = await joinWaitlist(input());
    expect(second).toEqual(first);
    expect(first).toEqual({ ok: true });
    expect(JSON.stringify(second)).not.toMatch(/already|updated|created/i);
  });

  it("does not change an existing subscriber's details or announce the unverified submission", async () => {
    await joinWaitlist(input({ phone: "0412345678", storeSlug: "revesby" }));
    notify.mockClear();
    await joinWaitlist(input({ name: "Mallory Attacker", phone: "0499999999", storeSlug: "bankstown" }));

    const rows = (getWaitlistRepository() as InMemoryWaitlistRepository).all();
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ name: "Sam Test", phone: "0412345678", storeSlug: "revesby", consentText: WAITLIST_CONSENT_TEXT });
    expect(notify).not.toHaveBeenCalled();
  });
});
