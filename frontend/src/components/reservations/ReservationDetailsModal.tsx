"use client";

import { CalendarCheck2 } from "lucide-react";

import { Button } from "@/components/ui/Button";
import {
  Modal,
  ModalFooter,
  ModalHeader,
} from "@/components/ui/Modal";
import { formatDateTime } from "@/lib/date-format";
import type {
  Reservation,
  ReservationStatus,
} from "@/types/reservation";

type ReservationDetailsModalProps = {
  reservation: Reservation | null;
  isLoading: boolean;
  error: string | null;
  onClose: () => void;
};

const statusLabels: Record<ReservationStatus, string> = {
  active: "نشط",
  cancelled: "ملغي",
  expired: "منتهي",
};

export function ReservationDetailsModal({
  reservation,
  isLoading,
  error,
  onClose,
}: ReservationDetailsModalProps) {
  const isOpen = Boolean(reservation || isLoading || error);
  const project = reservation?.unit?.project;
  const details = reservation
    ? [
        { label: "رقم الوحدة", value: reservation.unit?.unit_number ?? "—" },
        {
          label: "المشروع",
          value: project
            ? `${project.project_number} — ${project.name}`
            : "—",
        },
        { label: "العميل", value: reservation.customer?.name ?? "—" },
        { label: "الحالة", value: statusLabels[reservation.status] },
        {
          label: "تاريخ الحجز",
          value: formatDateTime(reservation.reserved_at),
        },
        {
          label: "تاريخ الانتهاء",
          value: formatDateTime(reservation.expires_at),
        },
        {
          label: "تاريخ الإلغاء",
          value: reservation.cancelled_at
            ? formatDateTime(reservation.cancelled_at)
            : "—",
        },
      ]
    : [];

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      closeLabel="إغلاق"
      maxWidthClassName="max-w-2xl"
      className="flex max-h-[94vh] flex-col"
    >
      <ModalHeader
        icon={
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
            <CalendarCheck2 size={21} />
          </span>
        }
        title="تفاصيل الحجز"
        closeLabel="إغلاق"
        onClose={onClose}
      />

      <div className="min-h-0 flex-1 overflow-y-auto p-5 sm:p-7">
        {isLoading ? (
          <p className="py-12 text-center text-sm text-[var(--text-secondary)]">
            جارٍ تحميل تفاصيل الحجز...
          </p>
        ) : error || !reservation ? (
          <p className="py-12 text-center text-sm font-semibold text-[var(--danger)]">
            {error ?? "تعذر تحميل تفاصيل الحجز."}
          </p>
        ) : (
          <>
            <div className="mb-5 flex items-center justify-between gap-3">
              <div>
                <p className="text-xl font-bold text-[var(--text-primary)]">
                  الوحدة {reservation.unit?.unit_number ?? "—"}
                </p>
                <p className="mt-1 text-sm text-[var(--text-secondary)]">
                  {reservation.customer?.name ?? "—"}
                </p>
              </div>
              <span className="rounded-full bg-[var(--surface-muted)] px-3 py-1 text-xs font-bold text-[var(--text-secondary)]">
                {statusLabels[reservation.status]}
              </span>
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

            <DetailText label="الملاحظات" value={reservation.notes} />
            {reservation.status === "cancelled" ? (
              <DetailText
                label="سبب الإلغاء"
                value={reservation.cancellation_reason}
              />
            ) : null}
          </>
        )}
      </div>

      <ModalFooter>
        <Button type="button" variant="secondary" onClick={onClose}>
          إغلاق
        </Button>
      </ModalFooter>
    </Modal>
  );
}

function DetailText({
  label,
  value,
}: {
  label: string;
  value: string | null;
}) {
  return (
    <div className="mt-4 rounded-xl bg-[var(--surface-soft)] p-4">
      <p className="text-xs font-semibold text-[var(--text-secondary)]">
        {label}
      </p>
      <p className="mt-1 max-h-48 overflow-y-auto whitespace-pre-wrap text-sm leading-7 text-[var(--text-primary)]">
        {value || "—"}
      </p>
    </div>
  );
}
