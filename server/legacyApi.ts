import type { Express, Request, Response } from "express";
import { randomUUID } from "node:crypto";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { invokeLLM, type Message } from "./_core/llm";
import { PHP_UPSTREAM_ACTIONS, runPhpLegacyAction } from "./phpLegacy";
import { deleteLegacyRecords,
  getLegacyRecord,
  listLegacyRecords,
  upsertLegacyRecord,
} from "./db";

const SCOPE_COOKIE = "legacy_scope";
const MIMI_COOKIE = "mimi_user";
const COOKIE_MAX_AGE = 30 * 24 * 60 * 60 * 1000;
const MIMI_USERS = ["MimiVip01", "Apimimi01", "Apimimi05", "Apimimi10"] as const;
const execFileAsync = promisify(execFile);
const DEFAULT_GITHUB_REPO = "Azuraseller/ld-tool-web";

function githubRepo(): string {
  return String(process.env.GITHUB_REPO || DEFAULT_GITHUB_REPO).trim().replace(/^https?:\/\/github\.com\//, "").replace(/\.git$/, "");
}

async function githubLatestCommit() {
  const token = String(process.env.GITHUB_TOKEN || "").trim();
  const headers: Record<string, string> = {
    Accept: "application/vnd.github+json",
    "X-GitHub-Api-Version": "2022-11-28",
    "User-Agent": "ld-tool-web-control-panel",
  };
  if (token) headers.Authorization = `Bearer ${token}`;
  const response = await fetch(`https://api.github.com/repos/${githubRepo()}/commits/main`, { headers });
  const payload = await response.json() as { sha?: string; commit?: { message?: string; author?: { date?: string } }; message?: string };
  if (!response.ok || !payload.sha) throw new Error(payload.message || `GitHub API trả về HTTP ${response.status}`);
  return {
    repository: githubRepo(),
    branch: "main",
    sha: payload.sha,
    shortSha: payload.sha.slice(0, 7),
    message: payload.commit?.message?.split("\n")[0] || "",
    date: payload.commit?.author?.date || null,
  };
}

function githubCommandEnv(): NodeJS.ProcessEnv {
  const token = String(process.env.GITHUB_TOKEN || '').trim();
  if (!token) return process.env;
  return {
    ...process.env,
    GIT_TERMINAL_PROMPT: '0',
    GIT_CONFIG_COUNT: '1',
    GIT_CONFIG_KEY_0: 'http.https://github.com/.extraheader',
    GIT_CONFIG_VALUE_0: `AUTHORIZATION: basic ${Buffer.from(`x-access-token:${token}`).toString('base64')}`,
  };
}

type LegacyValue = Record<string, unknown> | unknown[] | string | number | boolean | null;
type LegacyRequest = Request & { body?: Record<string, unknown> };

const proxyFeatures = [
  { key: "temp_email", label: "Tạo email ảo (temp-mail)", pool: "us", enabled: true },
  { key: "send_otp", label: "Gửi OTP", pool: "us", enabled: true },
  { key: "register_funpass", label: "Đăng ký FunPass", pool: "us", enabled: true },
  { key: "register_ldplayer", label: "Đăng ký LDPlayer mới", pool: "us", enabled: true },
  { key: "login_funpass", label: "Đăng nhập FunPass", pool: "us", enabled: true },
  { key: "login_ldplayer", label: "Đăng nhập LDPlayer", pool: "us", enabled: true },
  { key: "cpi_list", label: "Lấy danh sách game CPI", pool: "vn", enabled: true },
  { key: "cpi_claim", label: "Nhận thưởng CPI", pool: "vn", enabled: true },
  { key: "wallet", label: "Xem ví FunPass", pool: "us", enabled: false },
  { key: "transfer", label: "Chuyển điểm (detail/execute)", pool: "us", enabled: true },
  { key: "mt_login", label: "[Mua thẻ] Đăng nhập LDPlayer", pool: "us", enabled: false },
  { key: "mt_common", label: "[Mua thẻ] Số dư / đơn hàng", pool: "us", enabled: false },
  { key: "mt_item", label: "[Mua thẻ] Danh sách SKU", pool: "us", enabled: false },
  { key: "mt_order", label: "[Mua thẻ] Tạo đơn / săn thẻ", pool: "us", enabled: false },
] as const;

function parseCookieHeader(header: string | undefined): Record<string, string> {
  return Object.fromEntries((header || "").split(";").flatMap(part => {
    const separator = part.indexOf("=");
    if (separator < 0) return [];
    return [[part.slice(0, separator).trim(), decodeURIComponent(part.slice(separator + 1).trim())]];
  }));
}

function getScope(req: LegacyRequest, res: Response, targetUser = "") {
  const cookies = parseCookieHeader(req.headers.cookie);
  const requested = String(targetUser || "").trim();
  const current = currentMimiUser(req);
  if (requested && current === "MimiVip01" && MIMI_USERS.includes(requested as (typeof MIMI_USERS)[number])) return `mimi:${requested}`;
  const existing = cookies[SCOPE_COOKIE];
  if (existing && /^[a-f0-9-]{20,80}$/i.test(existing)) return existing;
  const scope = randomUUID();
  res.cookie(SCOPE_COOKIE, scope, {
    maxAge: COOKIE_MAX_AGE,
    httpOnly: true,
    sameSite: "lax",
    secure: req.secure,
    path: "/",
  });
  return scope;
}

function currentMimiUser(req: LegacyRequest) {
  const value = parseCookieHeader(req.headers.cookie)[MIMI_COOKIE];
  return MIMI_USERS.includes(value as (typeof MIMI_USERS)[number]) ? value : null;
}

function jsonPayload(value: LegacyValue): string {
  return JSON.stringify(value ?? null);
}

function readPayload<T>(payload: string | undefined, fallback: T): T {
  if (!payload) return fallback;
  try {
    return JSON.parse(payload) as T;
  } catch {
    return fallback;
  }
}

async function readRecord<T>(scope: string, type: string, key: string, fallback: T) {
  const record = await getLegacyRecord(scope, type, key);
  return readPayload(record?.payload, fallback);
}

async function listRecords<T>(scope: string, type: string): Promise<T[]> {
  const records = await listLegacyRecords(scope, type);
  return records.flatMap(record => {
    const value = readPayload<T | null>(record.payload, null);
    return value === null ? [] : [value];
  });
}

function badMethod(res: Response) {
  return res.status(405).json({ ok: false, error: "Action này yêu cầu phương thức POST." });
}

function unsupported(res: Response, action: string) {
  return res.status(501).json({
    ok: false,
    error: `Action ${action} cần runtime PHP api3.php gốc; backend web hiện chưa chạy action upstream này.`,
  });
}

export async function handleLegacyAction(req: LegacyRequest, res: Response) {
  const action = String(req.query.action || "").trim();
  if (!action) return false;
  const body = req.body && typeof req.body === "object" ? req.body : {};
  const targetUser = String((body as Record<string, unknown>)._target_user || req.query._target_user || "").trim();
  const scope = getScope(req, res, targetUser);
  const method = req.method.toUpperCase();
  const readOnly = new Set(["ping", "proxy_get", "mt_session", "acc_list", "runlog_list", "modules_list", "mimi_models_get", "auth_status", "mimi_memory_get", "mimi_chats_get", "github_status"]);
  if (method !== "POST" && !readOnly.has(action)) {
    badMethod(res);
    return true;
  }

  try {
    switch (action) {
      case "ping":
        res.json({ ok: true, service: "legacy-api-bridge", database: Boolean(process.env.DATABASE_URL) });
        return true;
      case "auth_status": {
        const user = currentMimiUser(req);
        res.json({ ok: true, authenticated: Boolean(user), user, developer: user === "MimiVip01", available_users: MIMI_USERS });
        return true;
      }
      case "auth_login": {
        const user = String(body.name || "");
        if (!MIMI_USERS.includes(user as (typeof MIMI_USERS)[number])) {
          res.status(401).json({ ok: false, error: "Tên tài khoản Mimi không hợp lệ" });
          return true;
        }
        res.cookie(MIMI_COOKIE, user, { maxAge: COOKIE_MAX_AGE, httpOnly: true, sameSite: "lax", secure: req.secure, path: "/" });
        res.json({ ok: true, user, developer: user === "MimiVip01", available_users: MIMI_USERS });
        return true;
      }
      case "auth_logout":
        res.clearCookie(MIMI_COOKIE, { httpOnly: true, sameSite: "lax", secure: req.secure, path: "/" });
        res.json({ ok: true });
        return true;
      case "github_status": {
        if (currentMimiUser(req) !== "MimiVip01") {
          res.status(403).json({ ok: false, error: "Chỉ MimiVip01 mới được xem trạng thái source GitHub." });
          return true;
        }
        const latest = await githubLatestCommit();
        res.json({ ok: true, ...latest });
        return true;
      }
      case "github_sync": {
        if (currentMimiUser(req) !== "MimiVip01") {
          res.status(403).json({ ok: false, error: "Chỉ MimiVip01 mới được đồng bộ source từ GitHub." });
          return true;
        }
        const cwd = process.cwd();
        const env = githubCommandEnv();
        try {
          await execFileAsync("git", ["rev-parse", "--is-inside-work-tree"], { cwd, env, timeout: 30000 });
          const latest = await githubLatestCommit();
          const pull = await execFileAsync("git", ["pull", "--ff-only", "origin", "main"], { cwd, env, timeout: 120000, maxBuffer: 2 * 1024 * 1024 });
          const build = await execFileAsync("pnpm", ["build"], { cwd, env, timeout: 300000, maxBuffer: 8 * 1024 * 1024 });
          const commit = await execFileAsync("git", ["rev-parse", "--short", "HEAD"], { cwd, env, timeout: 30000 });
          res.json({ ok: true, commit: commit.stdout.trim(), remote_commit: latest.shortSha, pulled: pull.stdout.trim(), built: build.stdout.trim().slice(-1200), reload_required: true });
        } catch (error) {
          const detail = error as { stderr?: string; stdout?: string; message?: string };
          const raw = String(detail.stderr || detail.stdout || detail.message || "Không đồng bộ được GitHub");
          const authFailure = /authentication failed|could not read username|terminal prompts disabled|repository not found|permission denied|private repository/i.test(raw);
          if (authFailure && !String(process.env.GITHUB_TOKEN || '').trim()) {
            res.status(503).json({
              ok: false,
              code: "github_runtime_credential_missing",
              error: "Runtime WebDev chưa có GITHUB_TOKEN để pull repository private. Hãy cấu hình credential cho deployment và redeploy; đăng nhập MimiVip01 không cấp quyền GitHub cho container.",
            });
          } else {
            res.status(500).json({ ok: false, error: raw.slice(-1800) });
          }
        }
        return true;
      }
      case "proxy_get": {
        const upstream = await runPhpLegacyAction(action, body, { mimiUser: currentMimiUser(req), guestScope: currentMimiUser(req) ? null : scope });
        res.status(upstream.ok === false ? 502 : 200).json(upstream);
        return true;
      }
      case "proxy_add": {
        if (!String(body.proxy || body.value || "").trim()) {
          res.status(400).json({ ok: false, error: "Thiếu proxy" });
          return true;
        }
        const upstream = await runPhpLegacyAction(action, body, { mimiUser: currentMimiUser(req), guestScope: currentMimiUser(req) ? null : scope });
        res.status(upstream.ok === false ? 502 : 200).json(upstream);
        return true;
      }
      case "proxy_reset_state": {
        const upstream = await runPhpLegacyAction(action, body, { mimiUser: currentMimiUser(req), guestScope: currentMimiUser(req) ? null : scope });
        res.status(upstream.ok === false ? 502 : 200).json(upstream);
        return true;
      }
      case "proxy_save": {
        const upstream = await runPhpLegacyAction(action, body, { mimiUser: currentMimiUser(req), guestScope: currentMimiUser(req) ? null : scope });
        res.status(upstream.ok === false ? 502 : 200).json(upstream);
        return true;
      }
      case "acc_list": {
        const accounts = await listRecords<Record<string, unknown>>(scope, "account");
        res.json({ ok: true, accounts, logs: [] });
        return true;
      }
      case "acc_save": {
        let supplied: Record<string, unknown> = {};
        if (typeof body.json === "string" && body.json.trim()) {
          try {
            const parsed: unknown = JSON.parse(body.json);
            if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("JSON tài khoản phải là một object");
            supplied = parsed as Record<string, unknown>;
          } catch (error) {
            res.status(400).json({ ok: false, error: error instanceof Error ? error.message : "JSON tài khoản không hợp lệ" });
            return true;
          }
        }
        const email = String(supplied.email || supplied.username || body.email || "").trim();
        const type = String(body.type || supplied.type || "ldplayer").trim() || "ldplayer";
        if (!email) {
          res.status(400).json({ ok: false, error: "Thiếu email tài khoản" });
          return true;
        }
        const account = { ...supplied, ...body, username: email, type, updated_at: new Date().toISOString() };
        delete (account as Record<string, unknown>).json;
        await upsertLegacyRecord({ scopeKey: scope, recordType: "account", recordKey: `${type}:${email}`.slice(0, 191), payload: jsonPayload(account) });
        res.json({ ok: true, msg: "Đã lưu tài khoản", accounts: await listRecords(scope, "account"), logs: [] });
        return true;
      }
      case "acc_delete": {
        const accounts = await listRecords<Record<string, unknown>>(scope, "account");
        const index = Number(body.idx);
        const target = Number.isInteger(index) ? accounts[index] : undefined;
        if (target) {
          const key = `${String(target.type || "ldplayer")}:${String(target.username || target.email || "")}`.slice(0, 191);
          await deleteLegacyRecords(scope, "account", key);
        }
        res.json({ ok: true, msg: "Đã xóa tài khoản", accounts: await listRecords(scope, "account"), logs: [] });
        return true;
      }
      case "runlog_list":
        res.json({ ok: true, history: await listRecords(scope, "runlog"), logs: [] });
        return true;
      case "runlog_clear":
        await deleteLegacyRecords(scope, "runlog");
        res.json({ ok: true, history: [], logs: [] });
        return true;
      case "mimi_models_get": {
        const upstream = await runPhpLegacyAction(action, body, { mimiUser: currentMimiUser(req), guestScope: currentMimiUser(req) ? null : scope });
        res.status(upstream.ok === false ? 502 : 200).json(upstream);
        return true;
      }
      case "mimi_memory_get":
        res.json({ ok: true, messages: await readRecord(scope, "mimi", "memory", []), logs: [] });
        return true;
      case "mimi_memory_save":
        await upsertLegacyRecord({ scopeKey: scope, recordType: "mimi", recordKey: "memory", payload: jsonPayload(body.messages || []) });
        res.json({ ok: true, logs: [] });
        return true;
      case "mimi_chats_get":
        res.json({ ok: true, sessions: await readRecord(scope, "mimi", "chats", []), logs: [] });
        return true;
      case "mimi_chats_save":
        await upsertLegacyRecord({ scopeKey: scope, recordType: "mimi", recordKey: "chats", payload: jsonPayload(body.sessions || []) });
        res.json({ ok: true, logs: [] });
        return true;
      case "mimi_chats_clear":
        await deleteLegacyRecords(scope, "mimi", "chats");
        res.json({ ok: true, sessions: [], logs: [] });
        return true;
      case "mt_session":
      case "mt_logout": {
        const upstream = await runPhpLegacyAction(action, body, { mimiUser: currentMimiUser(req), guestScope: currentMimiUser(req) ? null : scope });
        res.status(upstream.ok === false ? 502 : 200).json(upstream);
        return true;
      }
      case "ai_context": {
        const upstream = await runPhpLegacyAction(action, body, { mimiUser: currentMimiUser(req), guestScope: currentMimiUser(req) ? null : scope });
        res.status(upstream.ok === false ? 502 : 200).json(upstream);
        return true;
      }
      case "ai_chat": {
        const incoming: unknown[] = Array.isArray(body.messages) ? body.messages : [];
        if (incoming.length === 0) {
          res.status(400).json({ ok: false, error: "Thiếu nội dung hội thoại" });
          return true;
        }
        const messages: Message[] = incoming.slice(-30).flatMap(item => {
          if (!item || typeof item !== "object") return [];
          const role = String((item as Record<string, unknown>).role || "");
          const content = (item as Record<string, unknown>).content;
          if (!["system", "user", "assistant"].includes(role) || typeof content !== "string") return [];
          return [{ role: role as Message["role"], content: content.slice(0, 12000) }];
        });
        if (!messages.some(message => message.role === "user")) {
          res.status(400).json({ ok: false, error: "Thiếu câu hỏi người dùng" });
          return true;
        }
        const requestedModel = typeof body.model === "string" && body.model.trim() ? body.model.trim() : undefined;
        const response = await invokeLLM({
          model: requestedModel,
          messages: [
            {
              role: "system",
              content: "Bạn là Mimi Code AI trong bảng điều khiển FunPass API. Trả lời bằng tiếng Việt, ngắn gọn và thực tế. Không tự nhận đã chạy thao tác nếu chưa có kết quả từ API.",
            },
            ...messages,
          ],
          maxTokens: 1200,
        });
        const content = response.choices?.[0]?.message?.content;
        const answer = Array.isArray(content)
          ? content.filter(part => part.type === "text").map(part => part.text).join("\\n")
          : String(content || "");
        if (!answer.trim()) throw new Error("AI không trả về nội dung");
        res.json({ ok: true, answer, model: response.model, logs: [] });
        return true;
      }
      default:
        if (PHP_UPSTREAM_ACTIONS.has(action)) {
          const streaming = String(req.query.stream || "") === "1";
          if (streaming) {
            res.status(200);
            res.setHeader("Content-Type", "application/x-ndjson; charset=utf-8");
            res.setHeader("Cache-Control", "no-cache, no-transform");
            res.setHeader("X-Accel-Buffering", "no");
            res.flushHeaders?.();
          }
          const upstream = await runPhpLegacyAction(action, body, {
            mimiUser: currentMimiUser(req),
            guestScope: currentMimiUser(req) ? null : scope,
          }, streaming ? (line) => {
            res.write(JSON.stringify({ type: "log", line }) + "\n");
          } : undefined);
          const created = Array.isArray(upstream.created_accounts) ? upstream.created_accounts : [];
          for (const item of created) {
            if (!item || typeof item !== "object") continue;
            const account = item as Record<string, unknown>;
            const email = String(account.email || account.username || "").trim();
            if (!email) continue;
            const type = String(account.type || "ldplayer").trim() || "ldplayer";
            await upsertLegacyRecord({
              scopeKey: scope,
              recordType: "account",
              recordKey: `${type}:${email}`.slice(0, 191),
              payload: jsonPayload({ ...account, username: email, type, updated_at: new Date().toISOString() }),
            });
          }
          if (streaming) {
            res.write(JSON.stringify({ type: "done", result: upstream }) + "\n");
            res.end();
          } else {
            res.status(upstream.ok === false ? 502 : 200).json(upstream);
          }
          return true;
        }
        unsupported(res, action);
        return true;
    }
  } catch (error) {
    const detail = error instanceof Error ? error.message : "";
    const databaseFailure = /failed query|legacy_records|database|sql|drizzle/i.test(detail);
    const message = databaseFailure
      ? "Không thể lưu dữ liệu lúc này. Database đang được kiểm tra, hãy thử lại sau."
      : "Thao tác chưa hoàn tất. Hãy kiểm tra dữ liệu và thử lại.";
    console.error(`[Legacy API] ${String(req.query.action || "unknown")} failed: ${databaseFailure ? "database failure" : "request failure"}`);
    res.status(500).json({ ok: false, error: message, logs: [] });
    return true;
  }
}

export function registerLegacyApi(app: Express) {
  const legacyPaths = ["/", "/api3.php"];
  app.get(legacyPaths, (req, res, next) => {
    if (req.query.action) void handleLegacyAction(req as LegacyRequest, res).catch(next);
    else next();
  });
  app.post(legacyPaths, (req, res, next) => {
    if (req.query.action) void handleLegacyAction(req as LegacyRequest, res).catch(next);
    else next();
  });
}
