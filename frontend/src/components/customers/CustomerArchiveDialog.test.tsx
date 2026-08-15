import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { CustomerArchiveDialog } from "@/components/customers/CustomerArchiveDialog";
import { ApiRequestError } from "@/lib/api-error";
import type { Customer } from "@/types/customer";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));

const customer = {
  id: "customer-1",
  name: "عميل الاختبار",
} as Customer;

describe("CustomerArchiveDialog", () => {
  it("shows the canonical command failure message", async () => {
    const onConfirm = vi.fn().mockRejectedValue(
      new ApiRequestError(
        "لا يمكن أرشفة العميل لوجود ارتباطات نشطة.",
        {},
        409,
        "state_conflict"
      )
    );

    render(
      <CustomerArchiveDialog
        customer={customer}
        action="archive"
        isProcessing={false}
        onCancel={vi.fn()}
        onConfirm={onConfirm}
      />
    );

    fireEvent.click(
      screen.getByRole("button", { name: "أرشفة العميل" })
    );

    expect(
      await screen.findByText(
        "لا يمكن أرشفة العميل لوجود ارتباطات نشطة."
      )
    ).toBeTruthy();
  });
});
