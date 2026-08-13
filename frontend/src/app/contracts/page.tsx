"use client";

import {
  Ban,
  CheckCircle2,
  CircleCheckBig,
  Edit3,
  FileSignature,
  Filter,
  Plus,
  RefreshCw,
  Search,
} from "lucide-react";
import {
  useCallback,
  useEffect,
  useState,
} from "react";

import { ContractDetailsModal } from "@/components/contracts/ContractDetailsModal";
import { ContractFormModal } from "@/components/contracts/ContractFormModal";
import { ContractLifecycleDialog } from "@/components/contracts/ContractLifecycleDialog";
import {
  contractStatusLabels,
  lifecycleCopy,
  type LifecycleSelection,
} from "@/components/contracts/contract-presenters";
import { ContractsTable } from "@/components/contracts/ContractsTable";
import { AppShell } from "@/components/layout/AppShell";
import { Button } from "@/components/ui/Button";
import { SuccessBanner } from "@/components/ui/SuccessBanner";
import {
  CrudPageHeader,
  CrudPageLayout,
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
import {
  invalidateFrontendResources,
  useResourceInvalidation,
} from "@/hooks/useResourceInvalidation";
import { formatInteger } from "@/lib/number-format";
import {
  canCreateContract,
  canEditOrCancelReservation,
} from "@/lib/commercial-authorization";
import { useAuth } from "@/providers/AuthProvider";
import {
  createContract,
  executeContractLifecycleAction,
  fetchContract,
  fetchContracts,
  updateContract,
} from "@/services/contracts";
import {
  fetchReservation,
  fetchReservations,
} from "@/services/reservations";
import type {
  Contract,
  ContractFormPayload,
  ContractLifecycleAction,
  ContractStatus,
  ContractSummary,
  ContractUpdatePayload,
} from "@/types/contract";
import type { Reservation } from "@/types/reservation";

const emptySummary: ContractSummary = {
  total: 0,
  draft: 0,
  active: 0,
  completed: 0,
  cancelled: 0,
};

export default function ContractsPage() {
  const { token, user } = useAuth();
  const [contracts, setContracts] = useState<Contract[]>([]);
  const [summary, setSummary] = useState<ContractSummary>(emptySummary);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] =
    useState<ContractStatus | "all">("all");
  const [isLoading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const [isCreateOpen, setCreateOpen] = useState(false);
  const [activeReservations, setActiveReservations] = useState<Reservation[]>(
    []
  );
  const [isLoadingReservations, setLoadingReservations] = useState(false);
  const [reservationLoadError, setReservationLoadError] =
    useState<string | null>(null);
  const [isSubmitting, setSubmitting] = useState(false);
  const [editContract, setEditContract] = useState<Contract | null>(null);

  const [detailContract, setDetailContract] = useState<Contract | null>(null);
  const [detailReservation, setDetailReservation] =
    useState<Reservation | null>(null);
  const [isDetailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);

  const [lifecycleSelection, setLifecycleSelection] =
    useState<LifecycleSelection | null>(null);
  const [isProcessingLifecycle, setProcessingLifecycle] = useState(false);
  const [lifecycleError, setLifecycleError] = useState<string | null>(null);

  const loadContracts = useCallback(
    async (targetPage: number): Promise<void> => {
      if (!token) {
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const response = await fetchContracts(token, {
          page: targetPage,
          per_page: 20,
          search,
          status: statusFilter === "all" ? undefined : statusFilter,
        });

        setContracts(response.data.contracts.data);
        setPage(response.data.contracts.meta.current_page);
        setLastPage(response.data.contracts.meta.last_page);
        setTotal(response.data.contracts.meta.total);
        setSummary(response.data.summary);
      } catch (caughtError) {
        setError(
          caughtError instanceof Error
            ? caughtError.message
            : "تعذر تحميل العقود."
        );
      } finally {
        setLoading(false);
      }
    },
    [search, statusFilter, token]
  );

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void loadContracts(page);
    }, search ? 300 : 0);

    return () => window.clearTimeout(timeout);
  }, [loadContracts, page, search]);

  useResourceInvalidation("contracts", () => loadContracts(page));

  const hasFilters = Boolean(search || statusFilter !== "all");

  const resetFilters = (): void => {
    setSearch("");
    setStatusFilter("all");
    setPage(1);
  };

  const openCreateForm = async (): Promise<void> => {
    if (!token || !canCreateContract(user?.role)) {
      return;
    }

    setCreateOpen(true);
    setActiveReservations([]);
    setLoadingReservations(true);
    setReservationLoadError(null);

    try {
      const firstPage = await fetchReservations(token, {
        page: 1,
        status: "active",
        per_page: 100,
      });
      const remainingPages = Array.from(
        {
          length: Math.max(
            0,
            firstPage.data.reservations.meta.last_page - 1
          ),
        },
        (_, index) => index + 2
      );
      const remainingResponses = await Promise.all(
        remainingPages.map((reservationPage) =>
          fetchReservations(token, {
            page: reservationPage,
            status: "active",
            per_page: 100,
          })
        )
      );

      setActiveReservations([
        ...firstPage.data.reservations.data,
        ...remainingResponses.flatMap(
          (response) => response.data.reservations.data
        ),
      ].filter((reservation) => canEditOrCancelReservation(user, reservation)));
    } catch (caughtError) {
      setReservationLoadError(
        caughtError instanceof Error
          ? caughtError.message
          : "تعذر تحميل الحجوزات النشطة."
      );
    } finally {
      setLoadingReservations(false);
    }
  };

  const submitCreate = async (
    payload: ContractFormPayload
  ): Promise<void> => {
    if (!token) {
      return;
    }

    setSubmitting(true);
    try {
      await createContract(token, payload);
      setCreateOpen(false);
      setSuccessMessage("تم إنشاء العقد بنجاح.");
      await loadContracts(1);
    } finally {
      setSubmitting(false);
    }
  };

  const submitUpdate = async (
    payload: ContractUpdatePayload
  ): Promise<void> => {
    if (!token || !editContract) {
      return;
    }

    setSubmitting(true);
    try {
      await updateContract(token, editContract.id, payload);
      setEditContract(null);
      setSuccessMessage("تم تحديث العقد بنجاح.");
      await loadContracts(page);
    } finally {
      setSubmitting(false);
    }
  };

  const openDetails = async (contract: Contract): Promise<void> => {
    if (!token) {
      return;
    }

    setDetailContract(contract);
    setDetailReservation(null);
    setDetailLoading(true);
    setDetailError(null);

    try {
      const [loadedContract, reservation] = await Promise.all([
        fetchContract(token, contract.id),
        fetchReservation(token, contract.reservation_id),
      ]);
      setDetailContract(loadedContract);
      setDetailReservation(reservation);
    } catch (caughtError) {
      setDetailError(
        caughtError instanceof Error
          ? caughtError.message
          : "تعذر تحميل تفاصيل العقد."
      );
    } finally {
      setDetailLoading(false);
    }
  };

  const openLifecycleDialog = (
    contract: Contract,
    action: ContractLifecycleAction
  ): void => {
    setLifecycleSelection({ contract, action });
    setLifecycleError(null);
  };

  const submitLifecycleAction = async (): Promise<void> => {
    if (!token || !lifecycleSelection) {
      return;
    }

    setProcessingLifecycle(true);
    setLifecycleError(null);

    try {
      const copy = lifecycleCopy[lifecycleSelection.action];
      await executeContractLifecycleAction(
        token,
        lifecycleSelection.contract.id,
        lifecycleSelection.action
      );
      invalidateFrontendResources([
        "projects",
        "units",
        "reservations",
        "contracts",
      ]);
      setLifecycleSelection(null);
      setSuccessMessage(copy.successMessage);
      await loadContracts(page);
    } catch (caughtError) {
      setLifecycleError(
        caughtError instanceof Error
          ? caughtError.message
          : "تعذر تنفيذ الإجراء."
      );
    } finally {
      setProcessingLifecycle(false);
    }
  };

  return (
    <AppShell>
      <CrudPageLayout>
        <CrudPageHeader
          icon={FileSignature}
          title="العقود"
          description="إنشاء العقود ومتابعة حالتها وإجراءات دورة حياتها"
          action={canCreateContract(user?.role) ? (
            <Button
              type="button"
              onClick={() => void openCreateForm()}
              variant="primary"
              leadingIcon={<Plus size={18} />}
              className="min-w-44"
            >
              إنشاء عقد
            </Button>
          ) : undefined}
        />

        {successMessage ? (
          <SuccessBanner
            message={successMessage}
            onDismiss={() => setSuccessMessage(null)}
          />
        ) : null}

        <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
          <SummaryCard
            title="إجمالي العقود"
            value={summary.total}
            icon={FileSignature}
          />
          <SummaryCard
            title="المسودات"
            value={summary.draft}
            icon={Edit3}
          />
          <SummaryCard
            title="العقود النشطة"
            value={summary.active}
            icon={CircleCheckBig}
            tone="success"
          />
          <SummaryCard
            title="العقود المكتملة"
            value={summary.completed}
            icon={CheckCircle2}
            tone="info"
          />
          <SummaryCard
            title="العقود الملغاة"
            value={summary.cancelled}
            icon={Ban}
          />
        </section>

        <CrudSection>
          <FilterBar
            title="الفلاتر"
            icon={
              <Filter
                size={17}
                className="text-[var(--brand-gold-strong)]"
              />
            }
          >
            <div className="grid gap-3 md:grid-cols-[minmax(240px,1fr)_200px_auto_auto]">
              <div className="relative">
                <Search
                  size={17}
                  className="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
                />
                <input
                  type="search"
                  value={search}
                  onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                  }}
                  placeholder="البحث برقم الوحدة أو اسم العميل"
                  className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] ps-11 pe-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
                />
              </div>

              <select
                value={statusFilter}
                onChange={(event) => {
                  setStatusFilter(
                    event.target.value as ContractStatus | "all"
                  );
                  setPage(1);
                }}
                className="h-11 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)]"
              >
                <option value="all">كل الحالات</option>
                {Object.entries(contractStatusLabels).map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>

              <Button
                type="button"
                variant="secondary"
                onClick={() => void loadContracts(page)}
                disabled={isLoading}
                className="gap-2"
              >
                <RefreshCw
                  size={17}
                  className={isLoading ? "animate-spin" : ""}
                />
                تحديث
              </Button>

              {hasFilters ? (
                <Button type="button" variant="ghost" onClick={resetFilters}>
                  إعادة تعيين الفلاتر
                </Button>
              ) : null}
            </div>
          </FilterBar>
        </CrudSection>

        <CrudSection className="p-4 sm:p-5">
          <div className="mb-5 flex items-center justify-between">
            <p className="text-sm font-bold text-[var(--text-primary)]">
              النتائج: {formatInteger(total)}
            </p>
          </div>

          {isLoading ? (
            <ListLoadingState label="جارٍ تحميل العقود..." />
          ) : error ? (
            <ListErrorState
              message={error}
              action={
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  onClick={() => void loadContracts(page)}
                >
                  إعادة المحاولة
                </Button>
              }
            />
          ) : contracts.length === 0 ? (
            <ListEmptyState
              icon={FileSignature}
              title="لا توجد عقود"
              description={
                hasFilters
                  ? "لا توجد عقود مطابقة للفلاتر الحالية."
                  : "لا توجد عقود مسجلة حتى الآن."
              }
              action={
                hasFilters ? (
                  <Button
                    type="button"
                    variant="secondary"
                    onClick={resetFilters}
                  >
                    مسح الفلاتر
                  </Button>
                ) : undefined
              }
            />
          ) : (
            <ContractsTable
              contracts={contracts}
              currentUser={user}
              onDetails={openDetails}
              onEdit={setEditContract}
              onLifecycle={openLifecycleDialog}
            />
          )}

          <Pagination
            page={page}
            lastPage={lastPage}
            isLoading={isLoading}
            previousLabel="السابق"
            nextLabel="التالي"
            pageLabel="الصفحة"
            ofLabel="من"
            onPrevious={() => setPage((current) => current - 1)}
            onNext={() => setPage((current) => current + 1)}
          />
        </CrudSection>
      </CrudPageLayout>

      {isCreateOpen ? (
        <ContractFormModal
          key="create-contract"
          isOpen
          reservations={activeReservations}
          isLoadingReservations={isLoadingReservations}
          reservationLoadError={reservationLoadError}
          isSubmitting={isSubmitting}
          onClose={() => {
            if (!isSubmitting) {
              setCreateOpen(false);
            }
          }}
          onCreate={submitCreate}
        />
      ) : null}

      {editContract ? (
        <ContractFormModal
          key={editContract.id}
          isOpen
          contract={editContract}
          isSubmitting={isSubmitting}
          onClose={() => {
            if (!isSubmitting) {
              setEditContract(null);
            }
          }}
          onUpdate={submitUpdate}
        />
      ) : null}

      <ContractDetailsModal
        key={detailContract?.id ?? "contract-details"}
        contract={detailContract}
        reservation={detailReservation}
        isLoading={isDetailLoading}
        error={detailError}
        onClose={() => {
          setDetailContract(null);
          setDetailReservation(null);
          setDetailError(null);
        }}
      />

      <ContractLifecycleDialog
        selection={lifecycleSelection}
        error={lifecycleError}
        isProcessing={isProcessingLifecycle}
        onCancel={() => {
          if (!isProcessingLifecycle) {
            setLifecycleSelection(null);
            setLifecycleError(null);
          }
        }}
        onConfirm={() => void submitLifecycleAction()}
      />
    </AppShell>
  );
}
