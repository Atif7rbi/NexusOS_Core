"use client";

import {
  Ban,
  CheckCircle2,
  Edit3,
  ListOrdered,
  Plus,
  RefreshCw,
} from "lucide-react";
import type { ReactNode } from "react";

import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { DataTable } from "@/components/ui/crud/DataTable";
import { ListEmptyState } from "@/components/ui/crud/ListState";
import { useTranslation } from "@/hooks/useTranslation";
import { formatDate, formatDateTime } from "@/lib/date-format";
import { formatMoney } from "@/lib/number-format";
import type {
  ActiveCollection,
  CollectionScheduleResource,
} from "@/types/collection";
import {
  CollectionHistoryPanel,
  type CollectionHistoryState,
} from "@/components/collections/CollectionHistoryPanel";

type CollectionScheduleViewProps = {
  resource: CollectionScheduleResource;
  onCreateDraft?: () => void;
  onEditDraft?: () => void;
  onFinalize?: () => void;
  onAmend?: () => void;
  actionsDisabled?: boolean;
  history?: {
    state: CollectionHistoryState;
    onToggle: () => void;
    onRetry: () => void;
  };
};

export function CollectionScheduleView({
  resource,
  onCreateDraft,
  onEditDraft,
  onFinalize,
  onAmend,
  actionsDisabled = false,
  history,
}: CollectionScheduleViewProps) {
  const { t } = useTranslation();
  const { contract, schedule } = resource;

  if (schedule.derived_state === "absent") {
    return (
      <div className="space-y-5">
        <ScheduleSummary
          activeTotal={schedule.active_total}
          contractTotal={contract.total_amount}
          currency={contract.currency}
          lineCount={0}
        />
        <ListEmptyState
          icon={ListOrdered}
          title={t("collection.absent.title")}
          description={t("collection.absent.description")}
          action={
            resource.allowed_actions.can_save_draft &&
            onCreateDraft ? (
              <Button
                type="button"
                leadingIcon={<Plus size={17} />}
                disabled={actionsDisabled}
                onClick={onCreateDraft}
              >
                {t("collection.absent.create")}
              </Button>
            ) : undefined
          }
        />
      </div>
    );
  }

  if (schedule.derived_state === "cancelled") {
    return (
      <div className="space-y-5">
        <ScheduleHeader
          icon={<Ban size={20} />}
          title={t("collection.cancelled.title")}
          badge={t("collection.cancelled.badge")}
          badgeVariant="danger"
        />
        <div className="rounded-xl border border-[var(--danger)]/20 bg-[var(--danger-soft)] px-4 py-4 text-sm font-semibold leading-7 text-[var(--danger)]">
          {t("collection.cancelled.notice")}
        </div>
        {history ? (
          <CollectionHistoryPanel
            state={history.state}
            currency={contract.currency}
            alwaysVisible
            onToggle={history.onToggle}
            onRetry={history.onRetry}
          />
        ) : null}
      </div>
    );
  }

  const isScheduled =
    schedule.derived_state === "scheduled";

  return (
    <div className="space-y-5">
      <ScheduleHeader
        icon={<ListOrdered size={20} />}
        title={
          isScheduled
            ? t("collection.scheduled.title")
            : t("collection.draft.title")
        }
        badge={
          isScheduled
            ? t("collection.scheduled.badge")
            : t("collection.draft.badge")
        }
        badgeVariant={isScheduled ? "success" : "warning"}
        actions={
          !isScheduled ? (
            <>
              {resource.allowed_actions.can_save_draft &&
              onEditDraft ? (
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  leadingIcon={<Edit3 size={16} />}
                  disabled={actionsDisabled}
                  onClick={onEditDraft}
                >
                  {t("collection.draft.edit")}
                </Button>
              ) : null}
              {resource.allowed_actions.can_finalize &&
              onFinalize ? (
                <Button
                  type="button"
                  size="sm"
                  leadingIcon={<CheckCircle2 size={16} />}
                  disabled={
                    actionsDisabled ||
                    !collectionAmountsEqual(
                      resource.schedule.active_total,
                      resource.contract.total_amount
                    )
                  }
                  title={
                    collectionAmountsEqual(
                      resource.schedule.active_total,
                      resource.contract.total_amount
                    )
                      ? undefined
                      : t(
                          "collection.finalize.totalsMismatch"
                        )
                  }
                  onClick={onFinalize}
                >
                  {t("collection.finalize.button")}
                </Button>
              ) : null}
            </>
          ) : resource.allowed_actions.can_amend &&
            onAmend ? (
            <Button
              type="button"
              variant="secondary"
              size="sm"
              leadingIcon={<RefreshCw size={16} />}
              disabled={actionsDisabled}
              onClick={onAmend}
            >
              {t("collection.amend.button")}
            </Button>
          ) : undefined
        }
      />

      <ScheduleSummary
        activeTotal={schedule.active_total}
        contractTotal={contract.total_amount}
        currency={contract.currency}
        lineCount={schedule.active_collections.length}
      />

      {!isScheduled &&
      resource.allowed_actions.can_finalize &&
      !collectionAmountsEqual(
        resource.schedule.active_total,
        resource.contract.total_amount
      ) ? (
        <p className="rounded-xl border border-[var(--warning)]/20 bg-[var(--warning-soft)] px-4 py-3 text-sm font-semibold text-[var(--warning)]">
          {t("collection.finalize.totalsMismatch")}
        </p>
      ) : null}

      <CollectionLinesTable
        collections={schedule.active_collections}
        currency={contract.currency}
        showSchedulingMetadata={isScheduled}
      />

      {isScheduled && history ? (
        <CollectionHistoryPanel
          state={history.state}
          currency={contract.currency}
          onToggle={history.onToggle}
          onRetry={history.onRetry}
        />
      ) : null}
    </div>
  );
}

