import {
  Archive,
  CalendarClock,
  CircleCheckBig,
  Columns3,
  Filter,
  List,
  Plus,
  RefreshCw,
  Search,
  UserRoundCheck,
  UsersRound,
} from "lucide-react";

import { FollowUpQueueTabs } from "@/components/leads/FollowUpQueueTabs";
import { LeadsPipelineView } from "@/components/leads/LeadsPipelineView";
import { LeadsTable } from "@/components/leads/LeadsTable";
import {
  leadSources,
  leadStages,
  sourceKey,
  stageKey,
} from "@/components/leads/lead-options";
import { Button } from "@/components/ui/Button";
import {
  CrudPageHeader,
  CrudSection,
} from "@/components/ui/crud/CrudPageLayout";
import { FilterBar } from "@/components/ui/crud/FilterBar";
import {
  ListEmptyState,
  ListErrorState,
  ListLoadingState,
} from "@/components/ui/crud/ListState";
import { Pagination } from "@/components/ui/crud/Pagination";
import { SummaryCard } from "@/components/ui/crud/SummaryCard";
import { useTranslation } from "@/hooks/useTranslation";
import type {
  Lead,
  LeadsIndexQuery,
  LeadsIndexResponse,
} from "@/types/lead";
import type { Project } from "@/types/project";
import type { TenantUser } from "@/types/tenant-user";

type QueryChange = Record<
  string,
  string | number | boolean | null | undefined
>;

type LeadsIndexViewProps = {
  query: LeadsIndexQuery;
  viewMode: "list" | "pipeline";
  index: LeadsIndexResponse["data"] | null;
  projects: Project[];
  assignees: TenantUser[];
  searchInput: string;
  successMessage: string | null;
  isAdministrator: boolean;
  isLoading: boolean;
  error: string | null;
  renderedAt: number;
  hasFilters: boolean;
  onSearchInput: (value: string) => void;
  onQueryChange: (changes: QueryChange) => void;
  onViewChange: (view: "list" | "pipeline") => void;
  onCreate: () => void;
  onRefresh: () => void;
  onReset: () => void;
  onOpen: (lead: Lead) => void;
};

