import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CrmLeadsPage } from "@/components/leads/CrmLeadsPage";
import { leadFixture } from "@/test/lead-fixtures";
import type { LeadFollowUpDialogAction } from "@/components/leads/LeadFollowUpDialog";

const services = vi.hoisted(() => ({
  fetchLeads: vi.fn(),
  fetchLead: vi.fn(),
  fetchLeadActivities: vi.fn(),
  scheduleLeadFollowUp: vi.fn(),
  rescheduleLeadFollowUp: vi.fn(),
  completeLeadFollowUp: vi.fn(),
  cancelLeadFollowUp: vi.fn(),
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
  fetchTenantUsers: vi.fn().mockResolvedValue({
    data: { users: { data: [] } },
  }),
}));

vi.mock("@/services/leads", async (importOriginal) => {
  const actual = await importOriginal<typeof import("@/services/leads")>();

  return {
    ...actual,
    ...services,
  };
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
    onFollowUpAction: (action: LeadFollowUpDialogAction) => void;
  }) => (
    <div data-testid="details-view">
      <span>{lead?.next_action_type ?? "no follow-up"}</span>

      <button onClick={() => onFollowUpAction("schedule_follow_up")}>
        open schedule
      </button>

      <button onClick={() => onFollowUpAction("reschedule_follow_up")}>
        open reschedule
      </button>

      <button onClick={() => onFollowUpAction("complete_follow_up")}>
        open complete
      </button>

      <button onClick={() => onFollowUpAction("cancel_follow_up")}>
        open cancel
      </button>
    </div>
  ),
}));

