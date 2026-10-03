/**
 * Store opening hours, live open/closed status and pickup-slot generation.
 *
 * All wall-clock maths happens in the *store's* IANA timezone (Australia/Sydney),
 * so AEST/AEDT changeovers are handled by the platform's tz database through
 * Intl — never by hard-coding a +10/+11 offset. The functions are pure and take
 * `now` explicitly, which is what makes them unit-testable across DST.
 */
import type { PickupSlot, Store, StoreHoursWindow, StoreStatus } from "@/types/menu";

interface LocalParts {
  year: number;
  month: number; // 1–12
  day: number;
  weekday: number; // 0 = Sunday
  minutes: number; // minutes since local midnight
}

const WEEKDAYS: Record<string, number> = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
const formatters = new Map<string, Intl.DateTimeFormat>();

function formatterFor(timeZone: string): Intl.DateTimeFormat {
  let f = formatters.get(timeZone);
  if (!f) {
    f = new Intl.DateTimeFormat("en-AU", {
      timeZone,
      hourCycle: "h23",
      year: "numeric",
      month: "numeric",
      day: "numeric",
      weekday: "short",
      hour: "numeric",
      minute: "numeric",
    });
    formatters.set(timeZone, f);
  }
  return f;
}

export function localParts(instant: Date, timeZone: string): LocalParts {
  const parts = formatterFor(timeZone).formatToParts(instant);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? "";
  const hour = Number(get("hour")) % 24;
  return {
    year: Number(get("year")),
    month: Number(get("month")),
    day: Number(get("day")),
    weekday: WEEKDAYS[get("weekday")] ?? 0,
    minutes: hour * 60 + Number(get("minute")),
  };
}

/** Offset (ms) of `timeZone` from UTC at `instant`: positive east of Greenwich. */
function zoneOffsetMs(instant: Date, timeZone: string): number {
  const p = localParts(instant, timeZone);
  const asUtc = Date.UTC(p.year, p.month - 1, p.day, Math.floor(p.minutes / 60), p.minutes % 60);
  // Drop seconds/ms from the comparison — the formatter only resolves to the minute.
  return asUtc - Math.floor(instant.getTime() / 60_000) * 60_000;
}

/** Local wall-clock (Y-M-D + minutes after midnight) → the absolute instant, DST-correct. */
export function zonedTimeToInstant(
  year: number,
  month: number,
  day: number,
  minutes: number,
  timeZone: string,
): Date {
  const naive = Date.UTC(year, month - 1, day, 0, minutes);
  // Two passes converge across a DST boundary.
  let guess = naive - zoneOffsetMs(new Date(naive), timeZone);
  guess = naive - zoneOffsetMs(new Date(guess), timeZone);
  return new Date(guess);
}

const pad = (n: number) => String(n).padStart(2, "0");
export const localDateKey = (p: Pick<LocalParts, "year" | "month" | "day">) => `${p.year}-${pad(p.month)}-${pad(p.day)}`;

/** Add whole calendar days to a local date, using UTC arithmetic so DST can't skew it. */
function addDays(p: Pick<LocalParts, "year" | "month" | "day">, days: number) {
  const d = new Date(Date.UTC(p.year, p.month - 1, p.day + days));
  return { year: d.getUTCFullYear(), month: d.getUTCMonth() + 1, day: d.getUTCDate(), weekday: d.getUTCDay() };
}

export function formatClock(minutes: number): string {
  const h24 = Math.floor(minutes / 60) % 24;
  const m = minutes % 60;
  const suffix = h24 >= 12 ? "pm" : "am";
  const h12 = h24 % 12 === 0 ? 12 : h24 % 12;
  return m === 0 ? `${h12}${suffix}` : `${h12}:${pad(m)}${suffix}`;
}

function windowsFor(store: Store, weekday: number): StoreHoursWindow[] {
  return store.hours.filter((h) => h.dayOfWeek === weekday).sort((a, b) => a.opensMin - b.opensMin);
}

function isClosedDate(store: Store, p: Pick<LocalParts, "year" | "month" | "day">): boolean {
  return store.closures.includes(localDateKey(p));
}

