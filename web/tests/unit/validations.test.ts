import { readFileSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";
import { ZodError } from "zod";
import {
  PAYMENT_METHODS,
  WAITLIST_CONSENT_TEXT,
  checkoutSchema,
  fieldErrors,
  waitlistSchema,
  type CheckoutInput,
  type WaitlistInput,
} from "@/lib/validations";

const validWaitlist = (over: Record<string, unknown> = {}): WaitlistInput =>
  ({
    name: "Sample Person",
    email: "sample@example.com",
    consent: true,
    ...over,
  }) as WaitlistInput;

const validCheckout = (over: Record<string, unknown> = {}): CheckoutInput =>
  ({
    storeSlug: "revesby",
    pickupAt: "2026-10-03T19:30:00.000Z",
    customer: { name: "Sample Person", email: "sample@example.com", phone: "0412 345 678" },
    paymentMethod: "PAY_AT_SHOP",
    lines: [{ itemSlug: "zaatar-manoush", quantity: 2, selections: { "base-style": ["folded"] } }],
    ...over,
  }) as CheckoutInput;

describe("waitlistSchema", () => {
  it("accepts a valid payload", () => {
    const r = waitlistSchema.safeParse(validWaitlist());
    expect(r.success).toBe(true);
  });

  it("accepts every optional field when well formed", () => {
    const r = waitlistSchema.safeParse(
      validWaitlist({ phone: "0412345678", storeSlug: "bankstown", company: "" }),
    );
    expect(r.success).toBe(true);
  });

  it("trims and lower-cases the email", () => {
    const r = waitlistSchema.parse(validWaitlist({ email: "  Sample.Person@Example.COM  " }));
    expect(r.email).toBe("sample.person@example.com");
  });

  it("trims the name", () => {
    expect(waitlistSchema.parse(validWaitlist({ name: "  Sample Person " })).name).toBe("Sample Person");
  });

  describe("phone", () => {
    it("strips spaces from a local number", () => {
      expect(waitlistSchema.parse(validWaitlist({ phone: "0412 345 678" })).phone).toBe("0412345678");
    });

    it("keeps the leading + and strips spaces and dashes from an international number", () => {
      expect(waitlistSchema.parse(validWaitlist({ phone: "+61 412-345-678" })).phone).toBe("+61412345678");
    });

    it("strips brackets and dots", () => {
      expect(waitlistSchema.parse(validWaitlist({ phone: "(02) 9774.2286" })).phone).toBe("0297742286");
    });

    it("turns an empty string into undefined", () => {
      expect(waitlistSchema.parse(validWaitlist({ phone: "" })).phone).toBeUndefined();
    });

    it("treats an absent phone as undefined", () => {
      expect(waitlistSchema.parse(validWaitlist()).phone).toBeUndefined();
    });

    it.each(["abc", "123", "+1234567890123456", "04 12 34 5x78"])("rejects %j", (phone) => {
      expect(waitlistSchema.safeParse(validWaitlist({ phone })).success).toBe(false);
    });
  });

  describe("rejections", () => {
    it("rejects missing consent", () => {
      const { consent: _omit, ...rest } = validWaitlist();
      expect(waitlistSchema.safeParse(rest).success).toBe(false);
    });

    it("rejects consent: false and gives a friendly message", () => {
      const r = waitlistSchema.safeParse(validWaitlist({ consent: false }));
      expect(r.success).toBe(false);
      if (!r.success) expect(fieldErrors(r.error).consent).toMatch(/tick the box/);
    });

    it("rejects a filled honeypot", () => {
      expect(waitlistSchema.safeParse(validWaitlist({ company: "Acme Bots" })).success).toBe(false);
    });

    it("has no product-specific interest picker: unknown interest fields are stripped, never stored", () => {
      const r = waitlistSchema.parse(validWaitlist({ interests: ["SOMETHING"], partyPieces: 100 }));
      expect(r).not.toHaveProperty("interests");
      expect(r).not.toHaveProperty("partyPieces");
    });

    it("keeps the consent wording neutral: no product, size, price or date claim", () => {
      expect(WAITLIST_CONSENT_TEXT).not.toMatch(/mini|pizza|pack|bites|halal|\$|\d/i);
      expect(WAITLIST_CONSENT_TEXT).toMatch(/unsubscribe/i);
    });

    it("rejects a bad email", () => {
      expect(waitlistSchema.safeParse(validWaitlist({ email: "not-an-email" })).success).toBe(false);
    });

    it("rejects an over-long email (> 254 characters)", () => {
      const long = `${"a".repeat(250)}@example.com`;
      expect(waitlistSchema.safeParse(validWaitlist({ email: long })).success).toBe(false);
    });

    it("rejects a name containing a control character", () => {
      expect(waitlistSchema.safeParse(validWaitlist({ name: "Sample\u0007Person" })).success).toBe(false);
      expect(waitlistSchema.safeParse(validWaitlist({ name: "Sample\nPerson" })).success).toBe(false);
    });

    it("rejects a 1-character name and an over-long name", () => {
      expect(waitlistSchema.safeParse(validWaitlist({ name: "A" })).success).toBe(false);
      expect(waitlistSchema.safeParse(validWaitlist({ name: "A".repeat(81) })).success).toBe(false);
    });

    it("rejects an unknown store slug", () => {
      expect(waitlistSchema.safeParse(validWaitlist({ storeSlug: "parramatta" })).success).toBe(false);
    });
  });
});

describe("checkoutSchema", () => {
  it("accepts a valid sample and normalises the phone", () => {
    const r = checkoutSchema.safeParse(validCheckout());
    expect(r.success).toBe(true);
    if (r.success) expect(r.data.customer.phone).toBe("0412345678");
  });

  it("accepts an optional note and a line note", () => {
    const r = checkoutSchema.safeParse(
      validCheckout({
        notes: "Please cut in half",
        lines: [{ itemSlug: "x", quantity: 1, selections: {}, note: "extra crispy" }],
      }),
    );
    expect(r.success).toBe(true);
  });

  it("rejects empty lines with the friendly empty-cart message", () => {
    const r = checkoutSchema.safeParse(validCheckout({ lines: [] }));
    expect(r.success).toBe(false);
    if (!r.success) expect(fieldErrors(r.error).lines).toBe("Your cart is empty");
  });

  it("rejects more than MAX_CART_LINES lines", () => {
    const lines = Array.from({ length: 31 }, () => ({ itemSlug: "x", quantity: 1, selections: {} }));
    expect(checkoutSchema.safeParse(validCheckout({ lines })).success).toBe(false);
  });

  it.each([21, 0, 1.5])("rejects line quantity %s", (quantity) => {
    const lines = [{ itemSlug: "x", quantity, selections: {} }];
    expect(checkoutSchema.safeParse(validCheckout({ lines })).success).toBe(false);
  });

  it("rejects a pickupAt with a local offset (must be a UTC instant)", () => {
    expect(checkoutSchema.safeParse(validCheckout({ pickupAt: "2026-10-03T07:30:00+10:00" })).success).toBe(false);
  });

  it("rejects a date-only or free-text pickupAt", () => {
    expect(checkoutSchema.safeParse(validCheckout({ pickupAt: "2026-10-03" })).success).toBe(false);
    expect(checkoutSchema.safeParse(validCheckout({ pickupAt: "tomorrow 7am" })).success).toBe(false);
  });

  it("accepts a UTC instant without milliseconds", () => {
    expect(checkoutSchema.safeParse(validCheckout({ pickupAt: "2026-10-03T19:30:00Z" })).success).toBe(true);
  });

  it("rejects an unknown storeSlug", () => {
    expect(checkoutSchema.safeParse(validCheckout({ storeSlug: "parramatta" })).success).toBe(false);
  });

  it("accepts SQUARE and PAY_AT_SHOP and rejects the retired Stripe-era values", () => {
    for (const ok of ["SQUARE", "PAY_AT_SHOP"]) expect(checkoutSchema.safeParse(validCheckout({ paymentMethod: ok })).success).toBe(true);
    for (const old of ["STRIPE", "PAY_AT_PICKUP"]) expect(checkoutSchema.safeParse(validCheckout({ paymentMethod: old })).success).toBe(false);
  });

  it("uses the same payment method values as the analytics contract", () => {
    const events = readFileSync(path.resolve(__dirname, "../../src/lib/analytics/events.ts"), "utf8");
    expect(events).toContain(PAYMENT_METHODS.map((m) => `"${m}"`).join(" | "));
  });

  it("rejects an unknown payment method", () => {
    expect(checkoutSchema.safeParse(validCheckout({ paymentMethod: "BITCOIN" })).success).toBe(false);
  });

  it("rejects notes over 300 characters but accepts exactly 300", () => {
    expect(checkoutSchema.safeParse(validCheckout({ notes: "x".repeat(301) })).success).toBe(false);
    expect(checkoutSchema.safeParse(validCheckout({ notes: "x".repeat(300) })).success).toBe(true);
  });

  it("rejects a bad customer email and a missing phone", () => {
    expect(checkoutSchema.safeParse(validCheckout({ customer: { name: "Sample Person", email: "nope", phone: "0412345678" } })).success).toBe(false);
    expect(checkoutSchema.safeParse(validCheckout({ customer: { name: "Sample Person", email: "sample@example.com", phone: "" } })).success).toBe(false);
  });

  it("does not accept a client-supplied price on a line (strips it rather than trusting it)", () => {
    const r = checkoutSchema.safeParse(
      validCheckout({ lines: [{ itemSlug: "x", quantity: 1, selections: {}, priceCents: 1 }] }),
    );
    expect(r.success).toBe(true);
    if (r.success) expect(r.data.lines[0]).not.toHaveProperty("priceCents");
  });
});

describe("fieldErrors", () => {
  it("returns the first message per top-level field", () => {
    const r = waitlistSchema.safeParse(validWaitlist({ name: "A", email: "nope", consent: false }));
    expect(r.success).toBe(false);
    if (r.success) return;
    const errors = fieldErrors(r.error);
    expect(Object.keys(errors).sort()).toEqual(["consent", "email", "name"]);
    expect(errors.name).toBe("Please enter your name");
    expect(errors.email).toBe("Enter a valid email address");
    expect(errors.consent).toBe("Please tick the box so we’re allowed to contact you");
  });

  it("keeps only the first message when one field has several failures", () => {
    // A lone control character is both too short (min 2) and a control character: two issues, one message.
    const r = waitlistSchema.safeParse(validWaitlist({ name: "\u0007" }));
    expect(r.success).toBe(false);
    if (r.success) return;
    expect(r.error.issues.filter((i) => i.path[0] === "name").length).toBeGreaterThan(1);
    expect(fieldErrors(r.error)).toEqual({ name: "Please enter your name" });
  });

  it("collapses nested paths onto the TOP-LEVEL key: customer.email surfaces as `customer`", () => {
    // Documented behaviour (z.flattenError groups by the first path segment): there is no
    // "customer.email" key, and when several customer fields fail only the first message survives.
    const r = checkoutSchema.safeParse(validCheckout({ customer: { name: "Sample Person", email: "nope", phone: "0412345678" } }));
    expect(r.success).toBe(false);
    if (r.success) return;
    const errors = fieldErrors(r.error);
    expect(errors).toEqual({ customer: "Enter a valid email address" });
    expect(errors).not.toHaveProperty("customer.email");
  });

  it("reports only the first of several failing customer fields", () => {
    const r = checkoutSchema.safeParse(validCheckout({ customer: { name: "A", email: "nope", phone: "1" } }));
    expect(r.success).toBe(false);
    if (r.success) return;
    expect(fieldErrors(r.error)).toEqual({ customer: "Please enter your name" });
  });

  it("collapses array-element errors onto the array's key (lines.0.quantity -> lines)", () => {
    const r = checkoutSchema.safeParse(validCheckout({ lines: [{ itemSlug: "x", quantity: 99, selections: {} }] }));
    expect(r.success).toBe(false);
    if (r.success) return;
    expect(Object.keys(fieldErrors(r.error))).toEqual(["lines"]);
  });

  it("returns {} for an error with no issues", () => {
    expect(fieldErrors(new ZodError([]))).toEqual({});
  });
});
