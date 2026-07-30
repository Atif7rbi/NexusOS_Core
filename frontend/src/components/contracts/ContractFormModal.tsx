"use client";

import {
  Check,
  FilePenLine,
  FileSignature,
  Search,
} from "lucide-react";
import {
  useMemo,
  useRef,
  useState,
  type FormEvent,
  type KeyboardEvent,
} from "react";

import { Button } from "@/components/ui/Button";
import { FormErrorBanner } from "@/components/ui/FormErrorBanner";
import { FormShell } from "@/components/ui/FormShell";
import { Input } from "@/components/ui/Input";
import {
  Modal,
  ModalFooter,
  ModalHeader,
} from "@/components/ui/Modal";
import { useFormValidation } from "@/hooks/useFormValidation";
import type {
  Contract,
  ContractFormPayload,
  ContractUpdatePayload,
} from "@/types/contract";
import type { Reservation } from "@/types/reservation";

type ContractFormModalProps = {
  isOpen: boolean;
  contract?: Contract | null;
  reservations?: Reservation[];
  isLoadingReservations?: boolean;
  reservationLoadError?: string | null;
  isSubmitting: boolean;
  onClose: () => void;
  onCreate?: (payload: ContractFormPayload) => Promise<void>;
  onUpdate?: (payload: ContractUpdatePayload) => Promise<void>;
};

function isValidAmount(value: string): boolean {
  if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(value)) {
    return false;
  }

  return !/^0+(?:\.0{1,2})?$/.test(value);
}

function reservationSearchText(reservation: Reservation): string {
  const project = reservation.unit?.project;

  return [
    reservation.customer?.name,
    project?.name,
    project?.project_number,
    reservation.unit?.unit_number,
    reservation.id,
    shortReservationId(reservation.id),
  ]
    .filter(Boolean)
    .join(" ")
    .toLocaleLowerCase("ar");
}

function shortReservationId(value: string): string {
  return value.length > 12 ? `${value.slice(0, 6)}…${value.slice(-4)}` : value;
}

