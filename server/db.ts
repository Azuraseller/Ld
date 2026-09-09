import { and, eq, gt } from "drizzle-orm";
import { drizzle } from "drizzle-orm/mysql2";
import mysql from "mysql2/promise";
import { InsertLegacyRecord, InsertUser, legacyRecords, users } from "../drizzle/schema";
import { ENV } from "./_core/env";

type Database = ReturnType<typeof drizzle>;
let _db: Database | null = null;
let _pool: mysql.Pool | null = null;

function perfLog(event: string, fields: Record<string, unknown>) {
  if (process.env.PERF_LOG !== "1") return;
  console.info(JSON.stringify({ perf: event, ...fields }));
}

// Lazily create the drizzle instance so local tooling can run without a DB.
export async function getDb() {
  if (!_db && process.env.DATABASE_URL) {
    try {
      const databaseUrl = new URL(process.env.DATABASE_URL);
      const pool = mysql.createPool({
        host: databaseUrl.hostname,
        port: databaseUrl.port ? Number(databaseUrl.port) : 3306,
        user: decodeURIComponent(databaseUrl.username),
        password: decodeURIComponent(databaseUrl.password),
        database: databaseUrl.pathname.replace(/^\//, ""),
        connectionLimit: Number(process.env.DB_POOL_SIZE || 10),
        connectTimeout: Number(process.env.DB_CONNECT_TIMEOUT_MS || 10_000),
        idleTimeout: Number(process.env.DB_IDLE_TIMEOUT_MS || 60_000),
        enableKeepAlive: true,
        keepAliveInitialDelay: 0,
      });
      _pool = pool;
      // The project may resolve mysql2 types through two dependency paths;
      // the runtime object is compatible, so keep the existing inferred DB API.
      _db = drizzle(pool) as unknown as Database;
    } catch (error) {
      console.warn("[Database] Failed to connect:", error);
      _db = null;
    }
  }
  return _db;
}

export async function upsertUser(user: InsertUser): Promise<void> {
  if (!user.openId) throw new Error("User openId is required for upsert");

  const db = await getDb();
  if (!db) {
    console.warn("[Database] Cannot upsert user: database not available");
    return;
  }

  const values: InsertUser = { openId: user.openId };
  const updateSet: Record<string, unknown> = {};
  const textFields = ["name", "email", "loginMethod"] as const;
  type TextField = (typeof textFields)[number];

  const assignNullable = (field: TextField) => {
    const value = user[field];
    if (value === undefined) return;
    const normalized = value ?? null;
    values[field] = normalized;
    updateSet[field] = normalized;
  };
  textFields.forEach(assignNullable);

  if (user.lastSignedIn !== undefined) {
    values.lastSignedIn = user.lastSignedIn;
    updateSet.lastSignedIn = user.lastSignedIn;
  }
  if (user.role !== undefined) {
    values.role = user.role;
    updateSet.role = user.role;
  } else if (user.openId === ENV.ownerOpenId) {
    values.role = "admin";
    updateSet.role = "admin";
  }
  if (!values.lastSignedIn) values.lastSignedIn = new Date();
  if (Object.keys(updateSet).length === 0) updateSet.lastSignedIn = new Date();

  await db.insert(users).values(values).onDuplicateKeyUpdate({ set: updateSet });
}

export async function getUserByOpenId(openId: string) {
  const db = await getDb();
  if (!db) return undefined;
  const result = await db.select().from(users).where(eq(users.openId, openId)).limit(1);
  return result.length > 0 ? result[0] : undefined;
}

export async function getLegacyRecord(scopeKey: string, recordType: string, recordKey: string) {
  const db = await getDb();
  if (!db) return undefined;
  const result = await db
    .select()
    .from(legacyRecords)
    .where(and(
      eq(legacyRecords.scopeKey, scopeKey),
      eq(legacyRecords.recordType, recordType),
      eq(legacyRecords.recordKey, recordKey),
    ))
    .limit(1);
  return result[0];
}

export async function listLegacyRecords(scopeKey: string, recordType: string) {
  const page = await listLegacyRecordsPage(scopeKey, recordType, { limit: 10_000 });
  return page.records;
}

export async function listLegacyRecordsPage(
  scopeKey: string,
  recordType: string,
  options: { limit?: number; cursor?: number } = {},
) {
  const db = await getDb();
  if (!db) return { records: [], nextCursor: null };
  const limit = Math.min(Math.max(Number(options.limit || 100), 1), 1000);
  const started = performance.now();
  const filters = [eq(legacyRecords.scopeKey, scopeKey), eq(legacyRecords.recordType, recordType)];
  if (Number.isInteger(options.cursor) && Number(options.cursor) > 0) {
    filters.push(gt(legacyRecords.id, Number(options.cursor)));
  }
  const records = await db
    .select()
    .from(legacyRecords)
    .where(and(...filters))
    .orderBy(legacyRecords.id)
    .limit(limit + 1);
  const hasMore = records.length > limit;
  const visible = hasMore ? records.slice(0, limit) : records;
  perfLog("db.list_legacy_records", {
    scopeKey: scopeKey.slice(0, 8),
    recordType,
    limit,
    count: visible.length,
    durationMs: Math.round((performance.now() - started) * 100) / 100,
  });
  return { records: visible, nextCursor: hasMore ? visible[visible.length - 1]?.id ?? null : null };
}

export async function upsertLegacyRecord(
  record: Pick<InsertLegacyRecord, "scopeKey" | "recordType" | "recordKey" | "payload">,
) {
  const db = await getDb();
  if (!db) throw new Error("Cơ sở dữ liệu chưa sẵn sàng");
  // TiDB/MySQL can report an UPDATE with zero affected rows for a missing
  // record. Use one atomic insert-or-update statement instead of relying on
  // affectedRows and a race-prone update-then-insert sequence.
  await db.insert(legacyRecords).values(record).onDuplicateKeyUpdate({
    set: { payload: record.payload, updatedAt: new Date() },
  });
  return getLegacyRecord(record.scopeKey, record.recordType, record.recordKey);
}

export async function deleteLegacyRecords(scopeKey: string, recordType: string, recordKey?: string) {
  const db = await getDb();
  if (!db) throw new Error("Cơ sở dữ liệu chưa sẵn sàng");
  const filters = [eq(legacyRecords.scopeKey, scopeKey), eq(legacyRecords.recordType, recordType)];
  if (recordKey !== undefined) filters.push(eq(legacyRecords.recordKey, recordKey));
  await db.delete(legacyRecords).where(and(...filters));
}
