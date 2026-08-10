import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { AppearanceProfileSelector } from "@/components/theme/AppearanceProfileSelector";
import { LanguageProvider } from "@/providers/LanguageProvider";
import { ThemeProvider } from "@/providers/ThemeProvider";

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    user: { id: 7 },
    isLoading: false,
  }),
}));

function renderSelector() {
  return render(
    <LanguageProvider>
      <ThemeProvider>
        <AppearanceProfileSelector />
      </ThemeProvider>
    </LanguageProvider>
  );
}

describe("AppearanceProfileSelector", () => {
  afterEach(() => {
    window.localStorage.clear();
    document.documentElement.classList.remove("dark");
    delete document.documentElement.dataset.theme;
  });

  it("shows four preview cards and marks the current profile", async () => {
    window.localStorage.setItem("ufq_theme:user:7", "light-1");
    renderSelector();

    expect(screen.getByText("المظهر")).toBeTruthy();
    expect(
      screen.getByText(
        "اختر ملف الألوان الأنسب لك. يُحفظ الاختيار لهذا الحساب على هذا الجهاز."
      )
    ).toBeTruthy();
    expect(screen.getAllByRole("button")).toHaveLength(4);
    expect(
      screen
        .getByRole("button", { name: /Light 1/ })
        .getAttribute("aria-pressed")
    ).toBe("true");
    expect(
      screen
        .getByRole("button", { name: /Dark 2/ })
        .getAttribute("aria-pressed")
    ).toBe("false");
  });

  it("selects and persists a preview profile", async () => {
    window.localStorage.setItem("ufq_theme:user:7", "light-1");
    renderSelector();

    fireEvent.click(screen.getByRole("button", { name: /Dark 2/ }));

    await waitFor(() => {
      expect(document.documentElement.dataset.theme).toBe("dark-2");
    });
    expect(document.documentElement.classList.contains("dark")).toBe(true);
    expect(window.localStorage.getItem("ufq_theme:user:7")).toBe("dark-2");
    expect(
      screen
        .getByRole("button", { name: /Dark 2/ })
        .getAttribute("aria-pressed")
    ).toBe("true");
  });
});
