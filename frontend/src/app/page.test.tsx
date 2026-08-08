import {
  render,
  screen,
} from "@testing-library/react";
import {
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from "vitest";

import HomePage from "@/app/page";
import type { DashboardData } from "@/types/dashboard";

const dashboard = vi.hoisted(() => ({
  current: null as DashboardData | null,
}));

vi.mock("@/hooks/useDashboard", () => ({
  useDashboard: () => dashboard.current,
}));

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));

vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => (
    <>{children}</>
  ),
}));

vi.mock("@/components/dashboard/DashboardHero", () => ({
  DashboardHero: () => <div>dashboard-hero</div>,
}));

vi.mock("@/components/dashboard/FollowUpsPanel", () => ({
  FollowUpsPanel: () => <div>follow-ups-panel</div>,
}));

vi.mock("@/components/dashboard/CollectionSummary", () => ({
  CollectionSummary: () => <div>collections-panel</div>,
}));

vi.mock("@/components/dashboard/ProjectsOverview", () => ({
  ProjectsOverview: () => <div>active-projects-panel</div>,
}));

function resource<T>(data: T) {
  return {
    data,
    isLoading: false,
    error: null,
  };
}

function dashboardData(hasCrmAccess = true): DashboardData {
  return {
    projects: resource({ items: [], activeTotal: 4 }),
    customers: resource(12),
    units: resource(8),
    reservations: resource(5),
    contracts: resource(7),
    followUps: hasCrmAccess
      ? resource({
          summary: {
            open_leads: 6,
            overdue_follow_ups: 2,
            today_follow_ups: 3,
            unassigned_leads: 1,
            monthly_conversions: 0,
            lost_leads_in_selected_period: 0,
          },
          overdue: [],
          today: [],
        })
      : {
          data: null,
          isLoading: false,
          error: null,
        },
    collections: resource({
      items: [],
      summary: {
        total_contracts: 7,
        scheduled_count: 3,
        draft_count: 2,
        absent_count: 1,
        cancelled_count: 1,
      },
    }),
    hasCrmAccess,
    refresh: vi.fn(),
  };
}

describe("Operational dashboard", () => {
  beforeEach(() => {
    dashboard.current = dashboardData();
  });

  it("renders the approved hierarchy with real operational metrics", () => {
    render(<HomePage />);

    expect(screen.getByText("dashboard-hero")).toBeTruthy();
    expect(screen.getByText("ما يحتاج انتباهك اليوم")).toBeTruthy();
    expect(screen.getByText("متابعات متأخرة")).toBeTruthy();
    expect(screen.getByText("متابعات اليوم")).toBeTruthy();
    expect(screen.getByText("حجوزات نشطة")).toBeTruthy();
    expect(screen.getByText("إجمالي العقود")).toBeTruthy();
    expect(screen.getByText("follow-ups-panel")).toBeTruthy();
    expect(screen.getByText("collections-panel")).toBeTruthy();
    expect(screen.getByText("الحالة التشغيلية الحالية")).toBeTruthy();
    expect(screen.getByText("مشاريع نشطة")).toBeTruthy();
    expect(screen.getByText("إجمالي العملاء")).toBeTruthy();
    expect(screen.getByText("وحدات متاحة")).toBeTruthy();
    expect(screen.getByText("active-projects-panel")).toBeTruthy();
  });

  it("omits CRM attention without treating missing access as failure", () => {
    dashboard.current = dashboardData(false);

    render(<HomePage />);

    expect(screen.queryByText("متابعات متأخرة")).toBeNull();
    expect(screen.queryByText("متابعات اليوم")).toBeNull();
    expect(screen.queryByText("follow-ups-panel")).toBeNull();
    expect(screen.getByText("حجوزات نشطة")).toBeTruthy();
    expect(screen.getByText("collections-panel")).toBeTruthy();
  });
});
