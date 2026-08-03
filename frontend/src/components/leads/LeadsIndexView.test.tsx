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
    active: 8,
    unassigned: 3,
    overdue: 2,
    converted_this_month: 1,
  },
  pagination: { current_page: 2, last_page: 3, per_page: 20, total: 41 },
};

const baseProps = {
  query: { page: 2, per_page: 20 },
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
    ["8", "3", "2", "1"].forEach((value) =>
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
});
