import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { CustomerBusinessContext } from "@/components/customers/CustomerBusinessContext";
import type { CustomerBusinessContext as BusinessContext } from "@/types/customer-business-context";

const context: BusinessContext = {
  summary: {
    reservations_total: 1,
    contracts_total: 2,
  },
  journeys: [
    {
      reservation: {
        id: "01K00000000000000000000001",
        status: "converted",
        reserved_at: "2026-08-02T09:00:00.000Z",
        expires_at: "2026-09-02T09:00:00.000Z",
      },
      project: {
        id: "01K00000000000000000000002",
        project_number: "PILOT-1",
        name: "مشروع النخيل",
        status: "active",
      },
      unit: {
        id: "01K00000000000000000000003",
        unit_number: "A-101",
        unit_type: "apartment",
        status: "sold",
      },
      contracts: [
        {
          id: "01K00000000000000000000004",
          status: "active",
          total_amount: "130000.00",
          currency: "SAR",
          activated_at: "2026-08-03T09:00:00.000Z",
          completed_at: null,
          cancelled_at: null,
          collection_schedule: {
            state: "scheduled",
            scheduled_total: "130000.00",
            scheduled_lines_count: 2,
            next_scheduled_collection: {
              id: "01K00000000000000000000005",
              title: "الدفعة الأولى",
              amount: "65000.00",
              due_date: "2026-09-01",
            },
          },
        },
        {
          id: "01K00000000000000000000006",
          status: "cancelled",
          total_amount: "125000.00",
          currency: "SAR",
          activated_at: null,
          completed_at: null,
          cancelled_at: "2026-08-03T08:00:00.000Z",
          collection_schedule: {
            state: "absent",
            scheduled_total: "0.00",
            scheduled_lines_count: 0,
            next_scheduled_collection: null,
          },
        },
      ],
    },
  ],
};

describe("CustomerBusinessContext", () => {
  it("links the customer journey to contextual reservation creation", () => {
    const href =
      "/reservations/?create=1&customer=customer-1";

    render(
      <CustomerBusinessContext
        context={context}
        isArabic
        createReservationHref={href}
      />
    );

    expect(
      screen
        .getByRole("link", {
          name: "إنشاء حجز",
        })
        .getAttribute("href")
    ).toBe(href);
  });

  it("renders the complete read-only business journey", () => {
    render(
      <CustomerBusinessContext
        context={context}
        isArabic
      />
    );

    expect(screen.getByText("الرحلة التجارية")).toBeTruthy();
    expect(screen.getByText("PILOT-1 — مشروع النخيل · نشط")).toBeTruthy();
    expect(screen.getByText("A-101 · شقة · مباعة")).toBeTruthy();
    expect(screen.getByText("محول إلى عقد")).toBeTruthy();
    expect(screen.getAllByText("130,000 SAR")).toHaveLength(2);
    expect(screen.getByText("الدفعة الأولى — 65,000 SAR — 01/09/2026")).toBeTruthy();
    expect(screen.getByText("بدون جدول")).toBeTruthy();
    expect(screen.queryByRole("button")).toBeNull();
  });

  it("renders an empty journey without actions", () => {
    render(
      <CustomerBusinessContext
        context={{
          summary: {
            reservations_total: 0,
            contracts_total: 0,
          },
          journeys: [],
        }}
        isArabic
      />
    );

    expect(screen.getByText("لا توجد رحلة تجارية بعد")).toBeTruthy();
    expect(screen.getByText("لا توجد حجوزات مرتبطة بهذا العميل.")).toBeTruthy();
    expect(screen.queryByRole("button")).toBeNull();
  });
});
