import { describe, expect, it } from "vitest";

import {
  isQuickCreateRequested,
  removeQuickCreateQuery,
} from "@/lib/quick-create-query";

describe("quick create query contract", () => {
  it("accepts only create=1", () => {
    expect(isQuickCreateRequested("?create=1")).toBe(true);
    expect(isQuickCreateRequested("?create=0")).toBe(false);
    expect(isQuickCreateRequested("?create=true")).toBe(false);
  });

  it("removes only create while preserving the remaining query state", () => {
    expect(
      removeQuickCreateQuery(
        "/customers/",
        "?page=3&create=1&status=customer"
      )
    ).toBe("/customers/?page=3&status=customer");

    expect(
      removeQuickCreateQuery("/projects/", "?create=1")
    ).toBe("/projects/");
  });
});
