import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { fetchCurrentUser } from "@/services/auth";

describe("auth entitlement projection", () => {
  beforeEach(() => {
    vi.stubEnv(
      "NEXT_PUBLIC_API_BASE_URL",
      "https://api.example.test/api"
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
  });

  it("consumes and normalizes effective_modules from auth user", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            data: {
              user: { id: 1, role: "administrator" },
              effective_modules: [
                "projects",
                "crm",
                "crm",
                "users",
              ],
            },
          }),
          {
            status: 200,
            headers: { "Content-Type": "application/json" },
          }
        )
      )
    );

    const session = await fetchCurrentUser("token");

    expect(session.user).toMatchObject({ id: 1 });
    expect(session.effectiveModules).toEqual(["projects", "crm"]);
  });
});
