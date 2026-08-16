"use client";

import {
  CircleCheckBig,
  FilePen,
  FileSignature,
  FileX,
  HandCoins,
  RefreshCw,
  Search,
} from "lucide-react";
import {
  useCallback,
  useEffect,
  useRef,
  useState,
} from "react";

import { CollectionsIndexTable } from "@/components/collections/CollectionsIndexTable";
import { ModulePageGuard } from "@/components/entitlements/ModulePageGuard";
import { ContractDetailsModal } from "@/components/contracts/ContractDetailsModal";
import { AppShell } from "@/components/layout/AppShell";
import { Button } from "@/components/ui/Button";
import {
  CrudPageHeader,
  CrudPageLayout,
  CrudSection,
} from "@/components/ui/crud/CrudPageLayout";
import {
  ListEmptyState,
  ListErrorState,
  ListLoadingState,
} from "@/components/ui/crud/ListState";
import { Pagination } from "@/components/ui/crud/Pagination";
import { SummaryCard } from "@/components/ui/crud/SummaryCard";
import { useTranslation } from "@/hooks/useTranslation";
import { useAuth } from "@/providers/AuthProvider";
import { fetchCollectionsIndex } from "@/services/collections";
import { fetchContract } from "@/services/contracts";
import { fetchReservation } from "@/services/reservations";
import type {
  CollectionsIndexItem,
  CollectionsIndexQuery,
  CollectionsIndexResponse,
  ContractStatus,
  DerivedScheduleState,
} from "@/types/collection";
import type { Contract } from "@/types/contract";
import type { Reservation } from "@/types/reservation";

type DetailTab = "details" | "collections";

const defaultQuery: CollectionsIndexQuery = {
  page: 1,
  per_page: 20,
  search: "",
  status: "",
  schedule_state: "",
};

const contractStatuses: ContractStatus[] = [
  "draft",
  "active",
  "completed",
  "cancelled",
];

const scheduleStates: DerivedScheduleState[] = [
  "absent",
  "draft",
  "scheduled",
  "cancelled",
];

export default function CollectionsPage() {
  return (
    <ModulePageGuard module="collections">
      <CollectionsPageContent />
    </ModulePageGuard>
  );
}

