import { ArrowUpLeft, Clock3, Mail, Phone } from "lucide-react";

import { LeadStageBadge } from "@/components/leads/LeadStageBadge";
import { sourceKey } from "@/components/leads/lead-options";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/ui/crud/DataTable";
import { useTranslation } from "@/hooks/useTranslation";
import { formatDateTime } from "@/lib/date-format";
import type { Lead } from "@/types/lead";

type LeadsTableProps = {
  leads: Lead[];
  now: number;
  onOpen: (lead: Lead) => void;
};

function FollowUp({ lead, now }: { lead: Lead; now: number }) {
  const { t } = useTranslation();

  if (!lead.next_follow_up_at) {
    return <span className="text-[var(--text-muted)]">{t("crm.noFollowUp")}</span>;
  }

  const overdue = new Date(lead.next_follow_up_at).getTime() < now;

  return (
    <span className={overdue ? "font-semibold text-[var(--danger)]" : ""}>
      {formatDateTime(lead.next_follow_up_at)}
      {overdue ? (
        <span className="ms-2 inline-flex items-center gap-1">
          <Clock3 size={13} aria-hidden="true" />
          {t("crm.overdue")}
        </span>
      ) : null}
    </span>
  );
}

export function LeadsTable({ leads, now, onOpen }: LeadsTableProps) {
  const { t } = useTranslation();

  return (
    <>
      <div className="hidden lg:block">
        <DataTable minWidth="1120px">
          <thead>
            <tr className="border-b border-[var(--border)] text-xs font-bold text-[var(--text-secondary)]">
              {[
                "crm.table.lead",
                "crm.table.stage",
                "crm.table.source",
                "crm.table.project",
                "crm.table.assignee",
                "crm.table.followUp",
                "crm.table.updated",
                "crm.table.actions",
              ].map((key) => (
                <th key={key} scope="col" className="px-4 py-3 text-start">
                  {t(key as Parameters<typeof t>[0])}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {leads.map((lead) => (
              <tr
                key={lead.id}
                className="border-b border-[var(--border)] last:border-0 hover:bg-[var(--surface-soft)]"
              >
                <td className="px-4 py-4 align-top">
                  <button
                    type="button"
                    onClick={() => onOpen(lead)}
                    className="text-start font-bold text-[var(--text-primary)] hover:text-[var(--brand-primary)]"
                  >
                    {lead.name}
                  </button>
                  <div className="mt-2 flex flex-col gap-1 text-xs text-[var(--text-secondary)]">
                    <span className="inline-flex items-center gap-1.5" dir="ltr">
                      <Phone size={13} aria-hidden="true" />
                      {lead.phone}
                    </span>
                    {lead.email ? (
                      <span className="inline-flex items-center gap-1.5" dir="ltr">
                        <Mail size={13} aria-hidden="true" />
                        {lead.email}
                      </span>
                    ) : null}
                  </div>
                </td>
                <td className="px-4 py-4 align-top">
                  <div className="flex flex-col items-start gap-2">
                    <LeadStageBadge stage={lead.stage} />
                    {lead.archived_at ? (
                      <Badge variant="neutral">{t("crm.archived")}</Badge>
                    ) : null}
                  </div>
                </td>
                <td className="px-4 py-4 align-top text-sm text-[var(--text-secondary)]">
                  {t(sourceKey(lead.source))}
                </td>
                <td className="px-4 py-4 align-top text-sm text-[var(--text-secondary)]">
                  <p>{lead.project?.name ?? t("crm.noProject")}</p>
                  {lead.unit ? (
                    <p className="mt-1 text-xs text-[var(--text-muted)]">
                      {lead.unit.unit_number}
                    </p>
                  ) : null}
                </td>
                <td className="px-4 py-4 align-top text-sm text-[var(--text-secondary)]">
                  {lead.assigned_to?.name ?? t("crm.unassigned")}
                </td>
                <td className="px-4 py-4 align-top text-xs text-[var(--text-secondary)]">
                  <FollowUp lead={lead} now={now} />
                </td>
                <td className="px-4 py-4 align-top text-xs text-[var(--text-secondary)]">
                  {formatDateTime(lead.updated_at)}
                </td>
                <td className="px-4 py-4 align-top">
                  <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    leadingIcon={<ArrowUpLeft size={15} />}
                    onClick={() => onOpen(lead)}
                  >
                    {t("crm.table.open")}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      </div>

      <div className="grid gap-4 lg:hidden">
        {leads.map((lead) => (
          <article
            key={lead.id}
            className="rounded-2xl border border-[var(--border)] bg-[var(--surface-soft)] p-4"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <h3 className="truncate font-bold text-[var(--text-primary)]">
                  {lead.name}
                </h3>
                <p className="mt-1 text-xs text-[var(--text-secondary)]" dir="ltr">
                  {lead.phone}
                </p>
              </div>
              <LeadStageBadge stage={lead.stage} />
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt className="text-[var(--text-muted)]">{t("crm.table.project")}</dt>
                <dd className="mt-1 font-semibold text-[var(--text-secondary)]">
                  {lead.project?.name ?? t("crm.noProject")}
                </dd>
              </div>
              <div>
                <dt className="text-[var(--text-muted)]">{t("crm.table.assignee")}</dt>
                <dd className="mt-1 font-semibold text-[var(--text-secondary)]">
                  {lead.assigned_to?.name ?? t("crm.unassigned")}
                </dd>
              </div>
              <div className="col-span-2">
                <dt className="text-[var(--text-muted)]">{t("crm.table.followUp")}</dt>
                <dd className="mt-1 text-[var(--text-secondary)]">
                  <FollowUp lead={lead} now={now} />
                </dd>
              </div>
            </dl>

            <Button
              type="button"
              size="sm"
              variant="secondary"
              fullWidth
              className="mt-4"
              onClick={() => onOpen(lead)}
            >
              {t("crm.table.open")}
            </Button>
          </article>
        ))}
      </div>
    </>
  );
}