export function LeadsIndexView({
  query,
  viewMode,
  index,
  projects,
  assignees,
  searchInput,
  successMessage,
  isAdministrator,
  isLoading,
  error,
  renderedAt,
  hasFilters,
  onSearchInput,
  onQueryChange,
  onViewChange,
  onCreate,
  onRefresh,
  onReset,
  onOpen,
}: LeadsIndexViewProps) {
  const { t } = useTranslation();
  const summary = index?.summary;
  const pagination = index?.pagination;
  const leads = index?.leads ?? [];

  return (
    <>
      <CrudPageHeader
        icon={UsersRound}
        title={t("pages.crm.title")}
        description={t("pages.crm.description")}
        action={
          <Button
            type="button"
            leadingIcon={<Plus size={17} />}
            onClick={onCreate}
          >
            {t("crm.create")}
          </Button>
        }
      />

      {successMessage ? (
        <div
          role="status"
          className="rounded-xl border border-[var(--success)]/25 bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]"
        >
          {successMessage}
        </div>
      ) : null}

      {summary ? (
        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <SummaryCard
            title={t("crm.cards.openLeads")}
            value={summary.open_leads}
            icon={UsersRound}
            tone="gold"
          />
          <SummaryCard
            title={t("crm.cards.overdueFollowUps")}
            value={summary.overdue_follow_ups}
            icon={CalendarClock}
            tone="gold"
          />
          <SummaryCard
            title={t("crm.cards.todayFollowUps")}
            value={summary.today_follow_ups}
            icon={CalendarClock}
            tone="info"
          />
          <SummaryCard
            title={t("crm.cards.unassignedLeads")}
            value={summary.unassigned_leads}
            icon={UserRoundCheck}
            tone="info"
          />
          <SummaryCard
            title={t("crm.cards.monthlyConversions")}
            value={summary.monthly_conversions}
            icon={CircleCheckBig}
            tone="success"
          />
          <SummaryCard
            title={t("crm.cards.lostInPeriod")}
            value={summary.lost_leads_in_selected_period}
            icon={UsersRound}
            tone="gold"
          />
        </section>
      ) : null}

      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button
          type="button"
          size="sm"
          variant={viewMode === "list" ? "primary" : "secondary"}
          leadingIcon={<List size={16} />}
          onClick={() => onViewChange("list")}
        >
          {t("crm.view.list")}
        </Button>
        <Button
          type="button"
          size="sm"
          variant={viewMode === "pipeline" ? "primary" : "secondary"}
          leadingIcon={<Columns3 size={16} />}
          onClick={() => onViewChange("pipeline")}
        >
          {t("crm.view.pipeline")}
        </Button>
      </div>

      <FollowUpQueueTabs
        activeBucket={query.follow_up_bucket ?? ""}
        title={t("crm.queue.title")}
        allLabel={t("crm.queue.all")}
        items={[
          { value: "overdue", label: t("crm.queue.overdue") },
          { value: "today", label: t("crm.queue.today") },
          { value: "tomorrow", label: t("crm.queue.tomorrow") },
          { value: "this_week", label: t("crm.queue.thisWeek") },
          { value: "unscheduled", label: t("crm.queue.unscheduled") },
        ]}
        onChange={(followUpBucket) =>
          onQueryChange({
            follow_up_bucket: followUpBucket,
            follow_up_state: null,
            overdue: null,
            archived: null,
            page: 1,
          })
        }
      />

      <CrudSection>
        <FilterBar
          title={t("crm.filters.title")}
          icon={
            <Filter
              size={17}
              className="text-[var(--brand-gold-strong)]"
            />
          }
        >
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <label className="relative block md:col-span-2">
              <span className="sr-only">{t("crm.search.label")}</span>
              <Search
                size={17}
                aria-hidden="true"
                className="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
              />
              <input
                type="search"
                value={searchInput}
                onChange={(event) => onSearchInput(event.target.value)}
                placeholder={t("crm.search.placeholder")}
                className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] ps-11 pe-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
              />
            </label>

            <FilterSelect
              label={t("crm.table.stage")}
              value={query.stage ?? ""}
              onChange={(value) => onQueryChange({ stage: value, page: 1 })}
              options={[
                { value: "", label: t("crm.filters.allStages") },
                ...leadStages.map((stage) => ({
                  value: stage,
                  label: t(stageKey(stage)),
                })),
              ]}
            />
            <FilterSelect
              label={t("crm.table.source")}
              value={query.source ?? ""}
              onChange={(value) => onQueryChange({ source: value, page: 1 })}
              options={[
                { value: "", label: t("crm.filters.allSources") },
                ...leadSources.map((source) => ({
                  value: source,
                  label: t(sourceKey(source)),
                })),
              ]}
            />
            <FilterSelect
              label={t("crm.table.assignee")}
              value={query.assigned_to ? String(query.assigned_to) : ""}
              onChange={(value) =>
                onQueryChange({ assigned_to: value, page: 1 })
              }
              options={[
                { value: "", label: t("crm.filters.allAssignees") },
                ...assignees.map((membership) => ({
                  value: String(membership.user.id),
                  label: membership.user.name,
                })),
              ]}
            />
            <FilterSelect
              label={t("crm.table.project")}
              value={query.project_id ?? ""}
              onChange={(value) =>
                onQueryChange({ project_id: value, page: 1 })
              }
              options={[
                { value: "", label: t("crm.filters.allProjects") },
                ...projects
                  .filter((project) => project.archived_at === null)
                  .map((project) => ({
                    value: project.id,
                    label: project.name,
                  })),
              ]}
            />

            <FilterSelect
              label={t("crm.filters.lifecycle")}
              value={query.lifecycle ?? ""}
              onChange={(value) =>
                onQueryChange({ lifecycle: value, page: 1 })
              }
              options={[
                { value: "", label: t("crm.filters.allLifecycle") },
                { value: "open", label: t("crm.lifecycle.open") },
                { value: "won", label: t("crm.lifecycle.won") },
                { value: "lost", label: t("crm.lifecycle.lost") },
              ]}
            />

            <FilterSelect
              label={t("crm.filters.followUpState")}
              value={query.follow_up_state ?? ""}
              onChange={(value) =>
                onQueryChange({
                  follow_up_state: value,
                  follow_up_bucket: null,
                  overdue: null,
                  archived: null,
                  page: 1,
                })
              }
              options={[
                { value: "", label: t("crm.filters.allFollowUpStates") },
                { value: "overdue", label: t("crm.queue.overdue") },
                { value: "today", label: t("crm.queue.today") },
                { value: "tomorrow", label: t("crm.queue.tomorrow") },
                { value: "this_week", label: t("crm.queue.thisWeek") },
                {
                  value: "scheduled_later",
                  label: t("crm.filters.scheduledLater"),
                },
                { value: "unscheduled", label: t("crm.queue.unscheduled") },
              ]}
            />

            <FilterDate
              label={t("crm.filters.dateFrom")}
              value={query.date_from ?? ""}
              onChange={(value) =>
                onQueryChange({ date_from: value, page: 1 })
              }
            />

            <FilterDate
              label={t("crm.filters.dateTo")}
              value={query.date_to ?? ""}
              min={query.date_from || undefined}
              onChange={(value) =>
                onQueryChange({ date_to: value, page: 1 })
              }
            />

            {isAdministrator ? (
              <label className="flex h-11 items-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm font-semibold text-[var(--text-secondary)]">
                <input
                  type="checkbox"
                  checked={Boolean(query.archived)}
                  onChange={(event) =>
                    onQueryChange({
                      archived: event.target.checked,
                      follow_up_bucket: null,
                      follow_up_state: null,
                      overdue: null,
                      page: 1,
                      lead: null,
                    })
                  }
                />
                <Archive size={16} aria-hidden="true" />
                {t("crm.filters.archived")}
              </label>
            ) : null}

            <div className="flex flex-wrap gap-2 xl:col-span-2 xl:justify-end">
              <Button
                type="button"
                variant="secondary"
                disabled={isLoading}
                leadingIcon={
                  <RefreshCw
                    size={16}
                    className={isLoading ? "animate-spin" : ""}
                  />
                }
                onClick={onRefresh}
              >
                {t("crm.refresh")}
              </Button>
              {hasFilters ? (
                <Button type="button" variant="ghost" onClick={onReset}>
                  {t("crm.resetFilters")}
                </Button>
              ) : null}
            </div>
          </div>
        </FilterBar>
      </CrudSection>

      <CrudSection className="p-4 sm:p-5">
        {isLoading ? (
          <ListLoadingState label={t("crm.loading")} />
        ) : error ? (
          <ListErrorState
            message={error}
            action={
              <Button
                type="button"
                size="sm"
                variant="secondary"
                onClick={onRefresh}
              >
                {t("crm.retry")}
              </Button>
            }
          />
        ) : leads.length === 0 ? (
          <ListEmptyState
            icon={UsersRound}
            title={
              hasFilters ? t("crm.emptyFiltered.title") : t("crm.empty.title")
            }
            description={
              hasFilters
                ? t("crm.emptyFiltered.description")
                : t("crm.empty.description")
            }
            action={
              hasFilters ? (
                <Button type="button" variant="secondary" onClick={onReset}>
                  {t("crm.resetFilters")}
                </Button>
              ) : undefined
            }
          />
        ) : viewMode === "pipeline" ? (
          <LeadsPipelineView leads={leads} onOpen={onOpen} />
        ) : (
          <LeadsTable leads={leads} now={renderedAt} onOpen={onOpen} />
        )}

        {pagination ? (
          <Pagination
            page={pagination.current_page}
            lastPage={pagination.last_page}
            isLoading={isLoading}
            previousLabel={t("crm.pagination.previous")}
            nextLabel={t("crm.pagination.next")}
            pageLabel={t("crm.pagination.page")}
            ofLabel={t("crm.pagination.of")}
            onPrevious={() =>
              onQueryChange({
                page: Math.max(1, pagination.current_page - 1),
              })
            }
            onNext={() =>
              onQueryChange({ page: pagination.current_page + 1 })
            }
          />
        ) : null}
      </CrudSection>
    </>
  );
}

function FilterDate({
  label,
  value,
  min,
  onChange,
}: {
  label: string;
  value: string;
  min?: string;
  onChange: (value: string) => void;
}) {
  return (
    <label className="block">
      <span className="sr-only">{label}</span>
      <input
        type="date"
        aria-label={label}
        value={value}
        min={min}
        onChange={(event) => onChange(event.target.value)}
        className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
      />
    </label>
  );
}

function FilterSelect({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: Array<{ value: string; label: string }>;
  onChange: (value: string) => void;
}) {
  return (
    <label className="block">
      <span className="sr-only">{label}</span>
      <select
        aria-label={label}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  );
}
