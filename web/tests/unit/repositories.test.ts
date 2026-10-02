import { Prisma } from "@prisma/client";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { notifyWaitlistSignup } from "@/lib/notify";
import { resetEnvCache } from "@/lib/env";
import { StorageUnavailableError } from "@/lib/errors";
import {
  createInMemoryOrderRepository,
  createPrismaOrderRepository,
  getOrderRepository,
  resetOrderRepository,
  type NewOrder,
} from "@/lib/repositories/orders";
import {
  createInMemoryWaitlistRepository,
  createPrismaWaitlistRepository,
  getWaitlistRepository,
  resetWaitlistRepository,
  type WaitlistRecord,
} from "@/lib/repositories/waitlist";
import { selectBackend } from "@/lib/repositories/select";

vi.mock("@/lib/db", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/lib/db")>();
  return { ...actual, getPrisma: vi.fn(() => ({ __fake: true })) };
});

beforeEach(() => {
  vi.stubEnv("DATABASE_URL", "");
  vi.stubEnv("NODE_ENV", "test");
  vi.stubEnv("WAITLIST_WEBHOOK_URL", "");
  resetEnvCache();
  resetWaitlistRepository();
  resetOrderRepository();
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.useRealTimers();
  resetEnvCache();
});

const uniqueViolation = (target: string[] | undefined) =>
  new Prisma.PrismaClientKnownRequestError("Unique constraint failed", {
    code: "P2002",
    clientVersion: "test",
    ...(target ? { meta: { target } } : {}),
  });

// ───────────────────────── Backend selection ─────────────────────────

describe("backend selection", () => {
  it("uses the database whenever DATABASE_URL is set", () => {
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    resetEnvCache();
    expect(selectBackend()).toBe("prisma");
  });

  it("uses memory only outside production", () => {
    expect(selectBackend()).toBe("memory");
    vi.stubEnv("NODE_ENV", "development");
    resetEnvCache();
    expect(selectBackend()).toBe("memory");
  });

  it("REFUSES in production with no database (never a silent in-memory save)", () => {
    vi.stubEnv("NODE_ENV", "production");
    resetEnvCache();
    expect(() => selectBackend()).toThrow(StorageUnavailableError);
    expect(() => getWaitlistRepository()).toThrow(StorageUnavailableError);
    expect(() => getOrderRepository()).toThrow(StorageUnavailableError);
  });

  it("returns a stable in-memory singleton in dev so state survives between calls", () => {
    expect(getWaitlistRepository()).toBe(getWaitlistRepository());
    expect(getOrderRepository()).toBe(getOrderRepository());
  });

  it("returns the Prisma implementations when a database is configured", () => {
    vi.stubEnv("DATABASE_URL", "postgresql://u:p@localhost:5432/db");
    resetEnvCache();
    // A fresh object each call (no memory singleton involved).
    expect(getWaitlistRepository()).not.toBe(getWaitlistRepository());
    expect(getOrderRepository()).not.toBe(getOrderRepository());
  });
});

// ───────────────────────── Waitlist ─────────────────────────

const signup = (over: Partial<WaitlistRecord> = {}): WaitlistRecord => ({
  name: "Sam Test",
  email: "sam@example.test",
  interests: ["MINI_ZAATAR"],
  consentAt: new Date("2026-10-02T01:00:00Z"),
  consentText: "I agree (v1)",
  ...over,
});

