import { Copy, UserRound } from "lucide-react";

import { LeadStageBadge } from "@/components/leads/LeadStageBadge";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { useTranslation } from "@/hooks/useTranslation";
import { formatDateTime } from "@/lib/date-format";
import type { LeadDuplicateMatch } from "@/types/lead";

type LeadDuplicateDialogProps = {
  isOpen: boolean;
  matches: LeadDuplicateMatch[];
  isProcessing: boolean;
  error?: string | null;
  onCancel: () => void;
  onConfirm: () => void;
};

export function LeadDuplicateDialog({
  isOpen,
  matches,
  isProcessing,
  error = null,
  onCancel,
  onConfirm,
}: LeadDuplicateDialogProps) {
  const { t } = useTranslation();

  return (
    <ConfirmationDialog
      isOpen={isOpen}
      title={t("crm.duplicate.title")}
      description={t("crm.duplicate.description")}
      icon={
        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--warning-soft)] text-[var(--warning)]">
          <Copy size={21} aria-hidden="true" />
        </span>
      }
      error={error}
      isProcessing={isProcessing}
      closeLabel={t("crm.dialog.close")}
      cancelLabel={t("crm.duplicate.cancel")}
      confirmLabel={t("crm.duplicate.confirm")}
      processingLabel={t("crm.form.saving")}
      onCancel={onCancel}
      onConfirm={onConfirm}
    >
      <div className="max-h-64 space-y-3 overflow-y-auto pe-1">
        {matches.map((match) => (
          <article
            key={match.id}
            className="rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] p-3"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="truncate text-sm font-bold text-[var(--text-primary)]">
                  {match.name}
                </p>
                <p className="mt-1 inline-flex items-center gap-1.5 text-xs text-[var(--text-secondary)]">
                  <UserRound size={13} aria-hidden="true" />
                  {match.assigned_to?.name ?? t("crm.unassigned")}
                </p>
              </div>
              <LeadStageBadge stage={match.stage} />
            </div>
            {match.updated_at ? (
              <p className="mt-2 text-xs text-[var(--text-muted)]">
                {t("crm.table.updated")}: {formatDateTime(match.updated_at)}
              </p>
            ) : null}
          </article>
        ))}
      </div>
    </ConfirmationDialog>
  );
}
