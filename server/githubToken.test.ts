import { describe, expect, it } from "vitest";

describe("GitHub production credential", () => {
  it("can read the connected private repository without exposing the token to the client", async () => {
    const token = String(process.env.GITHUB_TOKEN || "").trim();
    expect(token.length).toBeGreaterThan(20);
    expect(token).not.toContain("\n");

    const response = await fetch("https://api.github.com/repos/Azuraseller/ld-tool-web", {
      headers: {
        Accept: "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "ld-tool-web-control-panel-test",
        Authorization: `Bearer ${token}`,
      },
    });

    expect(response.ok).toBe(true);
    const repository = await response.json() as { full_name?: string; private?: boolean };
    expect(repository.full_name).toBe("Azuraseller/ld-tool-web");
    expect(repository.private).toBe(true);
  });
});
