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
import type {
  Reservation,
  ReservationFormPayload,
} from "@/types/reservation";

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

const activeReservation: Reservation = {
  id: "01K00000000000000000000009",
  tenant_id: "tenant-1",
  unit_id: unitId,
  customer_id: customerId,
  status: "active",
  reserved_at: "2026-08-05T06:30:00.000Z",
  expires_at: "2026-08-10T10:00:00.000Z",
  notes: null,
  cancellation_reason: null,
  cancelled_at: null,
  cancelled_by: null,
  created_by: 1,
  updated_by: 1,
  created_at: "2026-08-05T06:30:00.000Z",
  updated_at: "2026-08-05T06:30:00.000Z",
  customer,
  unit: {
    id: unitId,
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

const activeProject = {
  id: "project-1",
  project_number: "PRJ-2026-001",
  project_number_year: 2026,
  project_sequence_number: 1,
  name: "مشروع النخيل",
  description: null,
  project_type: "residential",
  status: "active",
  country_code: "SA",
  city: "الرياض",
  district: null,
  address_line: null,
  currency: "SAR",
  estimated_budget: null,
  planned_start_date: null,
  planned_end_date: null,
  actual_start_date: null,
  actual_end_date: null,
  project_manager_id: 2,
  archived_at: null,
  data_origin: "user",
  external_reference: null,
  legacy_reference: null,
  created_by: 1,
  updated_by: 1,
  created_at: "",
  updated_at: "",
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

  it("exposes contextual contract creation from an authorized active reservation", async () => {
    services.fetchProjects.mockResolvedValue({
      data: {
        data: [activeProject],
      },
    });
    services.fetchReservations.mockImplementation(
      (_token: string, params: { page?: number }) =>
        Promise.resolve({
          data: {
            reservations: {
              data: [activeReservation],
              meta: {
                current_page: params.page ?? 1,
                last_page: 3,
                per_page: 20,
                total: 41,
              },
            },
            summary: {
              total: 41,
              active: 41,
              cancelled: 0,
              expired: 0,
            },
          },
        })
    );
    services.fetchReservation.mockResolvedValue(activeReservation);

    render(<ReservationsPage />);

    fireEvent.change(
      screen.getByPlaceholderText(
        "البحث برقم الوحدة أو اسم العميل"
      ),
      { target: { value: "محمد" } }
    );

    const projectOption = await screen.findByRole("option", {
      name: /مشروع النخيل/,
    });
    fireEvent.change(projectOption.closest("select")!, {
      target: { value: activeProject.id },
    });
    fireEvent.change(screen.getByDisplayValue("كل الحالات"), {
      target: { value: "active" },
    });

    await waitFor(() => {
      expect(services.fetchReservations).toHaveBeenCalledWith(
        "token",
        expect.objectContaining({
          page: 1,
          search: "محمد",
          status: "active",
          project_id: activeProject.id,
        })
      );
    });

    const nextButton = screen.getByRole("button", {
      name: "التالي",
    });
    await waitFor(() => {
      expect(nextButton.hasAttribute("disabled")).toBe(false);
    });
    fireEvent.click(nextButton);
    await waitFor(() => {
      expect(services.fetchReservations).toHaveBeenCalledWith(
        "token",
        expect.objectContaining({ page: 2 })
      );
    });
    await waitFor(() => {
      expect(nextButton.hasAttribute("disabled")).toBe(false);
    });
    fireEvent.click(nextButton);
    await waitFor(() => {
      expect(services.fetchReservations).toHaveBeenCalledWith(
        "token",
        expect.objectContaining({ page: 3 })
      );
    });

    fireEvent.click(
      screen.getByRole("button", {
        name: "عرض التفاصيل",
      })
    );

    const link = await screen.findByRole("link", {
      name: "إنشاء عقد",
    });
    const target = new URL(
      link.getAttribute("href") ?? "",
      "https://nexusos.test"
    );

    expect(target.pathname).toBe("/contracts/");
    expect(target.searchParams.get("create")).toBe("1");
    expect(target.searchParams.get("reservation")).toBe(
      activeReservation.id
    );
    expect(target.searchParams.get("returnTo")).toBe(
      "/reservations/?page=3&search=%D9%85%D8%AD%D9%85%D8%AF&status=active&project_id=project-1"
    );
  });
});
