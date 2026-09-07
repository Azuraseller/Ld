import { and, eq } from "drizzle-orm";
import { drizzle } from "drizzle-orm/mysql2";
import { InsertLegacyRecord, InsertUser, legacyRecords, users } from "../drizzle/schema";
import { ENV } from "./_core/env";

let _db: ReturnType<typeof drizzle> | null = null;

// Lazily create the drizzle instance so local tooling can run without a DB.
export async function getDb() {
  if (!_db && process.env.DATABASE_URL) {
    try {
      _db = drizzle(process.env.DATABASE_URL);
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
  const db = await getDb();
  if (!db) return [];
  return db
    .select()
    .from(legacyRecords)
    .where(and(eq(legacyRecords.scopeKey, scopeKey), eq(legacyRecords.recordType, recordType)));
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
