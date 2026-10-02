/**
 * Minis waitlist storage. Email is the unique key; a repeat signup merges
 * interests and keeps the EARLIEST consent timestamp (the consent that
 * actually authorised contact, per the Spam Act 2003).
 */
import { Prisma, type MinisInterest as Interest, type PrismaClient } from "@prisma/client";
import type { StoreSlug } from "@/types/menu";
import { getPrisma } from "../db";
import { selectBackend } from "./select";

export interface WaitlistRecord {
  name: string;
  email: string;
  phone?: string | undefined;
  interests: Interest[];
  partyPieces?: number | undefined;
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
const mergeInterests = (existing: readonly Interest[], incoming: readonly Interest[]): Interest[] => [
  ...new Set([...existing, ...incoming]),
];

/** Which consent (time + the wording agreed to) is the earliest? Text always travels with its timestamp. */
function earliestConsent(
  a: { consentAt: Date; consentText: string },
  b: { consentAt: Date; consentText: string },
): { consentAt: Date; consentText: string } {
  return b.consentAt.getTime() < a.consentAt.getTime() ? b : a;
}

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
        byEmail.set(email, { ...record, email, interests: mergeInterests([], record.interests), id, notifiedAt: null });
        return { created: true, id };
      }
      const consent = earliestConsent(existing, record);
      byEmail.set(email, {
        ...existing,
        name: record.name,
        phone: record.phone ?? existing.phone,
        interests: mergeInterests(existing.interests, record.interests),
        partyPieces: record.partyPieces ?? existing.partyPieces,
        storeSlug: record.storeSlug ?? existing.storeSlug,
        consentAt: consent.consentAt,
        consentText: consent.consentText,
      });
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

  async function mergeInto(email: string, record: WaitlistRecord, sid: string | null) {
    const existing = await db.waitlistSubscriber.findUnique({ where: { email } });
    if (!existing) return null;
    const consent = earliestConsent(existing, record);
    await db.waitlistSubscriber.update({
      where: { id: existing.id },
      data: {
        name: record.name,
        ...(record.phone ? { phone: record.phone } : {}),
        interests: mergeInterests(existing.interests, record.interests),
        ...(record.partyPieces !== undefined ? { partyPieces: record.partyPieces } : {}),
        ...(sid ? { storeId: sid } : {}),
        consentAt: consent.consentAt,
        consentText: consent.consentText,
      },
    });
    return { created: false, id: existing.id };
  }

  return {
    async upsert(record) {
      const email = normaliseEmail(record.email);
      const sid = await storeId(record.storeSlug);

      const merged = await mergeInto(email, record, sid);
      if (merged) return merged;

      try {
        const row = await db.waitlistSubscriber.create({
          data: {
            email,
            name: record.name,
            phone: record.phone ?? null,
            interests: mergeInterests([], record.interests),
            partyPieces: record.partyPieces ?? null,
            storeId: sid,
            source: record.source ?? "minis-teaser",
            consentAt: record.consentAt,
            consentText: record.consentText,
          },
          select: { id: true },
        });
        return { created: true, id: row.id };
      } catch (err) {
        // Two simultaneous signups with the same email: the loser merges into the winner.
        if (err instanceof Prisma.PrismaClientKnownRequestError && err.code === "P2002") {
          const raced = await mergeInto(email, record, sid);
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
