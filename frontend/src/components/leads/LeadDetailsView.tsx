"use client";

import {
  Archive,
  ArrowLeft,
  ArrowRight,
  CalendarClock,
  Mail,
  MapPin,
  Pencil,
  Phone,
  RefreshCw,
  RotateCcw,
  Route,
  UserCheck,
  UserRoundCog,
  UserX,
} from "lucide-react";

import { LeadActivityTimeline } from "@/components/leads/LeadActivityTimeline";
import type { LeadDialogAction } from "@/components/leads/LeadActionDialogs";
import { LeadStageBadge } from "@/components/leads/LeadStageBadge";
import {
  archiveReasonKey,
  lostReasonKey,
  sourceKey,
} from "@/components/leads/lead-options";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import {
  ListErrorState,
  ListLoadingState,
} from "@/components/ui/crud/ListState";
import { useTranslation } from "@/hooks/useTranslation";
import { formatDateTime } from "@/lib/date-format";
import type { Lead, LeadActivity } from "@/types/lead";

type LeadDetailsViewProps = {
  lead: Lead | null;
  isLoading: boolean;
  error: string | null;
  isNotFound: boolean;
  activities: LeadActivity[];
  activitiesLoading: boolean;
  activitiesError: string | null;
  isAddingNote: boolean;
  onBack: () => void;
  onRetry: () => void;
  onRetryActivities: () => void;
  onEdit: () => void;
  onAction: (action: LeadDialogAction) => void;
  onAddNote: (body: string) => Promise<void>;
};

