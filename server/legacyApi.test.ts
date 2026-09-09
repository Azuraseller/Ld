import { describe, expect, it } from "vitest";
import { handleLegacyAction } from "./legacyApi";

type MockResponse = {
  payload?: unknown;
  statusCode: number;
  cookies: Array<{ name: string; value: string }>;
  cookie: (name: string, value: string) => MockResponse;
  clearCookie: () => MockResponse;
  status: (code: number) => MockResponse;
  json: (value: unknown) => MockResponse;
};

function createResponse(): MockResponse {
  const response = {
    statusCode: 200,
    cookies: [],
    cookie(name: string, value: string) {
      response.cookies.push({ name, value });
      return response;
    },
    clearCookie() {
      return response;
    },
    status(code: number) {
      response.statusCode = code;
      return response;
    },
    json(value: unknown) {
      response.payload = value;
      return response;
    },
  } as MockResponse;
  return response;
}

describe("legacy API bridge", () => {
  it("returns JSON from ping without rendering the HTML shell", async () => {
    const response = createResponse();
    const handled = await handleLegacyAction({
      method: "GET",
      query: { action: "ping" },
      headers: {},
      secure: false,
      body: {},
    } as never, response as never);

    expect(handled).toBe(true);
    expect(response.statusCode).toBe(200);
    expect(response.payload).toMatchObject({ ok: true, service: "legacy-api-bridge" });
    expect(response.cookies).toHaveLength(1);
  });

  it("rejects ai_chat without messages with JSON error", async () => {
    const response = createResponse();
    await handleLegacyAction({
      method: "POST",
      query: { action: "ai_chat" },
      headers: {},
      secure: false,
      body: {},
    } as never, response as never);

    expect(response.statusCode).toBe(400);
    expect(response.payload).toMatchObject({ ok: false, error: "Thiếu nội dung hội thoại" });
  });

  it("rejects proxy_add without a proxy value before database access", async () => {
    const response = createResponse();
    await handleLegacyAction({
      method: "POST",
      query: { action: "proxy_add" },
      headers: {},
      secure: false,
      body: {},
    } as never, response as never);

    expect(response.statusCode).toBe(400);
    expect(response.payload).toMatchObject({ ok: false, error: "Thiếu proxy" });
  });

  it("returns the legacy Mimi authentication status shape", async () => {
    const response = createResponse();
    await handleLegacyAction({
      method: "GET",
      query: { action: "auth_status" },
      headers: { cookie: "" },
      secure: false,
      body: {},
    } as never, response as never);

    expect(response.payload).toMatchObject({
      ok: true,
      authenticated: false,
      user: null,
      developer: false,
    });
  });

  it("blocks UI source scanning for guests", async () => {
    const response = createResponse();
    await handleLegacyAction({
      method: "GET",
      query: { action: "ui_source_scan" },
      headers: { cookie: "" },
      secure: false,
      body: {},
    } as never, response as never);

    expect(response.statusCode).toBe(403);
    expect(response.payload).toMatchObject({ ok: false });
  });

  it("rejects malformed manual account JSON before persistence", async () => {
    const response = createResponse();
    await handleLegacyAction({
      method: "POST",
      query: { action: "acc_save" },
      headers: {},
      secure: false,
      body: { json: "{not-json", type: "funpass" },
    } as never, response as never);

    expect(response.statusCode).toBe(400);
    expect(response.payload).toMatchObject({ ok: false });
  });
});
