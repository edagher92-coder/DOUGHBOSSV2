/**
 * Order storage.
 *
 * This module never prices anything. `create()` receives lines that were
 * already priced by `priceCart` (the single pricing implementation) and stores
 * name/price snapshots, so later menu edits cannot rewrite history. It does
 * check the arithmetic is self-consistent, because a mismatch means a bug
 * upstream and must not be persisted quietly.
 */
import { randomInt, randomUUID } from "node:crypto";
import { Prisma, type PrismaClient } from "@prisma/client";
import { z } from "zod";
import type { PricedModifier, StoreSlug } from "@/types/menu";
import { getPrisma } from "../db";
import { generateOrderNumber, type Rng } from "../order-number";
import { selectBackend } from "./select";

export type PaymentMethodValue = "SQUARE" | "PAY_AT_SHOP";
export type OrderStatusValue = "PENDING_PAYMENT" | "CONFIRMED" | "PREPARING" | "READY" | "COMPLETED" | "CANCELLED";
export type PaymentStatusValue = "UNPAID" | "PAID" | "REFUNDED" | "FAILED";

export interface NewOrderLine {
  /** Link to the live menu row, if one exists. The snapshot below is the source of truth. */
  menuItemId?: string | undefined;
  name: string;
  /** Base price + modifier deltas, per unit, from priceCart. */
  unitPriceCents: number;
  quantity: number;
  lineTotalCents: number;
  modifiers: PricedModifier[];
  note?: string | undefined;
}

export interface NewOrder {
  storeSlug: StoreSlug;
  paymentMethod: PaymentMethodValue;
  customer: { name: string; email: string; phone: string };
  notes?: string | undefined;
  pickupAt: Date;
  lines: NewOrderLine[];
  subtotalCents: number;
  totalCents: number;
}

export interface OrderView {
  orderNumber: string;
  status: OrderStatusValue;
  paymentStatus: PaymentStatusValue;
  paymentMethod: PaymentMethodValue;
  storeSlug: string;
  pickupAt: Date;
  createdAt: Date;
  currency: string;
  subtotalCents: number;
  totalCents: number;
  items: Array<{
    name: string;
    quantity: number;
    unitPriceCents: number;
    lineTotalCents: number;
    modifiers: PricedModifier[];
    note?: string | undefined;
  }>;
}

export type MarkPaidResult = {
  updated: boolean;
  /**
   * NOT_PENDING is an addition to the brief's union: an order that is neither
   * PENDING_PAYMENT nor already paid (e.g. CANCELLED) received a payment. Calling
   * that ALREADY_PAID would be a lie, and it needs a human (likely a refund).
   */
  reason?: "NOT_FOUND" | "AMOUNT_MISMATCH" | "ALREADY_PAID" | "NOT_PENDING";
};

export interface OrderRepository {
  create(order: NewOrder): Promise<{ id: string; orderNumber: string }>;
  attachStripeSession(orderId: string, sessionId: string): Promise<void>;
  markPaidByStripeSession(
    sessionId: string,
    payment: { paymentIntentId: string; amountTotalCents: number },
  ): Promise<MarkPaidResult>;
  getForTracking(orderNumber: string, email: string): Promise<OrderView | null>;
}

export interface OrderRepositoryOptions {
  rng?: Rng;
}

type Created = { id: string; orderNumber: string };

const MAX_NUMBER_ATTEMPTS = 5;

const normaliseEmail = (email: string) => email.trim().toLowerCase();
const normaliseOrderNumber = (n: string) => n.trim().toUpperCase();

function assertConsistent(order: NewOrder): void {
  const isCents = (n: number) => Number.isInteger(n) && n >= 0;
  if (order.lines.length === 0) throw new Error("An order needs at least one line.");
  for (const l of order.lines) {
    if (!isCents(l.unitPriceCents) || !isCents(l.lineTotalCents) || !Number.isInteger(l.quantity) || l.quantity < 1) {
      throw new Error(`Order line "${l.name}" has an invalid price or quantity.`);
    }
    if (l.unitPriceCents * l.quantity !== l.lineTotalCents) {
      throw new Error(`Order line "${l.name}" total does not equal unit price x quantity.`);
    }
  }
  const sum = order.lines.reduce((s, l) => s + l.lineTotalCents, 0);
  if (!isCents(order.subtotalCents) || order.subtotalCents !== sum) {
    throw new Error("Order subtotal does not equal the sum of its lines.");
  }
  if (!isCents(order.totalCents) || order.totalCents < order.subtotalCents) {
    throw new Error("Order total is invalid.");
  }
}

const storedModifiers = z.array(
  z.object({ groupSlug: z.string(), slug: z.string(), name: z.string(), priceDeltaCents: z.number().int() }),
);

/** Result of one attempt to insert with a candidate number. */
type Attempt<T> = { kind: "ok"; value: T } | { kind: "collision" };

