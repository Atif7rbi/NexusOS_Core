"use client";

import {
  createContext,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from "react";

import type { SystemSettings } from "@/types/system-settings";

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

const SystemSettingsContext =
  createContext<SystemSettings>(defaultSettings);

type SystemSettingsProviderProps = {
  children: ReactNode;
};

export function SystemSettingsProvider({
  children,
}: SystemSettingsProviderProps) {
  const [settings, setSettings] =
    useState<SystemSettings>(defaultSettings);

  useEffect(() => {
    const apiBaseUrl =
      process.env.NEXT_PUBLIC_API_BASE_URL;

    if (!apiBaseUrl) {
      return;
    }

    const loadSettings = async (): Promise<void> => {
      try {
        const response = await fetch(
          `${apiBaseUrl}/system-settings`,
          {
            headers: {
              Accept: "application/json",
            },
          }
        );

        if (!response.ok) {
          return;
        }

        const payload = (await response.json()) as {
          data: SystemSettings;
        };

        setSettings(payload.data);

        document.documentElement.lang =
          payload.data.language;

        document.documentElement.dir = "rtl";

        document.documentElement.style.setProperty(
          "--company-primary",
          payload.data.primary_color
        );

        document.documentElement.style.setProperty(
          "--company-secondary",
          payload.data.secondary_color
        );
      } catch {
        // Keep safe default settings when the API is unavailable.
      }
    };

    void loadSettings();
  }, []);

  return (
    <SystemSettingsContext.Provider value={settings}>
      {children}
    </SystemSettingsContext.Provider>
  );
}

export function useSystemSettings(): SystemSettings {
  return useContext(SystemSettingsContext);
}