describe("in-memory waitlist", () => {
  it("creates a row, then merges a repeat signup instead of duplicating it", async () => {
    const repo = createInMemoryWaitlistRepository();
    const first = await repo.upsert(signup());
    expect(first.created).toBe(true);

    const second = await repo.upsert(signup({ interests: ["MINI_CHEESE", "MINI_ZAATAR"], phone: "+61400000000", partyPieces: 60 }));
    expect(second).toEqual({ created: false, id: first.id });

    const rows = repo.all();
    expect(rows).toHaveLength(1);
    expect(rows[0]?.interests).toEqual(["MINI_ZAATAR", "MINI_CHEESE"]);
    expect(rows[0]?.phone).toBe("+61400000000");
    expect(rows[0]?.partyPieces).toBe(60);
  });

  it("treats email case-insensitively", async () => {
    const repo = createInMemoryWaitlistRepository();
    const a = await repo.upsert(signup({ email: "Sam@Example.TEST" }));
    const b = await repo.upsert(signup({ email: " sam@example.test " }));
    expect(b).toEqual({ created: false, id: a.id });
    expect(repo.all()[0]?.email).toBe("sam@example.test");
  });

  it("keeps the EARLIEST consent timestamp, together with the wording that was agreed then", async () => {
    const repo = createInMemoryWaitlistRepository();
    await repo.upsert(signup({ consentAt: new Date("2026-10-02T01:00:00Z"), consentText: "v1" }));
    await repo.upsert(signup({ consentAt: new Date("2026-10-05T01:00:00Z"), consentText: "v2" }));
    expect(repo.all()[0]).toMatchObject({ consentAt: new Date("2026-10-02T01:00:00Z"), consentText: "v1" });

    // An earlier incoming timestamp (clock skew, replayed event) wins instead.
    await repo.upsert(signup({ consentAt: new Date("2026-09-30T01:00:00Z"), consentText: "v0" }));
    expect(repo.all()[0]).toMatchObject({ consentAt: new Date("2026-09-30T01:00:00Z"), consentText: "v0" });
  });

  it("does not wipe optional fields a repeat signup leaves out", async () => {
    const repo = createInMemoryWaitlistRepository();
    await repo.upsert(signup({ phone: "+61400000000", partyPieces: 40, storeSlug: "revesby" }));
    await repo.upsert(signup());
    expect(repo.all()[0]).toMatchObject({ phone: "+61400000000", partyPieces: 40, storeSlug: "revesby" });
  });

  it("markNotified stamps the row and ignores unknown ids", async () => {
    const repo = createInMemoryWaitlistRepository();
    const { id } = await repo.upsert(signup());
    expect(repo.all()[0]?.notifiedAt).toBeNull();
    await repo.markNotified("nope");
    expect(repo.all()[0]?.notifiedAt).toBeNull();
    await repo.markNotified(id);
    expect(repo.all()[0]?.notifiedAt).toBeInstanceOf(Date);
  });
});

describe("Prisma waitlist (fake client)", () => {
  const existing = {
    id: "w1",
    email: "sam@example.test",
    interests: ["MINI_PIES"],
    consentAt: new Date("2026-10-01T00:00:00Z"),
    consentText: "v1",
  };

  const fakeDb = (over: Record<string, unknown> = {}) => {
    const waitlistSubscriber = {
      findUnique: vi.fn().mockResolvedValue(null),
      create: vi.fn().mockResolvedValue({ id: "w-new" }),
      update: vi.fn().mockResolvedValue({}),
      updateMany: vi.fn().mockResolvedValue({ count: 1 }),
      ...over,
    };
    const store = { findUnique: vi.fn().mockResolvedValue({ id: "store-1" }) };
    return { waitlistSubscriber, store };
  };

  it("creates with a lower-cased email and the resolved store id", async () => {
    const db = fakeDb();
    const repo = createPrismaWaitlistRepository(db as never);
    const out = await repo.upsert(signup({ email: "Sam@Example.TEST", storeSlug: "bankstown" }));
    expect(out).toEqual({ created: true, id: "w-new" });
    expect(db.store.findUnique).toHaveBeenCalledWith({ where: { slug: "bankstown" }, select: { id: true } });
    expect(db.waitlistSubscriber.create.mock.calls[0]?.[0].data).toMatchObject({
      email: "sam@example.test",
      storeId: "store-1",
      source: "minis-teaser",
    });
  });

  it("merges into an existing row: union of interests, earliest consent", async () => {
    const db = fakeDb({ findUnique: vi.fn().mockResolvedValue(existing) });
    const repo = createPrismaWaitlistRepository(db as never);
    const out = await repo.upsert(signup({ interests: ["MINI_ZAATAR"], consentAt: new Date("2026-10-09T00:00:00Z"), consentText: "v2" }));
    expect(out).toEqual({ created: false, id: "w1" });
    expect(db.waitlistSubscriber.create).not.toHaveBeenCalled();
    expect(db.waitlistSubscriber.update.mock.calls[0]?.[0]).toMatchObject({
      where: { id: "w1" },
      data: { interests: ["MINI_PIES", "MINI_ZAATAR"], consentAt: existing.consentAt, consentText: "v1" },
    });
  });

  it("recovers from a simultaneous-signup unique violation by merging into the winner", async () => {
    const findUnique = vi.fn().mockResolvedValueOnce(null).mockResolvedValueOnce(existing);
    const db = fakeDb({ findUnique, create: vi.fn().mockRejectedValue(uniqueViolation(["email"])) });
    const out = await createPrismaWaitlistRepository(db as never).upsert(signup());
    expect(out).toEqual({ created: false, id: "w1" });
  });

  it("rethrows other database errors rather than swallowing them", async () => {
    const db = fakeDb({ create: vi.fn().mockRejectedValue(new Error("connection lost")) });
    await expect(createPrismaWaitlistRepository(db as never).upsert(signup())).rejects.toThrow("connection lost");
  });

  it("markNotified uses updateMany so a missing row is a no-op", async () => {
    const db = fakeDb();
    await createPrismaWaitlistRepository(db as never).markNotified("w1");
    expect(db.waitlistSubscriber.updateMany.mock.calls[0]?.[0]).toMatchObject({ where: { id: "w1" } });
  });
});

