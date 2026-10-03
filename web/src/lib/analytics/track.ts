import type { EventName, EventParams } from "./events";

/**
 * Record an analytics event.
 *
 * STUB: a safe no-op until the growth slice replaces this file with the real,
 * consent-aware dispatcher (dataLayer / gtag / Meta Pixel, all env-driven).
 * Call sites are final — they only ever pass the typed params from ./events.
 */
export function track<E extends EventName>(_name: E, _params: EventParams[E]): void {
  // intentionally empty
}
