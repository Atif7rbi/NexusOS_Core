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
import { LanguageProvider, useLanguage } from "@/providers/LanguageProvider";
import type { SystemSettings } from "@/types/system-settings";

const authState = vi.hoisted(() => ({
  token: "test-token" as string | null,
  isLoading: false,
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => authState,
}));

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
  const settings = useSystemSettings();

  return (
    <>
      <span>{settings.company_name_ar}</span>
      <span>{settings.hasLoadedSettings ? "settings loaded" : "settings pending"}</span>
      <span>{settings.loadError ? "settings error" : "settings ready"}</span>
      <button
        type="button"
        onClick={() =>
          settings.saveDemoCompanyProfile({
            company_name_ar: "شركة محلية",
            company_name_en: null,
            short_name_ar: "محلية",
            short_name_en: null,
            phone: null,
            email: null,
            address: null,
            website: null,
            commercial_registration: null,
            vat_number: null,
          })
        }
      >
        save demo
      </button>
    </>
  );
}

function LanguageProbe() {
  const { direction, locale } = useLanguage();

  return <span>{`${locale}:${direction}`}</span>;
}

describe("SystemSettingsProvider", () => {
  afterEach(() => {
    window.localStorage.clear();
    document.documentElement.style.removeProperty("--company-primary");
    document.documentElement.style.removeProperty("--company-secondary");
    document.documentElement.style.removeProperty("--action-primary");
    document.documentElement.removeAttribute("lang");
    document.documentElement.removeAttribute("dir");
    document.title = "";
    vi.unstubAllEnvs();
    vi.unstubAllGlobals();
    authState.token = "test-token";
    authState.isLoading = false;
  });

  it("keeps tenant branding separate from semantic action colors and derives the browser title from the company identity", async () => {
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
    expect(document.title).toBe("شركة الاختبار | NexusOS");
    expect(screen.getByText("settings loaded")).toBeTruthy();
  });

  it("keeps fallback settings non-authoritative when the live request fails", async () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "https://api.example.test/api");
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({ ok: false })
    );

    render(
      <SystemSettingsProvider>
        <SettingsProbe />
      </SystemSettingsProvider>
    );

    await waitFor(() => {
      expect(screen.getByText("settings error")).toBeTruthy();
    });

    expect(screen.getByText("settings pending")).toBeTruthy();
  });

  it("does not override the user-selected language or direction after loading settings", async () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "https://api.example.test/api");
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({
        ok: true,
        json: vi.fn().mockResolvedValue({ data: settings }),
      })
    );
    window.localStorage.setItem("ufq_locale", "en-US");

    render(
      <SystemSettingsProvider>
        <LanguageProvider>
          <LanguageProbe />
        </LanguageProvider>
      </SystemSettingsProvider>
    );

    await screen.findByText("en-US:ltr");
    await waitFor(() => {
      expect(document.documentElement.lang).toBe("en-US");
      expect(document.documentElement.dir).toBe("ltr");
    });
  });

  it("keeps Demo company profile overrides local to the current browser", async () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "https://api.example.test/api");
    vi.stubEnv("NEXT_PUBLIC_DEMO_MODE", "true");
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({
        ok: true,
        json: vi.fn().mockResolvedValue({ data: settings }),
      })
    );

    const view = render(
      <SystemSettingsProvider>
        <SettingsProbe />
      </SystemSettingsProvider>
    );

    await screen.findByText("شركة الاختبار");
    screen.getByRole("button", { name: "save demo" }).click();

    await waitFor(() => {
      expect(screen.getByText("شركة محلية")).toBeTruthy();
    });
    expect(document.title).toBe("شركة محلية | NexusOS");
    expect(
      window.localStorage.getItem("nexusos_demo_company_profile_v1")
    ).toContain("شركة محلية");

    view.unmount();
    render(
      <SystemSettingsProvider>
        <SettingsProbe />
      </SystemSettingsProvider>
    );

    await screen.findByText("شركة محلية");
  });
});
