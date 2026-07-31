"use client";

import { CheckCircle2 } from "lucide-react";

import { formatCollectionAmount } from "@/components/collections/CollectionScheduleView";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { useTranslation } from "@/hooks/useTranslation";
import type { CollectionScheduleResource } from "@/types/collection";

type FinalizeDialogProps = {
  resource: CollectionScheduleResource | null;
  error: string | null;
  isProcessing: boolean;
  onCancel: () => void;
  onConfirm: () => void;
};

export function FinalizeDialog({
  resource,
  error,
  isProcessing,
  onCancel,
  onConfirm,
}: FinalizeDialogProps) {
  const { t } = useTranslation();

  return (
    <ConfirmationDialog
      isOpen={resource !== null}
      title={t("collection.finalize.dialog.title")}
      description={t(
        "collection.finalize.dialog.description"
      )}
      icon={
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
          <CheckCircle2 size={21} />
        </span>
      }
      error={error}
      isProcessing={isProcessing}
      closeLabel={t("collection.finalize.dialog.close")}
      cancelLabel={t("collection.finalize.dialog.cancel")}
      confirmLabel={t("collection.finalize.dialog.confirm")}
      processingLabel={t(
        "collection.finalize.dialog.confirming"
      )}
      onCancel={onCancel}
      onConfirm={onConfirm}
    >
      {resource ? (
        <dl className="space-y-2 rounded-xl bg-[var(--surface-soft)] px-4 py-3 text-sm">
          <SummaryRow
            label={t("collection.finalize.dialog.lineCount")}
            value={new Intl.NumberFormat(
              "en-US-u-nu-latn"
            ).format(
              resource.schedule.active_collections.length
            )}
          />
          <SummaryRow
            label={t(
              "collection.summary.activeTotal"
            )}
            value={formatCollectionAmount(
              resource.schedule.active_total,
              resource.contract.currency
            )}
          />
          <SummaryRow
            label={t(
              "collection.summary.contractTotal"
            )}
            value={formatCollectionAmount(
              resource.contract.total_amount,
              resource.contract.currency
            )}
          />
        </dl>
      ) : null}
    </ConfirmationDialog>
  );
}

function SummaryRow({
  label,
  value,
}: {
  label: string;
  value: string;
}) {
  return (
    <div className="flex items-center justify-between gap-4">
      <dt className="text-[var(--text-secondary)]">
        {label}
      </dt>
      <dd
        className="font-bold text-[var(--text-primary)]"
        dir="ltr"
      >
        {value}
      </dd>
    </div>
  );
}
