import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  LeadActionDialogs,
  type LeadDialogPayload,
} from "@/components/leads/LeadActionDialogs";
import { leadFixture } from "@/test/lead-fixtures";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock("@/components/ui/ConfirmationDialog", () => ({
  ConfirmationDialog: ({
    title,
    description,
    confirmLabel,
    children,
    onConfirm,
    onCancel,
  }: {
    title: string;
    description?: string;
    confirmLabel: string;
    children?: React.ReactNode;
    onConfirm: () => void;
    onCancel: () => void;
  }) => (
    <div role="dialog">
      <h1>{title}</h1>
      {description ? <p>{description}</p> : null}
      {children}
      <button onClick={onConfirm}>{confirmLabel}</button>
      <button onClick={onCancel}>cancel</button>
    </div>
  ),
}));

vi.mock("@/components/ui/Select", () => ({
  Select: ({
    name,
    label,
    value,
    options,
    onChange,
  }: {
    name: string;
    label: string;
    value: string;
    options: Array<{ value: string; label: string }>;
    onChange: React.ChangeEventHandler<HTMLSelectElement>;
  }) => (
    <label>
      {label}
      <select name={name} value={value} onChange={onChange}>
        {options.map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </select>
    </label>
  ),
}));

const renderDialog = (
  conversionConflict: { id: string; status: string; name?: string } | null,
  onConfirm = vi.fn<(payload: LeadDialogPayload) => void>(),
) => {
  render(
    <LeadActionDialogs
      action="convert"
      lead={leadFixture()}
      assignees={[]}
      isProcessing={false}
      error={null}
      onClose={vi.fn()}
      onConfirm={onConfirm}
      conversionConflict={conversionConflict}
    />
  );

  return onConfirm;
};

describe("Lead conversion dialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("submits create_new with the selected type and category", () => {
    const onConfirm = renderDialog(null);

    fireEvent.change(screen.getByLabelText("crm.form.customerType"), {
      target: { value: "company" },
    });
    fireEvent.change(screen.getByLabelText("crm.form.customerCategory"), {
      target: { value: "investor" },
    });
    fireEvent.click(screen.getByText("crm.actions.convert"));

    expect(onConfirm).toHaveBeenCalledWith({
      action: "convert",
      intent: "create_new",
      type: "company",
      category: "investor",
    });
  });

  it.each([
    ["customer", "crm.dialog.convertMatchStatus.customer"],
    ["inactive", "crm.dialog.convertMatchStatus.inactive"],
    ["lead", "crm.dialog.convertMatchStatus.lead"],
  ])("requires explicit linking for a %s match", (status, statusKey) => {
    const onConfirm = renderDialog({
      id: "01MATCHEDCUSTOMER0000000000",
      status,
      name: "Matched Customer",
    });

    expect(screen.getByText("Matched Customer")).toBeTruthy();
    expect(screen.getByText(statusKey)).toBeTruthy();
    fireEvent.click(screen.getByText("crm.dialog.convertLinkConfirm"));

    expect(onConfirm).toHaveBeenCalledWith({
      action: "convert",
      intent: "link_existing",
      existingCustomerId: "01MATCHEDCUSTOMER0000000000",
    });
  });

  it("blocks archived matching customers and shows the customers CTA", () => {
    const onConfirm = renderDialog({
      id: "01ARCHIVEDCUSTOMER000000000",
      status: "archived",
      name: "Archived Customer",
    });

    expect(screen.getByText("crm.dialog.convertArchivedBlocked")).toBeTruthy();
    expect(screen.getByText("crm.dialog.convertViewCustomers")).toBeTruthy();
    expect(screen.queryByText("crm.dialog.convertLinkConfirm")).toBeNull();
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
