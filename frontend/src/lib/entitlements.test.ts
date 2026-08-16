import { describe, expect, it } from "vitest";

import {
  getCommercialModuleForPath,
  hasModuleEntitlement,
  normalizeEffectiveModules,
} from "@/lib/entitlements";

describe("entitlements", () => {
  it("normalizes only approved unique commercial module keys", () => {
    expect(
      normalizeEffectiveModules([
        "projects",
        "projects",
        "users",
        "unknown",
        null,
      ])
    ).toEqual(["projects"]);
    expect(normalizeEffectiveModules(null)).toEqual([]);
  });

  it("checks entitlement without treating core capabilities as modules", () => {
    expect(hasModuleEntitlement(["crm"], "crm")).toBe(true);
    expect(hasModuleEntitlement([], "crm")).toBe(false);
    expect(getCommercialModuleForPath("/crm/")).toBe("crm");
    expect(getCommercialModuleForPath("/settings/")).toBeNull();
    expect(getCommercialModuleForPath("/users/")).toBeNull();
    expect(getCommercialModuleForPath("/")).toBeNull();
  });
});
