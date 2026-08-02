import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { LeadDuplicateDialog } from "@/components/leads/LeadDuplicateDialog";
import { LeadFormDialog } from "@/components/leads/LeadFormDialog";
import type { CreateLeadPayload, UpdateLeadPayload } from "@/types/lead";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

const baseProps = {
  isOpen: true,
  lead: null,
  token: "token",
  projects: [],
  assignees: [],
  canChooseAssignee: false,
  isSubmitting: false,
  onClose: vi.fn(),
  onSubmit: vi.fn<(payload: CreateLeadPayload | UpdateLeadPayload) => Promise<void>>(async () => undefined),
};

describe("LeadFormDialog", () => {
  it.each([
    ["Arabic-Indic", "٠٥٠١٢٣٤٥٦٧"],
    ["Persian", "۰۵۰۱۲۳۴۵۶۷"],
  ])("normalizes %s digits and submits canonical phone", async (_label, phone) => {
    const onSubmit = vi.fn<(payload: CreateLeadPayload | UpdateLeadPayload) => Promise<void>>(async () => undefined);
    render(<LeadFormDialog {...baseProps} onSubmit={onSubmit} />);

    fireEvent.change(screen.getByLabelText("crm.form.name"), {
      target: { value: "New Lead" },
    });
    fireEvent.change(screen.getByLabelText("crm.form.phone"), {
      target: { value: phone },
    });
    fireEvent.change(screen.getByLabelText("crm.form.source"), {
      target: { value: "website" },
    });
    fireEvent.submit(screen.getByLabelText("crm.form.name").closest("form")!);

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    const payload = onSubmit.mock.calls[0]?.[0];
    expect(payload).toMatchObject({
      name: "New Lead",
      phone: "0501234567",
      source: "website",
    });
    expect(payload).not.toHaveProperty("normalized_phone");
  });

  it("displays client-side errors and does not submit invalid phone", async () => {
    const onSubmit = vi.fn<(payload: CreateLeadPayload | UpdateLeadPayload) => Promise<void>>(async () => undefined);
    render(<LeadFormDialog {...baseProps} onSubmit={onSubmit} />);

    fireEvent.change(screen.getByLabelText("crm.form.name"), {
      target: { value: "New Lead" },
    });
    fireEvent.change(screen.getByLabelText("crm.form.phone"), {
      target: { value: "+966501234567" },
    });
    fireEvent.change(screen.getByLabelText("crm.form.source"), {
      target: { value: "website" },
    });
    fireEvent.submit(screen.getByLabelText("crm.form.name").closest("form")!);

    expect(await screen.findByText("crm.form.phoneInvalid")).toBeTruthy();
    expect(onSubmit).not.toHaveBeenCalled();
  });
});

describe("LeadDuplicateDialog", () => {
  it("renders only API matches and confirms or cancels explicitly", () => {
    const onConfirm = vi.fn();
    const onCancel = vi.fn();
    render(
      <LeadDuplicateDialog
        isOpen
        matches={[
          {
            id: "api-visible",
            name: "API Visible Match",
            stage: "qualified",
            assigned_to: { id: 2, name: "Assigned User" },
            archived: false,
            updated_at: null,
          },
        ]}
        isProcessing={false}
        onConfirm={onConfirm}
        onCancel={onCancel}
      />
    );

    expect(screen.getByText("API Visible Match")).toBeTruthy();
    expect(document.body.textContent).not.toContain("Hidden Match");
    fireEvent.click(screen.getByText("crm.duplicate.confirm"));
    fireEvent.click(screen.getByText("crm.duplicate.cancel"));
    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onCancel).toHaveBeenCalledTimes(1);
  });
});
