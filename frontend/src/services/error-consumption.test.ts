import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { login } from "@/services/auth";
import { fetchProjects } from "@/services/projects";
import { fetchTenantUsers } from "@/services/users";

describe("API service error consumption", () => {
  beforeEach(() => {
    process.env.NEXT_PUBLIC_API_BASE_URL = "https://api.example.test/api";
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    delete process.env.NEXT_PUBLIC_API_BASE_URL;
  });

  it("preserves the canonical authentication error code and status", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            message: "انتهت الجلسة.",
            error: {
              code: "unauthenticated",
              message: "انتهت الجلسة.",
            },
          }),
          { status: 401 }
        )
      )
    );

    await expect(
      login({ email: "admin@example.test", password: "secret" })
    ).rejects.toMatchObject({
      message: "انتهت الجلسة.",
      status: 401,
      code: "unauthenticated",
    });
  });

  it("preserves user-management field validation errors", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            message: "The given data was invalid.",
            errors: {
              role: ["الدور غير صالح."],
            },
          }),
          { status: 422 }
        )
      )
    );

    await expect(fetchTenantUsers("token")).rejects.toMatchObject({
      message: "الدور غير صالح.",
      fieldErrors: { role: ["الدور غير صالح."] },
      status: 422,
      code: "validation_failed",
    });
  });

  it("preserves the canonical module entitlement denial", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            message: "هذه الوحدة غير متاحة ضمن باقة المنشأة الحالية.",
            error: {
              code: "module_not_entitled",
              message: "هذه الوحدة غير متاحة ضمن باقة المنشأة الحالية.",
            },
          }),
          { status: 403 }
        )
      )
    );

    await expect(fetchProjects("token")).rejects.toMatchObject({
      message: "هذه الوحدة غير متاحة ضمن باقة المنشأة الحالية.",
      status: 403,
      code: "module_not_entitled",
    });
  });
});
