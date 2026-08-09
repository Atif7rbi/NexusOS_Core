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

const STORAGE_KEY = "ufq_theme";

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

function getInitialTheme(): ThemeProfile {
  if (typeof window === "undefined") {
    return "light-1";
  }

  const storedTheme = normalizeStoredTheme(
    window.localStorage.getItem(STORAGE_KEY)
  );

  if (storedTheme) {
    return storedTheme;
  }

  return window.matchMedia(
    "(prefers-color-scheme: dark)"
  ).matches
    ? "dark-1"
    : "light-1";
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
  const [theme, setThemeState] =
    useState<ThemeProfile>(getInitialTheme);

  useEffect(() => {
    applyTheme(theme);
    window.localStorage.setItem(STORAGE_KEY, theme);
  }, [theme]);

  const setTheme = useCallback(
    (nextTheme: ThemeProfile): void => {
      setThemeState(nextTheme);
    },
    []
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
