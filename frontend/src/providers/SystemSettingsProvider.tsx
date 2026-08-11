"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";

import { useAuth } from "@/providers/AuthProvider";
import type {
  CompanyProfileInput,
  SystemSettings,
} from "@/types/system-settings";

const DEMO_COMPANY_PROFILE_STORAGE_KEY =
  "nexusos_demo_company_profile_v1";

const defaultSettings: SystemSettings = {
  id: 0,
  company_name_ar: "نظام إدارة الأعمال",
  company_name_en: "Business Management System",
  short_name_ar: "النظام",
  short_name_en: "System",
  company_tagline_ar: "للتطوير العقاري",
  company_tagline_en: "Real Estate Development",
  logo_path: null,
  favicon_path: null,
  primary_color: "#0f172a",
  secondary_color: "#475569",
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
  created_at: "",
  updated_at: "",
};

type SystemSettingsContextValue = SystemSettings & {
  isLoading: boolean;
  isDemoMode: boolean;
  applyCompanyProfile: (settings: SystemSettings) => void;
  saveDemoCompanyProfile: (profile: CompanyProfileInput) => void;
};

const SystemSettingsContext =
  createContext<SystemSettingsContextValue>({
    ...defaultSettings,
    isLoading: false,
    isDemoMode: false,
    applyCompanyProfile: () => undefined,
    saveDemoCompanyProfile: () => undefined,
  });

type SystemSettingsProviderProps = {
  children: ReactNode;
};

function isDemoMode(): boolean {
  return process.env.NEXT_PUBLIC_DEMO_MODE === "true";
}

function getDemoCompanyProfileOverride(): Partial<CompanyProfileInput> {
  if (typeof window === "undefined") {
    return {};
  }

  try {
    const stored = window.localStorage.getItem(
      DEMO_COMPANY_PROFILE_STORAGE_KEY
    );

    return stored
      ? (JSON.parse(stored) as Partial<CompanyProfileInput>)
      : {};
  } catch {
    return {};
  }
}

function applyDocumentSettings(settings: SystemSettings): void {
  document.title = `${settings.company_name_ar} | NexusOS`;
  document.documentElement.lang = settings.language;
  document.documentElement.dir = "rtl";
  document.documentElement.style.setProperty(
    "--company-primary",
    settings.primary_color
  );
  document.documentElement.style.setProperty(
    "--company-secondary",
    settings.secondary_color
  );
}

export function SystemSettingsProvider({
  children,
}: SystemSettingsProviderProps) {
  const { token, isLoading: isAuthLoading } = useAuth();

  const identityKey = isAuthLoading
    ? "auth-loading"
    : token ?? "anonymous";

  return (
    <SystemSettingsStateProvider
      key={identityKey}
      token={token}
      isAuthLoading={isAuthLoading}
    >
      {children}
    </SystemSettingsStateProvider>
  );
}

function SystemSettingsStateProvider({
  children,
  token,
  isAuthLoading,
}: SystemSettingsProviderProps & {
  token: string | null;
  isAuthLoading: boolean;
}) {
  const demoMode = isDemoMode();
  const [settings, setSettings] =
    useState<SystemSettings>(defaultSettings);
  const [isLoading, setIsLoading] = useState(
    Boolean(token && process.env.NEXT_PUBLIC_API_BASE_URL)
  );

  const applyCompanyProfile = useCallback(
    (nextSettings: SystemSettings): void => {
      const effectiveSettings = demoMode
        ? { ...nextSettings, ...getDemoCompanyProfileOverride() }
        : nextSettings;

      setSettings(effectiveSettings);
      applyDocumentSettings(effectiveSettings);
    },
    [demoMode]
  );

  const saveDemoCompanyProfile = useCallback(
    (profile: CompanyProfileInput): void => {
      if (!demoMode) {
        return;
      }

      window.localStorage.setItem(
        DEMO_COMPANY_PROFILE_STORAGE_KEY,
        JSON.stringify(profile)
      );
      applyCompanyProfile({ ...settings, ...profile });
    },
    [applyCompanyProfile, demoMode, settings]
  );

  useEffect(() => {
    if (isAuthLoading) {
      return;
    }

    if (!token) {
      document.title = "NexusOS";
      return;
    }

    const apiBaseUrl = process.env.NEXT_PUBLIC_API_BASE_URL;

    if (!apiBaseUrl) {
      return;
    }

    let active = true;

    const loadSettings = async (): Promise<void> => {
      try {
        const response = await fetch(`${apiBaseUrl}/system-settings`, {
          headers: {
            Accept: "application/json",
            Authorization: `Bearer ${token}`,
          },
        });

        if (!response.ok || !active) {
          return;
        }

        const payload = (await response.json()) as {
          data: SystemSettings;
        };

        applyCompanyProfile(payload.data);
      } catch {
        // Keep safe defaults when the API is unavailable.
      } finally {
        if (active) {
          setIsLoading(false);
        }
      }
    };

    void loadSettings();

    return () => {
      active = false;
    };
  }, [applyCompanyProfile, isAuthLoading, token]);

  const value = useMemo<SystemSettingsContextValue>(
    () => ({
      ...settings,
      isLoading,
      isDemoMode: demoMode,
      applyCompanyProfile,
      saveDemoCompanyProfile,
    }),
    [applyCompanyProfile, demoMode, isLoading, saveDemoCompanyProfile, settings]
  );

  return (
    <SystemSettingsContext.Provider value={value}>
      {children}
    </SystemSettingsContext.Provider>
  );
}

export function useSystemSettings(): SystemSettingsContextValue {
  return useContext(SystemSettingsContext);
}
