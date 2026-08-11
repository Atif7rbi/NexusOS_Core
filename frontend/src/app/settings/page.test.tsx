import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import SettingsPage from "@/app/settings/page";
const services = vi.hoisted(() => ({
  updateCompanyProfile: vi.fn(),
  applyCompanyProfile: vi.fn(),
  saveDemoCompanyProfile: vi.fn(),
}));

const authState = vi.hoisted(() => ({
  token: "token",
  user: { role: "administrator" },
}));

const settingsState = vi.hoisted(() => ({
  id: 1,
  company_name_ar: "شركة الاختبار",
  company_name_en: "Test Company",
  short_name_ar: "اختبار",
  short_name_en: "Test",
  company_tagline_ar: null,
  company_tagline_en: null,
  logo_path: null,
  favicon_path: null,
  primary_color: "#123456",
  secondary_color: "#654321",
  language: "ar",
  timezone: "Asia/Riyadh",
  currency: "SAR",
  date_format: "Y-m-d",
  phone: "0500000000",
  email: "test@example.com",
  website: null,
  address: null,
  commercial_registration: null,
  vat_number: null,
  created_at: "",
  updated_at: "",
  isLoading: false,
  hasLoadedSettings: true,
  loadError: false,
  isDemoMode: false,
  applyCompanyProfile: services.applyCompanyProfile,
  saveDemoCompanyProfile: services.saveDemoCompanyProfile,
}));

vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/theme/AppearanceProfileSelector", () => ({
  AppearanceProfileSelector: () => <span>appearance-controls</span>,
}));
vi.mock("@/components/theme/TypographyPreferencesSelector", () => ({
  TypographyPreferencesSelector: () => <span>IBM Plex Noto Sans Tajawal Tahoma</span>,
}));
vi.mock("@/providers/AuthProvider", () => ({ useAuth: () => authState }));
vi.mock("@/providers/SystemSettingsProvider", () => ({
  useSystemSettings: () => settingsState,
}));
vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));
vi.mock("@/services/system-settings", () => services);

describe("SettingsPage", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.user = { role: "administrator" };
    settingsState.hasLoadedSettings = true;
    settingsState.loadError = false;
    settingsState.isDemoMode = false;
    services.updateCompanyProfile.mockResolvedValue(settingsState);
  });

  it("combines appearance and typography and saves the real company profile", async () => {
    render(<SettingsPage />);

    expect(screen.getByText("المظهر والخط")).toBeTruthy();
    expect(screen.getByText("appearance-controls")).toBeTruthy();
    expect(screen.getByText("IBM Plex Noto Sans Tajawal Tahoma")).toBeTruthy();
    expect(screen.getByText("بيانات الشركة")).toBeTruthy();
    expect(screen.queryByText("رقم التواصل", { selector: "h2" })).toBeNull();

    fireEvent.change(screen.getByLabelText("اسم الشركة بالعربية"), {
      target: { value: "شركة جديدة" },
    });
    fireEvent.change(screen.getByLabelText("الاسم المختصر بالعربية"), {
      target: { value: "جديدة" },
    });
    fireEvent.click(screen.getByRole("button", { name: "حفظ التغييرات" }));

    await waitFor(() => {
      expect(services.updateCompanyProfile).toHaveBeenCalledWith(
        "token",
        expect.objectContaining({
          company_name_ar: "شركة جديدة",
          short_name_ar: "جديدة",
          phone: "0500000000",
        })
      );
    });
    expect(services.applyCompanyProfile).toHaveBeenCalledWith(settingsState);
  });

  it("keeps Demo company profile changes local instead of calling the API", async () => {
    settingsState.isDemoMode = true;
    settingsState.hasLoadedSettings = false;
    render(<SettingsPage />);

    fireEvent.click(screen.getByRole("button", { name: "حفظ التغييرات" }));

    await waitFor(() => {
      expect(services.saveDemoCompanyProfile).toHaveBeenCalled();
    });
    expect(services.updateCompanyProfile).not.toHaveBeenCalled();
    expect(screen.getByRole("status").textContent).toContain("محليًا");
  });

  it("does not expose fallback company settings for live editing after the settings request fails", () => {
    settingsState.hasLoadedSettings = false;
    settingsState.loadError = true;

    render(<SettingsPage />);

    expect(
      screen.getByText(/تعذر تحميل بيانات الشركة/)
    ).toBeTruthy();
    expect(
      screen.queryByRole("button", { name: "حفظ التغييرات" })
    ).toBeNull();
    expect(services.updateCompanyProfile).not.toHaveBeenCalled();
  });

  it("renders the company profile as read-only for non-administrators", () => {
    authState.user = { role: "sales" };
    render(<SettingsPage />);

    expect(screen.queryByRole("button", { name: "حفظ التغييرات" })).toBeNull();
    expect(
      (screen.getByLabelText("اسم الشركة بالعربية") as HTMLInputElement)
        .closest("fieldset")?.disabled
    ).toBe(true);
    expect(screen.getByText(/يلزم حساب مسؤول لتعديلها/)).toBeTruthy();
  });
});
