/**
 * "Something exciting is coming" waitlist storage. Email is the unique key.
 *
 * A repeat signup NEVER changes the existing record: the submission is unauthenticated, so
 * anyone who knows an address could otherwise overwrite that subscriber's name, phone or
 * store. The original record (and its original consent, the one that actually authorised
 * contact under the Spam Act 2003) stays as it was. Changing an existing entry needs a
 * signed confirmation flow (an emailed link proving ownership), which is not built yet.
 */
import { Prisma, type PrismaClient } from "@prisma/client";
import type { StoreSlug } from "@/types/menu";
import { getPrisma } from "../db";
import { selectBackend } from "./select";

export interface WaitlistRecord {
  name: string;
  email: string;
  phone?: string | undefined;
  storeSlug?: StoreSlug | undefined;
  source?: string | undefined;
  consentAt: Date;
  consentText: string;
}

export interface WaitlistRepository {
  upsert(record: WaitlistRecord): Promise<{ created: boolean; id: string }>;
  markNotified(id: string): Promise<void>;
}

const normaliseEmail = (email: string) => email.trim().toLowerCase();

// ───────────────────────── In-memory (dev and tests only) ─────────────────────────

interface MemoryRow extends WaitlistRecord {
  id: string;
  notifiedAt: Date | null;
}

export interface InMemoryWaitlistRepository extends WaitlistRepository {
  /** Test/inspection hook. */
  all(): MemoryRow[];
}

export function createInMemoryWaitlistRepository(): InMemoryWaitlistRepository {
  const byEmail = new Map<string, MemoryRow>();
  let seq = 0;

  return {
    async upsert(record) {
      const email = normaliseEmail(record.email);
      const existing = byEmail.get(email);
      if (!existing) {
        const id = `mem_wl_${++seq}`;
        byEmail.set(email, { ...record, email, id, notifiedAt: null });
        return { created: true, id };
      }
      // Existing subscriber: leave the record untouched (see the header note).
      return { created: false, id: existing.id };
    },
    async markNotified(id) {
      for (const row of byEmail.values()) {
        if (row.id === id) row.notifiedAt = new Date();
      }
    },
    all: () => [...byEmail.values()],
  };
}

// ───────────────────────── Prisma ─────────────────────────

type Db = Pick<PrismaClient, "waitlistSubscriber" | "store">;

export function createPrismaWaitlistRepository(db: Db): WaitlistRepository {
  async function storeId(slug: StoreSlug | undefined): Promise<string | null> {
    if (!slug) return null;
    const row = await db.store.findUnique({ where: { slug }, select: { id: true } });
    return row?.id ?? null;
  }

  /** Existing subscriber: report it, change nothing (see the header note). */
  async function existingId(email: string) {
    const existing = await db.waitlistSubscriber.findUnique({ where: { email }, select: { id: true } });
    return existing ? { created: false, id: existing.id } : null;
  }

  return {
    async upsert(record) {
      const email = normaliseEmail(record.email);

      const found = await existingId(email);
      if (found) return found;
      const sid = await storeId(record.storeSlug);

      try {
        const row = await db.waitlistSubscriber.create({
          data: {
            email,
            name: record.name,
            phone: record.phone ?? null,
            storeId: sid,
            source: record.source ?? "coming-soon-teaser",
            consentAt: record.consentAt,
            consentText: record.consentText,
          },
          select: { id: true },
        });
        return { created: true, id: row.id };
      } catch (err) {
        // Two simultaneous signups with the same email: the loser reports the winner's record.
        if (err instanceof Prisma.PrismaClientKnownRequestError && err.code === "P2002") {
          const raced = await existingId(email);
          if (raced) return raced;
        }
        throw err;
      }
    },

    async markNotified(id) {
      // updateMany: a vanished row is a no-op rather than an error on a best-effort path.
      await db.waitlistSubscriber.updateMany({ where: { id }, data: { notifiedAt: new Date() } });
    },
  };
}

// ───────────────────────── Selection ─────────────────────────

let memorySingleton: InMemoryWaitlistRepository | undefined;

export function getWaitlistRepository(): WaitlistRepository {
  if (selectBackend() === "prisma") return createPrismaWaitlistRepository(getPrisma());
  memorySingleton ??= createInMemoryWaitlistRepository();
  return memorySingleton;
}

/** Test hook. */
export function resetWaitlistRepository(): void {
  memorySingleton = undefined;
}
