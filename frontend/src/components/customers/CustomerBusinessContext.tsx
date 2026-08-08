"use client";

import {
  Building2,
  CalendarCheck2,
  FileSignature,
  Home,
  Plus,
  Route,
} from "lucide-react";

import {
  businessContextStatusTone,
  getCustomerBusinessContextCopy,
  shortCustomerContextId,
} from "@/components/customers/customer-business-context-presenters";
import { formatDate, formatDateTime } from "@/lib/date-format";
import { formatMoney } from "@/lib/number-format";
import type { CustomerBusinessContext as BusinessContext } from "@/types/customer-business-context";

type CustomerBusinessContextProps = {
  context: BusinessContext;
  isArabic: boolean;
  createReservationHref?: string;
};

export function CustomerBusinessContext({
  context,
  isArabic,
  createReservationHref,
}: CustomerBusinessContextProps) {
  const copy = getCustomerBusinessContextCopy(isArabic);

  return (
    <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)] sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
            <Route size={21} />
          </span>
          <div>
            <h3 className="text-lg font-bold text-[var(--text-primary)]">
              {copy.title}
            </h3>
            <p className="mt-1 text-sm text-[var(--text-secondary)]">
              {copy.description}
            </p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {createReservationHref ? (
            <a
              href={createReservationHref}
              className="motion-ui inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-[var(--radius-md)] border border-transparent bg-[var(--brand-primary)] px-4 text-sm font-bold text-[var(--text-inverse)] shadow-[var(--shadow-sm)] hover:-translate-y-0.5 hover:bg-[var(--brand-primary-hover)] hover:shadow-[var(--shadow-md)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
            >
              <Plus size={17} aria-hidden="true" />
              {copy.createReservation}
            </a>
          ) : null}

          <div className="flex gap-2">
            <SummaryValue
              label={copy.reservations}
              value={context.summary.reservations_total}
            />
            <SummaryValue
              label={copy.contracts}
              value={context.summary.contracts_total}
            />
          </div>
        </div>
      </div>

      {context.journeys.length === 0 ? (
        <div className="mt-6 rounded-2xl border border-dashed border-[var(--border-strong)] bg-[var(--surface-soft)] px-6 py-12 text-center">
          <CalendarCheck2
            size={26}
            className="mx-auto text-[var(--text-muted)]"
          />
          <h4 className="mt-4 font-bold text-[var(--text-primary)]">
            {copy.emptyTitle}
          </h4>
          <p className="mt-2 text-sm text-[var(--text-secondary)]">
            {copy.emptyDescription}
          </p>
        </div>
      ) : (
        <div className="mt-6 space-y-4">
          {context.journeys.map((journey) => (
            <article
              key={journey.reservation.id}
              className="rounded-2xl border border-[var(--border)] bg-[var(--surface-soft)] p-4 sm:p-5"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-xs font-semibold text-[var(--text-muted)]">
                    {copy.reservation}
                  </p>
                  <p className="mt-1 font-bold text-[var(--text-primary)]">
                    {shortCustomerContextId(journey.reservation.id)}
                  </p>
                </div>
                <StatusBadge
                  status={journey.reservation.status}
                  label={copy.status[journey.reservation.status]}
                />
              </div>

              <dl className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <ContextDetail
                  icon={<Building2 size={16} />}
                  label={copy.project}
                  value={
                    journey.project
                      ? `${journey.project.project_number} — ${journey.project.name} · ${copy.status[journey.project.status]}`
                      : copy.unavailable
                  }
                />
                <ContextDetail
                  icon={<Home size={16} />}
                  label={copy.unit}
                  value={
                    journey.unit
                      ? `${journey.unit.unit_number} · ${copy.unitType[journey.unit.unit_type]} · ${copy.status[journey.unit.status]}`
                      : copy.unavailable
                  }
                />
                <ContextDetail
                  label={copy.reservedAt}
                  value={formatDateTime(journey.reservation.reserved_at)}
                />
                <ContextDetail
                  label={copy.expiresAt}
                  value={formatDateTime(journey.reservation.expires_at)}
                />
              </dl>

              <div className="mt-5 border-t border-[var(--border)] pt-4">
                <div className="mb-3 flex items-center gap-2">
                  <FileSignature
                    size={17}
                    className="text-[var(--brand-gold-strong)]"
                  />
                  <h4 className="text-sm font-bold text-[var(--text-primary)]">
                    {copy.contracts}
                  </h4>
                </div>

                {journey.contracts.length === 0 ? (
                  <p className="rounded-xl bg-[var(--surface)] px-4 py-5 text-sm text-[var(--text-secondary)]">
                    {copy.noContracts}
                  </p>
                ) : (
                  <div className="grid gap-3 xl:grid-cols-2">
                    {journey.contracts.map((contract) => {
                      const schedule = contract.collection_schedule;
                      const next = schedule.next_scheduled_collection;

                      return (
                        <div
                          key={contract.id}
                          className="rounded-xl border border-[var(--border)] bg-[var(--surface)] p-4"
                        >
                          <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                              <p className="text-xs text-[var(--text-muted)]">
                                {copy.contract} {shortCustomerContextId(contract.id)}
                              </p>
                              <p className="mt-1 font-bold text-[var(--text-primary)]" dir="ltr">
                                {formatMoney(contract.total_amount)} {contract.currency}
                              </p>
                            </div>
                            <StatusBadge
                              status={contract.status}
                              label={copy.status[contract.status]}
                            />
                          </div>

                          <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                            <ContextDetail
                              label={copy.scheduleState}
                              value={copy.status[schedule.state]}
                            />
                            <ContextDetail
                              label={copy.scheduledTotal}
                              value={`${formatMoney(schedule.scheduled_total)} ${contract.currency}`}
                              direction="ltr"
                            />
                            <ContextDetail
                              label={copy.scheduledLines}
                              value={String(schedule.scheduled_lines_count)}
                            />
                            <ContextDetail
                              label={copy.nextScheduled}
                              value={
                                next
                                  ? `${next.title} — ${formatMoney(next.amount)} ${contract.currency} — ${formatDate(next.due_date)}`
                                  : copy.noneScheduled
                              }
                            />
                          </dl>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}

function SummaryValue({ label, value }: { label: string; value: number }) {
  return (
    <div className="min-w-24 rounded-xl bg-[var(--surface-soft)] px-4 py-3 text-center">
      <p className="text-xl font-bold text-[var(--text-primary)]">{value}</p>
      <p className="mt-1 text-xs text-[var(--text-secondary)]">{label}</p>
    </div>
  );
}

function ContextDetail({
  label,
  value,
  icon,
  direction,
}: {
  label: string;
  value: string;
  icon?: React.ReactNode;
  direction?: "ltr" | "rtl";
}) {
  return (
    <div>
      <dt className="flex items-center gap-2 text-xs font-semibold text-[var(--text-secondary)]">
        {icon}
        {label}
      </dt>
      <dd className="mt-1 break-words text-sm font-bold text-[var(--text-primary)]" dir={direction}>
        {value}
      </dd>
    </div>
  );
}

function StatusBadge({ status, label }: { status: string; label: string }) {
  return (
    <span className={`rounded-full px-3 py-1 text-xs font-bold ${businessContextStatusTone[status] ?? businessContextStatusTone.absent}`}>
      {label}
    </span>
  );
}
