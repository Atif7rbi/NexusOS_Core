"use client";

import { Building2 } from "lucide-react";

import { Button } from "@/components/ui/Button";
import {
  Modal,
  ModalFooter,
  ModalHeader,
} from "@/components/ui/Modal";
import { useTranslation } from "@/hooks/useTranslation";
import {
  formatDecimal,
  formatInteger,
  formatMoney,
} from "@/lib/number-format";
import type { Unit } from "@/types/unit";

type UnitDetailsModalProps = {
  unit: Unit | null;
  isLoading: boolean;
  error: string | null;
  onClose: () => void;
};

export function UnitDetailsModal({
  unit,
  isLoading,
  error,
  onClose,
}: UnitDetailsModalProps) {
  const { isArabic } = useTranslation();
  const isOpen = Boolean(unit || isLoading || error);

  const labels = isArabic
    ? {
        title: "تفاصيل الوحدة",
        close: "إغلاق",
        project: "المشروع",
        number: "رقم الوحدة",
        type: "النوع",
        status: "الحالة",
        price: "سعر البيع",
        area: "المساحة",
        floor: "الطابق",
        bedrooms: "غرف النوم",
        bathrooms: "دورات المياه",
        notes: "ملاحظات",
        archived: "مؤرشفة",
        available: "متاحة",
        reserved: "محجوزة",
        sold: "مباعة",
        loading: "جارٍ تحميل التفاصيل...",
        failed: "تعذر تحميل تفاصيل الوحدة.",
      }
    : {
        title: "Unit details",
        close: "Close",
        project: "Project",
        number: "Unit number",
        type: "Type",
        status: "Status",
        price: "Selling price",
        area: "Area",
        floor: "Floor",
        bedrooms: "Bedrooms",
        bathrooms: "Bathrooms",
        notes: "Notes",
        archived: "Archived",
        available: "Available",
        reserved: "Reserved",
        sold: "Sold",
        loading: "Loading details...",
        failed: "Unable to load unit details.",
      };

  const typeLabel = unit
    ? {
        apartment: isArabic ? "شقة" : "Apartment",
        villa: isArabic ? "فيلا" : "Villa",
        office: isArabic ? "مكتب" : "Office",
        shop: isArabic ? "محل" : "Shop",
        land: isArabic ? "أرض" : "Land",
        other: isArabic ? "أخرى" : "Other",
      }[unit.unit_type]
    : "";

  const projectLabel = unit?.project
    ? `${unit.project.project_number} — ${unit.project.name}`
    : "—";

  const statusLabel = unit
    ? {
        available: labels.available,
        reserved: labels.reserved,
        sold: labels.sold,
      }[unit.status]
    : "";

  const details = unit
    ? [
        {
          label: labels.project,
          value: projectLabel,
        },
        { label: labels.number, value: unit.unit_number },
        { label: labels.type, value: typeLabel },
        {
          label: labels.status,
          value: statusLabel,
        },
        {
          label: labels.price,
          value: `${formatMoney(unit.selling_price)} ${unit.project?.currency ?? ""}`,
        },
        {
          label: labels.area,
          value: unit.area === null ? "—" : formatDecimal(unit.area),
        },
        {
          label: labels.floor,
          value: unit.floor === null ? "—" : formatInteger(unit.floor),
        },
        {
          label: labels.bedrooms,
          value:
            unit.bedrooms === null ? "—" : formatInteger(unit.bedrooms),
        },
        {
          label: labels.bathrooms,
          value:
            unit.bathrooms === null ? "—" : formatInteger(unit.bathrooms),
        },
      ]
    : [];

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      closeLabel={labels.close}
      maxWidthClassName="max-w-2xl"
      className="flex max-h-[94vh] flex-col"
    >
      <ModalHeader
        icon={
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
            <Building2 size={21} />
          </span>
        }
        title={labels.title}
        closeLabel={labels.close}
        onClose={onClose}
      />

      <div className="min-h-0 flex-1 overflow-y-auto p-5 sm:p-7">
        {isLoading ? (
          <p className="py-12 text-center text-sm text-[var(--text-secondary)]">
            {labels.loading}
          </p>
        ) : error || !unit ? (
          <p className="py-12 text-center text-sm font-semibold text-[var(--danger)]">
            {error ?? labels.failed}
          </p>
        ) : (
          <>
            <div className="mb-5 flex items-center justify-between gap-3">
              <div>
                <p className="text-xl font-bold text-[var(--text-primary)]">
                  {unit.unit_number}
                </p>
                <p className="mt-1 text-sm text-[var(--text-secondary)]">
                  {projectLabel}
                </p>
              </div>
              <div className="flex gap-2">
                <span className="rounded-full bg-[var(--success-soft)] px-3 py-1 text-xs font-bold text-[var(--success)]">
                  {statusLabel}
                </span>
                {unit.archived_at ? (
                  <span className="rounded-full bg-[var(--surface-muted)] px-3 py-1 text-xs font-bold text-[var(--text-secondary)]">
                    {labels.archived}
                  </span>
                ) : null}
              </div>
            </div>

            <dl className="grid gap-4 sm:grid-cols-2">
              {details.map((detail) => (
                <div
                  key={detail.label}
                  className="rounded-xl bg-[var(--surface-soft)] p-4"
                >
                  <dt className="text-xs font-semibold text-[var(--text-secondary)]">
                    {detail.label}
                  </dt>
                  <dd className="mt-1 text-sm font-bold text-[var(--text-primary)]">
                    {detail.value}
                  </dd>
                </div>
              ))}
            </dl>

            <div className="mt-4 rounded-xl bg-[var(--surface-soft)] p-4">
              <p className="text-xs font-semibold text-[var(--text-secondary)]">
                {labels.notes}
              </p>
              <p className="mt-1 max-h-48 overflow-y-auto whitespace-pre-wrap text-sm leading-7 text-[var(--text-primary)]">
                {unit.notes || "—"}
              </p>
            </div>
          </>
        )}
      </div>

      <ModalFooter>
        <Button type="button" variant="secondary" onClick={onClose}>
          {labels.close}
        </Button>
      </ModalFooter>
    </Modal>
  );
}