// ───────────────────────── Orders ─────────────────────────

const line = (over: Partial<NewOrder["lines"][number]> = {}): NewOrder["lines"][number] => ({
  name: "Za’atar Manoush",
  unitPriceCents: 700,
  quantity: 2,
  lineTotalCents: 1400,
  modifiers: [{ groupSlug: "extras", slug: "extra-cheese", name: "Extra cheese", priceDeltaCents: 200 }],
  ...over,
});

const order = (over: Partial<NewOrder> = {}): NewOrder => ({
  storeSlug: "revesby",
  paymentMethod: "STRIPE",
  customer: { name: "Pat Example", email: "Pat@Example.test", phone: "+61400000000" },
  pickupAt: new Date("2026-10-03T21:30:00Z"),
  lines: [line()],
  subtotalCents: 1400,
  totalCents: 1400,
  ...over,
});

/** An rng that replays a fixed list of indexes (6 per order number). */
const replay = (values: number[]) => {
  let i = 0;
  return () => values[i++ % values.length] ?? 0;
};

describe("in-memory orders", () => {
  it("creates a PENDING_PAYMENT / UNPAID order with a well-formed number and snapshots", async () => {
    const repo = createInMemoryOrderRepository();
    const { id, orderNumber } = await repo.create(order());
    expect(orderNumber).toMatch(/^DB-REV-[0-9A-HJKMNP-TV-Z]{6}$/);
    const stored = repo.all().find((o) => o.id === id);
    expect(stored).toMatchObject({ status: "PENDING_PAYMENT", paymentStatus: "UNPAID" });
    expect(stored?.order.lines[0]).toMatchObject({ name: "Za’atar Manoush", unitPriceCents: 700, lineTotalCents: 1400 });
  });

  it("retries on an order-number collision and gives up after 5 attempts", async () => {
    // Draws: 6 values for the first number, the SAME 6 again (collision), then 6 fresh ones.
    const repo = createInMemoryOrderRepository({ rng: replay([1, 2, 3, 4, 5, 6, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]) });
    const first = await repo.create(order());
    const second = await repo.create(order());
    expect(first.orderNumber).toBe("DB-REV-123456");
    expect(second.orderNumber).toBe("DB-REV-789ABC");
    expect(repo.all()).toHaveLength(2);

    const stuck = createInMemoryOrderRepository({ rng: () => 0 });
    await stuck.create(order());
    await expect(stuck.create(order())).rejects.toThrow(/unique order number after 5 attempts/);
    expect(stuck.all()).toHaveLength(1);
  });

  it("refuses an order whose arithmetic does not add up (the repository stores prices, it never invents them)", async () => {
    const repo = createInMemoryOrderRepository();
    await expect(repo.create(order({ subtotalCents: 1300 }))).rejects.toThrow(/subtotal/);
    await expect(repo.create(order({ lines: [line({ lineTotalCents: 1399 })], subtotalCents: 1399, totalCents: 1399 }))).rejects.toThrow(/unit price x quantity/);
    await expect(repo.create(order({ totalCents: 1000 }))).rejects.toThrow(/total is invalid/);
    await expect(repo.create(order({ lines: [], subtotalCents: 0, totalCents: 0 }))).rejects.toThrow(/at least one line/);
    await expect(repo.create(order({ lines: [line({ unitPriceCents: 7.5, lineTotalCents: 15 })], subtotalCents: 15, totalCents: 15 }))).rejects.toThrow(/invalid price/);
    expect(repo.all()).toHaveLength(0);
  });

  describe("markPaidByStripeSession", () => {
    const paid = { paymentIntentId: "pi_test_1", amountTotalCents: 1400 };

    it("moves PENDING_PAYMENT -> CONFIRMED / PAID", async () => {
      const repo = createInMemoryOrderRepository();
      const { id } = await repo.create(order());
      await repo.attachStripeSession(id, "cs_test_1");
      expect(await repo.markPaidByStripeSession("cs_test_1", paid)).toEqual({ updated: true });
      expect(repo.all()[0]).toMatchObject({ status: "CONFIRMED", paymentStatus: "PAID", stripePaymentIntentId: "pi_test_1" });
    });

    it("is idempotent: a repeated webhook reports ALREADY_PAID and changes nothing", async () => {
      const repo = createInMemoryOrderRepository();
      const { id } = await repo.create(order());
      await repo.attachStripeSession(id, "cs_test_1");
      await repo.markPaidByStripeSession("cs_test_1", paid);
      const again = await repo.markPaidByStripeSession("cs_test_1", { ...paid, paymentIntentId: "pi_other" });
      expect(again).toEqual({ updated: false, reason: "ALREADY_PAID" });
      expect(repo.all()[0]?.stripePaymentIntentId).toBe("pi_test_1");
    });

    it("refuses when the paid amount differs from the stored total, leaving the order unpaid", async () => {
      const repo = createInMemoryOrderRepository();
      const { id } = await repo.create(order());
      await repo.attachStripeSession(id, "cs_test_1");
      expect(await repo.markPaidByStripeSession("cs_test_1", { ...paid, amountTotalCents: 1399 })).toEqual({
        updated: false,
        reason: "AMOUNT_MISMATCH",
      });
      expect(await repo.markPaidByStripeSession("cs_test_1", { ...paid, amountTotalCents: 1401 })).toEqual({
        updated: false,
        reason: "AMOUNT_MISMATCH",
      });
      expect(repo.all()[0]).toMatchObject({ status: "PENDING_PAYMENT", paymentStatus: "UNPAID" });
    });

    it("reports NOT_FOUND for an unknown session", async () => {
      const repo = createInMemoryOrderRepository();
      expect(await repo.markPaidByStripeSession("cs_nope", paid)).toEqual({ updated: false, reason: "NOT_FOUND" });
    });

    it("does not resurrect a cancelled order (NOT_PENDING, so a human can look at the payment)", async () => {
      const repo = createInMemoryOrderRepository();
      const { id } = await repo.create(order());
      await repo.attachStripeSession(id, "cs_test_1");
      repo.setStatus(id, "CANCELLED");
      expect(await repo.markPaidByStripeSession("cs_test_1", paid)).toEqual({ updated: false, reason: "NOT_PENDING" });
      expect(repo.all()[0]?.status).toBe("CANCELLED");
    });

    it("attachStripeSession on an unknown order is an error, not a silent no-op", async () => {
      await expect(createInMemoryOrderRepository().attachStripeSession("missing", "cs_x")).rejects.toThrow(/not found/);
    });
  });

  describe("getForTracking", () => {
    it("returns the order for the right number AND email (case-insensitive)", async () => {
      const repo = createInMemoryOrderRepository({ now: () => new Date("2026-10-02T00:00:00Z") });
      const { orderNumber } = await repo.create(order());
      const view = await repo.getForTracking(orderNumber.toLowerCase(), "  PAT@example.TEST ");
      expect(view).toMatchObject({
        orderNumber,
        status: "PENDING_PAYMENT",
        storeSlug: "revesby",
        totalCents: 1400,
        currency: "AUD",
        createdAt: new Date("2026-10-02T00:00:00Z"),
      });
      expect(view?.items[0]).toMatchObject({ name: "Za’atar Manoush", quantity: 2, lineTotalCents: 1400 });
    });

    it("returns null for a wrong email, an unknown number, and does not leak customer details", async () => {
      const repo = createInMemoryOrderRepository();
      const { orderNumber } = await repo.create(order());
      expect(await repo.getForTracking(orderNumber, "someone-else@example.test")).toBeNull();
      expect(await repo.getForTracking("DB-REV-000000", "pat@example.test")).toBeNull();
      const view = await repo.getForTracking(orderNumber, "pat@example.test");
      expect(JSON.stringify(view)).not.toMatch(/pat@example|Pat Example|\+61400000000/i);
    });
  });
});

