import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { LeadDetailsView } from "@/components/leads/LeadDetailsView";
import { leadFixture, noLeadActions } from "@/test/lead-fixtures";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    isArabic: false,
  }),
}));

const baseProps = {
  isLoading: false,
  error: null,
  isNotFound: false,
  activities: [],
  activitiesLoading: false,
  activitiesError: null,
  isAddingNote: false,
  onBack: vi.fn(),
  onRetry: vi.fn(),
  onRetryActivities: vi.fn(),
  onEdit: vi.fn(),
  onAction: vi.fn(),
  onAddNote: vi.fn(async () => undefined),
};

describe("LeadDetailsView authorization-aware actions", () => {
  it("renders every administrator action returned by allowed_actions", () => {
    const lead = leadFixture({
      assigned_to: {
        id: 4,
        name: "Sales User",
        role: "sales",
        account_status: "active",
        membership_status: "active",
        is_eligible: true,
      },
      allowed_actions: {
        can_edit: true,
        can_claim: false,
        can_assign: true,
        can_move_stage: true,
        can_move_backward: true,
        can_move_to_lost: true,
        can_reopen: true,
        can_add_note: true,
        can_archive: true,
        can_restore: true,
        can_convert: true,
      },
    });

    render(<LeadDetailsView {...baseProps} lead={lead} />);

    [
      "crm.actions.edit",
      "crm.actions.assign",
      "crm.actions.changeStage",
      "crm.actions.moveToLost",
      "crm.actions.reopen",
      "crm.actions.archive",
      "crm.actions.restore",
    ].forEach((label) => expect(screen.getByText(label)).toBeTruthy());
  });

  it("renders claim only for an unassigned open lead", () => {
    const onAction = vi.fn();
    const lead = leadFixture({
      allowed_actions: { ...noLeadActions, can_claim: true },
    });

    render(<LeadDetailsView {...baseProps} lead={lead} onAction={onAction} />);
    fireEvent.click(screen.getByText("crm.actions.claim"));

    expect(onAction).toHaveBeenCalledWith("claim");
    expect(screen.queryByText("crm.actions.edit")).toBeNull();
    expect(screen.queryByText("crm.activity.addNote")).toBeNull();
  });

  it.each(["won", "lost"] as const)("keeps a %s lead read-only", (stage) => {
    render(
      <LeadDetailsView
        {...baseProps}
        lead={leadFixture({ stage, allowed_actions: { ...noLeadActions } })}
      />
    );

    expect(screen.getByText("Test Lead")).toBeTruthy();
    expect(screen.queryByText("crm.actions.edit")).toBeNull();
    expect(screen.queryByText("crm.actions.claim")).toBeNull();
  });

  it("keeps an archived lead read-only and exposes restore only when allowed", () => {
    render(
      <LeadDetailsView
        {...baseProps}
        lead={leadFixture({
          archived_at: "2026-08-02T10:00:00Z",
          allowed_actions: { ...noLeadActions, can_restore: true },
        })}
      />
    );

    expect(screen.getByText("crm.archived")).toBeTruthy();
    expect(screen.getByText("crm.actions.restore")).toBeTruthy();
    expect(screen.queryByText("crm.actions.edit")).toBeNull();
  });

  it("never renders a conversion action", () => {
    render(<LeadDetailsView {...baseProps} lead={leadFixture()} />);
    expect(document.body.textContent?.toLowerCase()).not.toContain("convert");
    expect(document.body.textContent).not.toContain("تحويل");
  });

  it("shows the mapped not-found state and a back action", () => {
    render(<LeadDetailsView {...baseProps} lead={null} isNotFound />);
    expect(screen.getByText(/crm.details.notFoundTitle/)).toBeTruthy();
    fireEvent.click(screen.getByText("crm.details.back"));
    expect(baseProps.onBack).toHaveBeenCalled();
  });
});
