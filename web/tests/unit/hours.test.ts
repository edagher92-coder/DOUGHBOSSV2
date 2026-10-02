import { describe, expect, it } from "vitest";
import { STORES } from "@/lib/data/catalogue";
import {
  formatClock,
  getPickupSlots,
  getStoreStatus,
  isValidPickupInstant,
  localDateKey,
  localParts,
  zonedTimeToInstant,
} from "@/lib/hours";
import type { Store, StoreSlug } from "@/types/menu";

const TZ = "Australia/Sydney";
const iso = (d: Date) => d.toISOString();

function store(slug: StoreSlug): Store {
  const found = STORES.find((s) => s.slug === slug);
  if (!found) throw new Error(`fixture store missing: ${slug}`);
  return found;
}

describe("zonedTimeToInstant (Sydney DST)", () => {
  it("uses AEST (+10) the day before DST begins", () => {
    expect(iso(zonedTimeToInstant(2026, 10, 3, 390, TZ))).toBe("2026-10-02T20:30:00.000Z");
  });

  it("uses AEDT (+11) on the day DST begins (4 Oct 2026, 02:00 -> 03:00)", () => {
    expect(iso(zonedTimeToInstant(2026, 10, 4, 390, TZ))).toBe("2026-10-03T19:30:00.000Z");
  });

  it("uses AEDT (+11) the day before DST ends", () => {
    expect(iso(zonedTimeToInstant(2026, 4, 4, 390, TZ))).toBe("2026-04-03T19:30:00.000Z");
  });

  it("uses AEST (+10) on the day DST ended (5 Apr 2026, 03:00 -> 02:00)", () => {
    expect(iso(zonedTimeToInstant(2026, 4, 5, 390, TZ))).toBe("2026-04-04T20:30:00.000Z");
  });

  it("agrees with Intl: the converted instant renders back as the requested wall clock", () => {
    for (const [y, m, d] of [
      [2026, 10, 3],
      [2026, 10, 4],
      [2026, 10, 5],
      [2026, 4, 4],
      [2026, 4, 5],
      [2026, 7, 15],
    ] as const) {
      const instant = zonedTimeToInstant(y, m, d, 14 * 60 + 15, TZ);
      expect(localParts(instant, TZ)).toMatchObject({ year: y, month: m, day: d, minutes: 14 * 60 + 15 });
    }
  });
});

describe("localParts", () => {
  it("returns Sydney wall-clock fields including the weekday", () => {
    // 2026-10-02T21:00Z = Sat 3 Oct 2026 07:00 AEST (+10; DST only starts on Sun 4 Oct)
    expect(localParts(new Date("2026-10-02T21:00:00Z"), TZ)).toEqual({
      year: 2026,
      month: 10,
      day: 3,
      weekday: 6,
      minutes: 420,
    });
  });

  it("treats local midnight as minute 0, not 24:00", () => {
    // 2026-10-02T14:00Z = Sat 3 Oct 00:00 AEST
    expect(localParts(new Date("2026-10-02T14:00:00Z"), TZ)).toMatchObject({ day: 3, weekday: 6, minutes: 0 });
  });

  it("round-trips with zonedTimeToInstant for every quarter hour across a DST day", () => {
    for (let m = 0; m < 1440; m += 15) {
      // Skip the 02:00-02:59 hour that does not exist on 4 Oct 2026.
      if (m >= 120 && m < 180) continue;
      const parts = localParts(zonedTimeToInstant(2026, 10, 4, m, TZ), TZ);
      expect(parts).toMatchObject({ year: 2026, month: 10, day: 4, weekday: 0, minutes: m });
    }
  });

  it("formats date keys with zero padding", () => {
    expect(localDateKey({ year: 2026, month: 4, day: 5 })).toBe("2026-04-05");
  });
});

describe("formatClock", () => {
  it.each([
    [0, "12am"],
    [390, "6:30am"],
    [420, "7am"],
    [720, "12pm"],
    [870, "2:30pm"],
    [905, "3:05pm"],
  ])("%i minutes -> %s", (minutes, expected) => {
    expect(formatClock(minutes)).toBe(expected);
  });
});

