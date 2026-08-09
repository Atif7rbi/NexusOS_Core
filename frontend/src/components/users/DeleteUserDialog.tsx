"use client";

import {
  TriangleAlert,
  X,
} from "lucide-react";

import { useTranslation } from "@/hooks/useTranslation";
import type { TenantUser } from "@/types/tenant-user";

type DeleteUserDialogProps = {
  membership: TenantUser | null;
  isDeleting: boolean;
  onClose: () => void;
  onConfirm: () => Promise<void>;
};

export function DeleteUserDialog({
  membership,
  isDeleting,
  onClose,
  onConfirm,
}: DeleteUserDialogProps) {
  const { isArabic } = useTranslation();

  if (!membership) {
    return null;
  }

  const labels = isArabic
    ? {
        title: "إزالة المستخدم",
        description:
          "سيتم إيقاف وصول المستخدم وإزالة عضويته من الشركة. لن يتم حذف حسابه العالمي نهائيًا.",
        confirmQuestion: `هل تريد إزالة ${membership.user.name}؟`,
        cancel: "إلغاء",
        confirm: "إزالة العضوية",
        deleting: "جارٍ الإزالة...",
        close: "إغلاق",
      }
    : {
        title: "Remove user",
        description:
          "The user will lose access and their company membership will be removed. Their global account will not be permanently deleted.",
        confirmQuestion: `Remove ${membership.user.name}?`,
        cancel: "Cancel",
        confirm: "Remove membership",
        deleting: "Removing...",
        close: "Close",
      };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-[var(--backdrop)] p-4 backdrop-blur-sm">
      <div className="w-full max-w-md rounded-3xl border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow-lg)]">
        <div className="flex items-start justify-between gap-4">
          <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[var(--danger-soft)] text-[var(--danger)]">
            <TriangleAlert size={23} />
          </span>

          <button
            type="button"
            onClick={onClose}
            disabled={isDeleting}
            className="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--text-muted)] hover:bg-[var(--surface-muted)]"
            aria-label={labels.close}
          >
            <X size={18} />
          </button>
        </div>

        <h2 className="mt-5 text-xl font-bold text-[var(--text-primary)]">
          {labels.title}
        </h2>

        <p className="mt-3 text-sm font-semibold text-[var(--text-primary)]">
          {labels.confirmQuestion}
        </p>

        <p className="mt-2 text-sm leading-7 text-[var(--text-secondary)]">
          {labels.description}
        </p>

        <div className="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <button
            type="button"
            onClick={onClose}
            disabled={isDeleting}
            className="h-11 rounded-xl border border-[var(--border)] px-5 text-sm font-bold text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
          >
            {labels.cancel}
          </button>

          <button
            type="button"
            onClick={() => void onConfirm()}
            disabled={isDeleting}
            className="h-11 rounded-xl bg-[var(--action-danger)] px-5 text-sm font-bold text-[var(--action-danger-foreground)] hover:bg-[var(--action-danger-hover)] focus-visible:ring-4 focus-visible:ring-[var(--focus-ring-danger)] disabled:bg-[var(--surface-muted)] disabled:text-[var(--text-muted)] disabled:opacity-60"
          >
            {isDeleting
              ? labels.deleting
              : labels.confirm}
          </button>
        </div>
      </div>
    </div>
  );
}
