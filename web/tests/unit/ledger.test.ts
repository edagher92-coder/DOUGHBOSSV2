import { describe, expect, it } from "vitest";
import { assertLedger, claimText, publishable, type Claim } from "@/content/ledger";

const sourced: Claim = {
  id: "baked-in-house",
  text: "Baked in-house.",
  confirmed: true,
  source: { kind: "owner-site", ref: "doughxsnow/web/catering/index.html", retrieved: "2026-10-02" },
};

describe("claims ledger", () => {
  it("publishes only confirmed claims that have a source", () => {
    const claims: Claim[] = [
      sourced,
      { id: "idea", text: "Delivered across Sydney.", confirmed: false, note: "Delivery area unknown" },
      { id: "no-source", text: "Halal certified.", confirmed: true },
    ];
    expect(publishable(claims).map((c) => c.id)).toEqual(["baked-in-house"]);
  });

  it("looks a claim up by id and hides unpublishable ones", () => {
    const claims: Claim[] = [sourced, { id: "gap", text: "Free delivery.", confirmed: false }];
    expect(claimText(claims, "baked-in-house")).toBe("Baked in-house.");
    expect(claimText(claims, "gap")).toBeUndefined();
    expect(claimText(claims, "missing")).toBeUndefined();
  });

  it("accepts a valid ledger", () => {
    expect(() => assertLedger([sourced, { id: "draft", text: "A draft idea.", confirmed: false }])).not.toThrow();
  });

  it("rejects a confirmed claim with no source", () => {
    expect(() => assertLedger([{ id: "x", text: "Fact.", confirmed: true }])).toThrow(/confirmed but has no source/);
  });

  it("rejects placeholder markers, duplicates, empty text and bad ids", () => {
    expect(() => assertLedger([{ id: "a", text: "Opens at [CONFIRM: time]", confirmed: false }])).toThrow(/placeholder/);
    expect(() => assertLedger([{ id: "a", text: "TBC", confirmed: false }])).toThrow(/placeholder/);
    expect(() => assertLedger([sourced, sourced])).toThrow(/duplicate id/);
    expect(() => assertLedger([{ id: "a", text: "   ", confirmed: false }])).toThrow(/empty text/);
    expect(() => assertLedger([{ id: "Not_Kebab", text: "ok", confirmed: false }])).toThrow(/kebab-case/);
  });

  it("rejects a source with an empty reference", () => {
    expect(() =>
      assertLedger([{ id: "a", text: "ok", confirmed: true, source: { kind: "owner-confirmed", ref: " ", confirmedOn: "2026-10-02" } }]),
    ).toThrow(/empty ref/);
  });

  it("reports every problem at once, not just the first", () => {
    try {
      assertLedger([
        { id: "a", text: "x", confirmed: true },
        { id: "a", text: "", confirmed: false },
      ]);
      expect.unreachable();
    } catch (e) {
      const message = (e as Error).message;
      expect(message).toMatch(/no source/);
      expect(message).toMatch(/duplicate id/);
      expect(message).toMatch(/empty text/);
    }
  });
});
