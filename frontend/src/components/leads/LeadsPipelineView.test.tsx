import { fireEvent, render, screen, within } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { LeadsPipelineView } from "@/components/leads/LeadsPipelineView";
import { leadFixture } from "@/test/lead-fixtures";

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock("@/components/leads/LeadStageBadge", () => ({
  LeadStageBadge: ({ stage }: { stage: string }) => <span>{`stage:${stage}`}</span>,
}));

vi.mock("@/lib/date-format", () => ({
  formatDateTime: (value: string) => `formatted:${value}`,
}));

describe("LeadsPipelineView", () => {
  it("renders the six frozen lead stages", () => {
    render(<LeadsPipelineView leads={[]} onOpen={vi.fn()} />);

    ["new", "qualified", "viewing", "negotiation", "won", "lost"].forEach(
      (stage) => {
        expect(screen.getByText(`stage:${stage}`)).toBeTruthy();
      }
    );
  });

  it("groups each lead under its current stage", () => {
    render(
      <LeadsPipelineView
        leads={[
          leadFixture({ id: "lead-new", name: "New Lead", stage: "new" }),
          leadFixture({
            id: "lead-qualified",
            name: "Qualified Lead",
            stage: "qualified",
          }),
        ]}
        onOpen={vi.fn()}
      />
    );

    const newCard = screen.getByRole("button", {
      name: "crm.stage.new: New Lead",
    });
    const qualifiedCard = screen.getByRole("button", {
      name: "crm.stage.qualified: Qualified Lead",
    });

    expect(within(newCard).getByText("New Lead")).toBeTruthy();
    expect(within(qualifiedCard).getByText("Qualified Lead")).toBeTruthy();
  });

  it("shows an empty state for stages without leads", () => {
    render(
      <LeadsPipelineView
        leads={[leadFixture({ stage: "new" })]}
        onOpen={vi.fn()}
      />
    );

    expect(screen.getAllByText("crm.pipeline.emptyStage")).toHaveLength(5);
  });

  it("opens the selected lead from its pipeline card", () => {
    const lead = leadFixture({ id: "pipeline-lead", name: "Pipeline Lead" });
    const onOpen = vi.fn();

    render(<LeadsPipelineView leads={[lead]} onOpen={onOpen} />);
    fireEvent.click(
      screen.getByRole("button", { name: "crm.stage.new: Pipeline Lead" })
    );

    expect(onOpen).toHaveBeenCalledWith(lead);
  });
});
