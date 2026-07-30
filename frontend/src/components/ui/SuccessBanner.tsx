"use client";

import { X } from "lucide-react";

type SuccessBannerProps = {
  message: string;
  onDismiss: () => void;
};

export function SuccessBanner({
  message,
  onDismiss,
}: SuccessBannerProps) {
  return (
    <div
      role="status"
      className="flex items-center justify-between gap-3 rounded-2xl border border-[var(--success)]/25 bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]"
    >
      <span>{message}</span>
      <button
        type="button"
        onClick={onDismiss}
        aria-label="إغلاق رسالة النجاح"
        className="rounded-lg p-1 hover:bg-black/5"
      >
        <X size={17} />
      </button>
    </div>
  );
}