describe("Prisma orders (fake client)", () => {
  const fakeDb = (over: { order?: Record<string, unknown>; menuItem?: Record<string, unknown> } = {}) => ({
    store: { findUnique: vi.fn().mockResolvedValue({ id: "store-1" }) },
    menuItem: { findMany: vi.fn().mockResolvedValue([]), ...over.menuItem },
    order: {
      create: vi.fn().mockResolvedValue({ id: "o1", orderNumber: "DB-REV-AAAAAA" }),
      update: vi.fn().mockResolvedValue({}),
      updateMany: vi.fn().mockResolvedValue({ count: 0 }),
      findUnique: vi.fn().mockResolvedValue(null),
      ...over.order,
    },
  });

  it("creates with snapshots, a lower-cased email, and no live-menu link for unknown ids", async () => {
    const db = fakeDb({ menuItem: { findMany: vi.fn().mockResolvedValue([{ id: "real-item" }]) } });
    const repo = createPrismaOrderRepository(db as never);
    const out = await repo.create(order({ lines: [line({ menuItemId: "real-item" }), line({ menuItemId: "demo_x", name: "Demo" })], subtotalCents: 2800, totalCents: 2800 }));
    expect(out).toEqual({ id: "o1", orderNumber: "DB-REV-AAAAAA" });

    const data = db.order.create.mock.calls[0]?.[0].data;
    expect(data).toMatchObject({ customerEmail: "pat@example.test", storeId: "store-1", totalCents: 2800 });
    expect(data.orderNumber).toMatch(/^DB-REV-/);
    expect(data.items.create.map((i: { menuItemId: string | null }) => i.menuItemId)).toEqual(["real-item", null]);
    expect(data.items.create[0]).toMatchObject({ nameSnapshot: "Za’atar Manoush", unitPriceCents: 700, quantity: 2 });
  });

  it("retries an order-number unique violation with a NEW number, up to 5 attempts", async () => {
    const create = vi
      .fn()
      .mockRejectedValueOnce(uniqueViolation(["orderNumber"]))
      .mockRejectedValueOnce(uniqueViolation(["orderNumber"]))
      .mockResolvedValueOnce({ id: "o9", orderNumber: "DB-REV-ZZZZZZ" });
    const db = fakeDb({ order: { create } });
    const out = await createPrismaOrderRepository(db as never).create(order());
    expect(out.id).toBe("o9");
    expect(create).toHaveBeenCalledTimes(3);
    const numbers = create.mock.calls.map((c) => c[0].data.orderNumber);
    expect(new Set(numbers).size).toBe(3);

    const always = fakeDb({ order: { create: vi.fn().mockRejectedValue(uniqueViolation(["orderNumber"])) } });
    await expect(createPrismaOrderRepository(always as never).create(order())).rejects.toThrow(/after 5 attempts/);
    expect(always.order.create).toHaveBeenCalledTimes(5);
  });

  it("does NOT retry a different unique violation or an unrelated error", async () => {
    const other = fakeDb({ order: { create: vi.fn().mockRejectedValue(uniqueViolation(["stripeSessionId"])) } });
    await expect(createPrismaOrderRepository(other as never).create(order())).rejects.toBeInstanceOf(Prisma.PrismaClientKnownRequestError);
    expect(other.order.create).toHaveBeenCalledTimes(1);

    const down = fakeDb({ order: { create: vi.fn().mockRejectedValue(new Error("connection lost")) } });
    await expect(createPrismaOrderRepository(down as never).create(order())).rejects.toThrow("connection lost");
  });

  it("refuses to create for a store that is not in the database", async () => {
    const db = fakeDb();
    db.store.findUnique.mockResolvedValue(null);
    await expect(createPrismaOrderRepository(db as never).create(order())).rejects.toThrow(/does not exist/);
  });

  it("markPaid is ONE conditional update (pending + unpaid + matching total) so racing webhooks can't both win", async () => {
    const db = fakeDb({ order: { updateMany: vi.fn().mockResolvedValue({ count: 1 }) } });
    const out = await createPrismaOrderRepository(db as never).markPaidByStripeSession("cs_1", { paymentIntentId: "pi_1", amountTotalCents: 1400 });
    expect(out).toEqual({ updated: true });
    expect(db.order.updateMany.mock.calls[0]?.[0]).toEqual({
      where: { stripeSessionId: "cs_1", status: "PENDING_PAYMENT", paymentStatus: "UNPAID", totalCents: 1400 },
      data: { status: "CONFIRMED", paymentStatus: "PAID", stripePaymentIntentId: "pi_1" },
    });
  });

  it.each([
    [null, "NOT_FOUND"],
    [{ status: "CONFIRMED", paymentStatus: "PAID", totalCents: 1400 }, "ALREADY_PAID"],
    [{ status: "CANCELLED", paymentStatus: "UNPAID", totalCents: 1400 }, "NOT_PENDING"],
    [{ status: "PENDING_PAYMENT", paymentStatus: "UNPAID", totalCents: 1400 }, "AMOUNT_MISMATCH"],
  ])("explains a non-update (%j) as %s", async (row, reason) => {
    const db = fakeDb({ order: { findUnique: vi.fn().mockResolvedValue(row) } });
    const out = await createPrismaOrderRepository(db as never).markPaidByStripeSession("cs_1", { paymentIntentId: "pi_1", amountTotalCents: 999 });
    expect(out).toEqual({ updated: false, reason });
  });

  const stored = {
    orderNumber: "DB-REV-ABC234",
    customerEmail: "Pat@Example.test",
    status: "CONFIRMED",
    paymentStatus: "PAID",
    paymentMethod: "STRIPE",
    pickupAt: new Date("2026-10-03T21:30:00Z"),
    createdAt: new Date("2026-10-02T00:00:00Z"),
    currency: "AUD",
    subtotalCents: 1400,
    totalCents: 1400,
    store: { slug: "revesby" },
    items: [
      {
        nameSnapshot: "Za’atar Manoush",
        quantity: 2,
        unitPriceCents: 700,
        lineTotalCents: 1400,
        modifiers: [{ groupSlug: "extras", slug: "extra-cheese", name: "Extra cheese", priceDeltaCents: 200 }],
        note: null,
      },
    ],
  };

  it("tracking needs the matching email: wrong email and unknown number both give null", async () => {
    const repo = createPrismaOrderRepository(fakeDb({ order: { findUnique: vi.fn().mockResolvedValue(stored) } }) as never);
    expect(await repo.getForTracking("db-rev-abc234", "pat@example.TEST")).toMatchObject({ orderNumber: "DB-REV-ABC234", storeSlug: "revesby" });
    expect(await repo.getForTracking("DB-REV-ABC234", "wrong@example.test")).toBeNull();

    const none = createPrismaOrderRepository(fakeDb() as never);
    expect(await none.getForTracking("DB-REV-NOPE22", "pat@example.test")).toBeNull();
  });

  it("tracking refuses to render an order whose stored modifiers are malformed", async () => {
    const bad = { ...stored, items: [{ ...stored.items[0], modifiers: [{ slug: 1 }] }] };
    const repo = createPrismaOrderRepository(fakeDb({ order: { findUnique: vi.fn().mockResolvedValue(bad) } }) as never);
    await expect(repo.getForTracking("DB-REV-ABC234", "pat@example.test")).rejects.toThrow();
  });

  it("attachStripeSession updates by order id", async () => {
    const db = fakeDb();
    await createPrismaOrderRepository(db as never).attachStripeSession("o1", "cs_1");
    expect(db.order.update).toHaveBeenCalledWith({ where: { id: "o1" }, data: { stripeSessionId: "cs_1" } });
  });
});

