import {
  fireEvent,
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
  THEME_PROFILES,
  ThemeProvider,
  useTheme,
  type ThemeProfile,
} from "@/providers/ThemeProvider";

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

function renderProvider(storedTheme?: string) {
  if (storedTheme) {
    window.localStorage.setItem("ufq_theme", storedTheme);
  }

  return render(
    <ThemeProvider>
      <ThemeProbe />
    </ThemeProvider>
  );
}

async function expectRootTheme(
  profile: ThemeProfile,
  isDark: boolean
) {
  await waitFor(() => {
    expect(document.documentElement.dataset.theme).toBe(profile);
  });
  expect(document.documentElement.classList.contains("dark")).toBe(isDark);
  expect(window.localStorage.getItem("ufq_theme")).toBe(profile);
}

describe("ThemeProvider", () => {
  afterEach(() => {
    window.localStorage.clear();
    document.documentElement.classList.remove("dark");
    delete document.documentElement.dataset.theme;
    vi.unstubAllGlobals();
  });

  it("maps the system preference to the first matching profile", async () => {
    vi.stubGlobal(
      "matchMedia",
      vi.fn().mockReturnValue({ matches: true })
    );

    renderProvider();

    await expectRootTheme("dark-1", true);
    expect(screen.getByText("dark-1:dark")).toBeTruthy();
  });

  it.each([
    ["light-1", false],
    ["light-2", false],
    ["dark-1", true],
    ["dark-2", true],
  ] as const)(
    "restores and synchronizes the %s profile",
    async (profile, isDark) => {
      renderProvider(profile);
      await expectRootTheme(profile, isDark);
      expect(
        screen.getByText(`${profile}:${isDark ? "dark" : "light"}`)
      ).toBeTruthy();
    }
  );

  it.each([
    ["light", "light-1", false],
    ["dark", "dark-1", true],
  ] as const)(
    "migrates legacy %s storage to %s",
    async (legacy, profile, isDark) => {
      renderProvider(legacy);
      await expectRootTheme(profile, isDark);
    }
  );

  it("keeps the profile family when toggling light and dark", async () => {
    renderProvider("light-2");
    await expectRootTheme("light-2", false);

    fireEvent.click(screen.getByRole("button", { name: "toggle" }));
    await expectRootTheme("dark-2", true);

    fireEvent.click(screen.getByRole("button", { name: "toggle" }));
    await expectRootTheme("light-2", false);
  });

  it("updates storage, data-theme, and class through setTheme", async () => {
    renderProvider("light-1");

    fireEvent.click(screen.getByRole("button", { name: "dark-2" }));
    await expectRootTheme("dark-2", true);

    fireEvent.click(screen.getByRole("button", { name: "light-1" }));
    await expectRootTheme("light-1", false);
  });
});
