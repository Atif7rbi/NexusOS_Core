import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { CrmLeadsPage } from "@/components/leads/CrmLeadsPage";
import { leadFixture } from "@/test/lead-fixtures";

const services = vi.hoisted(() => ({
  fetchLeads: vi.fn(),
  fetchLead: vi.fn(),
  fetchLeadActivities: vi.fn(),
  convertLead: vi.fn(),
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(window.location.search),
}));
vi.mock("next/link", () => ({
  default: ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href}>{children}</a>
  ),
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
  LeadsIndexView: () => <div>list</div>,
}));
vi.mock("@/components/leads/LeadFormDialog", () => ({
  LeadFormDialog: () => null,
}));
vi.mock("@/components/leads/LeadDuplicateDialog", () => ({
  LeadDuplicateDialog: () => null,
}));
vi.mock("@/components/leads/LeadDetailsView", () => ({
  LeadDetailsView: ({ lead, onAction }: {
    lead: ReturnType<typeof leadFixture> | null;
    onAction: (action: "convert") => void;
  }) => (
    <div>
      <span>{lead?.name ?? "loading"}</span>
      <button onClick={() => onAction("convert")}>convert details</button>
    </div>
  ),
}));
vi.mock("@/components/leads/LeadActionDialogs", () => ({
  LeadActionDialogs: ({
    conversionConflict,
    onConfirm,
    onClose,
  }: {
    conversionConflict?: { id: string; status: string; name?: string } | null;
    onConfirm: (payload: {
      action: "convert";
      intent: "create_new" | "link_existing";
      type?: "individual";
      category?: "buyer";
      existingCustomerId?: string;
    }) => void;
    onClose: () => void;
  }) => (
    <div role="dialog">
      {conversionConflict ? (
        <>
          <span>{conversionConflict.name}</span>
          <button onClick={() => onConfirm({
            action: "convert",
            intent: "link_existing",
            existingCustomerId: conversionConflict.id,
          })}>confirm link</button>
        </>
      ) : (
        <button onClick={() => onConfirm({
          action: "convert",
          intent: "create_new",
          type: "individual",
          category: "buyer",
        })}>confirm create</button>
      )}
      <button onClick={onClose}>close conversion</button>
    </div>
  ),
}));

const indexResponse = {
  data: {
    leads: [leadFixture()],
    summary: { active: 1, unassigned: 0, overdue: 0, converted_this_month: 0 },
    pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  },
};

describe("CrmLeadsPage conversion workflow", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(null, "", `/crm/?lead=${leadFixture().id}`);
    services.fetchLeads.mockResolvedValue(indexResponse);
    services.fetchLead.mockResolvedValue(leadFixture());
    services.fetchLeadActivities.mockResolvedValue({
      data: { activities: [], pagination: {} },
    });
  });

  it("shows the customer name and customers CTA after successful conversion", async () => {
    services.convertLead.mockResolvedValue(leadFixture({
      stage: "won",
      customer: {
        id: "01CUSTOMER00000000000000000",
        name: "Converted Customer",
        status: "customer",
        archived_at: null,
      },
    }));

    render(<CrmLeadsPage />);
    await screen.findByText("Test Lead");
    fireEvent.click(screen.getByText("convert details"));
    fireEvent.click(screen.getByText("confirm create"));

    expect(await screen.findByText(/Converted Customer/)).toBeTruthy();
    const customersLink = screen.getByRole("link", {
      name: "crm.dialog.convertViewCustomers",
    });
    expect(customersLink.getAttribute("href")).toBe(
      "/customers/?customer=01CUSTOMER00000000000000000"
    );
  });

  it("clears a surfaced conflict when the dialog closes before a new attempt", async () => {
    const { LeadConversionConflictError } = await import("@/services/leads");
    services.convertLead.mockRejectedValueOnce(
      new LeadConversionConflictError("Conflict", {
        id: "01MATCH00000000000000000000",
        status: "customer",
        name: "Matched Customer",
      })
    );

    render(<CrmLeadsPage />);
    await screen.findByText("Test Lead");
    fireEvent.click(screen.getByText("convert details"));
    fireEvent.click(screen.getByText("confirm create"));
    expect(await screen.findByText("Matched Customer")).toBeTruthy();

    fireEvent.click(screen.getByText("close conversion"));
    await waitFor(() => expect(screen.queryByText("Matched Customer")).toBeNull());

    fireEvent.click(screen.getByText("convert details"));
    expect(screen.getByText("confirm create")).toBeTruthy();
    expect(screen.queryByText("Matched Customer")).toBeNull();
  });
});
