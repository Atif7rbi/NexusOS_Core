import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { ContractFormModal } from "@/components/contracts/ContractFormModal";
import type { Reservation } from "@/types/reservation";

const reservation: Reservation = {
  id: "01K00000000000000000000001",
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
  created_by: 7,
  updated_by: 7,
  created_at: "2026-08-14T08:00:00.000Z",
  updated_at: "2026-08-14T08:00:00.000Z",
  customer: {
    id: "customer-1",
    type: "individual",
    category: "buyer",
    status: "customer",
    name: "محمد العميل",
    phone: "0500000000",
    email: null,
    national_id: null,
    commercial_registration_number: null,
    city: null,
    address: null,
    notes: null,
    archived_at: null,
    created_by: 7,
    created_at: "",
    updated_at: "",
  },
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
      project_manager_id: 9,
    },
  },
};

describe("ContractFormModal", () => {
  it("locks and submits the directly loaded contextual reservation", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined);

    render(
      <ContractFormModal
        isOpen
        lockedReservation={reservation}
        reservations={[reservation]}
        isSubmitting={false}
        onClose={vi.fn()}
        onCreate={onCreate}
      />
    );

    expect(
      screen.getByRole("region", { name: "الحجز المحدد" })
    ).toBeTruthy();
    expect(screen.getByText("محمد العميل")).toBeTruthy();
    expect(screen.getByText(/مشروع النخيل/)).toBeTruthy();
    expect(screen.getByText(/الوحدة A-101/)).toBeTruthy();
    expect(screen.queryByRole("combobox")).toBeNull();

    fireEvent.change(screen.getByLabelText("قيمة العقد"), {
      target: { value: "650000.00" },
    });
    fireEvent.click(
      screen.getByRole("button", { name: "إنشاء العقد" })
    );

    await waitFor(() => {
      expect(onCreate).toHaveBeenCalledWith({
        reservation_id: reservation.id,
        total_amount: "650000.00",
      });
    });
  });

  it("preserves manual reservation search and selection", async () => {
    const onCreate = vi.fn().mockResolvedValue(undefined);

    render(
      <ContractFormModal
        isOpen
        reservations={[reservation]}
        isSubmitting={false}
        onClose={vi.fn()}
        onCreate={onCreate}
      />
    );

    expect(
      screen.getByRole("combobox", { name: "الحجز النشط" })
    ).toBeTruthy();
    fireEvent.click(
      screen.getByRole("option", { name: /محمد العميل/ })
    );
    fireEvent.change(screen.getByLabelText("قيمة العقد"), {
      target: { value: "650000" },
    });
    fireEvent.click(
      screen.getByRole("button", { name: "إنشاء العقد" })
    );

    await waitFor(() => {
      expect(onCreate).toHaveBeenCalledWith({
        reservation_id: reservation.id,
        total_amount: "650000",
      });
    });
  });
});
