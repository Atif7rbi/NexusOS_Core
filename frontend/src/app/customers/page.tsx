"use client";

import {
  Archive,
  BriefcaseBusiness,
  Filter,
  Plus,
  RefreshCw,
  Search,
  UserCheck,
  UserRoundSearch,
  Users,
} from "lucide-react";
import {
  Suspense,
  useCallback,
  useEffect,
  useState,
} from "react";
import { AppShell } from "@/components/layout/AppShell";
import { CustomerArchiveDialog } from "@/components/customers/CustomerArchiveDialog";
import { CustomerCard } from "@/components/customers/CustomerCard";
import { CustomerDetailsView } from "@/components/customers/CustomerDetailsView";
import { CustomerFormModal } from "@/components/customers/CustomerFormModal";
import { Button } from "@/components/ui/Button";
import { ApiRequestError } from "@/lib/api-error";
import { useCustomersQueryState } from "@/hooks/useCustomersQueryState";
import { useTranslation } from "@/hooks/useTranslation";
import { useAuth } from "@/providers/AuthProvider";
import {
  archiveCustomer,
  createCustomer,
  fetchCustomer,
  fetchCustomers,
  restoreCustomer,
  updateCustomer,
} from "@/services/customers";
import type {
  Customer,
  CustomerCategory,
  CustomerFormPayload,
  CustomerSummary,
  CustomerStatus,
  CustomerType,
} from "@/types/customer";

type CustomerAction = "archive" | "restore";

export default function CustomersPage() {
  return (
    <Suspense fallback={null}>
      <CustomersPageContent />
    </Suspense>
  );
}

