import { describe, expect, it } from "vitest";
import { hasAttribution, sanitiseAttribution } from "@/lib/attribution-schema";

describe("sanitiseAttribution", () => {
  it("keeps valid marketing parameters and trims them", () => {
    expect(
      sanitiseAttribution({
        utmSource: " google ",
        utmMedium: "cpc",
        utmCampaign: "corporate-catering-bankstown",
        gclid: "Cj0KCQ-abc_123",
        referrerHost: "www.google.com",
        landingPath: "/catering/corporate",
        firstSeenAt: "2026-10-02T21:00:00.000Z",
      }),
    ).toEqual({
      utmSource: "google",
      utmMedium: "cpc",
      utmCampaign: "corporate-catering-bankstown",
      gclid: "Cj0KCQ-abc_123",
      referrerHost: "www.google.com",
      landingPath: "/catering/corporate",
      firstSeenAt: "2026-10-02T21:00:00.000Z",
    });
  });

  it("drops invalid fields but keeps the valid ones", () => {
    const result = sanitiseAttribution({
      utmSource: "newsletter",
      utmMedium: "x".repeat(500),
      utmCampaign: "bad\u0000campaign",
      landingPath: "/catering?email=someone@example.com",
      firstSeenAt: "yesterday",
    });
    expect(result).toEqual({ utmSource: "newsletter" });
  });

  it("refuses a landing path that carries a query string or is not a path", () => {
    expect(sanitiseAttribution({ landingPath: "/a?b=1" })).toEqual({});
    expect(sanitiseAttribution({ landingPath: "https://evil.example/x" })).toEqual({});
    expect(sanitiseAttribution({ landingPath: "/catering#top" })).toEqual({});
    expect(sanitiseAttribution({ landingPath: "/catering/events" })).toEqual({ landingPath: "/catering/events" });
  });

  it("ignores unknown keys and non-object input without throwing", () => {
    expect(sanitiseAttribution({ ip: "203.0.113.9", userAgent: "x", utmSource: "a" })).toEqual({ utmSource: "a" });
    expect(sanitiseAttribution(null)).toEqual({});
    expect(sanitiseAttribution("utm_source=a")).toEqual({});
    expect(sanitiseAttribution(42)).toEqual({});
    expect(sanitiseAttribution([])).toEqual({});
  });

  it("rejects non-string parameter values", () => {
    expect(sanitiseAttribution({ utmSource: 123, gclid: { x: 1 } })).toEqual({});
  });
});

describe("hasAttribution", () => {
  it("is true only when something is worth storing", () => {
    expect(hasAttribution(undefined)).toBe(false);
    expect(hasAttribution({})).toBe(false);
    expect(hasAttribution({ utmSource: "google" })).toBe(true);
  });
});
