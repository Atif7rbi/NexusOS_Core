import {
  renderHook,
  waitFor,
} from "@testing-library/react";
import {
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from "vitest";

import { useDashboard } from "@/hooks/useDashboard";

const auth = vi.hoisted(() => ({
  role: "administrator" as string,
  effectiveModules: [
    "projects",
    "units",
    "customers",
    "crm",
    "reservations",
    "contracts",
    "collections",
  ] as string[],
}));

const services = vi.hoisted(() => ({
  fetchProjects: vi.fn(),
  fetchCustomers: vi.fn(),
  fetchUnits: vi.fn(),
  fetchReservations: vi.fn(),
  fetchContracts: vi.fn(),
  fetchLeads: vi.fn(),
  fetchCollectionsIndex: vi.fn(),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    token: "token",
    user: { role: auth.role },
    effectiveModules: auth.effectiveModules,
  }),
}));

vi.mock("@/services/projects", () => ({
  fetchProjects: services.fetchProjects,
}));
vi.mock("@/services/customers", () => ({
  fetchCustomers: services.fetchCustomers,
}));
vi.mock("@/services/units", () => ({
  fetchUnits: services.fetchUnits,
}));
vi.mock("@/services/reservations", () => ({
  fetchReservations: services.fetchReservations,
}));
vi.mock("@/services/contracts", () => ({
  fetchContracts: services.fetchContracts,
}));
vi.mock("@/services/leads", () => ({
  fetchLeads: services.fetchLeads,
}));
vi.mock("@/services/collections", () => ({
  fetchCollectionsIndex: services.fetchCollectionsIndex,
}));

const leadSummary = {
  open_leads: 6,
  overdue_follow_ups: 2,
  today_follow_ups: 3,
  unassigned_leads: 1,
  monthly_conversions: 1,
  lost_leads_in_selected_period: 0,
};

function configureSuccessResponses(): void {
  services.fetchProjects.mockResolvedValue({
    data: { data: [{ id: "project-1" }], total: 4 },
  });
  services.fetchCustomers.mockResolvedValue({
    data: { summary: { total: 12 } },
  });
  services.fetchUnits.mockResolvedValue({
    data: { units: { data: [], total: 8 } },
  });
  services.fetchReservations.mockResolvedValue({
    data: { summary: { active: 5 } },
  });
  services.fetchContracts.mockResolvedValue({
    data: { summary: { total: 7 } },
  });
  services.fetchCollectionsIndex.mockResolvedValue({
    data: {
      items: [{ contract_id: "contract-1" }],
      summary: {
        total_contracts: 7,
        scheduled_count: 3,
        draft_count: 2,
        absent_count: 1,
        cancelled_count: 1,
      },
    },
  });
  services.fetchLeads.mockImplementation(
    (_token: string, query: { follow_up_state: string }) =>
      Promise.resolve({
        data: {
          leads: [{ id: query.follow_up_state }],
          summary: leadSummary,
        },
      })
  );
}

describe("useDashboard", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    auth.role = "administrator";
    auth.effectiveModules = [
      "projects",
      "units",
      "customers",
      "crm",
      "reservations",
      "contracts",
      "collections",
    ];
    configureSuccessResponses();
  });

  it("loads operational metrics with the approved filters", async () => {
    const { result } = renderHook(() => useDashboard());

    await waitFor(() => {
      expect(result.current.collections.isLoading).toBe(false);
      expect(result.current.followUps.isLoading).toBe(false);
    });

    expect(result.current.projects.data).toMatchObject({
      activeTotal: 4,
    });
    expect(result.current.customers.data).toBe(12);
    expect(result.current.units.data).toBe(8);
    expect(result.current.reservations.data).toBe(5);
    expect(result.current.contracts.data).toBe(7);
    expect(result.current.followUps.data).toMatchObject({
      summary: leadSummary,
      overdue: [{ id: "overdue" }],
      today: [{ id: "today" }],
    });

    expect(services.fetchProjects).toHaveBeenCalledWith("token", {
      perPage: 5,
      status: "active",
    });
    expect(services.fetchUnits).toHaveBeenCalledWith("token", {
      per_page: 1,
      status: "available",
      archived: false,
    });
    expect(services.fetchCollectionsIndex).toHaveBeenCalledWith(
      "token",
      {
        per_page: 5,
        schedule_state: "scheduled",
        sort: "next_scheduled_collection",
      }
    );
  });

  it("isolates a failed section from successful dashboard data", async () => {
    services.fetchContracts.mockRejectedValue(
      new Error("contracts unavailable")
    );

    const { result } = renderHook(() => useDashboard());

    await waitFor(() => {
      expect(result.current.contracts.isLoading).toBe(false);
      expect(result.current.projects.isLoading).toBe(false);
    });

    expect(result.current.contracts.data).toBeNull();
    expect(result.current.contracts.error).toBe(
      "contracts unavailable"
    );
    expect(result.current.projects.data?.activeTotal).toBe(4);
    expect(result.current.reservations.data).toBe(5);
    expect(result.current.collections.data?.summary.scheduled_count).toBe(3);
  });

  it("skips CRM requests when the current role has no CRM access", async () => {
    auth.role = "accountant";

    const { result } = renderHook(() => useDashboard());

    await waitFor(() => {
      expect(result.current.projects.isLoading).toBe(false);
      expect(result.current.followUps.isLoading).toBe(false);
    });

    expect(result.current.hasCrmAccess).toBe(false);
    expect(result.current.followUps).toEqual({
      data: null,
      isLoading: false,
      error: null,
    });
    expect(services.fetchLeads).not.toHaveBeenCalled();
    expect(result.current.contracts.data).toBe(7);
  });

  it.each(["system_owner", "project_manager", "sales"])(
    "loads CRM sections for %s",
    async (role) => {
      auth.role = role;
      const { result } = renderHook(() => useDashboard());

      await waitFor(() => {
        expect(result.current.followUps.isLoading).toBe(false);
      });

      expect(result.current.hasCrmAccess).toBe(true);
      expect(services.fetchLeads).toHaveBeenCalledTimes(2);
    }
  );

  it("treats employee CRM access as unavailable without failing the dashboard", async () => {
    auth.role = "employee";
    const { result } = renderHook(() => useDashboard());

    await waitFor(() => {
      expect(result.current.projects.isLoading).toBe(false);
    });

    expect(result.current.hasCrmAccess).toBe(false);
    expect(result.current.followUps.error).toBeNull();
    expect(services.fetchLeads).not.toHaveBeenCalled();
    expect(result.current.collections.data?.summary.scheduled_count).toBe(3);
  });

  it("skips every API request whose source module is unavailable", async () => {
    auth.effectiveModules = ["projects", "customers"];

    const { result } = renderHook(() => useDashboard());

    await waitFor(() => {
      expect(result.current.projects.isLoading).toBe(false);
      expect(result.current.units.isLoading).toBe(false);
    });

    expect(services.fetchProjects).toHaveBeenCalledTimes(1);
    expect(services.fetchCustomers).toHaveBeenCalledTimes(1);
    expect(services.fetchUnits).not.toHaveBeenCalled();
    expect(services.fetchReservations).not.toHaveBeenCalled();
    expect(services.fetchContracts).not.toHaveBeenCalled();
    expect(services.fetchCollectionsIndex).not.toHaveBeenCalled();
    expect(services.fetchLeads).not.toHaveBeenCalled();
    expect(result.current.availableModules.units).toBe(false);
  });
});
