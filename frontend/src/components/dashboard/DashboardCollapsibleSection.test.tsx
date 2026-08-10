import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { DashboardCollapsibleSection } from "@/components/dashboard/DashboardCollapsibleSection";

describe("DashboardCollapsibleSection", () => {
  it("is expanded by default and exposes an accessible toggle", () => {
    const { container } = render(
      <DashboardCollapsibleSection
        title="قسم تشغيلي"
        summary={<span>المؤشر: 3</span>}
      >
        <span>تفاصيل القسم</span>
      </DashboardCollapsibleSection>
    );

    const toggle = screen.getByRole("button", {
      name: "قسم تشغيلي",
    });

    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    const contentId = toggle.getAttribute("aria-controls");
    const heading = screen.getByRole("heading", {
      level: 2,
      name: "قسم تشغيلي",
    });
    const section = container.querySelector("section");

    expect(contentId).toBeTruthy();
    expect(document.getElementById(contentId ?? "")).toBeTruthy();
    expect(section?.getAttribute("aria-labelledby")).toBe(heading.id);
    expect(screen.getByText("تفاصيل القسم")).toBeTruthy();
  });

  it("collapses and expands while keeping the summary visible", () => {
    render(
      <DashboardCollapsibleSection
        title="قسم تشغيلي"
        summary={<span>المؤشر: 3</span>}
      >
        <span>تفاصيل القسم</span>
      </DashboardCollapsibleSection>
    );

    const toggle = screen.getByRole("button", {
      name: "قسم تشغيلي",
    });

    fireEvent.click(toggle);

    expect(toggle.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByText("تفاصيل القسم")).toBeNull();
    expect(screen.getByText("المؤشر: 3")).toBeTruthy();

    fireEvent.click(toggle);

    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    expect(screen.getByText("تفاصيل القسم")).toBeTruthy();
  });
});
