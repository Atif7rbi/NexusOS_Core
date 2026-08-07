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

import CustomersPage from "@/app/customers/page";
import { ApiRequestError } from "@/lib/api-error";
import type { Customer } from "@/types/customer";

const services = vi.hoisted(() => ({
  fetchCustomers: vi.fn(),
  fetchCustomer: vi.fn(),
  createCustomer: vi.fn(),
  updateCustomer: vi.fn(),
  archiveCustomer: vi.fn(),
  restoreCustomer: vi.fn(),
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () =>
    new URLSearchParams(window.location.search),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    token: "token",
  }),
}));

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({
    isArabic: true,
  }),
}));

vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({
    children,
  }: {
    children: React.ReactNode;
  }) => <>{children}</>,
}));

vi.mock(
  "@/components/customers/CustomerFormModal",
  () => ({
    CustomerFormModal: () => null,
  })
);

vi.mock(
  "@/components/customers/CustomerArchiveDialog",
  () => ({
    CustomerArchiveDialog: () => null,
  })
);

vi.mock(
  "@/components/customers/CustomerCard",
  () => ({
    CustomerCard: ({
      customer,
      onOpen,
    }: {
      customer: Customer;
      onOpen: () => void;
    }) => (
      <button
        type="button"
        onClick={onOpen}
      >
        {`open:${customer.id}`}
      </button>
    ),
  })
);

vi.mock(
  "@/components/customers/CustomerDetailsView",
  () => ({
    CustomerDetailsView: ({
      customer,
      isLoading,
      isNotFound,
      error,
      onBack,
    }: {
      customer: Customer | null;
      isLoading: boolean;
      isNotFound: boolean;
      error: string | null;
      onBack: () => void;
    }) => (
      <div>
        {isLoading ? (
          <span>record-loading</span>
        ) : null}

        {isNotFound ? (
          <span>record-not-found</span>
        ) : null}

        {error ? (
          <span>{`record-error:${error}`}</span>
        ) : null}

        {customer ? (
          <span>{`record:${customer.id}`}</span>
        ) : null}

        <button
          type="button"
          onClick={onBack}
        >
          close-record
        </button>
      </div>
    ),
  })
);

vi.mock("@/services/customers", () => services);

const customer: Customer = {
  id: "01K00000000000000000000001",
  tenant_id: "01K00000000000000000000002",
  type: "individual",
  category: "buyer",
  status: "customer",
  name: "محمد العميل",
  phone: "0500000001",
  email: "customer@example.com",
  national_id: "1010000001",
  commercial_registration_number: null,
  city: "الرياض",
  address: "حي الياسمين",
  notes: null,
  archived_at: null,
  created_at: "2026-08-05T06:30:00.000Z",
  updated_at: "2026-08-05T06:30:00.000Z",
};

const indexResponse = {
  data: {
    customers: {
      current_page: 1,
      data: [customer],
      from: 1,
      last_page: 1,
      next_page_url: null,
      per_page: 20,
      prev_page_url: null,
      to: 1,
      total: 1,
    },
    summary: {
      total: 1,
      customers: 1,
      leads: 0,
      archived: 0,
    },
  },
};

describe("CustomersPage record query state", () => {
  beforeEach(() => {
    vi.clearAllMocks();

    window.history.replaceState(
      null,
      "",
      "/customers/"
    );

    services.fetchCustomers.mockResolvedValue(
      indexResponse
    );
    services.fetchCustomer.mockResolvedValue(
      customer
    );
  });

  it("opens a customer record while preserving query state", async () => {
    window.history.replaceState(
      null,
      "",
      "/customers/?page=3&status=customer"
    );

    const history = vi.spyOn(
      window.history,
      "pushState"
    );

    render(<CustomersPage />);

    fireEvent.click(
      await screen.findByText(
        `open:${customer.id}`
      )
    );

    expect(history).toHaveBeenLastCalledWith(
      null,
      "",
      `/customers/?page=3&status=customer&customer=${customer.id}`
    );
  });

  it("updates list filters in the URL before opening a customer record", async () => {
    const history = vi.spyOn(
      window.history,
      "pushState"
    );

    render(<CustomersPage />);

    const statusSelect =
      await screen.findByDisplayValue(
        "كل الحالات"
      );

    fireEvent.change(statusSelect, {
      target: {
        value: "customer",
      },
    });

    expect(
      new URLSearchParams(
        window.location.search
      ).get("status")
    ).toBe("customer");

    fireEvent.click(
      await screen.findByText(
        `open:${customer.id}`
      )
    );

    expect(history).toHaveBeenLastCalledWith(
      null,
      "",
      `/customers/?status=customer&customer=${customer.id}`
    );
  });

  it("loads a direct customer record and removes only customer on close", async () => {
    window.history.replaceState(
      null,
      "",
      `/customers/?page=3&status=customer&customer=${customer.id}`
    );

    const history = vi.spyOn(
      window.history,
      "pushState"
    );

    render(<CustomersPage />);

    expect(
      await screen.findByText(
        `record:${customer.id}`
      )
    ).toBeTruthy();

    expect(
      services.fetchCustomer
    ).toHaveBeenCalledWith(
      "token",
      customer.id
    );

    fireEvent.click(
      screen.getByText("close-record")
    );

    expect(history).toHaveBeenLastCalledWith(
      null,
      "",
      "/customers/?page=3&status=customer"
    );
  });

  it("renders not found for an inaccessible customer record", async () => {
    services.fetchCustomer.mockRejectedValue(
      new ApiRequestError(
        "Customer not found.",
        {},
        404
      )
    );

    window.history.replaceState(
      null,
      "",
      "/customers/?customer=missing"
    );

    render(<CustomersPage />);

    await waitFor(() => {
      expect(
        screen.getByText(
          "record-not-found"
        )
      ).toBeTruthy();
    });
  });
});