export function ContractFormModal({
  isOpen,
  contract = null,
  reservations = [],
  isLoadingReservations = false,
  reservationLoadError = null,
  isSubmitting,
  onClose,
  onCreate,
  onUpdate,
}: ContractFormModalProps) {
  const isEditing = contract !== null;
  const [reservationId, setReservationId] = useState(
    contract?.reservation_id ?? ""
  );
  const [totalAmount, setTotalAmount] = useState(
    contract?.total_amount ?? ""
  );
  const [reservationSearch, setReservationSearch] = useState("");
  const [activeSuggestionIndex, setActiveSuggestionIndex] = useState(0);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const {
    formRef,
    fieldErrors,
    formError,
    clearValidation,
    setClientFieldErrors,
    setValidationError,
  } = useFormValidation();

  const latestReservations = useMemo(
    () =>
      [...reservations].sort(
        (first, second) =>
          new Date(second.created_at).getTime() -
          new Date(first.created_at).getTime()
      ),
    [reservations]
  );

  const filteredReservations = useMemo(() => {
    const search = reservationSearch.trim().toLocaleLowerCase("ar");

    if (!search) {
      return latestReservations;
    }

    return latestReservations.filter((reservation) =>
      reservationSearchText(reservation).includes(search)
    );
  }, [latestReservations, reservationSearch]);

  const selectReservation = (reservation: Reservation): void => {
    clearValidation();
    setReservationId(reservation.id);
    searchInputRef.current?.focus();
  };

  const handleSearchKeyDown = (
    event: KeyboardEvent<HTMLInputElement>
  ): void => {
    if (filteredReservations.length === 0) {
      return;
    }

    if (event.key === "ArrowDown") {
      event.preventDefault();
      setActiveSuggestionIndex(
        (current) => (current + 1) % filteredReservations.length
      );
      return;
    }

    if (event.key === "ArrowUp") {
      event.preventDefault();
      setActiveSuggestionIndex(
        (current) =>
          (current - 1 + filteredReservations.length) %
          filteredReservations.length
      );
      return;
    }

    if (event.key === "Enter") {
      event.preventDefault();
      selectReservation(
        filteredReservations[
          Math.min(activeSuggestionIndex, filteredReservations.length - 1)
        ]
      );
    }
  };

  const handleSubmit = async (
    event: FormEvent<HTMLFormElement>
  ): Promise<void> => {
    event.preventDefault();
    clearValidation();

    const errors = {
      ...(!isEditing && !reservationId
        ? { reservation_id: ["اختر حجزًا نشطًا."] }
        : {}),
      ...(!isValidAmount(totalAmount.trim())
        ? {
            total_amount: [
              "أدخل قيمة موجبة بحد أقصى 10 أرقام ومنزلتين عشريتين.",
            ],
          }
        : {}),
    };

    if (Object.keys(errors).length > 0) {
      setClientFieldErrors(errors);
      return;
    }

    try {
      if (isEditing) {
        await onUpdate?.({ total_amount: totalAmount.trim() });
      } else {
        await onCreate?.({
          reservation_id: reservationId,
          total_amount: totalAmount.trim(),
        });
      }
    } catch (error) {
      setValidationError(
        error,
        isEditing ? "تعذر تحديث العقد." : "تعذر إنشاء العقد."
      );
    }
  };

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
            {isEditing ? <FilePenLine size={21} /> : <FileSignature size={21} />}
          </span>
        }
        title={isEditing ? "تعديل العقد" : "إنشاء عقد"}
        description={
          isEditing
            ? "يمكن تعديل قيمة العقد ما دام في حالة مسودة."
            : "أنشئ عقدًا جديدًا من حجز نشط وحدد القيمة المتفق عليها."
        }
        closeLabel="إغلاق"
        onClose={onClose}
      />

      <FormShell
        formRef={formRef}
        error={formError}
        onSubmit={handleSubmit}
        footer={
          <ModalFooter>
            <Button
              type="button"
              variant="secondary"
              onClick={onClose}
              disabled={isSubmitting}
            >
              إلغاء
            </Button>
            <Button
              type="submit"
              isLoading={isSubmitting}
              disabled={isLoadingReservations || Boolean(reservationLoadError)}
              className="!bg-[var(--brand-gold)] !text-white hover:!bg-[var(--brand-gold-strong)]"
            >
              {isEditing ? "حفظ التعديلات" : "إنشاء العقد"}
            </Button>
          </ModalFooter>
        }
      >
        {!isEditing ? (
          <>
            <FormErrorBanner message={reservationLoadError} />

            <div className="space-y-2">
              <label
                htmlFor="contract-reservation-search"
                className="block text-sm font-semibold text-[var(--text-secondary)]"
              >
                الحجز النشط
              </label>
              <div className="relative">
                <Search
                  size={17}
                  className="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
                />
                <input
                  ref={searchInputRef}
                  id="contract-reservation-search"
                  name="reservation_search"
                  type="search"
                  autoFocus
                  autoComplete="off"
                  role="combobox"
                  aria-autocomplete="list"
                  aria-controls="contract-reservation-suggestions"
                  aria-expanded={
                    !isLoadingReservations && filteredReservations.length > 0
                  }
                  value={reservationSearch}
                  placeholder="العميل، المشروع، الوحدة أو مرجع الحجز"
                  onChange={(event) => {
                    setReservationSearch(event.target.value);
                    setActiveSuggestionIndex(0);
                  }}
                  onKeyDown={handleSearchKeyDown}
                  className="h-12 w-full rounded-xl border border-[var(--border)] bg-[var(--surface)] ps-11 pe-4 text-sm text-[var(--text-primary)] outline-none shadow-[var(--shadow-sm)] focus:border-[var(--brand-primary)] focus:ring-4 focus:ring-[var(--focus-ring)]"
                />
              </div>

              <input
                type="hidden"
                name="reservation_id"
                value={reservationId}
              />

              {fieldErrors.reservation_id?.[0] ? (
                <p className="text-xs font-semibold text-[var(--danger)]">
                  {fieldErrors.reservation_id[0]}
                </p>
              ) : null}

              <div
                id="contract-reservation-suggestions"
                role="listbox"
                aria-label="الحجوزات النشطة"
                className="max-h-64 overflow-y-auto rounded-xl border border-[var(--border)] bg-[var(--surface)]"
              >
                {isLoadingReservations ? (
                  <p className="px-4 py-8 text-center text-sm text-[var(--text-secondary)]">
                    جارٍ تحميل الحجوزات النشطة...
                  </p>
                ) : reservations.length === 0 ? (
                  <p className="px-4 py-8 text-center text-sm text-[var(--text-secondary)]">
                    لا توجد حجوزات نشطة متاحة لإنشاء عقد.
                  </p>
                ) : filteredReservations.length === 0 ? (
                  <p className="px-4 py-8 text-center text-sm text-[var(--text-secondary)]">
                    لا توجد حجوزات مطابقة لعبارة البحث.
                  </p>
                ) : (
                  filteredReservations.map((reservation, index) => {
                    const isSelected = reservation.id === reservationId;
                    const project = reservation.unit?.project;

                    return (
                      <button
                        key={reservation.id}
                        type="button"
                        role="option"
                        aria-selected={isSelected}
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => selectReservation(reservation)}
                        className={[
                          "flex w-full items-start justify-between gap-3 border-b border-[var(--border)] px-4 py-3 text-start last:border-0",
                          index === activeSuggestionIndex
                            ? "bg-[var(--surface-soft)]"
                            : "hover:bg-[var(--surface-soft)]",
                          isSelected
                            ? "ring-1 ring-inset ring-[var(--brand-gold)]"
                            : "",
                        ].join(" ")}
                      >
                        <span className="min-w-0">
                          <span className="block truncate text-sm font-bold text-[var(--text-primary)]">
                            {reservation.customer?.name ?? "عميل غير متاح"}
                          </span>
                          <span className="mt-1 block truncate text-xs text-[var(--text-secondary)]">
                            {project
                              ? `${project.project_number} — ${project.name}`
                              : "مشروع غير متاح"}
                            {" · "}
                            الوحدة {reservation.unit?.unit_number ?? "—"}
                          </span>
                          <span className="mt-1 block font-mono text-[11px] text-[var(--text-muted)]">
                            مرجع الحجز: {shortReservationId(reservation.id)}
                          </span>
                        </span>
                        {isSelected ? (
                          <Check
                            size={18}
                            className="mt-1 shrink-0 text-[var(--success)]"
                          />
                        ) : null}
                      </button>
                    );
                  })
                )}
              </div>
            </div>
          </>
        ) : null}

        <Input
          label="قيمة العقد"
          name="total_amount"
          type="text"
          inputMode="decimal"
          value={totalAmount}
          error={fieldErrors.total_amount?.[0]}
          placeholder="0.00"
          dir="ltr"
          onChange={(event) => {
            clearValidation();
            setTotalAmount(event.target.value);
          }}
        />
      </FormShell>
    </Modal>
  );
}
