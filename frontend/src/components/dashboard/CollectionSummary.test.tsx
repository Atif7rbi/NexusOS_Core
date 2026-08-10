import {
  fireEvent,
  render,
  screen,
} from "@testing-library/react";
import {
  describe,
  expect,
  it,
  vi,
} from "vitest";

import { CollectionSummary } from "@/components/dashboard/CollectionSummary";
import type { DashboardCollections } from "@/types/dashboard";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));

const data: DashboardCollections = {
  summary: {
    total_contracts: 7,
    scheduled_count: 3,
    draft_count: 2,
    absent_count: 1,
    cancelled_count: 1,
  },
  items: [
    {
      contract_id: "contract-1",
      contract_status: "active",
      contract_total_amount: "130000.00",
      currency: "SAR",
      customer_name: "محمد العميل",
      unit_number: "A-101",
      project_name: "مشروع النخيل",
      schedule_state: "scheduled",
      schedule_active_total: "130000.00",
      next_scheduled_collection: {
        id: "collection-1",
        title: "الدفعة الأولى",
        amount: "65000.00",
        due_date: "2026-09-01",
      },
    },
  ],
};

describe("CollectionSummary", () => {
  it("renders schedule state and the exact next scheduled line", () => {
    render(
      <CollectionSummary
        resource={{
          data,
          isLoading: false,
          error: null,
        }}
      />
    );

    expect(screen.getByText("جداول معتمدة")).toBeTruthy();
    expect(screen.getByText("مسودات")).toBeTruthy();
    expect(screen.getByText("بدون جدول")).toBeTruthy();
    expect(screen.getByText("الدفعة الأولى")).toBeTruthy();
    expect(screen.getByText("65,000 SAR")).toBeTruthy();
    expect(screen.getByText("التاريخ المجدول: 01/09/2026")).toBeTruthy();
    expect(screen.getByText(/محمد العميل/)).toBeTruthy();
    expect(screen.queryByText(/مدفوع|غير مدفوع|متأخر/)).toBeNull();
  });

  it("renders a schedule-specific empty state", () => {
    render(
      <CollectionSummary
        resource={{
          data: { ...data, items: [] },
          isLoading: false,
          error: null,
        }}
      />
    );

    expect(
      screen.getByText("لا توجد بنود تحصيل مجدولة.")
    ).toBeTruthy();
  });

  it("collapses details while keeping schedule indicators visible", () => {
    render(
      <CollectionSummary
        resource={{
          data,
          isLoading: false,
          error: null,
        }}
      />
    );

    const toggle = screen.getByRole("button", {
      name: /نظرة عامة على التحصيل/,
    });

    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    fireEvent.click(toggle);

    expect(toggle.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByText("الدفعة الأولى")).toBeNull();
    expect(screen.getByText("جداول معتمدة: 3")).toBeTruthy();
    expect(screen.getByText("مسودات: 2")).toBeTruthy();
    expect(screen.getByText("بدون جدول: 1")).toBeTruthy();
  });
});
