import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CrmLeadsPage } from "@/components/leads/CrmLeadsPage";
import { leadFixture } from "@/test/lead-fixtures";

const services = vi.hoisted(() => ({
  fetchLeads: vi.fn(),
  fetchLead: vi.fn(),
  fetchLeadActivities: vi.fn(),
  scheduleLeadFollowUp: vi.fn(),
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
  LeadsIndexView: () => <div data-testid="list-view" />,
}));

vi.mock("@/components/leads/LeadDetailsView", () => ({
  LeadDetailsView: ({
    lead,
    onFollowUpAction,
  }: {
    lead: ReturnType<typeof leadFixture> | null;
    onFollowUpAction: (action: "schedule_follow_up") => void;
  }) => (
    <div data-testid="details-view">
      <span>{lead?.next_action_type ?? "no follow-up"}</span>
      <button onClick={() => onFollowUpAction("schedule_follow_up")}>open follow-up</button>
    </div>
  ),
}));

vi.mock("@/components/leads/LeadFollowUpDialog", () => ({
  LeadFollowUpDialog: ({
    action,
    onConfirm,
  }: {
    action: string;
    onConfirm: (payload: {
      action: "schedule_follow_up";
      nextFollowUpAt: string;
      nextActionType: "call";
      nextActionNote: string | null;
    }) => void;
  }) => (
    <button
      onClick={() =>
        onConfirm({
          action: "schedule_follow_up",
          nextFollowUpAt: "2026-08-05T09:00:00.000Z",
          nextActionType: "call",
          nextActionNote: "Call customer",
        })
      }
    >
      confirm {action}
    </button>
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

const leadId = leadFixture().id;
const scheduledLead = leadFixture({
  next_follow_up_at: "2026-08-05T09:00:00.000Z",
  next_action_type: "call",
  next_action_note: "Call customer",
  follow_up_state: "tomorrow",
});

const indexResponse = {
  data: {
    leads: [leadFixture()],
    summary: { active: 1, unassigned: 1, overdue: 0, converted_this_month: 0 },
    pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  },
};

describe("CrmLeadsPage follow-up workflow", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(null, "", `/crm/?lead=${leadId}`);
    services.fetchLeads.mockResolvedValue(indexResponse);
    services.fetchLead.mockResolvedValue(leadFixture());
    services.fetchLeadActivities.mockResolvedValue({
      data: { activities: [], pagination: {} },
    });
    services.scheduleLeadFollowUp.mockResolvedValue(scheduledLead);
  });

  it("schedules a follow-up and refreshes details, activities, and list data", async () => {
    render(<CrmLeadsPage />);

    await screen.findByText("no follow-up");
    fireEvent.click(screen.getByText("open follow-up"));
    fireEvent.click(screen.getByText("confirm schedule_follow_up"));

    await waitFor(() =>
      expect(services.scheduleLeadFollowUp).toHaveBeenCalledWith(
        "token",
        leadId,
        {
          next_follow_up_at: "2026-08-05T09:00:00.000Z",
          next_action_type: "call",
          next_action_note: "Call customer",
        }
      )
    );

    expect(await screen.findByText("call")).toBeTruthy();

    await waitFor(() => {
      expect(services.fetchLeadActivities.mock.calls.length).toBeGreaterThanOrEqual(2);
      expect(services.fetchLeads.mock.calls.length).toBeGreaterThanOrEqual(2);
    });
  });
});
