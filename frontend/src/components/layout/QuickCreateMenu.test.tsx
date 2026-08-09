import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { QuickCreateMenu } from "@/components/layout/QuickCreateMenu";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));

describe("QuickCreateMenu", () => {
  it("toggles an accessible menu with only the three approved routes", async () => {
    render(<QuickCreateMenu />);

    const trigger = screen.getByRole("button", {
      name: "إنشاء سريع",
    });

    expect(trigger.getAttribute("aria-expanded")).toBe("false");

    fireEvent.click(trigger);

    expect(trigger.getAttribute("aria-expanded")).toBe("true");
    expect(
      screen.getByRole("menuitem", { name: "مشروع جديد" }).getAttribute("href")
    ).toBe("/projects/?create=1");
    expect(
      screen.getByRole("menuitem", { name: "عميل جديد" }).getAttribute("href")
    ).toBe("/customers/?create=1");
    expect(
      screen.getByRole("menuitem", { name: "إضافة وحدة" }).getAttribute("href")
    ).toBe("/units/?create=1");
    expect(screen.queryByText("إنشاء عقد")).toBeNull();

    await waitFor(() => {
      expect(document.activeElement).toBe(
        screen.getByRole("menuitem", { name: "مشروع جديد" })
      );
    });

    fireEvent.click(trigger);
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("closes with Escape and restores focus to the trigger", () => {
    render(<QuickCreateMenu />);

    const trigger = screen.getByRole("button", {
      name: "إنشاء سريع",
    });

    fireEvent.click(trigger);
    fireEvent.keyDown(document, { key: "Escape" });

    expect(screen.queryByRole("menu")).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });

  it("supports menu arrow navigation with wrapping", async () => {
    render(<QuickCreateMenu />);

    fireEvent.click(
      screen.getByRole("button", { name: "إنشاء سريع" })
    );

    const project = screen.getByRole("menuitem", {
      name: "مشروع جديد",
    });
    const customer = screen.getByRole("menuitem", {
      name: "عميل جديد",
    });
    const unit = screen.getByRole("menuitem", {
      name: "إضافة وحدة",
    });

    await waitFor(() => {
      expect(document.activeElement).toBe(project);
    });

    fireEvent.keyDown(project, { key: "ArrowDown" });
    expect(document.activeElement).toBe(customer);

    fireEvent.keyDown(customer, { key: "ArrowDown" });
    expect(document.activeElement).toBe(unit);

    fireEvent.keyDown(unit, { key: "ArrowDown" });
    expect(document.activeElement).toBe(project);

    fireEvent.keyDown(project, { key: "ArrowUp" });
    expect(document.activeElement).toBe(unit);
  });

  it("supports Home and End navigation", async () => {
    render(<QuickCreateMenu />);

    fireEvent.click(
      screen.getByRole("button", { name: "إنشاء سريع" })
    );

    const project = screen.getByRole("menuitem", {
      name: "مشروع جديد",
    });
    const unit = screen.getByRole("menuitem", {
      name: "إضافة وحدة",
    });

    await waitFor(() => {
      expect(document.activeElement).toBe(project);
    });

    fireEvent.keyDown(project, { key: "End" });
    expect(document.activeElement).toBe(unit);

    fireEvent.keyDown(unit, { key: "Home" });
    expect(document.activeElement).toBe(project);
  });

  it("closes on an outside pointer interaction", () => {
    render(<QuickCreateMenu />);

    fireEvent.click(
      screen.getByRole("button", { name: "إنشاء سريع" })
    );
    fireEvent.pointerDown(document.body);

    expect(screen.queryByRole("menu")).toBeNull();
  });
});
