import { describe, expect, it } from "vitest";
import { PHP_UPSTREAM_ACTIONS, runPhpLegacyAction } from "./phpLegacy";

describe("PHP legacy bridge", () => {
  it("whitelists the original upstream actions", () => {
    expect(PHP_UPSTREAM_ACTIONS.has("fp_create_funpass")).toBe(true);
    expect(PHP_UPSTREAM_ACTIONS.has("fp_create_ldplayer")).toBe(true);
    expect(PHP_UPSTREAM_ACTIONS.has("mt_skus")).toBe(true);
    expect(PHP_UPSTREAM_ACTIONS.has("module_upload")).toBe(true);
  });

  it("rejects arbitrary actions before spawning PHP", async () => {
    await expect(runPhpLegacyAction("not_a_real_action", {})).rejects.toThrow(
      "Action PHP chưa được whitelist",
    );
  });

  it("runs fp_create_funpass through api3.php and preserves its JSON error envelope", async () => {
    const result = await runPhpLegacyAction("fp_create_funpass", {
      ctx: {
        email: "test@example.com",
        temp_token: "test-token",
        device_id_header: "test-device",
        mid_body: "test-mid",
        password: "test-password",
        otp: "bad",
      },
    });
    expect(result).toMatchObject({ ok: false });
    expect(String(result.error)).toContain("OTP");
  });
});