async function withUniqueOrderNumber<T>(
  storeSlug: StoreSlug,
  rng: Rng,
  attempt: (orderNumber: string) => Promise<Attempt<T>>,
): Promise<T> {
  for (let i = 0; i < MAX_NUMBER_ATTEMPTS; i++) {
    const result = await attempt(generateOrderNumber(storeSlug, rng));
    if (result.kind === "ok") return result.value;
  }
  throw new Error(`Could not allocate a unique order number after ${MAX_NUMBER_ATTEMPTS} attempts.`);
}

// ───────────────────────── In-memory (dev and tests only) ─────────────────────────

interface MemoryOrder {
  id: string;
  order: NewOrder;
  orderNumber: string;
  status: OrderStatusValue;
  paymentStatus: PaymentStatusValue;
  stripeSessionId?: string;
  stripePaymentIntentId?: string;
  createdAt: Date;
}

export interface InMemoryOrderRepository extends OrderRepository {
  /** Test hook: move an order to a status (e.g. CANCELLED) without a UI. */
  setStatus(orderId: string, status: OrderStatusValue): void;
  all(): MemoryOrder[];
}

export function createInMemoryOrderRepository(opts: OrderRepositoryOptions & { now?: () => Date } = {}): InMemoryOrderRepository {
  const rng = opts.rng ?? randomInt;
  const now = opts.now ?? (() => new Date());
  const orders = new Map<string, MemoryOrder>();
  const byNumber = new Map<string, string>();
  const bySession = new Map<string, string>();

  const mustGet = (id: string): MemoryOrder => {
    const found = orders.get(id);
    if (!found) throw new Error(`Order ${id} not found.`);
    return found;
  };

  return {
    async create(order) {
      assertConsistent(order);
      return withUniqueOrderNumber<Created>(order.storeSlug, rng, async (orderNumber) => {
        if (byNumber.has(orderNumber)) return { kind: "collision" };
        const id = randomUUID();
        orders.set(id, {
          id,
          order: { ...order, customer: { ...order.customer, email: normaliseEmail(order.customer.email) } },
          orderNumber,
          status: "PENDING_PAYMENT",
          paymentStatus: "UNPAID",
          createdAt: now(),
        });
        byNumber.set(orderNumber, id);
        return { kind: "ok", value: { id, orderNumber } };
      });
    },

    async attachStripeSession(orderId, sessionId) {
      const o = mustGet(orderId);
      o.stripeSessionId = sessionId;
      bySession.set(sessionId, orderId);
    },

    async markPaidByStripeSession(sessionId, { paymentIntentId, amountTotalCents }) {
      const id = bySession.get(sessionId);
      const o = id ? orders.get(id) : undefined;
      if (!o) return { updated: false, reason: "NOT_FOUND" };
      if (o.paymentStatus === "PAID") return { updated: false, reason: "ALREADY_PAID" };
      if (o.status !== "PENDING_PAYMENT") return { updated: false, reason: "NOT_PENDING" };
      if (o.order.totalCents !== amountTotalCents) return { updated: false, reason: "AMOUNT_MISMATCH" };
      o.status = "CONFIRMED";
      o.paymentStatus = "PAID";
      o.stripePaymentIntentId = paymentIntentId;
      return { updated: true };
    },

    async getForTracking(orderNumber, email) {
      const id = byNumber.get(normaliseOrderNumber(orderNumber));
      const o = id ? orders.get(id) : undefined;
      if (!o || o.order.customer.email !== normaliseEmail(email)) return null;
      return {
        orderNumber: o.orderNumber,
        status: o.status,
        paymentStatus: o.paymentStatus,
        paymentMethod: o.order.paymentMethod,
        storeSlug: o.order.storeSlug,
        pickupAt: o.order.pickupAt,
        createdAt: o.createdAt,
        currency: "AUD",
        subtotalCents: o.order.subtotalCents,
        totalCents: o.order.totalCents,
        items: o.order.lines.map((l) => ({
          name: l.name,
          quantity: l.quantity,
          unitPriceCents: l.unitPriceCents,
          lineTotalCents: l.lineTotalCents,
          modifiers: l.modifiers,
          note: l.note,
        })),
      };
    },

    setStatus(orderId, status) {
      mustGet(orderId).status = status;
    },
    all: () => [...orders.values()],
  };
}

// ───────────────────────── Prisma ─────────────────────────

type Db = Pick<PrismaClient, "order" | "store" | "menuItem">;

function isOrderNumberCollision(err: unknown): boolean {
  if (!(err instanceof Prisma.PrismaClientKnownRequestError) || err.code !== "P2002") return false;
  const target = err.meta?.target;
  // No target reported: orderNumber is the only unique column set at create time.
  if (target === undefined) return true;
  return Array.isArray(target) ? target.includes("orderNumber") : String(target).includes("orderNumber");
}