function CollectionsPageContent() {
  const { token } = useAuth();
  const { t, isArabic } = useTranslation();
  const [query, setQuery] =
    useState<CollectionsIndexQuery>(defaultQuery);
  const [searchInput, setSearchInput] = useState("");
  const [response, setResponse] =
    useState<CollectionsIndexResponse | null>(null);
  const [isLoading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const requestSequence = useRef(0);

  const [detailContract, setDetailContract] =
    useState<Contract | null>(null);
  const [detailReservation, setDetailReservation] =
    useState<Reservation | null>(null);
  const [detailTab, setDetailTab] =
    useState<DetailTab>("details");
  const [isDetailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);
  const detailRequestSequence = useRef(0);

  const invalidateIndexRequests = useCallback((): void => {
    requestSequence.current++;
  }, []);

  const loadIndex = useCallback(
    async (currentQuery: CollectionsIndexQuery): Promise<void> => {
      if (!token) {
        return;
      }

      const requestId = ++requestSequence.current;
      setLoading(true);
      setError(null);

      try {
        const result = await fetchCollectionsIndex(token, currentQuery);

        if (requestId === requestSequence.current) {
          setResponse(result);
        }
      } catch (caughtError) {
        if (requestId === requestSequence.current) {
          setError(
            caughtError instanceof Error
              ? caughtError.message
              : t("collections.error")
          );
        }
      } finally {
        if (requestId === requestSequence.current) {
          setLoading(false);
        }
      }
    },
    [t, token]
  );

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void loadIndex(query);
    }, 0);

    return () => {
      window.clearTimeout(timeout);
      invalidateIndexRequests();
    };
  }, [invalidateIndexRequests, loadIndex, query]);

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setQuery((current) => {
        if ((current.search ?? "") === searchInput) {
          return current;
        }

        return {
          ...current,
          search: searchInput,
          page: 1,
        };
      });
    }, 400);

    return () => window.clearTimeout(timeout);
  }, [searchInput]);

  const items = response?.data.items ?? [];
  const pagination = response?.data.pagination;
  const summary = response?.data.summary;
  const hasFilters = Boolean(
    searchInput ||
      query.status ||
      query.schedule_state
  );

  const resetFilters = (): void => {
    setSearchInput("");
    setQuery({
      ...defaultQuery,
    });
  };

  const setContractStatus = (status: ContractStatus | ""): void => {
    setQuery((current) => ({
      ...current,
      status,
      page: 1,
    }));
  };

  const setScheduleState = (
    scheduleState: DerivedScheduleState | ""
  ): void => {
    setQuery((current) => ({
      ...current,
      schedule_state: scheduleState,
      page: 1,
    }));
  };

  const openDetails = async (
    item: CollectionsIndexItem,
    initialTab: DetailTab
  ): Promise<void> => {
    if (!token || isDetailLoading) {
      return;
    }

    const requestId = ++detailRequestSequence.current;
    setDetailTab(initialTab);
    setDetailContract(null);
    setDetailReservation(null);
    setDetailError(null);
    setDetailLoading(true);

    try {
      const contract = await fetchContract(token, item.contract_id);
      const reservation = await fetchReservation(
        token,
        contract.reservation_id
      );

      if (requestId === detailRequestSequence.current) {
        setDetailContract(contract);
        setDetailReservation(reservation);
      }
    } catch (caughtError) {
      if (requestId === detailRequestSequence.current) {
        setDetailError(
          caughtError instanceof Error
            ? caughtError.message
            : t("collections.error")
        );
      }
    } finally {
      if (requestId === detailRequestSequence.current) {
        setDetailLoading(false);
      }
    }
  };

  const closeDetails = (): void => {
    detailRequestSequence.current++;
    setDetailContract(null);
    setDetailReservation(null);
    setDetailError(null);
    setDetailLoading(false);
  };

  return (
    <AppShell>
      <CrudPageLayout>
        <CrudPageHeader
          icon={HandCoins}
          title={t("pages.collections.title")}
          description={t("pages.collections.description")}
        />

        {summary ? (
          <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <SummaryCard
              title={t("collections.cards.totalContracts")}
              value={summary.total_contracts}
              icon={FileSignature}
              tone="gold"
            />
            <SummaryCard
              title={t("collections.cards.scheduled")}
              value={summary.scheduled_count}
              icon={CircleCheckBig}
              tone="success"
            />
            <SummaryCard
              title={t("collections.cards.draft")}
              value={summary.draft_count}
              icon={FilePen}
              tone="info"
            />
            <SummaryCard
              title={t("collections.cards.absent")}
              value={summary.absent_count}
              icon={FileX}
              tone="gold"
            />
          </section>
        ) : null}

        <CrudSection className="p-4 sm:p-5">
          <div className="grid gap-3 lg:grid-cols-[minmax(240px,1fr)_190px_190px_auto_auto]">
            <label className="relative block">
              <span className="sr-only">
                {t("collections.search.label")}
              </span>
              <Search
                size={17}
                aria-hidden="true"
                className="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
              />
              <input
                type="search"
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                placeholder={t("collections.search.placeholder")}
                className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] ps-11 pe-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
              />
            </label>

            <label className="block">
              <span className="sr-only">
                {t("collections.filter.status")}
              </span>
              <select
                value={query.status ?? ""}
                aria-label={t("collections.filter.status")}
                onChange={(event) =>
                  setContractStatus(
                    event.target.value as ContractStatus | ""
                  )
                }
                className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)]"
              >
                <option value="">
                  {t("collections.filter.allStatuses")}
                </option>
                {contractStatuses.map((status) => (
                  <option key={status} value={status}>
                    {t(`collections.contractStatus.${status}`)}
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="sr-only">
                {t("collections.filter.scheduleState")}
              </span>
              <select
                value={query.schedule_state ?? ""}
                aria-label={t("collections.filter.scheduleState")}
                onChange={(event) =>
                  setScheduleState(
                    event.target.value as DerivedScheduleState | ""
                  )
                }
                className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)]"
              >
                <option value="">
                  {t("collections.filter.allScheduleStates")}
                </option>
                {scheduleStates.map((state) => (
                  <option key={state} value={state}>
                    {t(`collections.scheduleState.${state}`)}
                  </option>
                ))}
              </select>
            </label>

            <Button
              type="button"
              variant="secondary"
              aria-label={t("collections.refresh")}
              title={t("collections.refresh")}
              disabled={isLoading}
              className="w-full px-3 lg:w-11"
              onClick={() => {
                if (!isLoading) {
                  void loadIndex(query);
                }
              }}
            >
              <RefreshCw
                size={17}
                aria-hidden="true"
                className={isLoading ? "animate-spin" : ""}
              />
              <span className="lg:sr-only">
                {t("collections.refresh")}
              </span>
            </Button>

            {hasFilters ? (
              <Button
                type="button"
                variant="ghost"
                onClick={resetFilters}
              >
                {t("collections.resetFilters")}
              </Button>
            ) : null}
          </div>
        </CrudSection>

        <CrudSection className="p-4 sm:p-5">
          {isLoading ? (
            <ListLoadingState label={t("collections.loading")} />
          ) : error ? (
            <ListErrorState
              message={error}
              action={
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  onClick={() => void loadIndex(query)}
                >
                  {t("collections.retry")}
                </Button>
              }
            />
          ) : items.length === 0 ? (
            <ListEmptyState
              icon={FileSignature}
              title={
                hasFilters
                  ? t("collections.emptyFiltered.title")
                  : t("collections.empty.title")
              }
              description={
                hasFilters
                  ? t("collections.emptyFiltered.description")
                  : t("collections.empty.description")
              }
              action={
                hasFilters ? (
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={resetFilters}
                  >
                    {t("collections.resetFilters")}
                  </Button>
                ) : undefined
              }
            />
          ) : (
            <CollectionsIndexTable
              items={items}
              isActionLoading={isDetailLoading}
              onOpenContract={(item) =>
                void openDetails(item, "details")
              }
              onOpenSchedule={(item) =>
                void openDetails(item, "collections")
              }
            />
          )}

          {pagination ? (
            <Pagination
              page={pagination.current_page}
              lastPage={pagination.last_page}
              isLoading={isLoading}
              previousLabel={isArabic ? "السابق" : "Previous"}
              nextLabel={isArabic ? "التالي" : "Next"}
              pageLabel={isArabic ? "الصفحة" : "Page"}
              ofLabel={isArabic ? "من" : "of"}
              onPrevious={() =>
                setQuery((current) => ({
                  ...current,
                  page: Math.max(1, (current.page ?? 1) - 1),
                }))
              }
              onNext={() =>
                setQuery((current) => ({
                  ...current,
                  page: (current.page ?? 1) + 1,
                }))
              }
            />
          ) : null}
        </CrudSection>
      </CrudPageLayout>

      <ContractDetailsModal
        contract={detailContract}
        reservation={detailReservation}
        isLoading={isDetailLoading}
        error={detailError}
        initialTab={detailTab}
        onClose={closeDetails}
      />
    </AppShell>
  );
}
