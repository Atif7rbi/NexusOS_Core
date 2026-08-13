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

import ReservationsPage from "@/app/reservations/page";
import type { Customer } from "@/types/customer";
import type { ReservationFormPayload } from "@/types/reservation";

const customerId = "01K00000000000000000000001";
const unitId = "01K00000000000000000000003";
const returnTo =
  `/customers/?page=3&status=customer&customer=${customerId}`;

const customer: Customer = {
  id: customerId,
  type: "individual",
  category: "buyer",
  status: "customer",
  name: "محمد العميل",
  phone: "0500000001",
  email: null,
  national_id: null,
  commercial_registration_number: null,
  city: null,
  address: null,
  notes: null,
  archived_at: null,
  created_at: "2026-08-05T06:30:00.000Z",
  updated_at: "2026-08-05T06:30:00.000Z",
};

const services = vi.hoisted(() => ({
  fetchReservations: vi.fn(),
  fetchReservation: vi.fn(),
  createReservation: vi.fn(),
  updateReservation: vi.fn(),
  cancelReservation: vi.fn(),
  fetchAvailableReservationUnits: vi.fn(),
  fetchProjects: vi.fn(),
  fetchCustomers: vi.fn(),
  fetchCustomer: vi.fn(),
}));

const navigation = vi.hoisted(() => ({
  returnToCustomerRecord: vi.fn(),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({ token: "token", user: { id: 1, role: "administrator" } }),
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

vi.mock("@/services/projects", () => ({
  fetchProjects: services.fetchProjects,
}));

vi.mock("@/services/customers", () => ({
  fetchCustomers: services.fetchCustomers,
  fetchCustomer: services.fetchCustomer,
}));

vi.mock("@/services/reservations", () => ({
  fetchReservations: services.fetchReservations,
  fetchReservation: services.fetchReservation,
  createReservation: services.createReservation,
  updateReservation: services.updateReservation,
  cancelReservation: services.cancelReservation,
  fetchAvailableReservationUnits:
    services.fetchAvailableReservationUnits,
}));

vi.mock(
  "@/lib/reservation-create-context",
  async (importOriginal) => {
    const actual = await importOriginal<
      typeof import("@/lib/reservation-create-context")
    >();

    return {
      ...actual,
      returnToCustomerRecord:
        navigation.returnToCustomerRecord,
    };
  }
);

vi.mock(
  "@/components/reservations/ReservationFormModal",
  () => ({
    ReservationFormModal: ({
      customers,
      initialCustomerId,
      isCustomerLocked,
      onClose,
      onSubmit,
    }: {
      customers: Customer[];
      initialCustomerId?: string;
      isCustomerLocked?: boolean;
      onClose: () => void;
      onSubmit: (
        payload: ReservationFormPayload
      ) => Promise<void>;
    }) => (
      <div>
        <span>
          {`create-modal:${isCustomerLocked ? "locked" : "manual"}:${initialCustomerId ?? ""}:${customers[0]?.id ?? ""}`}
        </span>
        <button type="button" onClick={onClose}>
          close-create
        </button>
        <button
          type="button"
          onClick={() =>
            void onSubmit({
              customer_id: initialCustomerId ?? customer.id,
              unit_id: unitId,
              expires_at: "2026-08-10T10:00",
              notes: null,
            })
          }
        >
          submit-create
        </button>
      </div>
    ),
  })
);

const emptyReservations = {
  data: {
    reservations: {
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
      active: 0,
      cancelled: 0,
      expired: 0,
    },
  },
};

const projectsResponse = {
  data: {
    data: [],
  },
};

const customersResponse = {
  data: {
    customers: {
      data: [customer],
    },
  },
};

describe("ReservationsPage contextual creation", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(
      null,
      "",
      "/reservations/"
    );

    services.fetchReservations.mockResolvedValue(
      emptyReservations
    );
    services.fetchProjects.mockResolvedValue(
      projectsResponse
    );
    services.fetchCustomers.mockResolvedValue(
      customersResponse
    );
    services.fetchCustomer.mockResolvedValue({
      customer,
      business_context: {
        summary: {
          reservations_total: 0,
          contracts_total: 0,
        },
        journeys: [],
      },
    });
    services.createReservation.mockResolvedValue({});
  });

  function openContextualUrl(): void {
    const query = new URLSearchParams({
      create: "1",
      customer: customerId,
      returnTo,
    });

    window.history.replaceState(
      null,
      "",
      `/reservations/?${query.toString()}`
    );
  }

  it("fetches and locks the contextual customer directly", async () => {
    openContextualUrl();
    render(<ReservationsPage />);

    expect(
      await screen.findByText(
        `create-modal:locked:${customerId}:${customerId}`
      )
    ).toBeTruthy();
    expect(services.fetchCustomer).toHaveBeenCalledWith(
      "token",
      customerId
    );
    expect(services.fetchCustomers).not.toHaveBeenCalled();

    fireEvent.click(screen.getByText("close-create"));

    expect(
      navigation.returnToCustomerRecord
    ).toHaveBeenCalledWith(returnTo);
  });

  it("submits the locked customer and returns to the refreshed record", async () => {
    openContextualUrl();
    render(<ReservationsPage />);

    await screen.findByText(
      `create-modal:locked:${customerId}:${customerId}`
    );
    fireEvent.click(screen.getByText("submit-create"));

    await waitFor(() => {
      expect(
        services.createReservation
      ).toHaveBeenCalledWith(
        "token",
        expect.objectContaining({
          customer_id: customerId,
          unit_id: unitId,
        })
      );
      expect(
        navigation.returnToCustomerRecord
      ).toHaveBeenCalledWith(returnTo);
    });
  });

  it("keeps the existing manual customer selection flow", async () => {
    render(<ReservationsPage />);

    fireEvent.click(
      await screen.findByRole("button", {
        name: "إضافة حجز",
      })
    );

    expect(
      await screen.findByText(
        `create-modal:manual::${customerId}`
      )
    ).toBeTruthy();
    expect(services.fetchCustomers).toHaveBeenCalledWith(
      "token",
      { per_page: 100 }
    );
  });
});
