"use client";

import { useEffect, useState, useSyncExternalStore } from "react";
import { useReducedMotion } from "motion/react";

const subscribeNoop = () => () => {};

/** false on the server and during hydration, true afterwards — browser-only rendering without mismatches. */
export function useIsClient(): boolean {
  return useSyncExternalStore(subscribeNoop, () => true, () => false);
}

/**
 * The current time, or null until mounted. Returning null (not `new Date()`)
 * on the first render is what keeps server and client markup identical —
 * "Open now" computed on the server would be stale or wrong by hydration.
 */
export function useNow(intervalMs = 30_000): Date | null {
  const [now, setNow] = useState<Date | null>(null);
  useEffect(() => {
    setNow(new Date());
    const id = window.setInterval(() => setNow(new Date()), intervalMs);
    return () => window.clearInterval(id);
  }, [intervalMs]);
  return now;
}

/** True when the visitor has asked their OS for reduced motion. */
export function usePrefersReducedMotion(): boolean {
  return useReducedMotion() ?? false;
}
