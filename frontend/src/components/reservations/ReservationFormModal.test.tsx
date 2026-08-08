import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import {
  describe,
  expect,
  it,
  vi,
} from "vitest";

import { ReservationFormModal } from "@/components/reservations/ReservationFormModal";
import type { Customer } from "@/types/customer";
import type { Project } from "@/types/project";
import type { AvailableReservationUnit } from "@/types/reservation";

const customer: Customer = {
  id: "01K00000000000000000000001",
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

const project = {
  id: "01K00000000000000000000002",
  project_number: "PRJ-2026-001",
  name: "مشروع النخيل",
} as Project;

const unit: AvailableReservationUnit = {
  id: "01K00000000000000000000003",
  unit_number: "A-101",
  project_name: "مشروع النخيل",
};

function renderModal(options?: {
  contextual?: boolean;
  onSubmit?: ReturnType<typeof vi.fn>;
}) {
  const onSubmit = options?.onSubmit ?? vi.fn();

  render(
    <ReservationFormModal
      isOpen
      customers={[customer]}
      projects={[project]}
      units={[unit]}
      isLoadingInitialOptions={false}
      isLoadingUnits={false}
      isSubmitting={false}
      initialCustomerId={
        options?.contextual
          ? customer.id
          : undefined
      }
      isCustomerLocked={
        options?.contextual ?? false
      }
      onClose={vi.fn()}
      onProjectChange={vi.fn().mockResolvedValue(undefined)}
      onSubmit={onSubmit}
    />
  );

  return onSubmit;
}

describe("ReservationFormModal", () => {
  it("locks and submits the contextual customer through the existing payload", async () => {
    const onSubmit = renderModal({ contextual: true });

    expect(
      screen.getByText("محمد العميل — 0500000001")
    ).toBeTruthy();
    expect(
      screen.queryByRole("combobox", {
        name: "العميل",
      })
    ).toBeNull();
    expect(
      document.querySelector<HTMLInputElement>(
        'input[name="customer_id"]'
      )?.value
    ).toBe(customer.id);

    fireEvent.change(
      screen.getByRole("combobox", {
        name: "المشروع",
      }),
      {
        target: { value: project.id },
      }
    );
    fireEvent.change(
      screen.getByRole("combobox", {
        name: "الوحدة المتاحة",
      }),
      {
        target: { value: unit.id },
      }
    );
    fireEvent.click(
      screen.getByRole("button", {
        name: "حفظ الحجز",
      })
    );

    await waitFor(() => {
      expect(onSubmit).toHaveBeenCalledWith(
        expect.objectContaining({
          customer_id: customer.id,
          unit_id: unit.id,
        })
      );
    });
  });

  it("keeps the customer editable and required in the manual flow", () => {
    const onSubmit = renderModal();

    expect(
      screen.getByRole("combobox", {
        name: "العميل",
      })
    ).toBeTruthy();

    fireEvent.click(
      screen.getByRole("button", {
        name: "حفظ الحجز",
      })
    );

    expect(screen.getByText("اختر العميل.")).toBeTruthy();
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
