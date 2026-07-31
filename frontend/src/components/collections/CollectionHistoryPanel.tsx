"use client";

import { History } from "lucide-react";
import { useMemo } from "react";

import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/ui/crud/DataTable";
import {
  ListEmptyState,
  ListErrorState,
  ListLoadingState,
} from "@/components/ui/crud/ListState";
import { useTranslation } from "@/hooks/useTranslation";
import { formatDate, formatDateTime } from "@/lib/date-format";
import { formatMoney } from "@/lib/number-format";
import type { CancelledCollection } from "@/types/collection";

export type CollectionHistoryState = {
  status: "not_loaded" | "loading" | "valid" | "error";
  isVisible: boolean;
  rows: CancelledCollection[];
  error: string | null;
};

type CollectionHistoryPanelProps = {
  state: CollectionHistoryState;
  currency: string;
  alwaysVisible?: boolean;
  onToggle: () => void;
  onRetry: () => void;
};

export function CollectionHistoryPanel({
  state,
  currency,
  alwaysVisible = false,
  onToggle,
  onRetry,
}: CollectionHistoryPanelProps) {
  const { t } = useTranslation();
  const isVisible = alwaysVisible || state.isVisible;
  const orderedRows = useMemo(
    () =>
      [...state.rows].sort((left, right) => {
        const cancelledComparison =
          Date.parse(right.cancelled_at) -
          Date.parse(left.cancelled_at);

        return cancelledComparison !== 0
          ? cancelledComparison
          : left.sequence - right.sequence;
      }),
    [state.rows]
  );

  return (
    <section
      className="space-y-4 border-t border-[var(--border)] pt-5"
      aria-labelledby="collection-history-title"
    >
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3
          id="collection-history-title"
          className="text-base font-bold text-[var(--text-primary)]"
        >
          {t("collection.history.title")}
        </h3>
        {!alwaysVisible ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            leadingIcon={<History size={16} />}
            aria-expanded={isVisible}
            aria-controls="collection-history-content"
            onClick={onToggle}
          >
            {isVisible
              ? t("collection.history.hide")
              : t("collection.history.show")}
          </Button>
        ) : null}
      </div>

      {isVisible ? (
        <div id="collection-history-content">
          {state.status === "loading" ||
          state.status === "not_loaded" ? (
            <div role="status" aria-live="polite">
              <ListLoadingState
                label={t("collection.history.loading")}
              />
            </div>
          ) : state.status === "error" ? (
            <ListErrorState
              message={
                state.error ??
                t("collection.history.loadError")
              }
              action={
                <Button
                  type="button"
                  size="sm"
                  variant="secondary"
                  onClick={onRetry}
                >
                  {t("collection.history.retry")}
                </Button>
              }
            />
          ) : orderedRows.length === 0 ? (
            <ListEmptyState
              icon={History}
              title={t("collection.history.empty.title")}
              description={t(
                "collection.history.empty.description"
              )}
            />
          ) : (
            <div className="overflow-hidden rounded-xl border border-[var(--border)]">
              <DataTable minWidth="1050px">
                <caption className="sr-only">
                  {t("collection.history.title")}
                </caption>
                <thead className="border-b border-[var(--border)] bg-[var(--surface-soft)] text-xs text-[var(--text-secondary)]">
                  <tr>
                    <th scope="col" className="px-3 py-3">
                      {t("collection.line.sequence")}
                    </th>
                    <th scope="col" className="px-3 py-3">
                      {t("collection.line.title")}
                    </th>
                    <th scope="col" className="px-3 py-3">
                      {t("collection.line.amount")}
                    </th>
                    <th scope="col" className="px-3 py-3">
                      {t("collection.line.dueDate")}
                    </th>
                    <th scope="col" className="px-3 py-3">
                      {t("collection.history.cancelledBy")}
                    </th>
                    <th scope="col" className="px-3 py-3">
                      {t("collection.history.reason")}
                    </th>
                    <th scope="col" className="px-3 py-3">
                      {t("collection.history.cancelledAt")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {orderedRows.map((collection) => (
                    <tr
                      key={collection.id}
                      className="border-b border-[var(--border)] last:border-0"
                    >
                      <td className="px-3 py-4 text-sm font-bold text-[var(--text-primary)]">
                        {collection.sequence}
                      </td>
                      <td className="px-3 py-4 text-sm font-semibold text-[var(--text-primary)]">
                        {collection.title}
                      </td>
                      <td
                        className="px-3 py-4 text-sm font-bold text-[var(--text-primary)]"
                        dir="ltr"
                      >
                        {formatCollectionAmount(
                          collection.amount,
                          currency
                        )}
                      </td>
                      <td
                        className="px-3 py-4 text-sm text-[var(--text-secondary)]"
                        dir="ltr"
                      >
                        {formatDate(collection.due_date)}
                      </td>
                      <td className="px-3 py-4 text-sm text-[var(--text-secondary)]">
                        {collection.cancelled_by.name}
                      </td>
                      <td className="max-w-80 whitespace-normal px-3 py-4 text-sm leading-6 text-[var(--text-secondary)]">
                        {collection.cancellation_reason}
                      </td>
                      <td
                        className="px-3 py-4 text-sm text-[var(--text-secondary)]"
                        dir="ltr"
                      >
                        {formatDateTime(
                          collection.cancelled_at
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
            </div>
          )}
        </div>
      ) : null}
    </section>
  );
}

function formatCollectionAmount(
  amount: string,
  currency: string
): string {
  return `${formatMoney(amount)} ${currency}`;
}
