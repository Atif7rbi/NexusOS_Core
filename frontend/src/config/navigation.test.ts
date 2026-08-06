import { describe, expect, it } from "vitest";

import {
  getNavigationGroupsForRole,
  navigationGroups,
} from "@/config/navigation";
import type { UserRole } from "@/types/auth";

function hrefsForRole(role: UserRole | null): string[] {
  return getNavigationGroupsForRole(role).flatMap(
    (group) => group.items.map((item) => item.href)
  );
}

describe("pilot navigation visibility", () => {
  it("does not expose incomplete pilot modules", () => {
    const hrefs = navigationGroups.flatMap(
      (group) => group.items.map((item) => item.href)
    );

    expect(hrefs).not.toContain("/contractors/");
    expect(hrefs).not.toContain(
      "/contractor-statements/"
    );
    expect(hrefs).not.toContain("/expenses/");
    expect(hrefs).not.toContain(
      "/financial-reports/"
    );
    expect(hrefs).not.toContain("/employees/");
    expect(hrefs).not.toContain("/reports/");
  });

  it("shows users and permissions only to administrators", () => {
    expect(hrefsForRole("administrator")).toContain(
      "/users/"
    );

    expect(hrefsForRole("system_owner")).toContain(
      "/users/"
    );

    const restrictedRoles: UserRole[] = [
      "project_manager",
      "sales",
      "accountant",
      "employee",
    ];

    restrictedRoles.forEach((role) => {
      expect(hrefsForRole(role)).not.toContain(
        "/users/"
      );
    });

    expect(hrefsForRole(null)).not.toContain(
      "/users/"
    );
  });

  it("keeps completed pilot modules visible", () => {
    const hrefs = hrefsForRole("sales");

    expect(hrefs).toContain("/");
    expect(hrefs).toContain("/crm/");
    expect(hrefs).toContain("/customers/");
    expect(hrefs).toContain("/reservations/");
    expect(hrefs).toContain("/contracts/");
    expect(hrefs).toContain("/projects/");
    expect(hrefs).toContain("/units/");
    expect(hrefs).toContain("/collections/");
    expect(hrefs).toContain("/settings/");
  });
});
