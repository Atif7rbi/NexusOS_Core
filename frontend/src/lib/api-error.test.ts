import { describe, expect, it } from "vitest";

import { parseApiError } from "@/lib/api-error";

function jsonResponse(payload: unknown, status: number): Response {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

describe("parseApiError", () => {
  it("preserves field errors and prioritizes their first message", async () => {
    const error = await parseApiError(
      jsonResponse(
        {
          message: "The given data was invalid.",
          errors: {
            email: ["البريد الإلكتروني غير صالح."],
          },
        },
        422
      )
    );

    expect(error.message).toBe("البريد الإلكتروني غير صالح.");
    expect(error.fieldErrors).toEqual({
      email: ["البريد الإلكتروني غير صالح."],
    });
    expect(error.status).toBe(422);
    expect(error.code).toBe("validation_failed");
  });

  it("uses the canonical nested error before the legacy message", async () => {
    const error = await parseApiError(
      jsonResponse(
        {
          message: "Legacy message",
          error: {
            code: "state_conflict",
            message: "Canonical conflict",
          },
        },
        409
      )
    );

    expect(error.message).toBe("Canonical conflict");
    expect(error.code).toBe("state_conflict");
    expect(error.status).toBe(409);
  });

  it("keeps the top-level legacy message fallback", async () => {
    const error = await parseApiError(
      jsonResponse({ message: "Legacy only" }, 403)
    );

    expect(error.message).toBe("Legacy only");
    expect(error.code).toBeNull();
    expect(error.status).toBe(403);
  });

  it("uses the generic fallback for invalid non-JSON responses", async () => {
    const error = await parseApiError(
      new Response("<html>Server failure</html>", {
        status: 500,
        headers: { "Content-Type": "text/html" },
      })
    );

    expect(error.message).toBe("تعذر إكمال العملية.");
    expect(error.fieldErrors).toEqual({});
    expect(error.status).toBe(500);
    expect(error.code).toBeNull();
  });

  it("ignores malformed field-error values safely", async () => {
    const error = await parseApiError(
      jsonResponse(
        {
          message: "تعذر التحقق من البيانات.",
          errors: {
            name: "not-an-array",
            phone: [null, "رقم التواصل غير صالح."],
          },
        },
        422
      )
    );

    expect(error.message).toBe("رقم التواصل غير صالح.");
    expect(error.fieldErrors).toEqual({
      phone: ["رقم التواصل غير صالح."],
    });
  });
});
