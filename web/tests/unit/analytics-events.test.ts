/**
 * WP-03: the analytics taxonomy and its export for the WordPress companion.
 *
 *  - begin_checkout.payment_method is "SQUARE" | "PAY_AT_SHOP" (Square replaces Stripe; the old values are gone);
 *  - doughboss-growth/content/events.json is exactly what scripts/wp-oracle/export-events.ts produces from events.ts
 *    (the ES5 dispatcher trusts that file as its allow-list, so a stale copy would let a wrong name or parameter through).
 */
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { EVENT_NAMES, type EventParams } from "@/lib/analytics/events";
import { EVENTS_JSON_PATH, buildEvents, render } from "../../scripts/wp-oracle/export-events";

describe("events.ts payment_method", () => {
  it("accepts SQUARE and PAY_AT_SHOP and no longer accepts the Stripe-era values (checked by tsc)", () => {
    const square: EventParams["begin_checkout"]["payment_method"] = "SQUARE";
    const shop: EventParams["begin_checkout"]["payment_method"] = "PAY_AT_SHOP";
    // @ts-expect-error STRIPE was replaced by SQUARE
    const stripe: EventParams["begin_checkout"]["payment_method"] = "STRIPE";
    // @ts-expect-error PAY_AT_PICKUP was replaced by PAY_AT_SHOP
    const pickup: EventParams["begin_checkout"]["payment_method"] = "PAY_AT_PICKUP";
    expect([square, shop, stripe, pickup]).toHaveLength(4);
  });

  it("the export carries exactly the new union", () => {
    const exported = buildEvents();
    expect(exported.events.begin_checkout?.params.payment_method).toEqual({ type: "enum", values: ["SQUARE", "PAY_AT_SHOP"], optional: false });
  });
});

describe("export-events", () => {
  // hero_explore belongs to the cancelled 3D hero: it stays in events.ts for the shelved Next.js kit but is not exported.
  const exportedNames = [...EVENT_NAMES].filter((name) => name !== "hero_explore");

  it("lists every exported event name, in EVENT_NAMES order, each with a params object", () => {
    const exported = buildEvents();
    expect(exported.event_names).toEqual(exportedNames);
    expect(Object.keys(exported.events)).toEqual(exportedNames);
    for (const name of exportedNames) expect(exported.events[name]?.params, name).toBeTypeOf("object");
  });

  it("never exports the cancelled 3D hero event or any WebGL value", () => {
    const exported = buildEvents();
    expect(exported.event_names).not.toContain("hero_explore");
    expect(JSON.stringify(exported)).not.toMatch(/webgl|hero_explore/i);
  });

  it("carries no field that could hold personal data: only enums, strings and integers, and no reserved key", () => {
    const exported = buildEvents();
    for (const [name, event] of Object.entries(exported.events)) {
      for (const [param, spec] of Object.entries(event.params)) {
        expect(["enum", "string", "integer"], `${name}.${param}`).toContain(spec.type);
        expect(["event", "event_id", "consent", "gtm"], `${name}.${param}`).not.toContain(param);
        expect(param, `${name}.${param}`).not.toMatch(/email|phone|mobile|address|first_name|last_name|full_name|customer|postcode|dob/);
      }
    }
  });

  it("content/events.json is current (matches a fresh export byte for byte)", () => {
    expect(existsSync(EVENTS_JSON_PATH)).toBe(true);
    expect(readFileSync(EVENTS_JSON_PATH, "utf8")).toBe(render(buildEvents()));
  });

  it("NEGATIVE CONTROL: a stale file is detected (old payment_method values would not match)", () => {
    const stale = readFileSync(EVENTS_JSON_PATH, "utf8").replace('"SQUARE", "PAY_AT_SHOP"', '"STRIPE", "PAY_AT_PICKUP"');
    expect(stale).not.toBe(readFileSync(EVENTS_JSON_PATH, "utf8"));
    expect(stale).not.toBe(render(buildEvents()));
  });

  it("the recorded source hash is the hash of events.ts today", () => {
    const parsed = JSON.parse(readFileSync(EVENTS_JSON_PATH, "utf8")) as { source_sha256: string };
    expect(parsed.source_sha256).toBe(buildEvents().source_sha256);
    expect(resolve(EVENTS_JSON_PATH).endsWith("doughboss-growth/content/events.json")).toBe(true);
  });
});
