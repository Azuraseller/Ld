import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";

const legacyHtml = readFileSync(new URL("../client/src/legacy.html", import.meta.url), "utf8");

describe("account data UI", () => {
  it("renders separate black Funpass and LDPlayer tables with the requested fields", () => {
    expect(legacyHtml).toContain("id=\"panelFp\"");
    expect(legacyHtml).toContain("id=\"panelLd\"");
    expect(legacyHtml).toContain("account-grid");
    expect(legacyHtml).toContain("field('Gmail'");
    expect(legacyHtml).toContain("field('Password'");
    expect(legacyHtml).toContain("field('Android ID'");
    expect(legacyHtml).toContain("field('SignIn'");
    expect(legacyHtml).toContain("'True'");
    expect(legacyHtml).toContain("'False'");
    expect(legacyHtml).toContain("'Same'");
  });

  it("keeps progress lines visible while hiding technical detail lines by default", () => {
    expect(legacyHtml).toContain("className = 'logline ' + (isError && !isSuccess ? 'err' : (isSuccess ? 'ok' : 'progress'))");
    expect(legacyHtml).toContain("await new Promise(resolve => requestAnimationFrame(resolve))");
  });
});
