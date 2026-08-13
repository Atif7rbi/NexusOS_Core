import { describe, expect, it } from "vitest";

import { canAccessCrm, crmAccessRoles } from "@/lib/crm-authorization";
import type { UserRole } from "@/types/auth";

describe("CRM authorization mirror", () => {
  it("allows only the frozen CRM roles", () => {
    expect(crmAccessRoles).toEqual([
      "system_owner",
      "administrator",
      "project_manager",
      "sales",
    ]);

    const expectations: Record<UserRole, boolean> = {
      system_owner: true,
      administrator: true,
      project_manager: true,
      sales: true,
      accountant: false,
      employee: false,
    };

    Object.entries(expectations).forEach(([role, allowed]) => {
      expect(canAccessCrm(role as UserRole)).toBe(allowed);
    });
    expect(canAccessCrm(null)).toBe(false);
    expect(canAccessCrm(undefined)).toBe(false);
  });
});
