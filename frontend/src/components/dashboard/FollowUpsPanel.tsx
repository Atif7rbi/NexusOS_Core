"use client";

import Link from "next/link";
import {
  CalendarClock,
  ChevronLeft,
  ChevronRight,
  ClockAlert,
} from "lucide-react";

import { formatDateTime } from "@/lib/date-format";
import { useTranslation } from "@/hooks/useTranslation";
import type {
  DashboardFollowUps,
  DashboardResource,
} from "@/types/dashboard";
import type { Lead } from "@/types/lead";

type FollowUpsPanelProps = {
  resource: DashboardResource<DashboardFollowUps>;
};

type FollowUpGroupProps = {
  title: string;
  count: number;
  leads: Lead[];
  state: "overdue" | "today";
  emptyMessage: string;
  isArabic: boolean;
};

function FollowUpGroup({
  title,
  count,
  leads,
  state,
  emptyMessage,
  isArabic,
}: FollowUpGroupProps) {
  const Arrow = isArabic ? ChevronLeft : ChevronRight;
  const Icon = state === "overdue" ? ClockAlert : CalendarClock;

  return (
    <section className="rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--surface-soft)] p-4">
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Icon
            size={18}
            className={
              state === "overdue"
                ? "text-[var(--danger)]"
                : "text-[var(--warning)]"
            }
          />
          <h4 className="text-sm font-bold text-[var(--text-primary)]">
            {title}
          </h4>
        </div>

        <span className="rounded-full bg-[var(--surface)] px-3 py-1 text-xs font-bold text-[var(--text-secondary)]">
          {count}
        </span>
      </div>

      {leads.length === 0 ? (
        <p className="mt-4 rounded-[var(--radius-sm)] border border-dashed border-[var(--border-strong)] px-4 py-6 text-center text-sm text-[var(--text-secondary)]">
          {emptyMessage}
        </p>
      ) : (
        <div className="mt-4 divide-y divide-[var(--border)]">
          {leads.map((lead) => (
            <a
              key={lead.id}
              href={`/crm/?follow_up_state=${state}&lead=${lead.id}`}
              className="motion-ui flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0 hover:text-[var(--brand-primary)]"
            >
              <div className="min-w-0">
                <p className="truncate text-sm font-bold text-[var(--text-primary)]">
                  {lead.name}
                </p>
                <p className="mt-1 truncate text-xs text-[var(--text-secondary)]">
                  {lead.next_action_note || lead.phone}
                </p>
                <p className="mt-1 text-xs font-semibold text-[var(--text-muted)]" dir="ltr">
                  {lead.next_follow_up_at
                    ? formatDateTime(lead.next_follow_up_at)
                    : "—"}
                </p>
              </div>

              <Arrow
                size={16}
                className="shrink-0 text-[var(--text-muted)]"
              />
            </a>
          ))}
        </div>
      )}
    </section>
  );
}

export function FollowUpsPanel({
  resource,
}: FollowUpsPanelProps) {
  const { isArabic } = useTranslation();
  const copy = isArabic
    ? {
        title: "قائمة المتابعة",
        description: "المتابعات المتأخرة ومتابعات اليوم ضمن نطاقك المصرح.",
        showAll: "فتح CRM",
        overdue: "متأخرة",
        today: "اليوم",
        noOverdue: "لا توجد متابعات متأخرة.",
        noToday: "لا توجد متابعات مجدولة اليوم.",
        loading: "جارٍ تحميل المتابعات...",
        error: "تعذر تحميل قائمة المتابعة.",
      }
    : {
        title: "Follow-up queue",
        description: "Overdue and today's follow-ups within your authorized scope.",
        showAll: "Open CRM",
        overdue: "Overdue",
        today: "Today",
        noOverdue: "No overdue follow-ups.",
        noToday: "No follow-ups scheduled today.",
        loading: "Loading follow-ups...",
        error: "Unable to load the follow-up queue.",
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
          href="/crm/"
          className="rounded-full bg-[var(--brand-primary-soft)] px-4 py-2 text-xs font-bold text-[var(--brand-primary)]"
        >
          {copy.showAll}
        </Link>
      </div>

      {resource.isLoading ? (
        <div className="mt-6 flex min-h-52 items-center justify-center text-sm text-[var(--text-secondary)]">
          {copy.loading}
        </div>
      ) : resource.error || !resource.data ? (
        <div className="mt-6 rounded-[var(--radius-md)] border border-[var(--danger)]/20 bg-[var(--danger-soft)] p-8 text-center text-sm font-semibold text-[var(--danger)]">
          {copy.error}
        </div>
      ) : (
        <div className="mt-6 grid gap-4 lg:grid-cols-2">
          <FollowUpGroup
            title={copy.overdue}
            count={resource.data.summary.overdue_follow_ups}
            leads={resource.data.overdue}
            state="overdue"
            emptyMessage={copy.noOverdue}
            isArabic={isArabic}
          />
          <FollowUpGroup
            title={copy.today}
            count={resource.data.summary.today_follow_ups}
            leads={resource.data.today}
            state="today"
            emptyMessage={copy.noToday}
            isArabic={isArabic}
          />
        </div>
      )}
    </section>
  );
}
