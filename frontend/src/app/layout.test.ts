import { describe, expect, it, vi } from "vitest";

vi.mock("next/font/google", () => ({
  IBM_Plex_Sans_Arabic: () => ({ variable: "font-ibm" }),
  Noto_Sans_Arabic: () => ({ variable: "font-noto" }),
  Tajawal: () => ({ variable: "font-tajawal" }),
}));

import RootLayout, { metadata } from "@/app/layout";

describe("root metadata", () => {
  it("uses a neutral NexusOS fallback rather than a tenant identity", () => {
    expect(metadata.title).toBe("NexusOS");
    expect(metadata.description).toBe("نظام تشغيل وإدارة الأعمال");
  });

  it("attaches the bundled Arabic font variables at the application root", () => {
    const root = RootLayout({ children: null });

    expect(root.props.className).toContain("font-ibm");
    expect(root.props.className).toContain("font-noto");
    expect(root.props.className).toContain("font-tajawal");
  });
});
