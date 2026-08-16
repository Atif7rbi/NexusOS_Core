"use client";

import { LockKeyhole } from "lucide-react";

import { useTranslation } from "@/hooks/useTranslation";

export function ModuleUnavailableState() {
  const { isArabic } = useTranslation();

  return (
    <section
      role="alert"
      className="mx-auto max-w-3xl rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-8 text-center shadow-[var(--shadow-sm)]"
    >
      <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--surface-soft)] text-[var(--text-secondary)]">
        <LockKeyhole size={22} aria-hidden="true" />
      </span>
      <h1 className="mt-4 type-page-title">
        {isArabic ? "الوحدة غير متاحة" : "Module unavailable"}
      </h1>
      <p className="mt-2 type-secondary text-[var(--text-secondary)]">
        {isArabic
          ? "هذه الوحدة غير متاحة ضمن باقة المنشأة الحالية."
          : "This module is not available in the current organization plan."}
      </p>
    </section>
  );
}
