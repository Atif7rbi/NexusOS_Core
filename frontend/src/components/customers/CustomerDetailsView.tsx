"use client";

import {
  ArrowLeft,
  Building2,
  CalendarDays,
  Edit3,
  Mail,
  MapPin,
  Phone,
  UserRound,
} from "lucide-react";

import { Button } from "@/components/ui/Button";
import { canEditOrArchiveCustomer } from "@/lib/commercial-authorization";
import type { AuthUser } from "@/types/auth";
import { CustomerBusinessContext } from "@/components/customers/CustomerBusinessContext";
import { formatDateTime } from "@/lib/date-format";
import type { CustomerBusinessContext as BusinessContext } from "@/types/customer-business-context";
import type {
  Customer,
  CustomerCategory,
  CustomerStatus,
  CustomerType,
} from "@/types/customer";

type CustomerDetailsViewProps = {
  customer: Customer | null;
  currentUser?: AuthUser | null;
  businessContext: BusinessContext | null;
  isArabic: boolean;
  isLoading: boolean;
  error: string | null;
  isNotFound: boolean;
  onBack: () => void;
  onRetry: () => void;
  onEdit: () => void;
  createReservationHref?: string;
};

export function CustomerDetailsView({
  customer,
  currentUser = null,
  businessContext,
  isArabic,
  isLoading,
  error,
  isNotFound,
  onBack,
  onRetry,
  onEdit,
  createReservationHref,
}: CustomerDetailsViewProps) {
  const labels = isArabic
    ? {
        back: "العودة إلى العملاء",
        title: "سجل العميل",
        edit: "تعديل",
        loading: "جارٍ تحميل سجل العميل...",
        notFoundTitle: "سجل العميل غير موجود",
        notFoundDescription:
          "تعذر العثور على العميل المطلوب أو لا تملك صلاحية الوصول إليه.",
        errorTitle: "تعذر تحميل سجل العميل",
        retry: "إعادة المحاولة",
        basicInformation: "المعلومات الأساسية",
        contactInformation: "بيانات التواصل",
        identityInformation: "بيانات الهوية",
        notes: "الملاحظات",
        type: "النوع",
        category: "التصنيف",
        status: "الحالة",
        name: "الاسم",
        phone: "رقم الجوال",
        email: "البريد الإلكتروني",
        city: "المدينة",
        address: "العنوان",
        nationalId: "رقم الهوية",
        commercialRegistration:
          "رقم السجل التجاري",
        createdAt: "تاريخ الإنشاء",
        updatedAt: "آخر تحديث",
        unavailable: "غير متوفر",
        noNotes: "لا توجد ملاحظات.",
      }
    : {
        back: "Back to customers",
        title: "Customer Record",
        edit: "Edit",
        loading: "Loading customer record...",
        notFoundTitle: "Customer record not found",
        notFoundDescription:
          "The requested customer could not be found or is not accessible.",
        errorTitle: "Unable to load customer record",
        retry: "Retry",
        basicInformation: "Basic information",
        contactInformation: "Contact information",
        identityInformation: "Identity information",
        notes: "Notes",
        type: "Type",
        category: "Category",
        status: "Status",
        name: "Name",
        phone: "Phone",
        email: "Email",
        city: "City",
        address: "Address",
        nationalId: "National ID",
        commercialRegistration:
          "Commercial registration",
        createdAt: "Created at",
        updatedAt: "Last updated",
        unavailable: "Not available",
        noNotes: "No notes.",
      };

  if (isLoading) {
    return (
      <CustomerRecordState
        title={labels.loading}
        onBack={onBack}
      />
    );
  }

  if (isNotFound) {
    return (
      <CustomerRecordState
        title={labels.notFoundTitle}
        description={labels.notFoundDescription}
        onBack={onBack}
      />
    );
  }

  if (error || !customer) {
    return (
      <CustomerRecordState
        title={labels.errorTitle}
        description={error ?? undefined}
        onBack={onBack}
        actionLabel={labels.retry}
        onAction={onRetry}
      />
    );
  }

  const typeLabels: Record<CustomerType, string> =
    isArabic
      ? {
          individual: "فرد",
          company: "شركة",
        }
      : {
          individual: "Individual",
          company: "Company",
        };

  const categoryLabels: Record<
    CustomerCategory,
    string
  > = isArabic
    ? {
        investor: "مستثمر",
        buyer: "مشتري",
        broker: "وسيط",
        owner: "مالك",
        other: "أخرى",
      }
    : {
        investor: "Investor",
        buyer: "Buyer",
        broker: "Broker",
        owner: "Owner",
        other: "Other",
      };

  const statusLabels: Record<
    CustomerStatus,
    string
  > = isArabic
    ? {
        lead: "عميل محتمل",
        customer: "عميل",
        inactive: "غير نشط",
        archived: "مؤرشف",
      }
    : {
        lead: "Lead",
        customer: "Customer",
        inactive: "Inactive",
        archived: "Archived",
      };

  const isArchived =
    customer.status === "archived";

  return (
    <div className="mx-auto max-w-[1200px] space-y-5">
      <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)] sm:p-6">
        <div className="flex flex-col justify-between gap-5 sm:flex-row sm:items-start">
          <div>
            <button
              type="button"
              onClick={onBack}
              className="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
            >
              <ArrowLeft size={17} />
              {labels.back}
            </button>

            <div className="flex items-start gap-3">
              <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
                {customer.type === "company" ? (
                  <Building2 size={22} />
                ) : (
                  <UserRound size={22} />
                )}
              </span>

              <div>
                <p className="text-xs font-bold uppercase tracking-wide text-[var(--text-muted)]">
                  {labels.title}
                </p>

                <h2 className="mt-1 text-xl font-bold text-[var(--text-primary)]">
                  {customer.name}
                </h2>

                <div className="mt-3 flex flex-wrap gap-2">
                  <RecordBadge>
                    {typeLabels[customer.type]}
                  </RecordBadge>
                  <RecordBadge>
                    {categoryLabels[customer.category]}
                  </RecordBadge>
                  <RecordBadge>
                    {statusLabels[customer.status]}
                  </RecordBadge>
                </div>
              </div>
            </div>
          </div>

          {!isArchived && canEditOrArchiveCustomer(currentUser, customer) ? (
            <Button
              type="button"
              variant="secondary"
              onClick={onEdit}
              leadingIcon={<Edit3 size={16} />}
            >
              {labels.edit}
            </Button>
          ) : null}
        </div>
      </section>

      <div className="grid gap-5 lg:grid-cols-2">
        <RecordSection
          title={labels.basicInformation}
        >
          <RecordField
            label={labels.name}
            value={customer.name}
          />
          <RecordField
            label={labels.type}
            value={typeLabels[customer.type]}
          />
          <RecordField
            label={labels.category}
            value={categoryLabels[customer.category]}
          />
          <RecordField
            label={labels.status}
            value={statusLabels[customer.status]}
          />
        </RecordSection>

        <RecordSection
          title={labels.contactInformation}
        >
          <RecordField
            label={labels.phone}
            value={customer.phone}
            icon={<Phone size={16} />}
            direction="ltr"
          />
          <RecordField
            label={labels.email}
            value={customer.email ?? labels.unavailable}
            icon={<Mail size={16} />}
            direction="ltr"
          />
          <RecordField
            label={labels.city}
            value={customer.city ?? labels.unavailable}
            icon={<MapPin size={16} />}
          />
          <RecordField
            label={labels.address}
            value={customer.address ?? labels.unavailable}
          />
        </RecordSection>

        <RecordSection
          title={labels.identityInformation}
        >
          <RecordField
            label={labels.nationalId}
            value={
              customer.national_id ??
              labels.unavailable
            }
            direction="ltr"
          />
          <RecordField
            label={labels.commercialRegistration}
            value={
              customer
                .commercial_registration_number ??
              labels.unavailable
            }
            direction="ltr"
          />
          <RecordField
            label={labels.createdAt}
            value={formatDateTime(customer.created_at)}
            icon={<CalendarDays size={16} />}
          />
          <RecordField
            label={labels.updatedAt}
            value={formatDateTime(customer.updated_at)}
            icon={<CalendarDays size={16} />}
          />
        </RecordSection>

        <RecordSection title={labels.notes}>
          <p className="whitespace-pre-wrap text-sm leading-7 text-[var(--text-secondary)]">
            {customer.notes || labels.noNotes}
          </p>
        </RecordSection>
      </div>

      {businessContext ? (
        <CustomerBusinessContext
          context={businessContext}
          isArabic={isArabic}
          createReservationHref={
            isArchived
              ? undefined
              : createReservationHref
          }
        />
      ) : null}
    </div>
  );
}

