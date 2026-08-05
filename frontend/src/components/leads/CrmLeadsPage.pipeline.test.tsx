import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CrmLeadsPage } from "@/components/leads/CrmLeadsPage";
import { leadFixture } from "@/test/lead-fixtures";

const services = vi.hoisted(() => ({
  fetchLeads: vi.fn(),
  fetchLead: vi.fn(),
  fetchLeadActivities: vi.fn(),
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(window.location.search),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    token: "token",
    user: { id: 1, name: "Admin", role: "administrator" },
  }),
}));

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock("@/components/ui/crud/CrudPageLayout", () => ({
  CrudPageLayout: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock("@/services/projects", () => ({
  fetchProjects: vi.fn().mockResolvedValue({ data: { data: [] } }),
}));

vi.mock("@/services/users", () => ({
  fetchTenantUsers: vi.fn().mockResolvedValue({ data: { users: { data: [] } } }),
}));

vi.mock("@/services/leads", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/services/leads")>();
  return { ...actual, ...services };
});

vi.mock("@/components/leads/LeadsIndexView", () => ({
  LeadsIndexView: ({
    viewMode,
    onViewChange,
    onOpen,
  }: {
    viewMode: "list" | "pipeline";
    onViewChange: (view: "list" | "pipeline") => void;
    onOpen: (lead: ReturnType<typeof leadFixture>) => void;
  }) => (
    <div data-testid="index-view">
      <span>{`view:${viewMode}`}</span>
      <button onClick={() => onViewChange("pipeline")}>show pipeline</button>
      <button onClick={() => onViewChange("list")}>show list</button>
      <button onClick={() => onOpen(leadFixture())}>open pipeline lead</button>
    </div>
  ),
}));

vi.mock("@/components/leads/LeadDetailsView", () => ({
  LeadDetailsView: ({
    lead,
    onBack,
  }: {
    lead: ReturnType<typeof leadFixture> | null;
    onBack: () => void;
  }) => (
    <div data-testid="details-view">
      <span>{lead?.name ?? "loading"}</span>
      <button onClick={onBack}>close details</button>
    </div>
  ),
}));

vi.mock("@/components/leads/LeadFormDialog", () => ({
  LeadFormDialog: () => null,
}));
vi.mock("@/components/leads/LeadDuplicateDialog", () => ({
  LeadDuplicateDialog: () => null,
}));
vi.mock("@/components/leads/LeadActionDialogs", () => ({
  LeadActionDialogs: () => null,
}));
vi.mock("@/components/leads/LeadFollowUpDialog", () => ({
  LeadFollowUpDialog: () => null,
}));

const indexResponse = {
  data: {
    leads: [leadFixture()],
    summary: { active: 1, unassigned: 1, overdue: 0, converted_this_month: 0 },
    pagination: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
  },
};

describe("CrmLeadsPage pipeline URL state", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    services.fetchLeads.mockResolvedValue(indexResponse);
    services.fetchLead.mockResolvedValue(leadFixture());
    services.fetchLeadActivities.mockResolvedValue({
      data: { activities: [], pagination: {} },
    });
  });

  it("activates pipeline mode from view=pipeline", () => {
    window.history.replaceState(null, "", "/crm/?view=pipeline");
    render(<CrmLeadsPage />);

    expect(screen.getByText("view:pipeline")).toBeTruthy();
    expect(services.fetchLeads).toHaveBeenCalledWith(
      "token",
      expect.objectContaining({ per_page: 100 })
    );
  });

  it("switches views while preserving the active filters", () => {
    window.history.replaceState(
      null,
      "",
      "/crm/?stage=qualified&source=website&page=3"
    );
    const history = vi.spyOn(window.history, "pushState");

    render(<CrmLeadsPage />);
    fireEvent.click(screen.getByText("show pipeline"));

    expect(history).toHaveBeenCalledWith(
      null,
      "",
      "/crm/?stage=qualified&source=website&page=1&view=pipeline"
    );
  });

  it("clears archived mode when switching to pipeline", () => {
    window.history.replaceState(
      null,
      "",
      "/crm/?archived=true&page=3"
    );

    const history = vi.spyOn(window.history, "pushState");

    render(<CrmLeadsPage />);
    fireEvent.click(screen.getByText("show pipeline"));

    expect(history).toHaveBeenCalledWith(
      null,
      "",
      "/crm/?page=1&view=pipeline"
    );
  });

  it("preserves pipeline state when opening and closing lead details", () => {
    window.history.replaceState(
      null,
      "",
      "/crm/?view=pipeline&stage=qualified&page=2"
    );
    const history = vi.spyOn(window.history, "pushState");

    const { rerender } = render(<CrmLeadsPage />);
    fireEvent.click(screen.getByText("open pipeline lead"));

    expect(history).toHaveBeenLastCalledWith(
      null,
      "",
      `/crm/?view=pipeline&stage=qualified&page=2&lead=${leadFixture().id}`
    );

    window.history.replaceState(
      null,
      "",
      `/crm/?view=pipeline&stage=qualified&page=2&lead=${leadFixture().id}`
    );
    rerender(<CrmLeadsPage />);
    fireEvent.click(screen.getByText("close details"));

    expect(history).toHaveBeenLastCalledWith(
      null,
      "",
      "/crm/?view=pipeline&stage=qualified&page=2"
    );
  });
});
