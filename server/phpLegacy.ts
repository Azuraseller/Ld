import { execFile, spawn } from "node:child_process";
import { promisify } from "node:util";
import fs from "node:fs";
import path from "node:path";

const execFileAsync = promisify(execFile);

export const PHP_UPSTREAM_ACTIONS = new Set([
  "ai_context",
  "proxy_get",
  "proxy_add",
  "proxy_save",
  "proxy_reset_state",
  "create_empty_file",
  "flow_funpass_once",
  "flow_khoga_full",
  "fp_cpi_ads",
  "fp_cpi_run",
  "fp_create_funpass",
  "fp_create_ldplayer",
  "fp_login_funpass",
  "fp_login_ldplayer",
  "fp_transfer_detail",
  "fp_transfer_execute",
  "fp_wallet",
  "module_delete",
  "module_upload",
  "modules_list",
  "modules_remove",
  "mimi_model_add",
  "mimi_model_delete",
  "mimi_models_get",
  "mt_available_skus",
  "mt_balance",
  "mt_buy_auto",
  "mt_create_order",
  "mt_gift_detail",
  "mt_gift_list",
  "mt_login",
  "mt_logout",
  "mt_session",
  "mt_snipe",
  "mt_skus",
  "mt_sync",
]);

const DEFAULT_TIMEOUT_MS = 180_000;
const MAX_BUFFER_BYTES = 16 * 1024 * 1024;

function parseJsonOutput(output: string): Record<string, unknown> {
  const trimmed = output.trim();
  if (!trimmed) throw new Error("api3.php không trả về dữ liệu");
  const firstObject = trimmed.indexOf("{");
  const firstArray = trimmed.indexOf("[");
  const start = firstObject >= 0 && (firstArray < 0 || firstObject < firstArray) ? firstObject : firstArray;
  if (start < 0) throw new Error(`api3.php trả về nội dung không phải JSON: ${trimmed.slice(0, 240)}`);
  try {
    return JSON.parse(trimmed.slice(start)) as Record<string, unknown>;
  } catch {
    throw new Error(`api3.php trả về JSON không hợp lệ: ${trimmed.slice(start, start + 480)}`);
  }
}

export async function runPhpLegacyAction(
  action: string,
  input: Record<string, unknown>,
  identity: { mimiUser?: string | null; guestScope?: string | null } = {},
  onLog?: (line: string) => void,
) {
  if (!PHP_UPSTREAM_ACTIONS.has(action)) {
    throw new Error(`Action PHP chưa được whitelist: ${action}`);
  }
  const phpBinary = process.env.PHP_BIN || "php";
  const moduleDir = typeof import.meta.dirname === "string" ? import.meta.dirname : undefined;
  const scriptCandidates = [
    process.env.LEGACY_PHP_SCRIPT,
    path.resolve(process.cwd(), "api3.php"),
    moduleDir ? path.resolve(moduleDir, "..", "api3.php") : undefined,
    moduleDir ? path.resolve(moduleDir, "..", "..", "api3.php") : undefined,
  ].filter((candidate): candidate is string => Boolean(candidate));
  const scriptPath = scriptCandidates.find(candidate => fs.existsSync(candidate));
  if (!scriptPath) {
    throw new Error("Không tìm thấy api3.php. Hãy đặt LEGACY_PHP_SCRIPT hoặc chạy từ thư mục project.");
  }
  const inputJson = JSON.stringify(input ?? {});
  const timeoutMs = Number(process.env.PHP_ACTION_TIMEOUT_MS || DEFAULT_TIMEOUT_MS);
  const env = {
    ...process.env,
    ...(identity.mimiUser ? { MIMI_USER: identity.mimiUser } : {}),
    ...(identity.guestScope
      ? { MIMI_GUEST: identity.guestScope.replace(/[^a-f0-9]/gi, "").slice(0, 32) }
      : {}),
  };
  try {
    if (onLog) {
      const result = await new Promise<{ stdout: string; stderr: string; code: number | null }>((resolve, reject) => {
        const child = spawn(phpBinary, [scriptPath, action, inputJson], { cwd: path.dirname(scriptPath), env, windowsHide: true });
        let stdout = "";
        let stderr = "";
        let buffer = "";
        let jsonStarted = false;
        const timer = setTimeout(() => { child.kill("SIGTERM"); reject(Object.assign(new Error("PHP action timeout"), { code: "ETIMEDOUT", killed: true, stdout, stderr })); }, timeoutMs);
        child.stdout.on("data", chunk => {
          const text = String(chunk);
          stdout += text;
          buffer += text;
          const lines = buffer.split(/\r?\n/);
          buffer = lines.pop() || "";
          for (const line of lines) {
            const trimmed = line.trim();
            if (!trimmed) continue;
            if (/^[\[{]/.test(trimmed)) { jsonStarted = true; continue; }
            if (!jsonStarted) onLog(trimmed);
          }
        });
        child.stderr.on("data", chunk => { stderr += String(chunk); });
        child.on("error", reject);
        child.on("close", code => {
          clearTimeout(timer);
          const trimmed = buffer.trim();
          if (trimmed && !jsonStarted && !/^[\[{]/.test(trimmed)) onLog(trimmed);
          resolve({ stdout, stderr, code });
        });
      });
      const parsed = parseJsonOutput(result.stdout);
      if (result.stderr.trim() && !Array.isArray(parsed.logs)) parsed.logs = result.stderr.trim().split(/\r?\n/).slice(-200);
      return parsed;
    }
    const { stdout, stderr } = await execFileAsync(
      phpBinary,
      [scriptPath, action, inputJson],
      {
        cwd: path.dirname(scriptPath),
        timeout: timeoutMs,
        maxBuffer: MAX_BUFFER_BYTES,
        windowsHide: true,
        env,
      },
    );
    const result = parseJsonOutput(stdout);
    if (stderr.trim() && !Array.isArray(result.logs)) {
      result.logs = stderr.trim().split(/\r?\n/).slice(-200);
    }
    return result;
  } catch (error) {
    const err = error as NodeJS.ErrnoException & { killed?: boolean; stdout?: string; stderr?: string; code?: string | number };
    // api3.php exits with code 1 for expected business errors but still emits
    // a valid JSON envelope on stdout. Preserve that envelope for the UI.
    if (typeof err.stdout === "string" && err.stdout.trim()) {
      try {
        return parseJsonOutput(err.stdout);
      } catch {
        // Fall through to the detailed process error below.
      }
    }
    if (err.code === "ENOENT") {
      throw new Error("PHP runtime chưa được cài trong môi trường chạy. Hãy dùng image deployment có PHP CLI hoặc đặt PHP_BIN.");
    }
    if (err.killed || err.code === "ETIMEDOUT") {
      throw new Error(`Action ${action} quá thời gian chờ PHP (${timeoutMs}ms).`);
    }
    const detail = String(err.stderr || err.stdout || err.message || "Lỗi PHP không xác định").trim();
    throw new Error(`Action ${action} lỗi trong api3.php: ${detail.slice(-1200)}`);
  }
}
