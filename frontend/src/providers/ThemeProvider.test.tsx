import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from "vitest";

import {
  THEME_PROFILES,
  ThemeProvider,
  useTheme,
  type ThemeProfile,
} from "@/providers/ThemeProvider";

const authState = vi.hoisted(() => ({
  user: null as { id: number } | null,
  isLoading: false,
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => authState,
}));

function ThemeProbe() {
  const { theme, isDark, toggleTheme, setTheme } = useTheme();

  return (
    <div>
      <output>{`${theme}:${isDark ? "dark" : "light"}`}</output>
      <button type="button" onClick={toggleTheme}>
        toggle
      </button>
      {THEME_PROFILES.map((profile) => (
        <button
          key={profile}
          type="button"
          onClick={() => setTheme(profile)}
        >
          {profile}
        </button>
      ))}
    </div>
  );
}

function renderProvider(userId: number | null = 1) {
  authState.user = userId === null ? null : { id: userId };

  return render(
    <ThemeProvider>
      <ThemeProbe />
    </ThemeProvider>
  );
}

function setCurrentUser(userId: number | null): void {
  authState.user = userId === null ? null : { id: userId };
}

function userThemeKey(userId: number): string {
  return `nexusos_theme:user:${userId}`;
}

async function expectRootTheme(
  profile: ThemeProfile,
  isDark: boolean
) {
  await waitFor(() => {
    expect(document.documentElement.dataset.theme).toBe(profile);
  });
  expect(document.documentElement.classList.contains("dark")).toBe(isDark);
}

describe("ThemeProvider", () => {
  beforeEach(() => {
    authState.user = null;
    authState.isLoading = false;
    vi.stubGlobal(
      "matchMedia",
      vi.fn().mockReturnValue({ matches: false })
    );
  });

  afterEach(() => {
    window.localStorage.clear();
    document.documentElement.classList.remove("dark");
    delete document.documentElement.dataset.theme;
    vi.unstubAllGlobals();
  });

  it("uses Dark 1 as the default profile regardless of the system preference", async () => {
    vi.stubGlobal(
      "matchMedia",
      vi.fn().mockReturnValue({ matches: true })
    );

    renderProvider(1);

    await expectRootTheme("dark-1", true);
    expect(screen.getByText("dark-1:dark")).toBeTruthy();
  });

  it.each([
    ["light-1", false],
    ["light-2", false],
    ["dark-1", true],
    ["dark-2", true],
  ] as const)(
    "restores and synchronizes the %s profile for the current user",
    async (profile, isDark) => {
      window.localStorage.setItem(userThemeKey(1), profile);
      renderProvider(1);
      await expectRootTheme(profile, isDark);
    }
  );

  it("removes malformed scoped and legacy values before using the safe default", async () => {
    window.localStorage.setItem(userThemeKey(7), "unknown-profile");
    window.localStorage.setItem("nexusos_theme", "malformed-theme");

    renderProvider(7);

    await expectRootTheme("dark-1", true);
    expect(window.localStorage.getItem(userThemeKey(7))).toBeNull();
    expect(window.localStorage.getItem("nexusos_theme")).toBeNull();
    expect(document.documentElement.classList.contains("dark")).toBe(true);
  });

  it.each([
    ["light", "light-1", false],
    ["dark", "dark-1", true],
  ] as const)(
    "migrates legacy %s storage once to the authenticated user",
    async (legacy, profile, isDark) => {
      window.localStorage.setItem("nexusos_theme", legacy);
      renderProvider(7);

      await expectRootTheme(profile, isDark);
      expect(window.localStorage.getItem(userThemeKey(7))).toBe(profile);
      expect(window.localStorage.getItem("nexusos_theme")).toBeNull();
    }
  );

  it.each([
    ["light-1", "dark-1"],
    ["light-2", "dark-2"],
  ] as const)(
    "keeps the %s and %s profile family when toggling",
    async (lightProfile, darkProfile) => {
      window.localStorage.setItem(userThemeKey(1), lightProfile);
      renderProvider(1);
      await expectRootTheme(lightProfile, false);

      fireEvent.click(screen.getByRole("button", { name: "toggle" }));
      await expectRootTheme(darkProfile, true);
      expect(window.localStorage.getItem(userThemeKey(1))).toBe(darkProfile);

      fireEvent.click(screen.getByRole("button", { name: "toggle" }));
      await expectRootTheme(lightProfile, false);
      expect(window.localStorage.getItem(userThemeKey(1))).toBe(lightProfile);
    }
  );

  it("restores independent preferences across logout and login without leakage", async () => {
    const view = renderProvider(101);

    fireEvent.click(screen.getByRole("button", { name: "dark-1" }));
    await expectRootTheme("dark-1", true);
    expect(window.localStorage.getItem(userThemeKey(101))).toBe("dark-1");

    setCurrentUser(null);
    view.rerender(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    await expectRootTheme("dark-1", true);

    setCurrentUser(202);
    view.rerender(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    await expectRootTheme("dark-1", true);
    expect(window.localStorage.getItem(userThemeKey(202))).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: "light-2" }));
    await expectRootTheme("light-2", false);
    expect(window.localStorage.getItem(userThemeKey(202))).toBe("light-2");

    setCurrentUser(null);
    view.rerender(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    await expectRootTheme("dark-1", true);

    setCurrentUser(101);
    view.rerender(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    await expectRootTheme("dark-1", true);

    setCurrentUser(202);
    view.rerender(
      <ThemeProvider>
        <ThemeProbe />
      </ThemeProvider>
    );
    await expectRootTheme("light-2", false);
  });

  it("keeps unauthenticated changes ephemeral", async () => {
    renderProvider(null);
    fireEvent.click(screen.getByRole("button", { name: "dark-2" }));

    await expectRootTheme("dark-2", true);
    expect(window.localStorage.getItem("nexusos_theme")).toBeNull();
    expect(window.localStorage.length).toBe(0);
  });
});