function CustomerRecordState({
  title,
  description,
  onBack,
  actionLabel,
  onAction,
}: {
  title: string;
  description?: string;
  onBack: () => void;
  actionLabel?: string;
  onAction?: () => void;
}) {
  return (
    <section className="mx-auto flex min-h-80 max-w-3xl items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-8 text-center shadow-[var(--shadow-sm)]">
      <div>
        <h2 className="text-lg font-bold text-[var(--text-primary)]">
          {title}
        </h2>

        {description ? (
          <p className="mt-2 text-sm leading-7 text-[var(--text-secondary)]">
            {description}
          </p>
        ) : null}

        <div className="mt-5 flex justify-center gap-3">
          <Button
            type="button"
            variant="secondary"
            onClick={onBack}
          >
            رجوع
          </Button>

          {actionLabel && onAction ? (
            <Button
              type="button"
              onClick={onAction}
            >
              {actionLabel}
            </Button>
          ) : null}
        </div>
      </div>
    </section>
  );
}

function RecordSection({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)]">
      <h3 className="mb-4 text-sm font-bold text-[var(--text-primary)]">
        {title}
      </h3>

      <div className="space-y-4">
        {children}
      </div>
    </section>
  );
}

function RecordField({
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
      <p className="text-xs font-semibold text-[var(--text-muted)]">
        {label}
      </p>

      <div className="mt-1 flex items-center gap-2">
        {icon ? (
          <span className="text-[var(--text-muted)]">
            {icon}
          </span>
        ) : null}

        <p
          dir={direction}
          className="text-sm font-medium text-[var(--text-secondary)]"
        >
          {value}
        </p>
      </div>
    </div>
  );
}

function RecordBadge({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <span className="rounded-full bg-[var(--surface-muted)] px-3 py-1 text-xs font-bold text-[var(--text-secondary)]">
      {children}
    </span>
  );
}
