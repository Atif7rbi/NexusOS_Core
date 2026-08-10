import { describe, expect, it } from "vitest";

import { metadata } from "@/app/layout";

describe("root metadata", () => {
  it("uses a neutral NexusOS fallback rather than a tenant identity", () => {
    expect(metadata.title).toBe("NexusOS");
    expect(metadata.description).toBe("نظام تشغيل وإدارة الأعمال");
    expect(JSON.stringify(metadata)).not.toContain("شركة أفق السكنية");
  });
});
