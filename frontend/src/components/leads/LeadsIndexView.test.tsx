import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { LeadsIndexView } from "@/components/leads/LeadsIndexView";
import { leadFixture } from "@/test/lead-fixtures";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

const data = {
  leads: [leadFixture()],
  summary: {
      open_leads: 12,
      overdue_follow_ups: 2,
      today_follow_ups: 5,
      unassigned_leads: 3,
      monthly_conversions: 4,
      lost_leads_in_selected_period: 6,
    },
  pagination: { current_page: 2, last_page: 3, per_page: 20, total: 41 },
};

const baseProps = {
  query: { page: 2, per_page: 20 },
  viewMode: "list" as const,
  index: data,
  projects: [],
  assignees: [],
  searchInput: "",
  successMessage: null,
  isAdministrator: false,
  isLoading: false,
  error: null,
  renderedAt: Date.now(),
  hasFilters: false,
  onSearchInput: vi.fn(),
  onQueryChange: vi.fn(),
  onViewChange: vi.fn(),
  onCreate: vi.fn(),
  onRefresh: vi.fn(),
  onReset: vi.fn(),
  onOpen: vi.fn(),
};

describe("LeadsIndexView", () => {
  it("renders loading, error, and empty states", () => {
    const { rerender } = render(<LeadsIndexView {...baseProps} index={null} isLoading />);
    expect(screen.getByText("crm.loading")).toBeTruthy();

    rerender(<LeadsIndexView {...baseProps} index={null} error="Network error" />);
    expect(screen.getByText("Network error")).toBeTruthy();

    rerender(
      <LeadsIndexView
        {...baseProps}
        index={{ ...data, leads: [], pagination: { ...data.pagination, total: 0 } }}
      />
    );
    expect(screen.getByText("crm.empty.title")).toBeTruthy();
  });

  it("renders summary values, leads, and pagination", () => {
    render(<LeadsIndexView {...baseProps} />);
    ["12", "2", "5", "3", "4", "6"].forEach((value) =>
      expect(screen.getByText(value)).toBeTruthy()
    );
    expect(screen.getAllByText("Test Lead").length).toBeGreaterThan(0);
    expect(screen.getByText(/crm.pagination.page/)).toBeTruthy();
  });

  it("updates search, stage, and page through query callbacks", () => {
    const onSearchInput = vi.fn();
    const onQueryChange = vi.fn();
    render(
      <LeadsIndexView
        {...baseProps}
        onSearchInput={onSearchInput}
        onQueryChange={onQueryChange}
      />
    );

    fireEvent.change(screen.getByRole("searchbox"), { target: { value: "Acme" } });
    fireEvent.change(screen.getAllByRole("combobox")[0], {
      target: { value: "qualified" },
    });
    fireEvent.click(screen.getByText("crm.pagination.next"));

    expect(onSearchInput).toHaveBeenCalledWith("Acme");
    expect(onQueryChange).toHaveBeenCalledWith({ stage: "qualified", page: 1 });
    expect(onQueryChange).toHaveBeenCalledWith({ page: 3 });
  });

  it("changes follow-up queue bucket and clears incompatible modes", () => {
    const onQueryChange = vi.fn();
    render(<LeadsIndexView {...baseProps} onQueryChange={onQueryChange} />);

    fireEvent.click(
      screen.getByRole("tab", { name: "crm.queue.today" })
    );

    expect(onQueryChange).toHaveBeenCalledWith({
      follow_up_bucket: "today",
      follow_up_state: null,
      overdue: null,
      archived: null,
      page: 1,
    });
  });

  it("switches between list and pipeline views", () => {
    const onViewChange = vi.fn();
    const { rerender } = render(
      <LeadsIndexView {...baseProps} onViewChange={onViewChange} />
    );

    fireEvent.click(screen.getByText("crm.view.pipeline"));
    expect(onViewChange).toHaveBeenCalledWith("pipeline");

    rerender(
      <LeadsIndexView
        {...baseProps}
        viewMode="pipeline"
        onViewChange={onViewChange}
      />
    );
    expect(screen.getByTestId("leads-pipeline")).toBeTruthy();
  });

  it("shows archived mode only to administrators", () => {
    const { rerender } = render(<LeadsIndexView {...baseProps} />);
    expect(screen.queryByText("crm.filters.archived")).toBeNull();

    rerender(<LeadsIndexView {...baseProps} isAdministrator />);
    expect(screen.getByText("crm.filters.archived")).toBeTruthy();
  });

  it("uses filtered empty state and reset action", () => {
    const onReset = vi.fn();
    render(
      <LeadsIndexView
        {...baseProps}
        hasFilters
        onReset={onReset}
        index={{ ...data, leads: [], pagination: { ...data.pagination, total: 0 } }}
      />
    );

    expect(screen.getByText("crm.emptyFiltered.title")).toBeTruthy();
    fireEvent.click(screen.getAllByText("crm.resetFilters")[0]);
    expect(onReset).toHaveBeenCalled();
  });
  it("emits final lifecycle follow-up and date filters", () => {
    const onQueryChange = vi.fn();

    render(
      <LeadsIndexView
        {...baseProps}
        onQueryChange={onQueryChange}
      />
    );

    fireEvent.change(
      screen.getByLabelText("crm.filters.lifecycle"),
      { target: { value: "lost" } }
    );

    expect(onQueryChange).toHaveBeenCalledWith({
      lifecycle: "lost",
      page: 1,
    });

    fireEvent.change(
      screen.getByLabelText("crm.filters.followUpState"),
      { target: { value: "scheduled_later" } }
    );

    expect(onQueryChange).toHaveBeenCalledWith({
      follow_up_state: "scheduled_later",
      follow_up_bucket: null,
      overdue: null,
      archived: null,
      page: 1,
    });

    fireEvent.change(
      screen.getByLabelText("crm.filters.dateFrom"),
      { target: { value: "2026-08-05" } }
    );

    expect(onQueryChange).toHaveBeenCalledWith({
      date_from: "2026-08-05",
      page: 1,
    });

    fireEvent.change(
      screen.getByLabelText("crm.filters.dateTo"),
      { target: { value: "2026-08-07" } }
    );

    expect(onQueryChange).toHaveBeenCalledWith({
      date_to: "2026-08-07",
      page: 1,
    });
  });

  it("clears follow-up filters when archived mode changes", () => {
    const onQueryChange = vi.fn();

    render(
      <LeadsIndexView
        {...baseProps}
        query={{
          ...baseProps.query,
          archived: false,
          follow_up_state: "today",
        }}
        isAdministrator
        onQueryChange={onQueryChange}
      />
    );

    fireEvent.click(
      screen.getByRole("checkbox", {
        name: "crm.filters.archived",
      })
    );

    expect(onQueryChange).toHaveBeenCalledWith({
      archived: true,
      follow_up_bucket: null,
      follow_up_state: null,
      overdue: null,
      page: 1,
      lead: null,
    });
  });

  it("renders the final operational summary contract", () => {
    render(<LeadsIndexView {...baseProps} />);

    expect(screen.getByText("crm.cards.openLeads")).toBeTruthy();
    expect(screen.getByText("crm.cards.overdueFollowUps")).toBeTruthy();
    expect(screen.getByText("crm.cards.todayFollowUps")).toBeTruthy();
    expect(screen.getByText("crm.cards.unassignedLeads")).toBeTruthy();
    expect(screen.getByText("crm.cards.monthlyConversions")).toBeTruthy();
    expect(screen.getByText("crm.cards.lostInPeriod")).toBeTruthy();

    ["12", "2", "5", "3", "4", "6"].forEach((value) => {
      expect(screen.getByText(value)).toBeTruthy();
    });
  });

});