/** The next moment (strictly after `now`) the store opens, searching up to 14 days ahead. */
function findNextOpening(store: Store, now: Date): Date | null {
  const here = localParts(now, store.timezone);
  for (let offset = 0; offset <= 14; offset++) {
    const day = addDays(here, offset);
    if (isClosedDate(store, day)) continue;
    for (const w of windowsFor(store, day.weekday)) {
      const opens = zonedTimeToInstant(day.year, day.month, day.day, w.opensMin, store.timezone);
      if (opens.getTime() > now.getTime()) return opens;
    }
  }
  return null;
}

function describeOpening(store: Store, opensAt: Date, now: Date): string {
  const nowLocal = localParts(now, store.timezone);
  const at = localParts(opensAt, store.timezone);
  const time = formatClock(at.minutes);
  const tomorrow = addDays(nowLocal, 1);
  if (localDateKey(at) === localDateKey(nowLocal)) return `Opens today ${time}`;
  if (localDateKey(at) === localDateKey(tomorrow)) return `Opens tomorrow ${time}`;
  const dayName = new Intl.DateTimeFormat("en-AU", { timeZone: store.timezone, weekday: "long" }).format(opensAt);
  return `Opens ${dayName} ${time}`;
}

export function getStoreStatus(store: Store, now: Date = new Date()): StoreStatus {
  const here = localParts(now, store.timezone);
  if (!isClosedDate(store, here)) {
    for (const w of windowsFor(store, here.weekday)) {
      if (here.minutes >= w.opensMin && here.minutes < w.closesMin) {
        const closesAt = zonedTimeToInstant(here.year, here.month, here.day, w.closesMin, store.timezone);
        return { state: "open", closesAt, label: `Open now · closes ${formatClock(w.closesMin)}` };
      }
    }
  }
  const opensAt = findNextOpening(store, now);
  return {
    state: "closed",
    opensAt,
    label: opensAt ? `Closed · ${describeOpening(store, opensAt, now)}` : "Closed",
  };
}

export interface PickupSlotOptions {
  /** Slot spacing. */
  intervalMin?: number;
  /** Calendar days to offer, including today. */
  days?: number;
  /** Hard cap on slots returned. */
  max?: number;
}

/**
 * Pickup slots from `now`. A slot is offered only if:
 *  - it falls inside an opening window on a non-closure day,
 *  - it is at least `store.prepMinutes` after `now`,
 *  - it starts strictly before closing (so there is always time to collect).
 */
export function getPickupSlots(store: Store, now: Date = new Date(), opts: PickupSlotOptions = {}): PickupSlot[] {
  const { intervalMin = 15, days = 2, max = 96 } = opts;
  const earliest = now.getTime() + store.prepMinutes * 60_000;
  const here = localParts(now, store.timezone);
  const slots: PickupSlot[] = [];

  for (let offset = 0; offset < days; offset++) {
    const day = addDays(here, offset);
    if (isClosedDate(store, day)) continue;
    const dayLabel =
      offset === 0
        ? "Today"
        : offset === 1
          ? "Tomorrow"
          : new Intl.DateTimeFormat("en-AU", {
              timeZone: store.timezone,
              weekday: "short",
              day: "numeric",
              month: "short",
            }).format(zonedTimeToInstant(day.year, day.month, day.day, 12 * 60, store.timezone));

    for (const w of windowsFor(store, day.weekday)) {
      for (let m = w.opensMin; m < w.closesMin; m += intervalMin) {
        const instant = zonedTimeToInstant(day.year, day.month, day.day, m, store.timezone);
        if (instant.getTime() < earliest) continue;
        slots.push({ iso: instant.toISOString(), label: formatClock(m), dayLabel });
        if (slots.length >= max) return slots;
      }
    }
  }
  return slots;
}

/** Server-side re-check: is this exact instant one the store would have offered? */
export function isValidPickupInstant(store: Store, pickupAt: Date, now: Date = new Date(), opts: PickupSlotOptions = {}): boolean {
  // toISOString() throws a RangeError on an invalid Date; a gate must answer "no", not crash.
  if (Number.isNaN(pickupAt.getTime())) return false;
  const iso = pickupAt.toISOString();
  return getPickupSlots(store, now, { ...opts, max: 1000 }).some((s) => s.iso === iso);
}
