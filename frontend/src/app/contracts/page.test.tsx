import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import {
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from "vitest";

import ContractsPage from "@/app/contracts/page";
import type { AuthUser } from "@/types/auth";
import type { ContractFormPayload } from "@/types/contract";
import type { Reservation } from "@/types/reservation";

const reservationId = "01K00000000000000000000001";
const returnTo = "/reservations/?page=2&status=active";

const reservation: Reservation = {
  id: reservationId,
  tenant_id: "tenant-1",
  unit_id: "unit-1",
  customer_id: "customer-1",
  status: "active",
  reserved_at: "2026-08-14T08:00:00.000Z",
  expires_at: "2026-08-20T08:00:00.000Z",
  notes: null,
  cancellation_reason: null,
  cancelled_at: null,
  cancelled_by: null,
  created_by: 1,
  updated_by: 1,
  created_at: "2026-08-14T08:00:00.000Z",
  updated_at: "2026-08-14T08:00:00.000Z",
  unit: {
    id: "unit-1",
    tenant_id: "tenant-1",
    project_id: "project-1",
    unit_number: "A-101",
    unit_type: "apartment",
    status: "reserved",
    selling_price: "650000.00",
    area: null,
    floor: null,
    bedrooms: null,
    bathrooms: null,
    notes: null,
    archived_at: null,
    created_at: "",
    updated_at: "",
    project: {
      id: "project-1",
      project_number: "PRJ-2026-001",
      name: "مشروع النخيل",
      currency: "SAR",
      project_manager_id: 2,
    },
  },
};

const administrator: AuthUser = {
  id: 1,
  name: "Administrator",
  email: "admin@example.test",
  role: "administrator",
  status: "active",
  phone: null,
  email_verified_at: null,
  last_login_at: null,
  created_at: "",
  updated_at: "",
};

const auth = vi.hoisted(() => ({
  user: null as AuthUser | null,
}));

const services = vi.hoisted(() => ({
  fetchContracts: vi.fn(),
  fetchContract: vi.fn(),
  createContract: vi.fn(),
  updateContract: vi.fn(),
  executeContractLifecycleAction: vi.fn(),
  fetchReservation: vi.fn(),
  fetchReservations: vi.fn(),
}));

const navigation = vi.hoisted(() => ({
  returnToReservationList: vi.fn(),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({ token: "token", user: auth.user }),
}));

vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => (
    <>{children}</>
  ),
}));

vi.mock("@/hooks/useResourceInvalidation", () => ({
  useResourceInvalidation: () => undefined,
  invalidateFrontendResources: vi.fn(),
}));

vi.mock("@/services/contracts", () => ({
  fetchContracts: services.fetchContracts,
  fetchContract: services.fetchContract,
  createContract: services.createContract,
  updateContract: services.updateContract,
  executeContractLifecycleAction:
    services.executeContractLifecycleAction,
}));

vi.mock("@/services/reservations", () => ({
  fetchReservation: services.fetchReservation,
  fetchReservations: services.fetchReservations,
}));

vi.mock(
  "@/lib/contract-create-context",
  async (importOriginal) => {
    const actual = await importOriginal<
      typeof import("@/lib/contract-create-context")
    >();

    return {
      ...actual,
      returnToReservationList:
        navigation.returnToReservationList,
    };
  }
);

vi.mock("@/components/contracts/ContractFormModal", () => ({
  ContractFormModal: ({
    lockedReservation,
    onClose,
    onCreate,
  }: {
    lockedReservation?: Reservation | null;
    onClose: () => void;
    onCreate?: (payload: ContractFormPayload) => Promise<void>;
  }) => (
    <div>
      <span>
        {lockedReservation
          ? `contract-modal:locked:${lockedReservation.id}`
          : "contract-modal:manual"}
      </span>
      <button type="button" onClick={onClose}>
        close-contract
      </button>
      <button
        type="button"
        onClick={() =>
          void onCreate?.({
            reservation_id: "tampered-reservation",
            total_amount: "650000.00",
          })
        }
      >
        submit-contract
      </button>
    </div>
  ),
}));

vi.mock("@/components/contracts/ContractDetailsModal", () => ({
  ContractDetailsModal: () => null,
}));

vi.mock("@/components/contracts/ContractLifecycleDialog", () => ({
  ContractLifecycleDialog: () => null,
}));

const emptyContracts = {
  data: {
    contracts: {
      data: [],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 20,
        total: 0,
      },
    },
    summary: {
      total: 0,
      draft: 0,
      active: 0,
      completed: 0,
      cancelled: 0,
    },
  },
};