describe("getStoreStatus", () => {
  it("reports Revesby open with a closing label and instant", () => {
    // 2026-10-02T21:00Z = Sat 07:00 AEST
    const status = getStoreStatus(store("revesby"), new Date("2026-10-02T21:00:00Z"));
    expect(status.state).toBe("open");
    if (status.state !== "open") return;
    expect(status.label).toBe("Open now · closes 2:30pm");
    expect(iso(status.closesAt)).toBe("2026-10-03T04:30:00.000Z");
  });

  it("reports Bankstown closed on Saturday and opening Monday 7am (DST-aware)", () => {
    const status = getStoreStatus(store("bankstown"), new Date("2026-10-03T00:00:00Z"));
    expect(status.state).toBe("closed");
    if (status.state !== "closed") return;
    expect(status.label).toBe("Closed · Opens Monday 7am");
    // Monday 5 Oct 07:00 AEDT (+11) = Sunday 4 Oct 20:00Z
    expect(status.opensAt && iso(status.opensAt)).toBe("2026-10-04T20:00:00.000Z");
  });

  it("is open one minute before closing and closed at closing (exclusive)", () => {
    const bankstown = store("bankstown");
    // Fri 2 Oct 13:59 AEST / 14:00 AEST
    expect(getStoreStatus(bankstown, new Date("2026-10-02T03:59:00Z")).state).toBe("open");
    expect(getStoreStatus(bankstown, new Date("2026-10-02T04:00:00Z")).state).toBe("closed");
  });

  it("is open at the exact opening minute", () => {
    // Fri 2 Oct 07:00 AEST
    expect(getStoreStatus(store("bankstown"), new Date("2026-10-01T21:00:00Z")).state).toBe("open");
  });

  it("says 'Opens today' before opening time", () => {
    // Fri 2 Oct 05:00 AEST
    const status = getStoreStatus(store("bankstown"), new Date("2026-10-01T19:00:00Z"));
    expect(status.state).toBe("closed");
    expect(status.label).toBe("Closed · Opens today 7am");
  });

  it("says 'Opens tomorrow' after closing", () => {
    // Roselands Fri 2 Oct 16:00 AEST, closed at 15:00, opens Sat 08:00
    const status = getStoreStatus(store("roselands"), new Date("2026-10-02T06:00:00Z"));
    expect(status.state).toBe("closed");
    expect(status.label).toBe("Closed · Opens tomorrow 8am");
  });

  it("skips a closure date and reports the next trading day", () => {
    const closedSat: Store = { ...store("revesby"), closures: ["2026-10-03"] };
    // Sat 3 Oct 07:00 AEST would normally be open, but it is a closure day.
    const status = getStoreStatus(closedSat, new Date("2026-10-02T21:00:00Z"));
    expect(status.state).toBe("closed");
    if (status.state !== "closed") return;
    expect(status.label).toBe("Closed · Opens tomorrow 6:30am");
    // Sun 4 Oct 06:30 AEDT = Sat 3 Oct 19:30Z
    expect(status.opensAt && iso(status.opensAt)).toBe("2026-10-03T19:30:00.000Z");
  });

  it("reports plain 'Closed' (opensAt null) when there is no opening within 14 days", () => {
    const shut: Store = { ...store("revesby"), hours: [] };
    const status = getStoreStatus(shut, new Date("2026-10-02T21:00:00Z"));
    expect(status).toEqual({ state: "closed", opensAt: null, label: "Closed" });
  });

  it("supports split trading windows in one day", () => {
    const split: Store = {
      ...store("revesby"),
      hours: [
        { dayOfWeek: 6, opensMin: 8 * 60, closesMin: 10 * 60 },
        { dayOfWeek: 6, opensMin: 16 * 60, closesMin: 18 * 60 },
      ],
    };
    // Sat 3 Oct 12:00 AEST: between windows
    const midday = getStoreStatus(split, new Date("2026-10-03T02:00:00Z"));
    expect(midday.state).toBe("closed");
    expect(midday.label).toBe("Closed · Opens today 4pm");
  });
});