vi.mock("@/components/leads/LeadFollowUpDialog", () => ({
  LeadFollowUpDialog: ({
    action,
    onConfirm,
  }: {
    action: LeadFollowUpDialogAction;
    onConfirm: (payload:
      | {
          action: "schedule_follow_up" | "reschedule_follow_up";
          nextFollowUpAt: string;
          nextActionType: "meeting";
          nextActionNote: string;
        }
      | {
          action: "complete_follow_up" | "cancel_follow_up";
          note: string;
        }
    ) => void;
  }) => (
    <button
      onClick={() => {
        if (
          action === "schedule_follow_up" ||
          action === "reschedule_follow_up"
        ) {
          onConfirm({
            action,
            nextFollowUpAt: "2026-08-06T07:00:00.000Z",
            nextActionType: "meeting",
            nextActionNote: "Discuss final offer",
          });

          return;
        }

        onConfirm({
          action,
          note: "Follow-up operation note",
        });
      }}
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

const unscheduledLead = leadFixture({
  next_follow_up_at: null,
  next_action_type: null,
  next_action_note: null,
  follow_up_state: "unscheduled",
});

const scheduledLead = leadFixture({
  next_follow_up_at: "2026-08-05T09:00:00.000Z",
  next_action_type: "call",
  next_action_note: "Call customer",
  follow_up_state: "today",
});

const rescheduledLead = leadFixture({
  next_follow_up_at: "2026-08-06T07:00:00.000Z",
  next_action_type: "meeting",
  next_action_note: "Discuss final offer",
  follow_up_state: "tomorrow",
});

const clearedLead = leadFixture({
  next_follow_up_at: null,
  next_action_type: null,
  next_action_note: null,
  follow_up_state: "unscheduled",
});

const indexResponse = {
  data: {
    leads: [unscheduledLead],
    summary: {
      open_leads: 1,
      unassigned_leads: 1,
      overdue_follow_ups: 0,
      today_follow_ups: 0,
      tomorrow_follow_ups: 0,
      unscheduled_follow_ups: 1,
      monthly_conversions: 0,
    },
    pagination: {
      current_page: 1,
      last_page: 1,
      per_page: 20,
      total: 1,
    },
  },
};

async function expectRefreshes(): Promise<void> {
  await waitFor(() => {
    expect(
      services.fetchLeadActivities.mock.calls.length
    ).toBeGreaterThanOrEqual(2);

    expect(
      services.fetchLeads.mock.calls.length
    ).toBeGreaterThanOrEqual(2);
  });
}

describe("CrmLeadsPage follow-up workflow", () => {
  beforeEach(() => {
    vi.clearAllMocks();

    window.history.replaceState(
      null,
      "",
      `/crm/?lead=${leadId}`
    );

    services.fetchLeads.mockResolvedValue(indexResponse);

    services.fetchLeadActivities.mockResolvedValue({
      data: {
        activities: [],
        pagination: {},
      },
    });
  });

  it("schedules a follow-up and refreshes details, activities, and list data", async () => {
    services.fetchLead
      .mockResolvedValueOnce(unscheduledLead)
      .mockResolvedValue(scheduledLead);
    services.scheduleLeadFollowUp.mockResolvedValue(scheduledLead);

    render(<CrmLeadsPage />);

    await screen.findByText("no follow-up");

    fireEvent.click(screen.getByText("open schedule"));
    fireEvent.click(
      screen.getByText("confirm schedule_follow_up")
    );

    await waitFor(() => {
      expect(services.scheduleLeadFollowUp).toHaveBeenCalledWith(
        "token",
        leadId,
        {
          next_follow_up_at: "2026-08-06T07:00:00.000Z",
          next_action_type: "meeting",
          next_action_note: "Discuss final offer",
        }
      );
    });

    expect(await screen.findByText("call")).toBeTruthy();

    await expectRefreshes();
  });

  it("reschedules a follow-up and refreshes details, activities, and list data", async () => {
    services.fetchLead
      .mockResolvedValueOnce(scheduledLead)
      .mockResolvedValue(rescheduledLead);
    services.rescheduleLeadFollowUp.mockResolvedValue(
      rescheduledLead
    );

    render(<CrmLeadsPage />);

    await screen.findByText("call");

    fireEvent.click(screen.getByText("open reschedule"));
    fireEvent.click(
      screen.getByText("confirm reschedule_follow_up")
    );

    await waitFor(() => {
      expect(
        services.rescheduleLeadFollowUp
      ).toHaveBeenCalledWith("token", leadId, {
        next_follow_up_at: "2026-08-06T07:00:00.000Z",
        next_action_type: "meeting",
        next_action_note: "Discuss final offer",
      });
    });

    expect(await screen.findByText("meeting")).toBeTruthy();

    await expectRefreshes();
  });

  it("completes a follow-up and refreshes details, activities, and list data", async () => {
    services.fetchLead
      .mockResolvedValueOnce(scheduledLead)
      .mockResolvedValue(clearedLead);
    services.completeLeadFollowUp.mockResolvedValue(clearedLead);

    render(<CrmLeadsPage />);

    await screen.findByText("call");

    fireEvent.click(screen.getByText("open complete"));
    fireEvent.click(
      screen.getByText("confirm complete_follow_up")
    );

    await waitFor(() => {
      expect(services.completeLeadFollowUp).toHaveBeenCalledWith(
        "token",
        leadId,
        {
          note: "Follow-up operation note",
        }
      );
    });

    expect(await screen.findByText("no follow-up")).toBeTruthy();

    await expectRefreshes();
  });

  it("cancels a follow-up and refreshes details, activities, and list data", async () => {
    services.fetchLead
      .mockResolvedValueOnce(scheduledLead)
      .mockResolvedValue(clearedLead);
    services.cancelLeadFollowUp.mockResolvedValue(clearedLead);

    render(<CrmLeadsPage />);

    await screen.findByText("call");

    fireEvent.click(screen.getByText("open cancel"));
    fireEvent.click(
      screen.getByText("confirm cancel_follow_up")
    );

    await waitFor(() => {
      expect(services.cancelLeadFollowUp).toHaveBeenCalledWith(
        "token",
        leadId,
        {
          note: "Follow-up operation note",
        }
      );
    });

    expect(await screen.findByText("no follow-up")).toBeTruthy();

    await expectRefreshes();
  });
});
