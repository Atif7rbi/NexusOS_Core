"use client";

import {
  Archive,
  Building2,
  Edit3,
  FolderOpen,
  Mail,
  MapPin,
  Phone,
  RotateCcw,
  UserRound,
} from "lucide-react";

import type {
  Customer,
  CustomerCategory,
  CustomerStatus,
  CustomerType,
} from "@/types/customer";
import type { AuthUser } from "@/types/auth";
import {
  canEditOrArchiveCustomer,
  canRestoreCustomer,
} from "@/lib/commercial-authorization";

type CustomerCardProps = {
  customer: Customer;
  currentUser: AuthUser | null;
  isArabic: boolean;
  onOpen: () => void;
  onEdit: () => void;
  onArchive: () => void;
  onRestore: () => void;
};

export function CustomerCard({
  customer,
  currentUser,
  isArabic,
  onOpen,
  onEdit,
  onArchive,
  onRestore,
}: CustomerCardProps) {
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
    <article
      className={`group flex h-full flex-col rounded-2xl border bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)] transition ${
        isArchived
          ? "border-[var(--border)] opacity-80"
          : "border-[var(--border)] hover:-translate-y-0.5 hover:border-[var(--brand-gold)]/50 hover:shadow-[var(--shadow-md)]"
      }`}
    >
      <div className="flex items-start justify-between gap-4">
        <div className="flex min-w-0 items-start gap-3">
          <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
            {customer.type === "company" ? (
              <Building2 size={20} />
            ) : (
              <UserRound size={20} />
            )}
          </span>

          <div className="min-w-0">
            <h3 className="truncate text-sm font-bold text-[var(--text-primary)]">
              {customer.name}
            </h3>

            <div className="mt-2 flex flex-wrap items-center gap-2">
              <span className="rounded-full bg-[var(--surface-muted)] px-2.5 py-1 text-[10px] font-bold text-[var(--text-secondary)]">
                {typeLabels[customer.type]}
              </span>

              <span className="rounded-full bg-[var(--brand-gold-soft)] px-2.5 py-1 text-[10px] font-bold text-[var(--brand-gold-strong)]">
                {categoryLabels[customer.category]}
              </span>
            </div>
          </div>
        </div>

        <StatusBadge
          status={customer.status}
          label={statusLabels[customer.status]}
        />
      </div>

      <div className="mt-5 space-y-3 text-xs text-[var(--text-secondary)]">
        <InfoRow
          icon={<Phone size={15} />}
          value={customer.phone}
          direction="ltr"
        />

        <InfoRow
          icon={<Mail size={15} />}
          value={
            customer.email ||
            (isArabic
              ? "لا يوجد بريد إلكتروني"
              : "No email address")
          }
          muted={!customer.email}
          direction="ltr"
        />

        <InfoRow
          icon={<MapPin size={15} />}
          value={
            customer.city ||
            (isArabic
              ? "المدينة غير محددة"
              : "City not specified")
          }
          muted={!customer.city}
        />
      </div>

      {customer.notes ? (
        <p className="mt-4 line-clamp-2 rounded-xl bg-[var(--surface-soft)] px-3 py-2 text-[11px] leading-6 text-[var(--text-secondary)]">
          {customer.notes}
        </p>
      ) : null}

      <div className="mt-auto flex flex-wrap justify-end gap-2 border-t border-[var(--border)] pt-4">
        <button
          type="button"
          onClick={onOpen}
          className="flex h-9 items-center gap-2 rounded-xl border border-[var(--brand-gold)]/30 px-3 text-xs font-bold text-[var(--brand-gold-strong)] transition hover:bg-[var(--brand-gold-soft)]"
        >
          <FolderOpen size={15} />
          {isArabic
            ? "فتح سجل العميل"
            : "Open Customer Record"}
        </button>

        {!isArchived && canEditOrArchiveCustomer(currentUser, customer) ? (
          <>
            <button
              type="button"
              onClick={onEdit}
              className="flex h-9 items-center gap-2 rounded-xl border border-[var(--border)] px-3 text-xs font-bold text-[var(--text-secondary)] transition hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]"
            >
              <Edit3 size={15} />
              {isArabic ? "تعديل" : "Edit"}
            </button>

            <button
              type="button"
              onClick={onArchive}
              className="flex h-9 items-center gap-2 rounded-xl border border-[var(--danger)]/20 px-3 text-xs font-bold text-[var(--danger)] transition hover:bg-[var(--danger-soft)]"
            >
              <Archive size={15} />
              {isArabic ? "أرشفة" : "Archive"}
            </button>
          </>
        ) : isArchived && canRestoreCustomer(currentUser?.role) ? (
          <button
            type="button"
            onClick={onRestore}
            className="flex h-9 items-center gap-2 rounded-xl border border-[var(--success)]/25 px-3 text-xs font-bold text-[var(--success)] transition hover:bg-[var(--success-soft)]"
          >
            <RotateCcw size={15} />
            {isArabic ? "استعادة" : "Restore"}
          </button>
        ) : null}
      </div>
    </article>
  );
}

function InfoRow({
  icon,
  value,
  muted = false,
  direction,
}: {
  icon: React.ReactNode;
  value: string;
  muted?: boolean;
  direction?: "ltr" | "rtl";
}) {
  return (
    <div className="flex min-w-0 items-center gap-2">
      <span className="shrink-0 text-[var(--text-muted)]">
        {icon}
      </span>

      <span
        dir={direction}
        className={`truncate ${
          muted
            ? "text-[var(--text-muted)]"
            : "font-medium text-[var(--text-secondary)]"
        }`}
      >
        {value}
      </span>
    </div>
  );
}

function StatusBadge({
  status,
  label,
}: {
  status: CustomerStatus;
  label: string;
}) {
  const toneClasses: Record<
    CustomerStatus,
    string
  > = {
    lead:
      "bg-[var(--info-soft)] text-[var(--info)]",
    customer:
      "bg-[var(--success-soft)] text-[var(--success)]",
    inactive:
      "bg-[var(--surface-muted)] text-[var(--text-secondary)]",
    archived:
      "bg-[var(--danger-soft)] text-[var(--danger)]",
  };

  return (
    <span
      className={`shrink-0 rounded-full px-3 py-1 text-[10px] font-bold ${toneClasses[status]}`}
    >
      {label}
    </span>
  );
}
