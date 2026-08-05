import { CalendarClock, Phone, UserRound } from "lucide-react";

import { LeadStageBadge } from "@/components/leads/LeadStageBadge";
import { leadStages, stageKey } from "@/components/leads/lead-options";
import { Badge } from "@/components/ui/Badge";
import { useTranslation } from "@/hooks/useTranslation";
import { formatDateTime } from "@/lib/date-format";
import type { Lead, LeadStage } from "@/types/lead";

type LeadsPipelineViewProps = {
  leads: Lead[];
  onOpen: (lead: Lead) => void;
};

export function LeadsPipelineView({ leads, onOpen }: LeadsPipelineViewProps) {
  const { t } = useTranslation();
  const grouped = leadStages.reduce<Record<LeadStage, Lead[]>>(
    (result, stage) => {
      result[stage] = leads.filter((lead) => lead.stage === stage);
      return result;
    },
    {
      new: [],
      qualified: [],
      viewing: [],
      negotiation: [],
      won: [],
      lost: [],
    }
  );

  return (
    <div className="overflow-x-auto pb-2" data-testid="leads-pipeline">
      <div className="grid min-w-[1320px] grid-cols-6 gap-4">
        {leadStages.map((stage) => (
          <section
            key={stage}
            className="rounded-2xl border border-[var(--border)] bg-[var(--surface-soft)] p-3"
          >
            <header className="mb-3 flex items-center justify-between gap-2">
              <LeadStageBadge stage={stage} />
              <Badge>{grouped[stage].length}</Badge>
            </header>

            <div className="space-y-3">
              {grouped[stage].length === 0 ? (
                <p className="rounded-xl border border-dashed border-[var(--border)] px-3 py-6 text-center text-xs text-[var(--text-muted)]">
                  {t("crm.pipeline.emptyStage")}
                </p>
              ) : (
                grouped[stage].map((lead) => (
                  <button
                    key={lead.id}
                    type="button"
                    onClick={() => onOpen(lead)}
                    className="block w-full rounded-xl border border-[var(--border)] bg-[var(--surface)] p-3 text-start shadow-[var(--shadow-sm)] transition hover:border-[var(--brand-gold)]"
                    aria-label={`${t(stageKey(stage))}: ${lead.name}`}
                  >
                    <p className="font-bold text-[var(--text-primary)]">
                      {lead.name}
                    </p>
                    <p className="mt-2 flex items-center gap-2 text-xs text-[var(--text-secondary)]" dir="ltr">
                      <Phone size={13} aria-hidden="true" />
                      {lead.phone}
                    </p>
                    <p className="mt-2 flex items-center gap-2 text-xs text-[var(--text-secondary)]">
                      <UserRound size={13} aria-hidden="true" />
                      {lead.assigned_to?.name ?? t("crm.unassigned")}
                    </p>
                    <p className="mt-2 flex items-center gap-2 text-xs text-[var(--text-secondary)]">
                      <CalendarClock size={13} aria-hidden="true" />
                      {lead.next_follow_up_at
                        ? formatDateTime(lead.next_follow_up_at)
                        : t("crm.noFollowUp")}
                    </p>
                  </button>
                ))
              )}
            </div>
          </section>
        ))}
      </div>
    </div>
  );
}
