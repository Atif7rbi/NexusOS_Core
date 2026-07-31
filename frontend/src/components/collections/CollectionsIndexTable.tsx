"use client";

import {
  CalendarDays,
  FileSearch,
} from "lucide-react";
import type { ReactNode } from "react";

import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/ui/crud/DataTable";
import { useTranslation } from "@/hooks/useTranslation";
import { formatMoney } from "@/lib/number-format";
import type {
  CollectionsIndexItem,
  ContractStatus,
  DerivedScheduleState,
} from "@/types/collection";

type CollectionsIndexTableProps = {
  items: CollectionsIndexItem[];
  isActionLoading: boolean;
  onOpenContract: (item: CollectionsIndexItem) => void;
  onOpenSchedule: (item: CollectionsIndexItem) => void;
};

const contractToneClasses: Record<ContractStatus, string> = {
  draft: "bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]",
  active: "bg-[var(--success-soft)] text-[var(--success)]",
  completed: "bg-[var(--info-soft)] text-[var(--info)]",
  cancelled: "bg-[var(--danger-soft)] text-[var(--danger)]",
};

const scheduleToneClasses: Record<DerivedScheduleState, string> = {
  absent: "bg-[var(--surface-muted)] text-[var(--text-secondary)]",
  draft: "bg-[var(--info-soft)] text-[var(--info)]",
  scheduled: "bg-[var(--success-soft)] text-[var(--success)]",
  cancelled: "bg-[var(--danger-soft)] text-[var(--danger)]",
};

export function CollectionsIndexTable({
  items,
  isActionLoading,
  onOpenContract,
  onOpenSchedule,
}: CollectionsIndexTableProps) {
  const { t } = useTranslation();

  return (
    <>
      <div className="hidden lg:block">
        <DataTable minWidth="1180px">
          <thead className="border-b border-[var(--border)] text-xs text-[var(--text-secondary)]">
            <tr>
              <th scope="col" className="px-3 py-3">
                {t("collections.table.customer")}
              </th>
              <th scope="col" className="px-3 py-3">
                {t("collections.table.project")}
              </th>
              <th scope="col" className="px-3 py-3">
                {t("collections.table.unit")}
              </th>
              <th scope="col" className="px-3 py-3">
                {t("collections.table.contractTotal")}
              </th>
              <th scope="col" className="px-3 py-3">
                {t("collections.table.contractStatus")}
              </th>
              <th scope="col" className="px-3 py-3">
                {t("collections.table.scheduleState")}
              </th>
              <th scope="col" className="px-3 py-3">
                {t("collections.table.scheduleTotal")}
              </th>
              <th scope="col" className="px-3 py-3">
                {t("collections.table.actions")}
              </th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr
                key={item.contract_id}
                className="border-b border-[var(--border)] last:border-0"
              >
                <td className="px-3 py-4 text-sm font-semibold text-[var(--text-primary)]">
                  {item.customer_name ?? "—"}
                </td>
                <td className="px-3 py-4 text-sm text-[var(--text-secondary)]">
                  {item.project_name ?? "—"}
                </td>
                <td className="px-3 py-4 text-sm font-semibold text-[var(--text-primary)]">
                  {item.unit_number ?? "—"}
                </td>
                <td className="px-3 py-4 text-sm font-bold text-[var(--text-primary)]">
                  {formatCurrency(item.contract_total_amount, item.currency)}
                </td>
                <td className="px-3 py-4">
                  <StatusBadge
                    label={t(
                      `collections.contractStatus.${item.contract_status}`
                    )}
                    className={contractToneClasses[item.contract_status]}
                  />
                </td>
                <td className="px-3 py-4">
                  <StatusBadge
                    label={t(
                      `collections.scheduleState.${item.schedule_state}`
                    )}
                    ariaLabel={`${t("collections.table.scheduleState")}: ${t(
                      `collections.scheduleState.${item.schedule_state}`
                    )}`}
                    className={scheduleToneClasses[item.schedule_state]}
                  />
                </td>
                <td className="px-3 py-4 text-sm font-bold text-[var(--text-primary)]">
                  {item.schedule_state === "absent"
                    ? "—"
                    : formatCurrency(
                        item.schedule_active_total,
                        item.currency
                      )}
                </td>
                <td className="px-3 py-4">
                  <RowActions
                    item={item}
                    disabled={isActionLoading}
                    openContractLabel={t(
                      "collections.actions.openContract"
                    )}
                    openScheduleLabel={t(
                      "collections.actions.openSchedule"
                    )}
                    onOpenContract={onOpenContract}
                    onOpenSchedule={onOpenSchedule}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      </div>

      <div className="grid gap-3 lg:hidden">
        {items.map((item) => {
          const scheduleLabel = t(
            `collections.scheduleState.${item.schedule_state}`
          );

          return (
            <article
              key={item.contract_id}
              className="rounded-2xl border border-[var(--border)] bg-[var(--surface-soft)] p-4"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h3 className="font-bold text-[var(--text-primary)]">
                    {item.customer_name ?? "—"}
                  </h3>
                  <p className="mt-1 text-sm text-[var(--text-secondary)]">
                    {item.project_name ?? "—"} · {item.unit_number ?? "—"}
                  </p>
                </div>
                <StatusBadge
                  label={scheduleLabel}
                  ariaLabel={`${t(
                    "collections.table.scheduleState"
                  )}: ${scheduleLabel}`}
                  className={scheduleToneClasses[item.schedule_state]}
                />
              </div>

              <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                <MobileDetail
                  label={t("collections.table.contractTotal")}
                  value={formatCurrency(
                    item.contract_total_amount,
                    item.currency
                  )}
                />
                <MobileDetail
                  label={t("collections.table.contractStatus")}
                  value={
                    <StatusBadge
                      label={t(
                        `collections.contractStatus.${item.contract_status}`
                      )}
                      className={contractToneClasses[item.contract_status]}
                    />
                  }
                />
                <MobileDetail
                  label={t("collections.table.scheduleTotal")}
                  value={
                    item.schedule_state === "absent"
                      ? "—"
                      : formatCurrency(
                          item.schedule_active_total,
                          item.currency
                        )
                  }
                />
              </dl>

              <div className="mt-4 border-t border-[var(--border)] pt-4">
                <RowActions
                  item={item}
                  disabled={isActionLoading}
                  openContractLabel={t(
                    "collections.actions.openContract"
                  )}
                  openScheduleLabel={t(
                    "collections.actions.openSchedule"
                  )}
                  onOpenContract={onOpenContract}
                  onOpenSchedule={onOpenSchedule}
                />
              </div>
            </article>
          );
        })}
      </div>
    </>
  );
}

