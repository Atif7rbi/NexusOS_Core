import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { TypographyPreferencesSelector } from "@/components/theme/TypographyPreferencesSelector";
import { LanguageProvider } from "@/providers/LanguageProvider";
import { TypographyProvider } from "@/providers/TypographyProvider";

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({ user: { id: 7 }, isLoading: false }),
}));

function renderSelector() {
  return render(
    <LanguageProvider>
      <TypographyProvider>
        <TypographyPreferencesSelector />
      </TypographyProvider>
    </LanguageProvider>
  );
}

describe("TypographyPreferencesSelector", () => {
  afterEach(() => {
    window.localStorage.clear();
    delete document.documentElement.dataset.textSize;
    delete document.documentElement.dataset.font;
  });

  it("selects and persists semantic text size and font preferences", async () => {
    renderSelector();

    expect(screen.getByText("الخط وحجم النص")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: /كبير جدًا/ }));
    fireEvent.click(screen.getByRole("button", { name: /Noto Sans Arabic/ }));

    await waitFor(() => {
      expect(document.documentElement.dataset.textSize).toBe("extra-large");
    });
    expect(document.documentElement.dataset.font).toBe("noto-sans-arabic");
    expect(window.localStorage.getItem("ufq_text_size:user:7")).toBe("extra-large");
    expect(window.localStorage.getItem("ufq_font:user:7")).toBe("noto-sans-arabic");
  });
});