export function LeadDetailsView({
  lead,
  isLoading,
  error,
  isNotFound,
  activities,
  activitiesLoading,
  activitiesError,
  isAddingNote,
  onBack,
  onRetry,
  onRetryActivities,
  onEdit,
  onAction,
  onAddNote,
}: LeadDetailsViewProps) {
  const { t, isArabic } = useTranslation();
  const BackIcon = isArabic ? ArrowRight : ArrowLeft;

  if (isLoading) {
    return (
      <div className="space-y-5">
        <Button type="button" variant="ghost" leadingIcon={<BackIcon size={17} />} onClick={onBack}>
          {t("crm.details.back")}
        </Button>
        <div className="rounded-2xl border border-[var(--border)] bg-[var(--surface)]">
          <ListLoadingState label={t("crm.loading")} />
        </div>
      </div>
    );
  }

  if (!lead) {
    return (
      <div className="space-y-5">
        <Button type="button" variant="ghost" leadingIcon={<BackIcon size={17} />} onClick={onBack}>
          {t("crm.details.back")}
        </Button>
        <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow-sm)]">
          <ListErrorState
            message={
              isNotFound
                ? `${t("crm.details.notFoundTitle")} ${t("crm.details.notFoundDescription")}`
                : error ?? t("crm.loadError")
            }
            action={
              isNotFound ? undefined : (
                <Button type="button" size="sm" variant="secondary" onClick={onRetry}>
                  {t("crm.retry")}
                </Button>
              )
            }
          />
        </section>
      </div>
    );
  }

  const actions = lead.allowed_actions;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Button type="button" variant="ghost" leadingIcon={<BackIcon size={17} />} onClick={onBack}>
          {t("crm.details.back")}
        </Button>

        <div className="flex flex-wrap gap-2">
          {actions.can_edit ? (
            <Button type="button" size="sm" variant="secondary" leadingIcon={<Pencil size={15} />} onClick={onEdit}>
              {t("crm.actions.edit")}
            </Button>
          ) : null}
          {actions.can_claim ? (
            <Button type="button" size="sm" leadingIcon={<UserCheck size={15} />} onClick={() => onAction("claim")}>
              {t("crm.actions.claim")}
            </Button>
          ) : null}
          {actions.can_assign ? (
            <Button type="button" size="sm" variant="secondary" leadingIcon={<UserRoundCog size={15} />} onClick={() => onAction("assign")}>
              {t("crm.actions.assign")}
            </Button>
          ) : null}
          {actions.can_move_stage &&
          (lead.stage !== "negotiation" || actions.can_move_backward) ? (
            <Button type="button" size="sm" variant="secondary" leadingIcon={<Route size={15} />} onClick={() => onAction("stage")}>
              {t("crm.actions.changeStage")}
            </Button>
          ) : null}
          {actions.can_move_to_lost ? (
            <Button type="button" size="sm" variant="danger" leadingIcon={<UserX size={15} />} onClick={() => onAction("lost")}>
              {t("crm.actions.moveToLost")}
            </Button>
          ) : null}
          {actions.can_reopen ? (
            <Button type="button" size="sm" leadingIcon={<RotateCcw size={15} />} onClick={() => onAction("reopen")}>
              {t("crm.actions.reopen")}
            </Button>
          ) : null}
          {actions.can_archive ? (
            <Button type="button" size="sm" variant="danger" leadingIcon={<Archive size={15} />} onClick={() => onAction("archive")}>
              {t("crm.actions.archive")}
            </Button>
          ) : null}
          {actions.can_restore ? (
            <Button type="button" size="sm" leadingIcon={<RefreshCw size={15} />} onClick={() => onAction("restore")}>
              {t("crm.actions.restore")}
            </Button>
          ) : null}
          {actions.can_convert ? (
            <Button type="button" size="sm" variant="primary" onClick={() => onAction("convert")}>
              {t("crm.actions.convert")}
            </Button>
          ) : null}
        </div>
      </div>

      <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)] sm:p-6">
        <div className="flex flex-col justify-between gap-4 md:flex-row md:items-start">
          <div>
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-2xl font-bold text-[var(--text-primary)]">{lead.name}</h1>
              <LeadStageBadge stage={lead.stage} />
              {lead.archived_at ? <Badge>{t("crm.archived")}</Badge> : null}
            </div>
            <p className="mt-2 text-sm text-[var(--text-secondary)]">
              {t(sourceKey(lead.source))}
              {lead.source_detail ? ` · ${lead.source_detail}` : ""}
            </p>
          </div>
          <div className="text-sm text-[var(--text-secondary)]">
            <p>{lead.assigned_to?.name ?? t("crm.unassigned")}</p>
            {lead.assigned_to && !lead.assigned_to.is_eligible ? (
              <Badge variant="warning">{lead.assigned_to.membership_status ?? lead.assigned_to.account_status}</Badge>
            ) : null}
          </div>
        </div>

        <div className="mt-6 grid gap-4 md:grid-cols-3">
          <DetailSection title={t("crm.details.contact")}>
            <DetailLine icon={Phone} value={lead.phone} dir="ltr" />
            <DetailLine icon={Mail} value={lead.email ?? "—"} dir="ltr" />
          </DetailSection>
          <DetailSection title={t("crm.details.interest")}>
            <DetailLine icon={MapPin} value={lead.project?.name ?? t("crm.noProject")} />
            <DetailLine icon={MapPin} value={lead.unit?.unit_number ?? t("crm.noUnit")} />
          </DetailSection>
          <DetailSection title={t("crm.details.lifecycle")}>
            <DetailLine
              icon={CalendarClock}
              value={
                lead.next_follow_up_at
                  ? formatDateTime(lead.next_follow_up_at)
                  : t("crm.noFollowUp")
              }
            />
            <p className="text-xs text-[var(--text-muted)]">
              {t("crm.details.createdAt")}: {formatDateTime(lead.created_at)}
            </p>
            <p className="text-xs text-[var(--text-muted)]">
              {t("crm.details.updatedAt")}: {formatDateTime(lead.updated_at)}
            </p>
          </DetailSection>
        </div>

        {lead.lost_reason ? (
          <p className="mt-5 rounded-xl bg-[var(--danger-soft)] px-4 py-3 text-sm text-[var(--danger)]">
            <strong>{t("crm.details.lostReason")}:</strong>{" "}
            {t(lostReasonKey(lead.lost_reason))}
            {lead.lost_reason_detail ? ` · ${lead.lost_reason_detail}` : ""}
          </p>
        ) : null}
        {lead.archive_reason ? (
          <p className="mt-5 rounded-xl bg-[var(--surface-soft)] px-4 py-3 text-sm text-[var(--text-secondary)]">
            <strong>{t("crm.details.archiveReason")}:</strong>{" "}
            {t(archiveReasonKey(lead.archive_reason))}
            {lead.archive_reason_detail ? ` · ${lead.archive_reason_detail}` : ""}
          </p>
        ) : null}
      </section>

      <LeadActivityTimeline
        activities={activities}
        isLoading={activitiesLoading}
        error={activitiesError}
        canAddNote={actions.can_add_note}
        isAddingNote={isAddingNote}
        onRetry={onRetryActivities}
        onAddNote={onAddNote}
      />
    </div>
  );
}

function DetailSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] p-4">
      <h2 className="text-sm font-bold text-[var(--text-primary)]">{title}</h2>
      <div className="mt-3 space-y-2">{children}</div>
    </section>
  );
}

function DetailLine({
  icon: Icon,
  value,
  dir,
}: {
  icon: typeof Phone;
  value: string;
  dir?: "ltr" | "rtl";
}) {
  return (
    <p className="flex items-center gap-2 text-sm text-[var(--text-secondary)]" dir={dir}>
      <Icon size={15} aria-hidden="true" />
      <span className="break-all">{value}</span>
    </p>
  );
}