describe("getPickupSlots", () => {
  const revesby = store("revesby");
  // Sat 3 Oct 07:10 AEST; prep 20 minutes -> earliest 07:30.
  const NOW = new Date("2026-10-02T21:10:00Z");

  it("starts at now + prep, snapped onto the 15-minute grid", () => {
    const [first] = getPickupSlots(revesby, NOW);
    expect(first).toEqual({ iso: "2026-10-02T21:30:00.000Z", label: "7:30am", dayLabel: "Today" });
  });

  it("offers 28 slots today (…2:15pm, closing time exclusive) and 32 tomorrow, 60 in total", () => {
    const slots = getPickupSlots(revesby, NOW);
    const today = slots.filter((s) => s.dayLabel === "Today");
    const tomorrow = slots.filter((s) => s.dayLabel === "Tomorrow");
    expect(today).toHaveLength(28);
    expect(tomorrow).toHaveLength(32);
    expect(slots).toHaveLength(60);
    expect(today.at(-1)?.label).toBe("2:15pm");
  });

  it("labels tomorrow's first slot 6:30am at the post-DST offset", () => {
    const tomorrowFirst = getPickupSlots(revesby, NOW).find((s) => s.dayLabel === "Tomorrow");
    expect(tomorrowFirst).toEqual({ iso: "2026-10-03T19:30:00.000Z", label: "6:30am", dayLabel: "Tomorrow" });
  });

  it("returns slots sorted ascending with no duplicates", () => {
    const isos = getPickupSlots(revesby, NOW, { days: 5 }).map((s) => s.iso);
    expect(isos).toEqual([...isos].sort());
    expect(new Set(isos).size).toBe(isos.length);
  });

  it("respects the max option", () => {
    expect(getPickupSlots(revesby, NOW, { max: 5 })).toHaveLength(5);
    expect(getPickupSlots(revesby, NOW, { max: 1 })).toHaveLength(1);
  });

  it("respects intervalMin", () => {
    const slots = getPickupSlots(revesby, NOW, { intervalMin: 30, days: 1 });
    const gaps = slots.slice(1).map((s, i) => Date.parse(s.iso) - Date.parse(slots[i]?.iso ?? ""));
    expect(gaps.every((g) => g === 30 * 60_000)).toBe(true);
  });

  it("returns [] when the only offered day is already past the last slot", () => {
    // Sat 3 Oct 15:00 AEST, Revesby closed at 14:30
    expect(getPickupSlots(revesby, new Date("2026-10-03T05:00:00Z"), { days: 1 })).toEqual([]);
  });

  it("excludes slots inside the prep window right up to closing", () => {
    // 14:00 AEST + 20 min prep = 14:20 earliest, so only 14:30 would qualify, but closing is exclusive.
    expect(getPickupSlots(revesby, new Date("2026-10-03T04:00:00Z"), { days: 1 })).toEqual([]);
  });

  it("skips closure days", () => {
    const closedTomorrow: Store = { ...revesby, closures: ["2026-10-04"] };
    // NOW is Sat 3 Oct local, so tomorrow is Sun 4 Oct.
    const slots = getPickupSlots(closedTomorrow, NOW);
    expect(slots.some((s) => s.dayLabel === "Tomorrow")).toBe(false);
    expect(slots).toHaveLength(28);
  });

  it("returns [] for Bankstown on a weekend when only two days are offered", () => {
    // Sat 3 Oct 10:00 AEST: today and tomorrow (Sun) are both non-trading.
    expect(getPickupSlots(store("bankstown"), new Date("2026-10-03T00:00:00Z"), { days: 2 })).toEqual([]);
  });

  it("finds Monday 7am for Bankstown when a week is offered, with a dated label", () => {
    const slots = getPickupSlots(store("bankstown"), new Date("2026-10-03T00:00:00Z"), { days: 7 });
    expect(slots[0]).toMatchObject({ iso: "2026-10-04T20:00:00.000Z", label: "7am" });
    expect(slots[0]?.dayLabel).toMatch(/Mon/);
  });
});

describe("isValidPickupInstant", () => {
  const revesby = store("revesby");
  const NOW = new Date("2026-10-02T21:10:00Z");

  it("accepts every slot that getPickupSlots offers", () => {
    for (const slot of getPickupSlots(revesby, NOW)) {
      expect(isValidPickupInstant(revesby, new Date(slot.iso), NOW)).toBe(true);
    }
  });

  it("rejects an off-grid minute", () => {
    expect(isValidPickupInstant(revesby, new Date("2026-10-02T21:40:00Z"), NOW)).toBe(false);
  });

  it("rejects a slot before the prep cutoff", () => {
    // 07:15 AEST is on the grid but only 5 minutes after NOW.
    expect(isValidPickupInstant(revesby, new Date("2026-10-02T21:15:00Z"), NOW)).toBe(false);
  });

  it("rejects the closing time itself and anything after closing", () => {
    // 14:30 and 15:00 AEST on Sat 3 Oct
    expect(isValidPickupInstant(revesby, new Date("2026-10-03T04:30:00Z"), NOW)).toBe(false);
    expect(isValidPickupInstant(revesby, new Date("2026-10-03T05:00:00Z"), NOW)).toBe(false);
  });

  it("rejects a slot before opening", () => {
    // 06:00 AEDT Sun 4 Oct, 30 minutes before Revesby opens
    expect(isValidPickupInstant(revesby, new Date("2026-10-03T19:00:00Z"), NOW)).toBe(false);
  });

  it("rejects a closure day", () => {
    const closedTomorrow: Store = { ...revesby, closures: ["2026-10-04"] };
    const slotTomorrow = new Date("2026-10-03T19:30:00Z");
    expect(isValidPickupInstant(revesby, slotTomorrow, NOW)).toBe(true);
    expect(isValidPickupInstant(closedTomorrow, slotTomorrow, NOW)).toBe(false);
  });

  it("rejects a day outside the offered horizon", () => {
    // Mon 5 Oct 07:00 AEDT is a normal trading slot but beyond the default two days.
    expect(isValidPickupInstant(revesby, new Date("2026-10-04T20:00:00Z"), NOW)).toBe(false);
  });

  it("rejects an invalid Date instead of throwing", () => {
    // toISOString() throws a RangeError on an invalid Date; the server gate must degrade to false.
    expect(() => isValidPickupInstant(revesby, new Date("not a date"), NOW)).not.toThrow();
    expect(isValidPickupInstant(revesby, new Date("not a date"), NOW)).toBe(false);
  });
});
