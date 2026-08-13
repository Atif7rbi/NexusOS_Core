import { describe, expect, it } from "vitest";

import { canAdministrateTenant } from "@/lib/tenant-administrator";

describe("canAdministrateTenant", () => {
  it("treats system owner and administrator as the same tenant authority", () => {
    expect(canAdministrateTenant("system_owner")).toBe(true);
    expect(canAdministrateTenant("administrator")).toBe(true);
  });

  it.each(["project_manager", "sales", "accountant", "employee"] as const)(
    "does not grant administrative authority to %s",
    (role) => {
      expect(canAdministrateTenant(role)).toBe(false);
    }
  );

  it("does not grant authority without an authenticated role", () => {
    expect(canAdministrateTenant(null)).toBe(false);
    expect(canAdministrateTenant(undefined)).toBe(false);
  });
});