function CustomersPageContent() {
  const { isArabic } = useTranslation();
  const { token } = useAuth();
  const query = useCustomersQueryState();

  const [customers, setCustomers] = useState<
    Customer[]
  >([]);

  const [pagination, setPagination] =
    useState<{
      last_page: number;
      total: number;
    }>({
      last_page: 1,
      total: 0,
    });

  const [summary, setSummary] =
    useState<CustomerSummary>({
      total: 0,
      customers: 0,
      leads: 0,
      archived: 0,
    });

  const [isLoading, setIsLoading] =
    useState(true);

  const [error, setError] =
    useState<string | null>(null);

  const [isFormOpen, setFormOpen] =
    useState(false);

  const [formCustomer, setFormCustomer] =
    useState<Customer | null>(null);

  const [isSubmitting, setSubmitting] =
    useState(false);

  const [
    customerToProcess,
    setCustomerToProcess,
  ] = useState<Customer | null>(null);

  const [customerAction, setCustomerAction] =
    useState<CustomerAction>("archive");

  const [isProcessing, setProcessing] =
    useState(false);

  const [customerRecord, setCustomerRecord] =
    useState<Customer | null>(null);

  const [
    isCustomerRecordLoading,
    setCustomerRecordLoading,
  ] = useState(false);

  const [
    customerRecordError,
    setCustomerRecordError,
  ] = useState<string | null>(null);

  const [
    isCustomerRecordNotFound,
    setCustomerRecordNotFound,
  ] = useState(false);

  const labels = isArabic
    ? {
        title: "العملاء",
        description:
          "إدارة العملاء المحتملين والعملاء والشركات وبيانات التواصل",
        newCustomer: "إضافة عميل",
        search:
          "البحث بالاسم أو الجوال أو البريد الإلكتروني",
        allStatuses: "كل الحالات",
        allTypes: "كل الأنواع",
        allCategories: "كل التصنيفات",
        refresh: "تحديث",
        loading: "جارٍ تحميل العملاء...",
        emptyTitle: "لا توجد نتائج",
        emptyDescription:
          "لا يوجد عملاء يطابقون معايير البحث الحالية.",
        firstCustomer:
          "ابدأ بإضافة أول عميل إلى النظام.",
        loadError: "تعذر تحميل العملاء.",
        total: "إجمالي العملاء",
        customers: "العملاء",
        leads: "العملاء المحتملون",
        archived: "المؤرشفون",
        filters: "الفلاتر",
        results: "النتائج",
        individual: "فرد",
        company: "شركة",
        investor: "مستثمر",
        buyer: "مشتري",
        broker: "وسيط",
        owner: "مالك",
        other: "أخرى",
        lead: "عميل محتمل",
        customer: "عميل",
        inactive: "غير نشط",
        archivedStatus: "مؤرشف",
        previous: "السابق",
        next: "التالي",
        page: "الصفحة",
        of: "من",
      }
    : {
        title: "Customers",
        description:
          "Manage leads, customers, companies and contact information",
        newCustomer: "Add customer",
        search:
          "Search by name, phone number or email",
        allStatuses: "All statuses",
        allTypes: "All types",
        allCategories: "All categories",
        refresh: "Refresh",
        loading: "Loading customers...",
        emptyTitle: "No results",
        emptyDescription:
          "No customers match the current search criteria.",
        firstCustomer:
          "Start by adding the first customer.",
        loadError: "Unable to load customers.",
        total: "Total customers",
        customers: "Customers",
        leads: "Leads",
        archived: "Archived",
        filters: "Filters",
        results: "Results",
        individual: "Individual",
        company: "Company",
        investor: "Investor",
        buyer: "Buyer",
        broker: "Broker",
        owner: "Owner",
        other: "Other",
        lead: "Lead",
        customer: "Customer",
        inactive: "Inactive",
        archivedStatus: "Archived",
        previous: "Previous",
        next: "Next",
        page: "Page",
        of: "of",
      };

  const statusLabels: Record<
    CustomerStatus,
    string
  > = {
    lead: labels.lead,
    customer: labels.customer,
    inactive: labels.inactive,
    archived: labels.archivedStatus,
  };

  const typeLabels: Record<
    CustomerType,
    string
  > = {
    individual: labels.individual,
    company: labels.company,
  };

  const categoryLabels: Record<
    CustomerCategory,
    string
  > = {
    investor: labels.investor,
    buyer: labels.buyer,
    broker: labels.broker,
    owner: labels.owner,
    other: labels.other,
  };

  const loadCustomers =
    useCallback(async (page = query.page): Promise<void> => {
      if (!token) {
        return;
      }

      setIsLoading(true);
      setError(null);

      try {
        const response = await fetchCustomers(
          token,
          {
            page,
            per_page: 20,
            search: query.search,
            status:
              query.status === "all"
                ? undefined
                : query.status,
            type:
              query.type === "all"
                ? undefined
                : query.type,
            category:
              query.category === "all"
                ? undefined
                : query.category,
          }
        );

        setCustomers(response.data.customers.data);
        setPagination({
          last_page:
            response.data.customers.last_page,
          total: response.data.customers.total,
        });
        setSummary(response.data.summary);
      } catch (caughtError) {
        setError(
          caughtError instanceof Error
            ? caughtError.message
            : labels.loadError
        );
      } finally {
        setIsLoading(false);
      }
    }, [
      token,
      query.page,
      query.search,
      query.status,
      query.type,
      query.category,
      labels.loadError,
    ]);

  useEffect(() => {
    if (!token) {
      return;
    }

    let isCancelled = false;

    fetchCustomers(token, {
      page: query.page,
      per_page: 20,
      search: query.search,
      status:
        query.status === "all"
          ? undefined
          : query.status,
      type:
        query.type === "all"
          ? undefined
          : query.type,
      category:
        query.category === "all"
          ? undefined
          : query.category,
    })
      .then((response) => {
        if (!isCancelled) {
          setCustomers(response.data.customers.data);
          setPagination({
            last_page:
              response.data.customers.last_page,
            total: response.data.customers.total,
          });
          setSummary(response.data.summary);
          setError(null);
        }
      })
      .catch((caughtError: unknown) => {
        if (!isCancelled) {
          setError(
            caughtError instanceof Error
              ? caughtError.message
              : labels.loadError
          );
        }
      })
      .finally(() => {
        if (!isCancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [
    token,
    query.page,
    query.search,
    query.status,
    query.type,
    query.category,
    labels.loadError,
  ]);

  const loadCustomerRecord =
    useCallback(async (): Promise<void> => {
      if (!token || !query.customerId) {
        setCustomerRecord(null);
        setCustomerRecordError(null);
        setCustomerRecordNotFound(false);
        setCustomerRecordLoading(false);
        return;
      }

      setCustomerRecordLoading(true);
      setCustomerRecordError(null);
      setCustomerRecordNotFound(false);

      try {
        const record = await fetchCustomer(
          token,
          query.customerId
        );

        setCustomerRecord(record);
      } catch (caughtError) {
        setCustomerRecord(null);

        if (
          caughtError instanceof ApiRequestError &&
          caughtError.status === 404
        ) {
          setCustomerRecordNotFound(true);
        } else {
          setCustomerRecordError(
            caughtError instanceof Error
              ? caughtError.message
              : labels.loadError
          );
        }
      } finally {
        setCustomerRecordLoading(false);
      }
    }, [
      token,
      query.customerId,
      labels.loadError,
    ]);

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      void loadCustomerRecord();
    }, 0);

    return () => {
      window.clearTimeout(timeoutId);
    };
  }, [loadCustomerRecord]);

  const openCreateModal = (): void => {
    setFormCustomer(null);
    setFormOpen(true);
  };

  const openEditModal = (
    customer: Customer
  ): void => {
    setFormCustomer(customer);
    setFormOpen(true);
  };

  const openActionDialog = (
    customer: Customer,
    action: CustomerAction
  ): void => {
    setCustomerToProcess(customer);
    setCustomerAction(action);
  };

  const handleFormSubmit = async (
    payload: CustomerFormPayload
  ): Promise<void> => {
    if (!token) {
      return;
    }

    setSubmitting(true);

    try {
      if (formCustomer) {
        const updatedCustomer =
          await updateCustomer(
            token,
            formCustomer.id,
            payload
          );

        setCustomerRecord((current) =>
          current?.id === updatedCustomer.id
            ? updatedCustomer
            : current
        );

        await loadCustomers();
      } else {
        await createCustomer(
          token,
          payload
        );

        await loadCustomers(1);
      }

      setFormOpen(false);
      setFormCustomer(null);
    } finally {
      setSubmitting(false);
    }
  };

  const handleCustomerAction =
    async (): Promise<void> => {
      if (!token || !customerToProcess) {
        return;
      }

      setProcessing(true);

      try {
        if (customerAction === "archive") {
          await archiveCustomer(
            token,
            customerToProcess.id
          );
        } else {
          await restoreCustomer(
            token,
            customerToProcess.id
          );
        }

        await loadCustomers();

        setCustomerToProcess(null);
      } finally {
        setProcessing(false);
      }
    };

  const hasActiveFilters =
    query.search.trim() !== "" ||
    query.status !== "all" ||
    query.type !== "all" ||
    query.category !== "all";

  if (query.customerId) {
    return (
      <AppShell>
        <CustomerDetailsView
          customer={customerRecord}
          isArabic={isArabic}
          isLoading={isCustomerRecordLoading}
          error={customerRecordError}
          isNotFound={isCustomerRecordNotFound}
          onBack={query.closeCustomer}
          onRetry={() => {
            void loadCustomerRecord();
          }}
          onEdit={() => {
            if (customerRecord) {
              openEditModal(customerRecord);
            }
          }}
        />

        {isFormOpen ? (
          <CustomerFormModal
            key={
              formCustomer?.id ??
              "customer-record-edit"
            }
            isOpen
            customer={formCustomer}
            isSubmitting={isSubmitting}
            onClose={() => {
              if (!isSubmitting) {
                setFormOpen(false);
                setFormCustomer(null);
              }
            }}
            onSubmit={handleFormSubmit}
          />
        ) : null}
      </AppShell>
    );
  }

  return (
    <AppShell>
      <div className="mx-auto max-w-[1600px] space-y-5">
        <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)] sm:p-6">
          <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-center">
            <div className="flex items-center gap-3">
              <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
                <Users size={21} />
              </span>

              <div>
                <h2 className="text-xl font-bold text-[var(--text-primary)]">
                  {labels.title}
                </h2>

                <p className="mt-1 text-xs text-[var(--text-secondary)]">
                  {labels.description}
                </p>
              </div>
            </div>

            <Button
              type="button"
              onClick={openCreateModal}
              className="gap-2 !bg-[var(--brand-gold)] !text-white hover:!bg-[var(--brand-gold-strong)]"
            >
              <Plus size={18} />
              {labels.newCustomer}
            </Button>
          </div>
        </section>

        <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatisticCard
            title={labels.total}
            value={summary.total}
            icon={<Users size={20} />}
            tone="gold"
          />

          <StatisticCard
            title={labels.customers}
            value={summary.customers}
            icon={<UserCheck size={20} />}
            tone="success"
          />

          <StatisticCard
            title={labels.leads}
            value={summary.leads}
            icon={<UserRoundSearch size={20} />}
            tone="info"
          />

          <StatisticCard
            title={labels.archived}
            value={summary.archived}
            icon={<Archive size={20} />}
            tone="muted"
          />
        </section>

        <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow-sm)] sm:p-5">
          <div className="mb-4 flex items-center gap-2">
            <Filter
              size={17}
              className="text-[var(--brand-gold-strong)]"
            />

            <h3 className="text-sm font-bold text-[var(--text-primary)]">
              {labels.filters}
            </h3>
          </div>

          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(280px,1fr)_180px_180px_190px_auto]">
            <div className="relative">
              <Search
                size={17}
                className="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
              />

              <input
                type="search"
                value={query.search}
                onChange={(event) => {
                  query.setSearch(
                    event.target.value
                  );
                }}
                placeholder={labels.search}
                className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] ps-11 pe-4 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-muted)] focus:border-[var(--brand-gold)]"
              />
            </div>

            <select
              value={query.status}
              onChange={(event) => {
                query.setStatus(
                  event.target.value as
                    | CustomerStatus
                    | "all"
                );
              }}
              className="h-11 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
            >
              <option value="all">
                {labels.allStatuses}
              </option>

              {Object.entries(statusLabels).map(
                ([value, label]) => (
                  <option
                    key={value}
                    value={value}
                  >
                    {label}
                  </option>
                )
              )}
            </select>

            <select
              value={query.type}
              onChange={(event) => {
                query.setType(
                  event.target.value as
                    | CustomerType
                    | "all"
                );
              }}
              className="h-11 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
            >
              <option value="all">
                {labels.allTypes}
              </option>

              {Object.entries(typeLabels).map(
                ([value, label]) => (
                  <option
                    key={value}
                    value={value}
                  >
                    {label}
                  </option>
                )
              )}
            </select>

            <select
              value={query.category}
              onChange={(event) => {
                query.setCategory(
                  event.target.value as
                    | CustomerCategory
                    | "all"
                );
              }}
              className="h-11 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
            >
              <option value="all">
                {labels.allCategories}
              </option>

              {Object.entries(
                categoryLabels
              ).map(([value, label]) => (
                <option
                  key={value}
                  value={value}
                >
                  {label}
                </option>
              ))}
            </select>

            <Button
              type="button"
              variant="secondary"
              onClick={() =>
                void loadCustomers()
              }
              disabled={isLoading}
              className="gap-2 !border-[var(--border)] !bg-[var(--surface)] !text-[var(--text-primary)] hover:!bg-[var(--surface-muted)]"
            >
              <RefreshCw
                size={17}
                className={
                  isLoading ? "animate-spin" : ""
                }
              />
              {labels.refresh}
            </Button>
          </div>
        </section>

        <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow-sm)] sm:p-5">
          <div className="mb-5 flex items-center justify-between gap-4">
            <div className="flex items-center gap-2">
              <BriefcaseBusiness
                size={17}
                className="text-[var(--brand-gold-strong)]"
              />

              <p className="text-sm font-bold text-[var(--text-primary)]">
                {labels.results}: {pagination.total}
              </p>
            </div>
          </div>

          {error ? (
            <div className="mb-5 rounded-xl border border-[var(--danger)]/25 bg-[var(--danger-soft)] px-4 py-3 text-sm font-semibold text-[var(--danger)]">
              {error}
            </div>
          ) : null}

          {isLoading ? (
            <div className="flex min-h-72 items-center justify-center">
              <div className="text-center">
                <div className="mx-auto h-9 w-9 animate-spin rounded-full border-2 border-[var(--border)] border-t-[var(--brand-gold)]" />

                <p className="mt-4 text-sm text-[var(--text-secondary)]">
                  {labels.loading}
                </p>
              </div>
            </div>
          ) : customers.length === 0 ? (
            <div className="flex min-h-80 items-center justify-center px-6 text-center">
              <div className="max-w-md">
                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
                  <Users size={28} />
                </div>

                <h3 className="mt-5 text-base font-bold text-[var(--text-primary)]">
                  {labels.emptyTitle}
                </h3>

                <p className="mt-2 text-sm leading-7 text-[var(--text-secondary)]">
                  {hasActiveFilters
                    ? labels.emptyDescription
                    : labels.firstCustomer}
                </p>

                {!hasActiveFilters ? (
                  <Button
                    type="button"
                    onClick={openCreateModal}
                    className="mt-5 gap-2 !bg-[var(--brand-gold)] !text-white"
                  >
                    <Plus size={17} />
                    {labels.newCustomer}
                  </Button>
                ) : null}
              </div>
            </div>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
              {customers.map(
                (customer) => (
                  <CustomerCard
                    key={customer.id}
                    customer={customer}
                    isArabic={isArabic}
                    onOpen={() =>
                      query.openCustomer(customer.id)
                    }
                    onEdit={() =>
                      openEditModal(customer)
                    }
                    onArchive={() =>
                      openActionDialog(
                        customer,
                        "archive"
                      )
                    }
                    onRestore={() =>
                      openActionDialog(
                        customer,
                        "restore"
                      )
                    }
                  />
                )
              )}
            </div>
          )}

          {pagination.last_page > 1 ? (
            <div className="mt-5 flex items-center justify-between gap-3 border-t border-[var(--border)] pt-5">
              <Button
                type="button"
                variant="secondary"
                disabled={
                  isLoading || query.page === 1
                }
                onClick={() => {
                  query.setPage(query.page - 1);
                }}
              >
                {labels.previous}
              </Button>

              <p className="text-sm font-semibold text-[var(--text-secondary)]">
                {labels.page} {query.page} {labels.of}{" "}
                {pagination.last_page}
              </p>

              <Button
                type="button"
                variant="secondary"
                disabled={
                  isLoading ||
                  query.page === pagination.last_page
                }
                onClick={() => {
                  query.setPage(query.page + 1);
                }}
              >
                {labels.next}
              </Button>
            </div>
          ) : null}
        </section>
      </div>

      {isFormOpen ? (
        <CustomerFormModal
          key={
            formCustomer?.id ??
            "new-customer"
          }
          isOpen
          customer={formCustomer}
          isSubmitting={isSubmitting}
          onClose={() => {
            if (!isSubmitting) {
              setFormOpen(false);
              setFormCustomer(null);
            }
          }}
          onSubmit={handleFormSubmit}
        />
      ) : null}

      <CustomerArchiveDialog
        customer={customerToProcess}
        isProcessing={isProcessing}
        action={customerAction}
        onCancel={() => {
          if (!isProcessing) {
            setCustomerToProcess(null);
          }
        }}
        onConfirm={
          handleCustomerAction
        }
      />
    </AppShell>
  );
}

type StatisticCardProps = {
  title: string;
  value: number;
  icon: React.ReactNode;
  tone:
    | "gold"
    | "success"
    | "info"
    | "muted";
};

function StatisticCard({
  title,
  value,
  icon,
  tone,
}: StatisticCardProps) {
  const toneClasses = {
    gold: "bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]",
    success:
      "bg-[var(--success-soft)] text-[var(--success)]",
    info: "bg-[var(--info-soft)] text-[var(--info)]",
    muted:
      "bg-[var(--surface-muted)] text-[var(--text-secondary)]",
  };

  return (
    <article className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)]">
      <div className="flex items-center justify-between gap-4">
        <div>
          <p className="text-xs font-semibold text-[var(--text-secondary)]">
            {title}
          </p>

          <p className="mt-3 text-3xl font-bold text-[var(--text-primary)]">
            {value}
          </p>
        </div>

        <span
          className={`flex h-11 w-11 items-center justify-center rounded-2xl ${toneClasses[tone]}`}
        >
          {icon}
        </span>
      </div>
    </article>
  );
}
