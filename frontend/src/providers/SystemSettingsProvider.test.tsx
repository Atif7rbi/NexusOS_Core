import {
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import {
  afterEach,
  describe,
  expect,
  it,
  vi,
} from "vitest";

import {
  SystemSettingsProvider,
  useSystemSettings,
} from "@/providers/SystemSettingsProvider";
import type { SystemSettings } from "@/types/system-settings";

const settings: SystemSettings = {
  id: 1,
  company_name_ar: "شركة الاختبار",
  company_name_en: "Test Company",
  short_name_ar: "اختبار",
  short_name_en: "Test",
  company_tagline_ar: "وصف الشركة",
  company_tagline_en: "Company tagline",
  logo_path: null,
  favicon_path: null,
  primary_color: "#123456",
  secondary_color: "#654321",
  language: "ar",
  timezone: "Asia/Riyadh",
  currency: "SAR",
  date_format: "Y-m-d",
  phone: null,
  email: null,
  website: null,
  address: null,
  commercial_registration: null,
  vat_number: null,
  created_at: "2026-08-09T00:00:00.000Z",
  updated_at: "2026-08-09T00:00:00.000Z",
};

function SettingsProbe() {
  return <span>{useSystemSettings().company_name_ar}</span>;
}

describe("SystemSettingsProvider", () => {
  afterEach(() => {
    document.documentElement.style.removeProperty("--company-primary");
    document.documentElement.style.removeProperty("--company-secondary");
    document.documentElement.style.removeProperty("--action-primary");
    document.documentElement.removeAttribute("lang");
    document.documentElement.removeAttribute("dir");
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
  });

  it("keeps tenant branding separate from semantic action colors", async () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "https://api.example.test/api");
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({
        ok: true,
        json: vi.fn().mockResolvedValue({ data: settings }),
      })
    );
    document.documentElement.style.setProperty(
      "--action-primary",
      "semantic-sentinel"
    );

    render(
      <SystemSettingsProvider>
        <SettingsProbe />
      </SystemSettingsProvider>
    );

    await waitFor(() => {
      expect(screen.getByText("شركة الاختبار")).toBeTruthy();
    });

    expect(
      document.documentElement.style.getPropertyValue("--company-primary")
    ).toBe("#123456");
    expect(
      document.documentElement.style.getPropertyValue("--company-secondary")
    ).toBe("#654321");
    expect(
      document.documentElement.style.getPropertyValue("--action-primary")
    ).toBe("semantic-sentinel");
    expect(
      document.documentElement.style.getPropertyValue("--brand-primary")
    ).toBe("");
  });
});
