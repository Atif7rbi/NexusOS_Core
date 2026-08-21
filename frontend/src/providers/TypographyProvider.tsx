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

export const TEXT_SIZE_PROFILES = [
  "normal",
  "large",
  "extra-large",
] as const;

export const FONT_FAMILIES = [
  "ibm-plex-sans-arabic",
  "noto-sans-arabic",
  "tajawal",
  "tahoma",
] as const;

export type TextSizeProfile = (typeof TEXT_SIZE_PROFILES)[number];
export type FontFamily = (typeof FONT_FAMILIES)[number];

type TypographyContextValue = {
  textSize: TextSizeProfile;
  fontFamily: FontFamily;
  setTextSize: (textSize: TextSizeProfile) => void;
  setFontFamily: (fontFamily: FontFamily) => void;
};

const TEXT_SIZE_STORAGE_KEY = "nexusos_text_size";
const FONT_STORAGE_KEY = "nexusos_font";
const DEFAULT_TEXT_SIZE: TextSizeProfile = "large";
const DEFAULT_FONT: FontFamily = "tajawal";

const TypographyContext =
  createContext<TypographyContextValue | null>(null);

type TypographyProviderProps = {
  children: ReactNode;
};

function getUserStorageKey(key: string, userId: number): string {
  return `${key}:user:${userId}`;
}

function isTextSizeProfile(value: string | null): value is TextSizeProfile {
  return TEXT_SIZE_PROFILES.some((profile) => profile === value);
}

function isFontFamily(value: string | null): value is FontFamily {
  return FONT_FAMILIES.some((font) => font === value);
}

function getStoredPreference<T extends string>(
  key: string,
  userId: number | null,
  fallback: T,
  isValid: (value: string | null) => value is T
): T {
  if (typeof window === "undefined" || userId === null) {
    return fallback;
  }

  const scopedKey = getUserStorageKey(key, userId);
  const value = window.localStorage.getItem(scopedKey);

  if (isValid(value)) {
    return value;
  }

  if (value !== null) {
    window.localStorage.removeItem(scopedKey);
  }

  return fallback;
}

function applyTypography(
  textSize: TextSizeProfile,
  fontFamily: FontFamily
): void {
  document.documentElement.dataset.textSize = textSize;
  document.documentElement.dataset.font = fontFamily;
}

export function TypographyProvider({
  children,
}: TypographyProviderProps) {
  const { user, isLoading } = useAuth();
  const userId = isLoading ? null : (user?.id ?? null);
  const identityKey = isLoading
    ? "auth-loading"
    : userId === null
      ? "anonymous"
      : `user-${userId}`;

  return (
    <TypographyStateProvider key={identityKey} userId={userId}>
      {children}
    </TypographyStateProvider>
  );
}

function TypographyStateProvider({
  children,
  userId,
}: TypographyProviderProps & { userId: number | null }) {
  const [textSize, setTextSizeState] = useState<TextSizeProfile>(() =>
    getStoredPreference(
      TEXT_SIZE_STORAGE_KEY,
      userId,
      DEFAULT_TEXT_SIZE,
      isTextSizeProfile
    )
  );
  const [fontFamily, setFontFamilyState] = useState<FontFamily>(() =>
    getStoredPreference(
      FONT_STORAGE_KEY,
      userId,
      DEFAULT_FONT,
      isFontFamily
    )
  );

  useEffect(() => {
    applyTypography(textSize, fontFamily);
  }, [fontFamily, textSize]);

  const setTextSize = useCallback(
    (nextTextSize: TextSizeProfile): void => {
      setTextSizeState(nextTextSize);
      if (userId !== null) {
        window.localStorage.setItem(
          getUserStorageKey(TEXT_SIZE_STORAGE_KEY, userId),
          nextTextSize
        );
      }
    },
    [userId]
  );

  const setFontFamily = useCallback(
    (nextFontFamily: FontFamily): void => {
      setFontFamilyState(nextFontFamily);
      if (userId !== null) {
        window.localStorage.setItem(
          getUserStorageKey(FONT_STORAGE_KEY, userId),
          nextFontFamily
        );
      }
    },
    [userId]
  );

  const value = useMemo<TypographyContextValue>(
    () => ({
      textSize,
      fontFamily,
      setTextSize,
      setFontFamily,
    }),
    [fontFamily, setFontFamily, setTextSize, textSize]
  );

  return (
    <TypographyContext.Provider value={value}>
      {children}
    </TypographyContext.Provider>
  );
}

export function useTypography(): TypographyContextValue {
  const context = useContext(TypographyContext);

  if (!context) {
    throw new Error(
      "useTypography must be used within TypographyProvider."
    );
  }

  return context;
}
