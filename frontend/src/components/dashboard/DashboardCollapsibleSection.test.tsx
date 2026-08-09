import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { DashboardCollapsibleSection } from "@/components/dashboard/DashboardCollapsibleSection";

describe("DashboardCollapsibleSection", () => {
  it("is expanded by default and exposes an accessible toggle", () => {
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

    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    expect(toggle.getAttribute("aria-controls")).toBeTruthy();
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
