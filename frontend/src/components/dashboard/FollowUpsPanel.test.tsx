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

import { FollowUpsPanel } from "@/components/dashboard/FollowUpsPanel";
import type { DashboardFollowUps } from "@/types/dashboard";
import type { Lead } from "@/types/lead";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));

function lead(
  id: string,
  name: string,
  nextFollowUpAt: string
): Lead {
  return {
    id,
    name,
    phone: "0500000000",
    next_follow_up_at: nextFollowUpAt,
    next_action_note: "اتصال متابعة",
  } as Lead;
}

const data: DashboardFollowUps = {
  summary: {
    open_leads: 6,
    overdue_follow_ups: 2,
    today_follow_ups: 1,
    unassigned_leads: 0,
    monthly_conversions: 0,
    lost_leads_in_selected_period: 0,
  },
  overdue: [
    lead(
      "01K00000000000000000000001",
      "عميل متأخر",
      "2026-08-08T06:30:00.000Z"
    ),
  ],
  today: [
    lead(
      "01K00000000000000000000002",
      "عميل اليوم",
      "2026-08-08T10:30:00.000Z"
    ),
  ],
};

describe("FollowUpsPanel", () => {
  it("renders both operational queues with contextual CRM links", () => {
    render(
      <FollowUpsPanel
        resource={{
          data,
          isLoading: false,
          error: null,
        }}
      />
    );

    expect(screen.getByText("عميل متأخر")).toBeTruthy();
    expect(screen.getByText("عميل اليوم")).toBeTruthy();
    expect(
      screen
        .getByRole("link", { name: /عميل متأخر/ })
        .getAttribute("href")
    ).toBe(
      "/crm/?follow_up_state=overdue&lead=01K00000000000000000000001"
    );
    expect(
      screen
        .getByRole("link", { name: /عميل اليوم/ })
        .getAttribute("href")
    ).toBe(
      "/crm/?follow_up_state=today&lead=01K00000000000000000000002"
    );
    expect(screen.getAllByText("اتصال متابعة")).toHaveLength(2);
  });

  it("contains failure inside the follow-up section", () => {
    render(
      <FollowUpsPanel
        resource={{
          data: null,
          isLoading: false,
          error: "CRM unavailable",
        }}
      />
    );

    expect(
      screen.getByText("تعذر تحميل قائمة المتابعة.")
    ).toBeTruthy();
    expect(screen.getByRole("link", { name: "فتح CRM" })).toBeTruthy();
  });

  it("collapses the queue while keeping follow-up counts visible", () => {
    render(
      <FollowUpsPanel
        resource={{
          data,
          isLoading: false,
          error: null,
        }}
      />
    );

    const toggle = screen.getByRole("button", {
      name: /قائمة المتابعة/,
    });

    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    fireEvent.click(toggle);

    expect(toggle.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByText("عميل متأخر")).toBeNull();
    expect(screen.getByText("متأخرة: 2")).toBeTruthy();
    expect(screen.getByText("اليوم: 1")).toBeTruthy();
  });
});
