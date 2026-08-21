import { act, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  getGreetingForTimeZone,
  Topbar,
} from "@/components/layout/Topbar";
import { shouldDisplayCompanyTagline } from "@/components/brand/CompanyBrand";

vi.mock("next/navigation", () => ({
  usePathname: () => "/",
  useRouter: () => ({ replace: vi.fn() }),
}));

vi.mock("@/components/language/LanguageToggle", () => ({
  LanguageToggle: () => <button type="button">language</button>,
}));

vi.mock("@/components/theme/ThemeToggle", () => ({
  ThemeToggle: () => <button type="button">theme</button>,
}));

vi.mock("@/components/layout/QuickCreateMenu", () => ({
  QuickCreateMenu: () => <button type="button">quick-create</button>,
}));

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({
    isArabic: true,
    t: (key: string) =>
      ({
        "navigation.openSidebar": "فتح القائمة",
        "dashboard.title": "لوحة التحكم",
        "common.userMenu": "قائمة المستخدم",
        "common.logout": "تسجيل الخروج",
        "roles.administrator": "مدير النظام",
      })[key] ?? key,
  }),
}));

vi.mock("@/providers/AppShellProvider", () => ({
  useAppShell: () => ({ openMobileSidebar: vi.fn() }),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    user: {
      name: "مدير النظام",
      role: "administrator",
    },
    logout: vi.fn(),
  }),
}));

vi.mock("@/providers/SystemSettingsProvider", () => ({
  useSystemSettings: () => ({
    company_name_ar: "شركة المثال العقارية",
    company_name_en: "Example Real Estate",
    short_name_ar: "المثال",
    short_name_en: "Example",
    company_tagline_ar: "للتطوير العقاري",
    company_tagline_en: "Real Estate Development",
    logo_path: null,
    timezone: "Asia/Riyadh",
  }),
}));

describe("Topbar", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-08-09T02:00:00.000Z"));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("renders company identity and a timezone-aware greeting without the user name", async () => {
    render(<Topbar />);

    act(() => vi.runOnlyPendingTimers());

    expect(screen.getByText("شركة المثال العقارية")).toBeTruthy();
    expect(screen.getByText("— للتطوير العقاري")).toBeTruthy();
    expect(
      screen.getByRole("heading", { name: "لوحة التحكم" })
    ).toBeTruthy();

    expect(screen.getByText("صباح الخير")).toBeTruthy();
    expect(screen.queryByText("صباح الخير، مدير النظام")).toBeNull();
  });

  it("derives the greeting from the configured timezone", () => {
    const date = new Date("2026-08-09T02:00:00.000Z");

    expect(
      getGreetingForTimeZone(date, "Asia/Riyadh", true)
    ).toBe("صباح الخير");
    expect(
      getGreetingForTimeZone(date, "America/New_York", true)
    ).toBe("مساء الخير");
  });

  it("does not duplicate a tagline already contained in the full company name", () => {
    expect(
      shouldDisplayCompanyTagline(
        "شركة أسوار للتطوير العقاري",
        "للتطوير العقاري"
      )
    ).toBe(false);
    expect(
      shouldDisplayCompanyTagline("أسوار", "للتطوير العقاري")
    ).toBe(true);
  });
});
