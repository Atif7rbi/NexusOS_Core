"use client";

import {
  Archive,
  RotateCcw,
  X,
} from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";
import type { Customer } from "@/types/customer";

type CustomerArchiveDialogProps = {
  customer: Customer | null;
  isProcessing: boolean;
  action: "archive" | "restore";
  onCancel: () => void;
  onConfirm: () => Promise<void>;
};

export function CustomerArchiveDialog({
  customer,
  isProcessing,
  action,
  onCancel,
  onConfirm,
}: CustomerArchiveDialogProps) {
  const { isArabic } = useTranslation();
  const [error, setError] = useState<string | null>(null);

  if (!customer) {
    return null;
  }

  const isArchive = action === "archive";

  const handleConfirm = async (): Promise<void> => {
    setError(null);

    try {
      await onConfirm();
    } catch (caughtError) {
      setError(
        caughtError instanceof Error
          ? caughtError.message
          : isArabic
            ? "تعذر إكمال العملية. حاول مرة أخرى."
            : "Unable to complete the action. Please try again."
      );
    }
  };

  const labels = isArabic
    ? {
        close: "إغلاق",
        archiveTitle: "أرشفة العميل",
        restoreTitle: "استعادة العميل",
        archiveDescription: `هل أنت متأكد من أرشفة العميل «${customer.name}»؟ سيبقى محفوظًا في النظام ويمكن استعادته لاحقًا.`,
        restoreDescription: `هل تريد استعادة العميل «${customer.name}» إلى قائمة العملاء النشطين؟`,
        cancel: "إلغاء",
        archive: "أرشفة العميل",
        restore: "استعادة العميل",
        archiving: "جارٍ الأرشفة...",
        restoring: "جارٍ الاستعادة...",
      }
    : {
        close: "Close",
        archiveTitle: "Archive customer",
        restoreTitle: "Restore customer",
        archiveDescription: `Are you sure you want to archive “${customer.name}”? The record will remain stored and can be restored later.`,
        restoreDescription: `Do you want to restore “${customer.name}” to the active customer list?`,
        cancel: "Cancel",
        archive: "Archive customer",
        restore: "Restore customer",
        archiving: "Archiving...",
        restoring: "Restoring...",
      };

  return (
    <div className="fixed inset-0 z-[80] flex items-center justify-center p-4">
      <button
        type="button"
        onClick={onCancel}
        aria-label={labels.close}
        className="absolute inset-0 bg-[var(--backdrop)] backdrop-blur-[2px]"
      />

      <div className="relative w-full max-w-md rounded-[24px] border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow-lg)]">
        <button
          type="button"
          onClick={onCancel}
          aria-label={labels.close}
          className="absolute end-4 top-4 flex h-8 w-8 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--surface-muted)]"
        >
          <X size={17} />
        </button>

        <div
          className={
            isArchive
              ? "flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--danger-soft)] text-[var(--danger)]"
              : "flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--success-soft)] text-[var(--success)]"
          }
        >
          {isArchive ? (
            <Archive size={22} />
          ) : (
            <RotateCcw size={22} />
          )}
        </div>

        <h2 className="mt-5 text-lg font-bold text-[var(--text-primary)]">
          {isArchive
            ? labels.archiveTitle
            : labels.restoreTitle}
        </h2>

        <p className="mt-3 text-sm leading-7 text-[var(--text-secondary)]">
          {isArchive
            ? labels.archiveDescription
            : labels.restoreDescription}
        </p>

        {error ? (
          <div className="mt-4 rounded-xl border border-[var(--danger)]/25 bg-[var(--danger-soft)] px-4 py-3 text-sm font-semibold text-[var(--danger)]">
            {error}
          </div>
        ) : null}

        <div className="mt-6 flex justify-end gap-3">
          <Button
            type="button"
            variant="secondary"
            onClick={onCancel}
            disabled={isProcessing}
            className="!border-[var(--border)] !bg-[var(--surface)] !text-[var(--text-primary)] hover:!bg-[var(--surface-muted)]"
          >
            {labels.cancel}
          </Button>

          <Button
            type="button"
            variant={isArchive ? "danger" : "primary"}
            onClick={() => void handleConfirm()}
            disabled={isProcessing}
            className={
              isArchive
                ? ""
                : "!bg-[var(--success)] !text-[var(--success-foreground)] hover:!bg-[var(--success-hover)] focus-visible:!ring-[var(--focus-ring-success)]"
            }
          >
            {isProcessing
              ? isArchive
                ? labels.archiving
                : labels.restoring
              : isArchive
                ? labels.archive
                : labels.restore}
          </Button>
        </div>
      </div>
    </div>
  );
}
