"use client";

import { ArrowLeftRight } from "lucide-react";

import { formatCollectionAmount } from "@/components/collections/CollectionScheduleView";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { useTranslation } from "@/hooks/useTranslation";
import type {
  CollectionLineInput,
  CollectionScheduleResource,
} from "@/types/collection";

type AmendDialogProps = {
  resource: CollectionScheduleResource | null;
  proposedLines: CollectionLineInput[];
  proposedTotal: string;
  cancellationReason: string;
  error: string | null;
  isProcessing: boolean;
  onBack: () => void;
  onConfirm: () => void;
};

export function AmendDialog({
  resource,
  proposedLines,
  proposedTotal,
  cancellationReason,
  error,
  isProcessing,
  onBack,
  onConfirm,
}: AmendDialogProps) {
  const { t } = useTranslation();

  return (
    <ConfirmationDialog
      isOpen={resource !== null}
      title={t("collection.amend.dialog.title")}
      description={t(
        "collection.amend.dialog.description"
      )}
      icon={
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
          <ArrowLeftRight size={21} />
        </span>
      }
      error={error}
      isProcessing={isProcessing}
      closeLabel={t("collection.amend.dialog.close")}
      cancelLabel={t("collection.amend.dialog.cancel")}
      confirmLabel={t("collection.amend.dialog.confirm")}
      processingLabel={t(
        "collection.amend.dialog.confirming"
      )}
      onCancel={onBack}
      onConfirm={onConfirm}
    >
      {resource ? (
        <div className="space-y-3">
          <ScheduleSnapshot
            title={t("collection.amend.dialog.current")}
            lineCount={
              resource.schedule.active_collections.length
            }
            total={resource.schedule.active_total}
            currency={resource.contract.currency}
          />
          <ScheduleSnapshot
            title={t("collection.amend.dialog.proposed")}
            lineCount={proposedLines.length}
            total={proposedTotal}
            currency={resource.contract.currency}
          />
          <div className="rounded-xl border border-[var(--border)] px-4 py-3 text-sm">
            <p className="text-xs font-semibold text-[var(--text-secondary)]">
              {t("collection.amend.dialog.reason")}
            </p>
            <p className="mt-1 whitespace-pre-wrap font-semibold leading-6 text-[var(--text-primary)]">
              {cancellationReason}
            </p>
          </div>
        </div>
      ) : null}
    </ConfirmationDialog>
  );
}

function ScheduleSnapshot({
  title,
  lineCount,
  total,
  currency,
}: {
  title: string;
  lineCount: number;
  total: string;
  currency: string;
}) {
  const { t } = useTranslation();

  return (
    <div className="rounded-xl bg-[var(--surface-soft)] px-4 py-3 text-sm">
      <p className="font-bold text-[var(--text-primary)]">
        {title}
      </p>
      <dl className="mt-2 space-y-1.5">
        <div className="flex items-center justify-between gap-4">
          <dt className="text-[var(--text-secondary)]">
            {t("collection.summary.lineCount")}
          </dt>
          <dd className="font-bold text-[var(--text-primary)]">
            {new Intl.NumberFormat(
              "en-US-u-nu-latn"
            ).format(lineCount)}
          </dd>
        </div>
        <div className="flex items-center justify-between gap-4">
          <dt className="text-[var(--text-secondary)]">
            {t("collection.summary.activeTotal")}
          </dt>
          <dd
            className="font-bold text-[var(--text-primary)]"
            dir="ltr"
          >
            {formatCollectionAmount(total, currency)}
          </dd>
        </div>
      </dl>
    </div>
  );
}
