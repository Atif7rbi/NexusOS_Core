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

export const THEME_PROFILES = [
  "light-1",
  "light-2",
  "dark-1",
  "dark-2",
] as const;

export type ThemeProfile = (typeof THEME_PROFILES)[number];

type ThemeContextValue = {
  theme: ThemeProfile;
  isDark: boolean;
  toggleTheme: () => void;
  setTheme: (theme: ThemeProfile) => void;
};

const STORAGE_KEY = "nexusos_theme";

function getUserStorageKey(userId: number): string {
  return `${STORAGE_KEY}:user:${userId}`;
}

const ThemeContext = createContext<ThemeContextValue | null>(null);

type ThemeProviderProps = {
  children: ReactNode;
};

function normalizeStoredTheme(
  storedTheme: string | null
): ThemeProfile | null {
  if (storedTheme === "light") {
    return "light-1";
  }

  if (storedTheme === "dark") {
    return "dark-1";
  }

  return THEME_PROFILES.find(
    (profile) => profile === storedTheme
  ) ?? null;
}

function getDefaultTheme(): ThemeProfile {
  return "dark-1";
}

function getUserTheme(userId: number): ThemeProfile {
  const userStorageKey = getUserStorageKey(userId);
  const storedValue = window.localStorage.getItem(userStorageKey);
  const storedTheme = normalizeStoredTheme(storedValue);

  if (storedTheme) {
    window.localStorage.removeItem(STORAGE_KEY);
    return storedTheme;
  }

  if (storedValue !== null) {
    window.localStorage.removeItem(userStorageKey);
  }

  const legacyValue = window.localStorage.getItem(STORAGE_KEY);
  const legacyTheme = normalizeStoredTheme(legacyValue);

  if (legacyTheme) {
    window.localStorage.setItem(userStorageKey, legacyTheme);
    window.localStorage.removeItem(STORAGE_KEY);
    return legacyTheme;
  }

  if (legacyValue !== null) {
    window.localStorage.removeItem(STORAGE_KEY);
  }

  return getDefaultTheme();
}

function isDarkProfile(theme: ThemeProfile): boolean {
  return theme.startsWith("dark-");
}

function applyTheme(theme: ThemeProfile): void {
  document.documentElement.dataset.theme = theme;
  document.documentElement.classList.toggle(
    "dark",
    isDarkProfile(theme)
  );
}

export function ThemeProvider({
  children,
}: ThemeProviderProps) {
  const { user, isLoading } = useAuth();
  const userId = isLoading ? null : (user?.id ?? null);
  const identityKey = isLoading
    ? "auth-loading"
    : userId === null
      ? "anonymous"
      : `user-${userId}`;

  return (
    <ThemeStateProvider key={identityKey} userId={userId}>
      {children}
    </ThemeStateProvider>
  );
}

function ThemeStateProvider({
  children,
  userId,
}: ThemeProviderProps & { userId: number | null }) {
  const [theme, setThemeState] =
    useState<ThemeProfile>(() =>
      userId === null ? getDefaultTheme() : getUserTheme(userId)
    );

  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  const setTheme = useCallback(
    (nextTheme: ThemeProfile): void => {
      setThemeState(nextTheme);

      if (userId !== null) {
        window.localStorage.setItem(
          getUserStorageKey(userId),
          nextTheme
        );
      }
    },
    [userId]
  );

  const toggleTheme = useCallback((): void => {
    const matchingProfile: Record<ThemeProfile, ThemeProfile> = {
      "light-1": "dark-1",
      "light-2": "dark-2",
      "dark-1": "light-1",
      "dark-2": "light-2",
    };

    setTheme(matchingProfile[theme]);
  }, [setTheme, theme]);

  const value = useMemo<ThemeContextValue>(
    () => ({
      theme,
      isDark: isDarkProfile(theme),
      toggleTheme,
      setTheme,
    }),
    [theme, toggleTheme, setTheme]
  );

  return (
    <ThemeContext.Provider value={value}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext);

  if (!context) {
    throw new Error(
      "useTheme must be used within ThemeProvider."
    );
  }

  return context;
}
