import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const legacyHtml = readFileSync(new URL("../client/src/legacy.html", import.meta.url), "utf8");

describe("legacy Mimi GIF integration", () => {
  it("uses normalized 624 GIF by default and 042 GIF while thinking", () => {
    expect(legacyHtml).toContain("const MIMI_READY_GIF = 'https://media.tenor.com/vm6OQ3JjFmkAAAAj/remiel-zzz-remiel-chibi.gif';")
    expect(legacyHtml).toContain("const MIMI_THINKING_GIF = 'https://media.tenor.com/71GvklzSo0UAAAAj/remielle-cuteremille.gif';")
    expect(legacyHtml).toContain("thinking: MIMI_THINKING_GIF");
    expect(legacyHtml).toContain("neutral: MIMI_READY_GIF");
  });

  it("sets thinking before ai_chat and resets to neutral in the finally path", () => {
    const typingIndex = legacyHtml.indexOf("function addTyping()");
    const aiChatIndex = legacyHtml.indexOf("askServer(state.conversation)");
    const finallyIndex = legacyHtml.indexOf("finally { setBusy(false); setMood('neutral'); scrollBottom(); }");
    expect(typingIndex).toBeGreaterThan(-1);
    expect(aiChatIndex).toBeGreaterThan(typingIndex);
    expect(legacyHtml.slice(typingIndex, aiChatIndex)).toContain("setMood('thinking')");
    expect(finallyIndex).toBeGreaterThan(aiChatIndex);
    expect(legacyHtml.slice(finallyIndex, finallyIndex + 220)).toContain("setMood('neutral')");
    expect(legacyHtml).toContain("if (!value && state.mood === 'thinking') setMood('neutral')");
  });

  it("keeps all main navigation tabs and removes the broken external chatgpt script", () => {
    for (const tab of ["navAuto", "navKhoga", "navMuathe", "navProxy", "navAccounts"]) {
      expect(legacyHtml).toContain(`id="${tab}"`);
    }
    expect(legacyHtml).not.toContain('<script src="chatgpt.js?v=opt84"></script>');
  });

  it("keeps console progress wiring and renders latest account data in a dark panel without temp token", () => {
    expect(legacyHtml).toContain('id="console"');
    expect(legacyHtml).toContain('function readStream(');
    expect(legacyHtml).toContain('function renderLastData(');
    expect(legacyHtml).toContain('#lastData{min-width:0;max-width:100%;background:#050505');
    expect(legacyHtml).toContain('id="panelFp"');
    expect(legacyHtml).toContain('id="panelLd"');
    expect(legacyHtml).toContain('False ( Lỗi Bảo Mật )');
    expect(legacyHtml).toContain('signin-unsafe');
    expect(legacyHtml).toContain('signin-safe');
    expect(legacyHtml).toContain('code:90001');
    expect(legacyHtml).not.toContain('id="m_temp_token"');
    expect(legacyHtml).not.toContain('Temp Token');
    expect(legacyHtml).not.toContain('function fillTempToken');
    expect(legacyHtml).not.toContain("['Temp token', account.temp_token");
  });
});
