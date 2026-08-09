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
  units?: AvailableReservationUnit[];
  isLoadingUnits?: boolean;
  onProjectChange?: ReturnType<typeof vi.fn>;
}) {
  const onSubmit = options?.onSubmit ?? vi.fn();
  const onProjectChange =
    options?.onProjectChange ??
    vi.fn().mockResolvedValue(undefined);
  const props = {
    isOpen: true,
    customers: [customer],
    projects: [project],
    units: options?.units ?? [unit],
    isLoadingInitialOptions: false,
    isLoadingUnits: options?.isLoadingUnits ?? false,
    isSubmitting: false,
    initialCustomerId: options?.contextual
      ? customer.id
      : undefined,
    isCustomerLocked: options?.contextual ?? false,
    onClose: vi.fn(),
    onProjectChange,
    onSubmit,
  };

  const view = render(<ReservationFormModal {...props} />);

  return {
    onSubmit,
    onProjectChange,
    rerender: (
      overrides: Partial<typeof props>
    ): void => {
      view.rerender(
        <ReservationFormModal
          {...props}
          {...overrides}
        />
      );
    },
  };
}

describe("ReservationFormModal", () => {
  it("locks and submits the contextual customer through the existing payload", async () => {
    const { onSubmit } = renderModal({ contextual: true });

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
    const { onSubmit } = renderModal();

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

  it("keeps the normal available-unit selection behavior", async () => {
    const { onProjectChange } = renderModal();

    fireEvent.change(
      screen.getByRole("combobox", { name: "المشروع" }),
      { target: { value: project.id } }
    );

    await waitFor(() => {
      expect(onProjectChange).toHaveBeenCalledWith(project.id);
    });

    const unitSelect = screen.getByRole("combobox", {
      name: "الوحدة المتاحة",
    }) as HTMLSelectElement;

    expect(unitSelect.disabled).toBe(false);
    expect(
      screen.getByRole("option", {
        name: "A-101 — مشروع النخيل",
      })
    ).toBeTruthy();
  });

  it("shows a disabled no-units state without misleading validation", async () => {
    const { onSubmit } = renderModal({ units: [] });

    fireEvent.change(
      screen.getByRole("combobox", { name: "المشروع" }),
      { target: { value: project.id } }
    );

    const status = await screen.findByRole("status");

    expect(status.textContent).toContain(
      "لا توجد وحدات متاحة لهذا المشروع."
    );

    const unitSelect = screen.getByRole("combobox", {
      name: "الوحدة المتاحة",
    }) as HTMLSelectElement;
    const submit = screen.getByRole("button", {
      name: "حفظ الحجز",
    }) as HTMLButtonElement;

    expect(unitSelect.disabled).toBe(true);
    expect(submit.disabled).toBe(true);
    expect(
      screen.getByRole("option", {
        name: "لا توجد وحدات متاحة لهذا المشروع.",
      })
    ).toBeTruthy();
    expect(screen.queryByText("اختر الوحدة.")).toBeNull();
    expect(onSubmit).not.toHaveBeenCalled();
    expect(
      screen
        .getByRole("link", { name: "إضافة وحدة" })
        .getAttribute("href")
    ).toBe("/units/?create=1");
  });

  it("keeps loading separate and disables unit selection and submit", () => {
    const { rerender } = renderModal();

    fireEvent.change(
      screen.getByRole("combobox", { name: "المشروع" }),
      { target: { value: project.id } }
    );
    rerender({ isLoadingUnits: true, units: [] });

    expect(
      screen.getByText("جارٍ تحميل الوحدات المتاحة للمشروع...")
    ).toBeTruthy();
    expect(
      (screen.getByRole("combobox", {
        name: "الوحدة المتاحة",
      }) as HTMLSelectElement).disabled
    ).toBe(true);
    expect(
      (screen.getByRole("button", {
        name: "حفظ الحجز",
      }) as HTMLButtonElement).disabled
    ).toBe(true);
  });

  it("shows API failure separately from the successful empty state", async () => {
    renderModal({
      units: [],
      onProjectChange: vi
        .fn()
        .mockRejectedValue(new Error("API unavailable")),
    });

    fireEvent.change(
      screen.getByRole("combobox", { name: "المشروع" }),
      { target: { value: project.id } }
    );

    expect(
      await screen.findByRole("alert")
    ).toHaveProperty(
      "textContent",
      "تعذر تحميل الوحدات المتاحة."
    );
    expect(
      screen.queryByText("لا توجد وحدات متاحة لهذا المشروع.")
    ).toBeNull();
    expect(
      (screen.getByRole("combobox", {
        name: "الوحدة المتاحة",
      }) as HTMLSelectElement).disabled
    ).toBe(true);
    expect(screen.queryByText("اختر الوحدة.")).toBeNull();
  });
});