// ───────────────────────── Notifications ─────────────────────────

describe("notifyWaitlistSignup", () => {
  const payload = { name: "Sam", email: "sam@example.test", interests: ["MINI_ZAATAR"] };
  const withHook = () => {
    vi.stubEnv("WAITLIST_WEBHOOK_URL", "https://hooks.example/abc");
    resetEnvCache();
  };

  it("no-ops with NOT_CONFIGURED when there is no webhook", async () => {
    const fetchImpl = vi.fn();
    expect(await notifyWaitlistSignup(payload, fetchImpl)).toEqual({ delivered: false, reason: "NOT_CONFIGURED" });
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it("POSTs JSON to the webhook and reports delivery", async () => {
    withHook();
    const fetchImpl = vi.fn(async () => new Response("ok", { status: 200 }));
    expect(await notifyWaitlistSignup(payload, fetchImpl)).toEqual({ delivered: true });
    const [url, init] = fetchImpl.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe("https://hooks.example/abc");
    expect(init.method).toBe("POST");
    expect((init.headers as Record<string, string>)["Content-Type"]).toBe("application/json");
    expect(JSON.parse(String(init.body))).toMatchObject({ event: "waitlist.signup", email: "sam@example.test" });
  });

  it("returns the HTTP status as the reason on a non-2xx and never throws", async () => {
    withHook();
    const out = await notifyWaitlistSignup(payload, vi.fn(async () => new Response("nope", { status: 502 })));
    expect(out).toEqual({ delivered: false, reason: "HTTP_502" });
  });

  it("swallows a network failure", async () => {
    withHook();
    const out = await notifyWaitlistSignup(payload, vi.fn(async () => {
      throw new TypeError("fetch failed");
    }));
    expect(out).toEqual({ delivered: false, reason: "NETWORK_ERROR" });
  });

  it("aborts after 4 seconds and reports TIMEOUT", async () => {
    withHook();
    vi.useFakeTimers();
    const hang = vi.fn(
      (_url: string, init: RequestInit) =>
        new Promise<Response>((_resolve, reject) => {
          init.signal?.addEventListener("abort", () => reject(new DOMException("aborted", "AbortError")));
        }),
    );
    const pending = notifyWaitlistSignup(payload, hang as unknown as typeof fetch);
    await vi.advanceTimersByTimeAsync(3_999);
    let settled = false;
    void pending.then(() => (settled = true));
    await Promise.resolve();
    expect(settled).toBe(false);
    await vi.advanceTimersByTimeAsync(1);
    expect(await pending).toEqual({ delivered: false, reason: "TIMEOUT" });
  });

  it("does not throw on an invalid environment either", async () => {
    vi.stubEnv("WAITLIST_WEBHOOK_URL", "not a url");
    resetEnvCache();
    expect(await notifyWaitlistSignup(payload, vi.fn())).toEqual({ delivered: false, reason: "CONFIG_INVALID" });
  });
});
