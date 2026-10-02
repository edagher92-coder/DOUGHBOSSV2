/**
 * Team notification for new Minis waitlist signups. Best effort by design: the
 * signup is already saved, so a dead webhook must never fail the visitor's
 * request. Failures are returned (and the row stays un-notified for retry).
 */
import { getEnv } from "./env";

export interface WaitlistNotification {
  name: string;
  email: string;
  phone?: string | undefined;
  interests: string[];
  partyPieces?: number | undefined;
  storeSlug?: string | undefined;
  source?: string | undefined;
  createdAt?: string | undefined;
}

export type NotifyResult = { delivered: boolean; reason?: string };

const TIMEOUT_MS = 4_000;

export async function notifyWaitlistSignup(
  payload: WaitlistNotification,
  fetchImpl: typeof fetch = fetch,
): Promise<NotifyResult> {
  let url: string | undefined;
  try {
    url = getEnv().WAITLIST_WEBHOOK_URL;
  } catch {
    return { delivered: false, reason: "CONFIG_INVALID" };
  }
  if (!url) return { delivered: false, reason: "NOT_CONFIGURED" };

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
  try {
    const res = await fetchImpl(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ event: "waitlist.signup", ...payload }),
      signal: controller.signal,
    });
    return res.ok ? { delivered: true } : { delivered: false, reason: `HTTP_${res.status}` };
  } catch {
    return { delivered: false, reason: controller.signal.aborted ? "TIMEOUT" : "NETWORK_ERROR" };
  } finally {
    clearTimeout(timer);
  }
}
