/**
 * Prisma access. The client is created lazily so importing this module (or
 * running the site without a database) never opens a connection.
 */
import { PrismaClient } from "@prisma/client";
import { StorageUnavailableError } from "./errors";

/** Dev hot-reload re-evaluates modules; hang the client off globalThis so it isn't recreated each time. */
const globalForPrisma = globalThis as unknown as { __doughbossPrisma?: PrismaClient };

export function isDatabaseConfigured(source: Record<string, string | undefined> = process.env): boolean {
  return Boolean(source.DATABASE_URL && source.DATABASE_URL.trim() !== "");
}

export function getPrisma(): PrismaClient {
  if (!isDatabaseConfigured()) {
    throw new StorageUnavailableError("DATABASE_URL is not set, so there is nowhere to read from or write to.");
  }
  globalForPrisma.__doughbossPrisma ??= new PrismaClient();
  return globalForPrisma.__doughbossPrisma;
}
