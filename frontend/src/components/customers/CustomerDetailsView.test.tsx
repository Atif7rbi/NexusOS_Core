import {
  fireEvent,
  render,
  screen,
} from "@testing-library/react";
import {
  describe,
  expect,
  it,
  vi,
} from "vitest";

import { CustomerDetailsView } from "@/components/customers/CustomerDetailsView";
import type { Customer } from "@/types/customer";

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
  notes: "عميل مهتم بشراء وحدة.",
  archived_at: null,
  created_at: "2026-08-05T06:30:00.000Z",
  updated_at: "2026-08-05T07:30:00.000Z",
};

describe("CustomerDetailsView", () => {
  it("renders the full customer record", () => {
    render(
      <CustomerDetailsView
        customer={customer}
        isArabic
        isLoading={false}
        error={null}
        isNotFound={false}
        onBack={vi.fn()}
        onRetry={vi.fn()}
        onEdit={vi.fn()}
      />
    );

    expect(
      screen.getByText("سجل العميل")
    ).toBeTruthy();
    expect(
      screen.getAllByText("محمد العميل")
    ).toHaveLength(2);
    expect(
      screen.getByText("0500000001")
    ).toBeTruthy();
    expect(
      screen.getByText("customer@example.com")
    ).toBeTruthy();
    expect(
      screen.getByText("1010000001")
    ).toBeTruthy();
    expect(
      screen.getByText("عميل مهتم بشراء وحدة.")
    ).toBeTruthy();
  });

  it("emits back and edit actions", () => {
    const onBack = vi.fn();
    const onEdit = vi.fn();

    render(
      <CustomerDetailsView
        customer={customer}
        isArabic
        isLoading={false}
        error={null}
        isNotFound={false}
        onBack={onBack}
        onRetry={vi.fn()}
        onEdit={onEdit}
      />
    );

    fireEvent.click(
      screen.getByText("العودة إلى العملاء")
    );
    fireEvent.click(
      screen.getByText("تعديل")
    );

    expect(onBack).toHaveBeenCalledOnce();
    expect(onEdit).toHaveBeenCalledOnce();
  });

  it("does not expose edit for an archived customer", () => {
    render(
      <CustomerDetailsView
        customer={{
          ...customer,
          status: "archived",
          archived_at:
            "2026-08-05T08:00:00.000Z",
        }}
        isArabic
        isLoading={false}
        error={null}
        isNotFound={false}
        onBack={vi.fn()}
        onRetry={vi.fn()}
        onEdit={vi.fn()}
      />
    );

    expect(
      screen.queryByText("تعديل")
    ).toBeNull();
  });

  it("renders loading not-found and retry states", () => {
    const { rerender } = render(
      <CustomerDetailsView
        customer={null}
        isArabic
        isLoading
        error={null}
        isNotFound={false}
        onBack={vi.fn()}
        onRetry={vi.fn()}
        onEdit={vi.fn()}
      />
    );

    expect(
      screen.getByText(
        "جارٍ تحميل سجل العميل..."
      )
    ).toBeTruthy();

    rerender(
      <CustomerDetailsView
        customer={null}
        isArabic
        isLoading={false}
        error={null}
        isNotFound
        onBack={vi.fn()}
        onRetry={vi.fn()}
        onEdit={vi.fn()}
      />
    );

    expect(
      screen.getByText(
        "سجل العميل غير موجود"
      )
    ).toBeTruthy();

    const onRetry = vi.fn();

    rerender(
      <CustomerDetailsView
        customer={null}
        isArabic
        isLoading={false}
        error="تعذر الاتصال"
        isNotFound={false}
        onBack={vi.fn()}
        onRetry={onRetry}
        onEdit={vi.fn()}
      />
    );

    fireEvent.click(
      screen.getByText("إعادة المحاولة")
    );

    expect(onRetry).toHaveBeenCalledOnce();
  });
});
