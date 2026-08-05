import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CrmLeadsPage } from "@/components/leads/CrmLeadsPage";
import { leadFixture } from "@/test/lead-fixtures";

const services = vi.hoisted(() => ({
  fetchLeads: vi.fn(),
  fetchLead: vi.fn(),
  fetchLeadActivities: vi.fn(),
  createLead: vi.fn(),
  updateLead: vi.fn(),
  claimLead: vi.fn(),
  assignLead: vi.fn(),
  moveLeadStage: vi.fn(),
  moveLeadToLost: vi.fn(),
  reopenLead: vi.fn(),
  archiveLead: vi.fn(),
  restoreLead: vi.fn(),
  addLeadNote: vi.fn(),
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
    onOpen,
    onCreate,
    onQueryChange,
    onRefresh,
    renderedAt,
  }: {
    onOpen: (lead: ReturnType<typeof leadFixture>) => void;
    onCreate: () => void;
    onQueryChange: (changes: Record<string, unknown>) => void;
    onRefresh: () => void;
    renderedAt: number;
  }) => (
    <div data-testid="list-view">
      <span data-testid="rendered-at">{renderedAt}</span>
      <button onClick={() => onOpen(leadFixture())}>open lead</button>
      <button onClick={onCreate}>create lead</button>
      <button
        onClick={() =>
          onQueryChange({ stage: "qualified", page: 1 })
        }
      >
        filter
      </button>
      <button onClick={onRefresh}>refresh leads</button>
    </div>
  ),
}));
vi.mock("@/components/leads/LeadDetailsView", () => ({
  LeadDetailsView: ({ lead, onBack, onAction }: {
    lead: ReturnType<typeof leadFixture> | null;
    onBack: () => void;
    onAction: (action: "claim") => void;
  }) => (
    <div data-testid="details-view">
      <span>{lead?.name ?? "loading details"}</span>
      <button onClick={onBack}>close details</button>
      <button onClick={() => onAction("claim")}>claim details</button>
    </div>
  ),
}));
vi.mock("@/components/leads/LeadFormDialog", () => ({
  LeadFormDialog: ({ onSubmit, onClose }: {
    onSubmit: (payload: { name: string; phone: string; source: "website" }) => Promise<void>;
    onClose: () => void;
  }) => (
    <div data-testid="lead-form">
      <button onClick={() => void onSubmit({ name: "New", phone: "0501234567", source: "website" })}>submit create</button>
      <button onClick={onClose}>cancel create</button>
    </div>
  ),
}));
vi.mock("@/components/leads/LeadDuplicateDialog", () => ({
  LeadDuplicateDialog: ({ isOpen, matches, onConfirm, onCancel }: {
    isOpen: boolean;
    matches: Array<{ name: string }>;
    onConfirm: () => void;
    onCancel: () => void;
  }) => isOpen ? (
    <div data-testid="duplicate-dialog">
      {matches.map((match) => <span key={match.name}>{match.name}</span>)}
      <button onClick={onConfirm}>confirm duplicate</button>
      <button onClick={onCancel}>cancel duplicate</button>
    </div>
  ) : null,
}));
vi.mock("@/components/leads/LeadActionDialogs", () => ({
  LeadActionDialogs: ({ action, onConfirm }: {
    action: string | null;
    onConfirm: (payload: { action: "claim" }) => void;
  }) => action ? <button onClick={() => onConfirm({ action: "claim" })}>confirm claim</button> : null,
}));

const indexResponse = {
  data: {
    leads: [leadFixture()],
    summary: { active: 1, unassigned: 1, overdue: 0, converted_this_month: 0 },
    pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  },
};

