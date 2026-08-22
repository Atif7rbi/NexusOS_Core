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

import {
  DEFAULT_LOCALE,
  dictionaries,
  getDirection,
} from "@/i18n";
import type {
  AppLocale,
  TextDirection,
  TranslationKey,
} from "@/i18n/types";

const STORAGE_KEY = "nexusos_locale";

type LanguageContextValue = {
  locale: AppLocale;
  direction: TextDirection;
  isArabic: boolean;
  setLocale: (locale: AppLocale) => void;
  toggleLocale: () => void;
  t: (key: TranslationKey) => string;
};

const LanguageContext =
  createContext<LanguageContextValue | null>(null);

function getInitialLocale(): AppLocale {
  if (typeof window === "undefined") {
    return DEFAULT_LOCALE;
  }

  const storedLocale =
    window.localStorage.getItem(STORAGE_KEY);

  if (
    storedLocale === "ar-SA" ||
    storedLocale === "en-US"
  ) {
    return storedLocale;
  }

  return DEFAULT_LOCALE;
}

export function LanguageProvider({
  children,
}: {
  children: ReactNode;
}) {
  const [locale, setLocaleState] =
    useState<AppLocale>(getInitialLocale);

  const direction = getDirection(locale);

  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = direction;
  }, [locale, direction]);

  const setLocale = useCallback(
    (nextLocale: AppLocale): void => {
      setLocaleState(nextLocale);

      window.localStorage.setItem(
        STORAGE_KEY,
        nextLocale
      );
    },
    []
  );

  const toggleLocale = useCallback((): void => {
    setLocale(
      locale === "ar-SA"
        ? "en-US"
        : "ar-SA"
    );
  }, [locale, setLocale]);

  const t = useCallback(
    (key: TranslationKey): string => {
      return dictionaries[locale][key];
    },
    [locale]
  );

  const value = useMemo<LanguageContextValue>(
    () => ({
      locale,
      direction,
      isArabic: locale === "ar-SA",
      setLocale,
      toggleLocale,
      t,
    }),
    [
      locale,
      direction,
      setLocale,
      toggleLocale,
      t,
    ]
  );

  return (
    <LanguageContext.Provider value={value}>
      {children}
    </LanguageContext.Provider>
  );
}

export function useLanguage(): LanguageContextValue {
  const context = useContext(LanguageContext);

  if (!context) {
    throw new Error(
      "useLanguage must be used within LanguageProvider."
    );
  }

  return context;
}
