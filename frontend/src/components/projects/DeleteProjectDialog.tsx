"use client";

import {
  Archive,
  X,
} from "lucide-react";
import { useState } from "react";

import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";
import type { Project } from "@/types/project";

type ArchiveAction = "archive" | "restore" | "activate" | "revert-to-draft" | "complete" | "cancel";

type ArchiveProjectDialogProps = {
  action: ArchiveAction;
  project: Project | null;
  isProcessing: boolean;
  onCancel: () => void;
  onConfirm: () => Promise<void>;
};

export function ArchiveProjectDialog({
  action,
  project,
  isProcessing,
  onCancel,
  onConfirm,
}: ArchiveProjectDialogProps) {
  const { isArabic } = useTranslation();
  const [error, setError] = useState<string | null>(null);

  if (!project) {
    return null;
  }

  const isRestore = action === "restore";
  const actionLabels = isArabic ? {
    archive: "أرشفة المشروع", restore: "استعادة المشروع", activate: "تفعيل المشروع",
    "revert-to-draft": "إرجاع المشروع إلى مسودة", complete: "إكمال المشروع", cancel: "إلغاء المشروع",
  } : {
    archive: "Archive project", restore: "Restore project", activate: "Activate project",
    "revert-to-draft": "Revert project to draft", complete: "Complete project", cancel: "Cancel project",
  };

  const handleConfirm = async (): Promise<void> => {
    setError(null);

    try {
      await onConfirm();
    } catch (caughtError) {
      setError(
        caughtError instanceof Error
          ? caughtError.message
          : isArabic
            ? isRestore
              ? "تعذرت استعادة المشروع. حاول مرة أخرى."
              : "تعذرت أرشفة المشروع. حاول مرة أخرى."
            : isRestore
              ? "Unable to restore the project. Please try again."
              : "Unable to archive the project. Please try again."
      );
    }
  };

  return (
    <div className="fixed inset-0 z-[80] flex items-center justify-center p-4">
      <button
        type="button"
        onClick={onCancel}
        aria-label="Close"
        className="absolute inset-0 bg-slate-950/55 backdrop-blur-[2px]"
      />

      <div className="relative w-full max-w-md rounded-[24px] border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow-lg)]">
        <button
          type="button"
          onClick={onCancel}
          className="absolute end-4 top-4 flex h-8 w-8 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--surface-muted)]"
        >
          <X size={17} />
        </button>

        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
          <Archive size={22} />
        </div>

        <h2 className="mt-5 text-lg font-bold text-[var(--text-primary)]">
          {actionLabels[action]}
        </h2>

        <p className="mt-3 text-sm leading-7 text-[var(--text-secondary)]">
          {action === "activate" || action === "revert-to-draft" || action === "complete" || action === "cancel"
            ? isArabic ? `هل أنت متأكد من تنفيذ إجراء «${actionLabels[action]}» للمشروع «${project.name}»؟` : `Are you sure you want to ${actionLabels[action].toLowerCase()} for “${project.name}”?`
            : isArabic
            ? isRestore
              ? `هل أنت متأكد من استعادة المشروع «${project.name}»؟ سيعود متاحًا للتعديل.`
              : `هل أنت متأكد من أرشفة المشروع «${project.name}»؟ ستبقى بياناته محفوظة ويمكن استعادته لاحقًا.`
            : isRestore
              ? `Are you sure you want to restore “${project.name}”? It will become available for editing.`
              : `Are you sure you want to archive “${project.name}”? Its data will be preserved and can be restored later.`}
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
            {isArabic ? "إلغاء" : "Cancel"}
          </Button>

          <Button
            type="button"
            variant="primary"
            onClick={() => void handleConfirm()}
            disabled={isProcessing}
          >
            {isProcessing
              ? isArabic
                ? "جارٍ التنفيذ..."
                : isRestore
                  ? "Restoring..."
                  : "Archiving..."
              : isArabic
                ? actionLabels[action]
                : actionLabels[action]}
          </Button>
        </div>
      </div>
    </div>
  );
}