function StatusBadge({
  label,
  ariaLabel,
  className,
}: {
  label: string;
  ariaLabel?: string;
  className: string;
}) {
  return (
    <span
      aria-label={ariaLabel}
      className={[
        "inline-flex rounded-full px-3 py-1 text-xs font-bold",
        className,
      ].join(" ")}
    >
      {label}
    </span>
  );
}

function RowActions({
  item,
  disabled,
  openContractLabel,
  openScheduleLabel,
  onOpenContract,
  onOpenSchedule,
}: {
  item: CollectionsIndexItem;
  disabled: boolean;
  openContractLabel: string;
  openScheduleLabel: string;
  onOpenContract: (selected: CollectionsIndexItem) => void;
  onOpenSchedule: (selected: CollectionsIndexItem) => void;
}) {
  return (
    <div className="flex flex-wrap gap-2">
      <Button
        type="button"
        size="sm"
        variant="secondary"
        disabled={disabled}
        leadingIcon={<FileSearch size={16} />}
        onClick={() => onOpenContract(item)}
      >
        {openContractLabel}
      </Button>
      <Button
        type="button"
        size="sm"
        variant="secondary"
        disabled={disabled}
        leadingIcon={<CalendarDays size={16} />}
        onClick={() => onOpenSchedule(item)}
      >
        {openScheduleLabel}
      </Button>
    </div>
  );
}

function MobileDetail({
  label,
  value,
}: {
  label: string;
  value: ReactNode;
}) {
  return (
    <div>
      <dt className="text-xs font-semibold text-[var(--text-secondary)]">
        {label}
      </dt>
      <dd className="mt-1 text-sm font-bold text-[var(--text-primary)]">
        {value}
      </dd>
    </div>
  );
}

function formatCurrency(value: string, currency: string): string {
  const numericValue = Number(value);

  if (!Number.isFinite(numericValue)) {
    return `${formatMoney(value)} ${currency}`;
  }

  return new Intl.NumberFormat("en-US-u-nu-latn", {
    style: "currency",
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(numericValue);
}