function ScheduleHeader({
  icon,
  title,
  badge,
  badgeVariant,
  actions,
}: {
  icon: ReactNode;
  title: string;
  badge: string;
  badgeVariant: "success" | "warning" | "danger";
  actions?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex items-center gap-3">
        <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
          {icon}
        </span>
        <h3 className="text-lg font-bold text-[var(--text-primary)]">
          {title}
        </h3>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        {actions}
        <Badge variant={badgeVariant}>{badge}</Badge>
      </div>
    </div>
  );
}

function ScheduleSummary({
  activeTotal,
  contractTotal,
  currency,
  lineCount,
}: {
  activeTotal: string;
  contractTotal: string;
  currency: string;
  lineCount: number;
}) {
  const { t } = useTranslation();
  const items = [
    {
      label: t("collection.summary.activeTotal"),
      value: formatCollectionAmount(activeTotal, currency),
    },
    {
      label: t("collection.summary.contractTotal"),
      value: formatCollectionAmount(contractTotal, currency),
    },
    {
      label: t("collection.summary.lineCount"),
      value: new Intl.NumberFormat(
        "en-US-u-nu-latn"
      ).format(lineCount),
    },
  ];

  return (
    <dl className="grid gap-3 sm:grid-cols-3">
      {items.map((item) => (
        <div
          key={item.label}
          className="rounded-xl bg-[var(--surface-soft)] p-4"
        >
          <dt className="text-xs font-semibold text-[var(--text-secondary)]">
            {item.label}
          </dt>
          <dd
            className="mt-1 text-sm font-bold text-[var(--text-primary)]"
            dir="ltr"
          >
            {item.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}

function CollectionLinesTable({
  collections,
  currency,
  showSchedulingMetadata,
}: {
  collections: ActiveCollection[];
  currency: string;
  showSchedulingMetadata: boolean;
}) {
  const { t } = useTranslation();

  return (
    <div className="overflow-hidden rounded-xl border border-[var(--border)]">
      <DataTable minWidth={showSchedulingMetadata ? "920px" : "700px"}>
        <thead className="border-b border-[var(--border)] bg-[var(--surface-soft)] text-xs text-[var(--text-secondary)]">
          <tr>
            <th className="px-3 py-3">
              {t("collection.line.sequence")}
            </th>
            <th className="px-3 py-3">
              {t("collection.line.title")}
            </th>
            <th className="px-3 py-3">
              {t("collection.line.amount")}
            </th>
            <th className="px-3 py-3">
              {t("collection.line.dueDate")}
            </th>
            {showSchedulingMetadata ? (
              <>
                <th className="px-3 py-3">
                  {t("collection.summary.scheduledAt")}
                </th>
                <th className="px-3 py-3">
                  {t("collection.summary.scheduledBy")}
                </th>
              </>
            ) : (
              <th className="px-3 py-3">
                {t("collection.line.notes")}
              </th>
            )}
          </tr>
        </thead>
        <tbody>
          {collections.map((collection) => (
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
              {showSchedulingMetadata ? (
                <>
                  <td
                    className="px-3 py-4 text-sm text-[var(--text-secondary)]"
                    dir="ltr"
                  >
                    {collection.scheduled_at
                      ? formatDateTime(
                          collection.scheduled_at
                        )
                      : "—"}
                  </td>
                  <td className="px-3 py-4 text-sm text-[var(--text-secondary)]">
                    {collection.scheduled_by?.name ?? "—"}
                  </td>
                </>
              ) : (
                <td className="max-w-64 px-3 py-4 text-sm text-[var(--text-secondary)]">
                  {collection.notes || "—"}
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </DataTable>
    </div>
  );
}

export function formatCollectionAmount(
  amount: string,
  currency: string
): string {
  return `${formatMoney(amount)} ${currency}`;
}

export function collectionAmountsEqual(
  left: string,
  right: string
): boolean {
  const leftNumber = Number(left);
  const rightNumber = Number(right);

  return (
    Number.isFinite(leftNumber) &&
    Number.isFinite(rightNumber) &&
    leftNumber.toFixed(2) === rightNumber.toFixed(2)
  );
}