describe("CrmLeadsPage routing and workflows", () => {
  beforeEach(() => {
    window.history.replaceState(null, "", "/crm/");
    services.fetchLeads.mockResolvedValue(indexResponse);
    services.fetchLead.mockResolvedValue(leadFixture());
    services.fetchLeadActivities.mockResolvedValue({ data: { activities: [], pagination: {} } });
    services.createLead.mockResolvedValue(leadFixture({ id: "created-lead" }));
    services.claimLead.mockResolvedValue(leadFixture());
  });

  it("shows list view for /crm/ and updates filter query", async () => {
    const history = vi.spyOn(window.history, "pushState");
    render(<CrmLeadsPage />);
    expect(screen.getByTestId("list-view")).toBeTruthy();
    fireEvent.click(screen.getByText("filter"));
    expect(history).toHaveBeenCalledWith(null, "", "/crm/?stage=qualified&page=1");
  });

  it("opens details while preserving filters and pagination", () => {
    window.history.replaceState(null, "", "/crm/?stage=qualified&page=2");
    const history = vi.spyOn(window.history, "pushState");
    render(<CrmLeadsPage />);
    fireEvent.click(screen.getByText("open lead"));
    expect(history).toHaveBeenCalledWith(
      null,
      "",
      `/crm/?stage=qualified&page=2&lead=${leadFixture().id}`
    );
  });

  it("shows details for lead query and closing removes lead only", async () => {
    window.history.replaceState(null, "", `/crm/?stage=qualified&page=2&lead=${leadFixture().id}`);
    const history = vi.spyOn(window.history, "pushState");
    render(<CrmLeadsPage />);
    expect(screen.getByTestId("details-view")).toBeTruthy();
    await screen.findByText("Test Lead");
    fireEvent.click(screen.getByText("close details"));
    expect(history).toHaveBeenCalledWith(null, "", "/crm/?stage=qualified&page=2");
  });

  it("confirms duplicate creation with acknowledge_duplicate=true", async () => {
    const { LeadDuplicateError } = await import("@/services/leads");
    services.createLead
      .mockRejectedValueOnce(new LeadDuplicateError("Duplicate", [{
        id: "api-match",
        name: "API Match",
        stage: "new",
        assigned_to: null,
        archived: false,
        updated_at: null,
      }]))
      .mockResolvedValueOnce(leadFixture({ id: "created-lead" }));
    render(<CrmLeadsPage />);

    fireEvent.click(screen.getByText("create lead"));
    fireEvent.click(screen.getByText("submit create"));
    expect(await screen.findByText("API Match")).toBeTruthy();
    fireEvent.click(screen.getByText("confirm duplicate"));

    await waitFor(() => expect(services.createLead).toHaveBeenCalledTimes(2));
    expect(services.createLead.mock.calls[1]?.[1]).toMatchObject({
      acknowledge_duplicate: true,
    });
  });

  it("cancelling duplicate flow does not send another create", async () => {
    const { LeadDuplicateError } = await import("@/services/leads");
    services.createLead.mockRejectedValueOnce(new LeadDuplicateError("Duplicate", []));
    render(<CrmLeadsPage />);
    fireEvent.click(screen.getByText("create lead"));
    fireEvent.click(screen.getByText("submit create"));
    await screen.findByTestId("duplicate-dialog");
    fireEvent.click(screen.getByText("cancel duplicate"));
    expect(services.createLead).toHaveBeenCalledTimes(1);
  });

  it("executes claim through the approved command service", async () => {
    window.history.replaceState(null, "", `/crm/?lead=${leadFixture().id}`);
    render(<CrmLeadsPage />);
    await screen.findByText("Test Lead");
    fireEvent.click(screen.getByText("claim details"));
    fireEvent.click(screen.getByText("confirm claim"));
    await waitFor(() => expect(services.claimLead).toHaveBeenCalledWith("token", leadFixture().id));
  });

  it("updates renderedAt when the list is refreshed", async () => {
    const dateSpy = vi
      .spyOn(Date, "now")
      .mockReturnValue(1000);

    try {
      render(<CrmLeadsPage />);

      expect(
        (await screen.findByTestId("rendered-at")).textContent
      ).toBe("1000");

      dateSpy.mockReturnValue(2000);

      fireEvent.click(screen.getByText("refresh leads"));

      await waitFor(() => {
        expect(
          screen.getByTestId("rendered-at").textContent
        ).toBe("2000");
      });
    } finally {
      dateSpy.mockRestore();
    }
  });

  it("contains no dynamic-route or conversion UI contract", () => {
    render(<CrmLeadsPage />);
    expect(document.body.textContent?.toLowerCase()).not.toContain("convert");
  });
});