export function createPrismaOrderRepository(db: Db, opts: OrderRepositoryOptions = {}): OrderRepository {
  const rng = opts.rng ?? randomInt;

  return {
    async create(order) {
      assertConsistent(order);
      const store = await db.store.findUnique({ where: { slug: order.storeSlug }, select: { id: true } });
      if (!store) throw new Error(`Store "${order.storeSlug}" does not exist in the database.`);

      // A line may reference a menu row that is not in this database (e.g. demo ids).
      // The snapshot carries the truth, so an unknown link is stored as null, not as a broken FK.
      const wanted = [...new Set(order.lines.flatMap((l) => (l.menuItemId ? [l.menuItemId] : [])))];
      const known = new Set(
        wanted.length === 0
          ? []
          : (await db.menuItem.findMany({ where: { id: { in: wanted } }, select: { id: true } })).map((m) => m.id),
      );

      return withUniqueOrderNumber<Created>(order.storeSlug, rng, async (orderNumber) => {
        try {
          const row = await db.order.create({
            data: {
              orderNumber,
              storeId: store.id,
              paymentMethod: order.paymentMethod,
              customerName: order.customer.name,
              customerEmail: normaliseEmail(order.customer.email),
              customerPhone: order.customer.phone,
              notes: order.notes ?? null,
              pickupAt: order.pickupAt,
              subtotalCents: order.subtotalCents,
              totalCents: order.totalCents,
              items: {
                create: order.lines.map((l) => ({
                  menuItemId: l.menuItemId && known.has(l.menuItemId) ? l.menuItemId : null,
                  nameSnapshot: l.name,
                  unitPriceCents: l.unitPriceCents,
                  quantity: l.quantity,
                  lineTotalCents: l.lineTotalCents,
                  modifiers: l.modifiers.map((m) => ({
                    groupSlug: m.groupSlug,
                    slug: m.slug,
                    name: m.name,
                    priceDeltaCents: m.priceDeltaCents,
                  })),
                  note: l.note ?? null,
                })),
              },
            },
            select: { id: true, orderNumber: true },
          });
          return { kind: "ok", value: { id: row.id, orderNumber: row.orderNumber } };
        } catch (err) {
          if (isOrderNumberCollision(err)) return { kind: "collision" };
          throw err;
        }
      });
    },

    async attachStripeSession(orderId, sessionId) {
      await db.order.update({ where: { id: orderId }, data: { stripeSessionId: sessionId } });
    },

    async markPaidByStripeSession(sessionId, { paymentIntentId, amountTotalCents }) {
      // One conditional UPDATE decides the outcome, so two webhook deliveries
      // racing each other cannot both "win".
      const { count } = await db.order.updateMany({
        where: {
          stripeSessionId: sessionId,
          status: "PENDING_PAYMENT",
          paymentStatus: "UNPAID",
          totalCents: amountTotalCents,
        },
        data: { status: "CONFIRMED", paymentStatus: "PAID", stripePaymentIntentId: paymentIntentId },
      });
      if (count > 0) return { updated: true };

      // Nothing matched: work out why, so the caller can alert on the right thing.
      const row = await db.order.findUnique({
        where: { stripeSessionId: sessionId },
        select: { status: true, paymentStatus: true, totalCents: true },
      });
      if (!row) return { updated: false, reason: "NOT_FOUND" };
      if (row.paymentStatus === "PAID") return { updated: false, reason: "ALREADY_PAID" };
      if (row.status !== "PENDING_PAYMENT") return { updated: false, reason: "NOT_PENDING" };
      return { updated: false, reason: "AMOUNT_MISMATCH" };
    },

    async getForTracking(orderNumber, email) {
      const row = await db.order.findUnique({
        where: { orderNumber: normaliseOrderNumber(orderNumber) },
        include: { items: true, store: { select: { slug: true } } },
      });
      // Same answer for "no such order" and "wrong email" so numbers can't be enumerated.
      if (!row || normaliseEmail(row.customerEmail) !== normaliseEmail(email)) return null;
      return {
        orderNumber: row.orderNumber,
        status: row.status,
        paymentStatus: row.paymentStatus,
        paymentMethod: row.paymentMethod,
        storeSlug: row.store.slug,
        pickupAt: row.pickupAt,
        createdAt: row.createdAt,
        currency: row.currency,
        subtotalCents: row.subtotalCents,
        totalCents: row.totalCents,
        items: row.items.map((i) => ({
          name: i.nameSnapshot,
          quantity: i.quantity,
          unitPriceCents: i.unitPriceCents,
          lineTotalCents: i.lineTotalCents,
          // A malformed snapshot throws: showing an order with silently missing modifiers would misstate it.
          modifiers: storedModifiers.parse(i.modifiers),
          note: i.note ?? undefined,
        })),
      };
    },
  };
}

// ───────────────────────── Selection ─────────────────────────

let memorySingleton: InMemoryOrderRepository | undefined;

export function getOrderRepository(): OrderRepository {
  if (selectBackend() === "prisma") return createPrismaOrderRepository(getPrisma());
  memorySingleton ??= createInMemoryOrderRepository();
  return memorySingleton;
}

/** Test hook. */
export function resetOrderRepository(): void {
  memorySingleton = undefined;
}
