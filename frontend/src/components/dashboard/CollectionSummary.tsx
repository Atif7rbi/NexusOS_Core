"use client";

import Link from "next/link";
import {
  CalendarDays,
  ClipboardList,
  FileClock,
  ListChecks,
} from "lucide-react";

import { formatDate } from "@/lib/date-format";
import { formatMoney } from "@/lib/number-format";
import { useTranslation } from "@/hooks/useTranslation";
import type {
  DashboardCollections,
  DashboardResource,
} from "@/types/dashboard";

type CollectionSummaryProps = {
  resource: DashboardResource<DashboardCollections>;
};

export function CollectionSummary({
  resource,
}: CollectionSummaryProps) {
  const { isArabic } = useTranslation();
  const copy = isArabic
    ? {
        title: "نظرة على جداول التحصيل",
        description: "حالة الجداول المعتمدة والمسودات والعقود التي لم يُنشأ لها جدول.",
        open: "فتح جداول التحصيل",
        scheduled: "جداول معتمدة",
        draft: "مسودات",
        absent: "بدون جدول",
        next: "أقرب مواعيد التحصيل المجدولة",
        scheduledDate: "التاريخ المجدول",
        empty: "لا توجد بنود تحصيل مجدولة.",
        loading: "جارٍ تحميل جداول التحصيل...",
        error: "تعذر تحميل بيانات جداول التحصيل.",
        unknownCustomer: "عميل غير متاح",
      }
    : {
        title: "Collection schedule overview",
        description: "Approved schedules, drafts, and contracts without a schedule.",
        open: "Open collection schedules",
        scheduled: "Scheduled",
        draft: "Draft",
        absent: "No schedule",
        next: "Nearest scheduled collection dates",
        scheduledDate: "Scheduled date",
        empty: "No scheduled collection lines.",
        loading: "Loading collection schedules...",
        error: "Unable to load collection schedule data.",
        unknownCustomer: "Customer unavailable",
      };

  return (
    <section className="rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow-sm)]">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h3 className="text-lg font-bold text-[var(--text-primary)]">
            {copy.title}
          </h3>
          <p className="mt-1 text-sm text-[var(--text-secondary)]">
            {copy.description}
          </p>
        </div>

        <Link
          href="/collections/"
          className="rounded-full bg-[var(--brand-primary-soft)] px-4 py-2 text-xs font-bold text-[var(--brand-primary)]"
        >
          {copy.open}
        </Link>
      </div>

      {resource.isLoading ? (
        <div className="mt-6 flex min-h-64 items-center justify-center text-sm text-[var(--text-secondary)]">
          {copy.loading}
        </div>
      ) : resource.error || !resource.data ? (
        <div className="mt-6 rounded-[var(--radius-md)] border border-[var(--danger)]/20 bg-[var(--danger-soft)] p-8 text-center text-sm font-semibold text-[var(--danger)]">
          {copy.error}
        </div>
      ) : (
        <>
          <div className="mt-6 grid gap-3 sm:grid-cols-3">
            {[
              {
                label: copy.scheduled,
                value: resource.data.summary.scheduled_count,
                icon: ListChecks,
                className: "text-[var(--success)]",
              },
              {
                label: copy.draft,
                value: resource.data.summary.draft_count,
                icon: FileClock,
                className: "text-[var(--warning)]",
              },
              {
                label: copy.absent,
                value: resource.data.summary.absent_count,
                icon: ClipboardList,
                className: "text-[var(--danger)]",
              },
            ].map(({ label, value, icon: Icon, className }) => (
              <div
                key={label}
                className="rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--surface-soft)] p-4"
              >
                <div className="flex items-center justify-between gap-3">
                  <Icon size={18} className={className} />
                  <span className="text-2xl font-bold text-[var(--text-primary)]">
                    {value}
                  </span>
                </div>
                <p className="mt-3 text-xs font-semibold text-[var(--text-secondary)]">
                  {label}
                </p>
              </div>
            ))}
          </div>

          <div className="mt-7">
            <div className="flex items-center gap-2">
              <CalendarDays
                size={18}
                className="text-[var(--brand-primary)]"
              />
              <h4 className="text-sm font-bold text-[var(--text-primary)]">
                {copy.next}
              </h4>
            </div>

            {resource.data.items.length === 0 ? (
              <p className="mt-4 rounded-[var(--radius-md)] border border-dashed border-[var(--border-strong)] px-4 py-8 text-center text-sm text-[var(--text-secondary)]">
                {copy.empty}
              </p>
            ) : (
              <div className="mt-4 divide-y divide-[var(--border)] rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--surface-soft)] px-4">
                {resource.data.items.map((item) => {
                  const next = item.next_scheduled_collection;

                  if (!next) {
                    return null;
                  }

                  return (
                    <article
                      key={item.contract_id}
                      className="grid gap-3 py-4 sm:grid-cols-[1fr_auto] sm:items-center"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-bold text-[var(--text-primary)]">
                          {next.title}
                        </p>
                        <p className="mt-1 truncate text-xs text-[var(--text-secondary)]">
                          {item.customer_name ?? copy.unknownCustomer}
                          {item.project_name ? ` · ${item.project_name}` : ""}
                          {item.unit_number ? ` · ${item.unit_number}` : ""}
                        </p>
                      </div>

                      <div className="text-start sm:text-end">
                        <p className="text-sm font-bold text-[var(--brand-primary)]" dir="ltr">
                          {formatMoney(next.amount)} {item.currency}
                        </p>
                        <p className="mt-1 text-xs text-[var(--text-muted)]">
                          {copy.scheduledDate}: {formatDate(next.due_date)}
                        </p>
                      </div>
                    </article>
                  );
                })}
              </div>
            )}
          </div>
        </>
      )}
    </section>
  );
}