const activeReservations = {
  data: {
    reservations: {
      data: [reservation],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 100,
        total: 1,
      },
    },
    summary: {
      total: 1,
      active: 1,
      cancelled: 0,
      expired: 0,
    },
  },
};

function openContextualUrl(customReturnTo = returnTo): void {
  const query = new URLSearchParams({
    create: "1",
    reservation: reservationId,
    returnTo: customReturnTo,
  });

  window.history.replaceState(
    null,
    "",
    `/contracts/?${query.toString()}`
  );
}

describe("ContractsPage contextual creation", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    auth.user = administrator;
    window.history.replaceState(null, "", "/contracts/");
    services.fetchContracts.mockResolvedValue(emptyContracts);
    services.fetchReservations.mockResolvedValue(activeReservations);
    services.fetchReservation.mockResolvedValue(reservation);
    services.createContract.mockResolvedValue({
      id: "contract-1",
      status: "draft",
    });
  });

  it("fetches the contextual reservation directly and locks it", async () => {
    openContextualUrl();
    render(<ContractsPage />);

    expect(
      await screen.findByText(
        `contract-modal:locked:${reservationId}`
      )
    ).toBeTruthy();
    expect(services.fetchReservation).toHaveBeenCalledWith(
      "token",
      reservationId
    );
    expect(services.fetchReservations).not.toHaveBeenCalled();
  });

  it("preserves the existing manual create flow", async () => {
    render(<ContractsPage />);

    fireEvent.click(
      await screen.findByRole("button", { name: "إنشاء عقد" })
    );

    expect(
      await screen.findByText("contract-modal:manual")
    ).toBeTruthy();
    expect(services.fetchReservations).toHaveBeenCalledWith(
      "token",
      { page: 1, status: "active", per_page: 100 }
    );
    expect(services.fetchReservation).not.toHaveBeenCalled();
  });

  it("returns to a validated reservation list URL on contextual cancel", async () => {
    openContextualUrl();
    render(<ContractsPage />);

    await screen.findByText(
      `contract-modal:locked:${reservationId}`
    );
    fireEvent.click(screen.getByText("close-contract"));

    expect(
      navigation.returnToReservationList
    ).toHaveBeenCalledWith(returnTo);
  });

  it("creates only a draft payload, stays on contracts, and clears contextual state", async () => {
    openContextualUrl();
    render(<ContractsPage />);

    await screen.findByText(
      `contract-modal:locked:${reservationId}`
    );
    fireEvent.click(screen.getByText("submit-contract"));

    await waitFor(() => {
      expect(services.createContract).toHaveBeenCalledWith(
        "token",
        {
          reservation_id: reservationId,
          total_amount: "650000.00",
        }
      );
      expect(
        services.executeContractLifecycleAction
      ).not.toHaveBeenCalled();
      expect(window.location.pathname).toBe("/contracts/");
      expect(window.location.search).toBe("");
      expect(
        screen.getByText("تم إنشاء العقد بنجاح.")
      ).toBeTruthy();
    });
  });

  it("rejects a non-active contextual reservation without breaking the list", async () => {
    services.fetchReservation.mockResolvedValue({
      ...reservation,
      status: "converted",
    });
    openContextualUrl();
    render(<ContractsPage />);

    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toContain(
      "تعذر فتح إنشاء العقد من هذا الحجز"
    );
    expect(
      screen.queryByText(/contract-modal:/)
    ).toBeNull();
    expect(screen.getByText("لا توجد عقود")).toBeTruthy();
    expect(window.location.search).toBe("");
  });

  it("uses the same non-disclosing failure for an unavailable or cross-tenant reservation", async () => {
    services.fetchReservation.mockRejectedValue(
      new Error("الحجز غير موجود في Tenant آخر")
    );
    openContextualUrl();
    render(<ContractsPage />);

    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toContain(
      "تعذر فتح إنشاء العقد من هذا الحجز"
    );
    expect(alert.textContent).not.toContain("Tenant آخر");
    expect(screen.getByText("لا توجد عقود")).toBeTruthy();
  });

  it("rejects contextual creation for a read-only role before loading the reservation", async () => {
    auth.user = {
      ...administrator,
      id: 8,
      role: "accountant",
    };
    openContextualUrl();
    render(<ContractsPage />);

    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toContain(
      "تعذر فتح إنشاء العقد من هذا الحجز"
    );
    expect(services.fetchReservation).not.toHaveBeenCalled();
    expect(
      screen.queryByText(/contract-modal:/)
    ).toBeNull();
  });
});
