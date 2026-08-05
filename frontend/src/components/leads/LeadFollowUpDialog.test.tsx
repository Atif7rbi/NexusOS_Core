import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  LeadFollowUpDialog,
  type LeadFollowUpDialogAction,
  type LeadFollowUpDialogPayload,
} from "@/components/leads/LeadFollowUpDialog";
import { leadFixture } from "@/test/lead-fixtures";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock("@/components/ui/ConfirmationDialog", () => ({
  ConfirmationDialog: ({
    title,
    confirmLabel,
    children,
    error,
    onConfirm,
    onCancel,
  }: {
    title: string;
    confirmLabel: string;
    children?: React.ReactNode;
    error?: string | null;
    onConfirm: () => void;
    onCancel: () => void;
  }) => (
    <div role="dialog">
      <h1>{title}</h1>
      {error ? <p role="alert">{error}</p> : null}
      {children}
      <button onClick={onConfirm}>{confirmLabel}</button>
      <button onClick={onCancel}>cancel</button>
    </div>
  ),
}));

vi.mock("@/components/ui/Input", () => ({
  Input: ({
    name,
    label,
    value,
    onChange,
  }: {
    name: string;
    label: string;
    value: string;
    onChange: React.ChangeEventHandler<HTMLInputElement>;
  }) => (
    <label>
      {label}
      <input name={name} value={value} onChange={onChange} />
    </label>
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
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  ),
}));

function renderDialog(
  action: LeadFollowUpDialogAction,
  overrides = {},
  onConfirm = vi.fn<(payload: LeadFollowUpDialogPayload) => void>()
) {
  render(
    <LeadFollowUpDialog
      action={action}
      lead={leadFixture(overrides)}
      isProcessing={false}
      error={null}
      onClose={vi.fn()}
      onConfirm={onConfirm}
    />
  );

  return onConfirm;
}

describe("LeadFollowUpDialog", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("submits a schedule payload with an ISO timestamp", () => {
    const onConfirm = renderDialog("schedule_follow_up");
    const localDateTime = "2026-08-05T10:30";

    fireEvent.change(screen.getByLabelText("crm.followUp.date"), {
      target: { value: localDateTime },
    });
    fireEvent.change(screen.getByLabelText("crm.followUp.actionType"), {
      target: { value: "meeting" },
    });
    fireEvent.change(screen.getByLabelText("crm.followUp.actionNote"), {
      target: { value: "Discuss financing" },
    });
    fireEvent.click(screen.getByText("crm.followUp.schedule"));

    expect(onConfirm).toHaveBeenCalledWith({
      action: "schedule_follow_up",
      nextFollowUpAt: new Date(
        `${localDateTime}:00+03:00`
      ).toISOString(),
      nextActionType: "meeting",
      nextActionNote: "Discuss financing",
    });
  });

  it("prefills reschedule fields from the current follow-up", () => {
    renderDialog("reschedule_follow_up", {
      next_follow_up_at: "2026-08-05T07:30:00.000000Z",
      next_action_type: "whatsapp",
      next_action_note: "Send location",
    });

    expect(
      (screen.getByLabelText("crm.followUp.date") as HTMLInputElement).value
    ).not.toBe("");
    expect(
      (screen.getByLabelText("crm.followUp.actionType") as HTMLSelectElement).value
    ).toBe("whatsapp");
    expect(
      (screen.getByLabelText("crm.followUp.actionNote") as HTMLTextAreaElement).value
    ).toBe("Send location");
  });

  it("requires a follow-up date for write actions", () => {
    const onConfirm = renderDialog("schedule_follow_up");

    fireEvent.click(screen.getByText("crm.followUp.schedule"));

    expect(screen.getByRole("alert").textContent).toBe(
      "crm.followUp.dateRequired"
    );
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("requires action details when Other is selected", () => {
    const onConfirm = renderDialog("schedule_follow_up");

    fireEvent.change(screen.getByLabelText("crm.followUp.date"), {
      target: { value: "2026-08-05T10:30" },
    });
    fireEvent.change(screen.getByLabelText("crm.followUp.actionType"), {
      target: { value: "other" },
    });
    fireEvent.click(screen.getByText("crm.followUp.schedule"));

    expect(screen.getByRole("alert").textContent).toBe(
      "crm.followUp.otherNoteRequired"
    );
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("submits an optional completion note", () => {
    const onConfirm = renderDialog("complete_follow_up", {
      next_follow_up_at: "2026-08-05T07:30:00.000000Z",
      next_action_type: "call",
    });

    fireEvent.change(screen.getByLabelText("crm.followUp.closeNote"), {
      target: { value: "Customer answered" },
    });
    fireEvent.click(screen.getByText("crm.followUp.complete"));

    expect(onConfirm).toHaveBeenCalledWith({
      action: "complete_follow_up",
      note: "Customer answered",
    });
  });

  it("submits a null cancellation note when left blank", () => {
    const onConfirm = renderDialog("cancel_follow_up", {
      next_follow_up_at: "2026-08-05T07:30:00.000000Z",
      next_action_type: "call",
    });

    fireEvent.click(screen.getByText("crm.followUp.cancel"));

    expect(onConfirm).toHaveBeenCalledWith({
      action: "cancel_follow_up",
      note: null,
    });
  });
});
