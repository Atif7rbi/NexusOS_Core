"use client";

import { FilePenLine, FileSignature } from "lucide-react";
import { useMemo, useState, type FormEvent } from "react";

import { Button } from "@/components/ui/Button";
import { FormErrorBanner } from "@/components/ui/FormErrorBanner";
import { FormShell } from "@/components/ui/FormShell";
import { Input } from "@/components/ui/Input";
import {
  Modal,
  ModalFooter,
  ModalHeader,
} from "@/components/ui/Modal";
import { Select } from "@/components/ui/Select";
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

function reservationLabel(reservation: Reservation): string {
  const project = reservation.unit?.project;
  const projectName = project
    ? `${project.project_number} — ${project.name}`
    : "مشروع غير متاح";
  const unitNumber = reservation.unit?.unit_number ?? "—";
  const customerName = reservation.customer?.name ?? "—";

  return `${projectName} | الوحدة ${unitNumber} | ${customerName}`;
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
  const {
    formRef,
    fieldErrors,
    formError,
    clearValidation,
    setClientFieldErrors,
    setValidationError,
  } = useFormValidation();

  const filteredReservations = useMemo(() => {
    const search = reservationSearch.trim().toLocaleLowerCase("ar");

    if (!search) {
      return reservations;
    }

    return reservations.filter((reservation) =>
      reservationLabel(reservation).toLocaleLowerCase("ar").includes(search)
    );
  }, [reservationSearch, reservations]);

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

            <Input
              label="البحث في الحجوزات النشطة"
              name="reservation_search"
              type="search"
              value={reservationSearch}
              placeholder="اسم العميل أو المشروع أو رقم الوحدة"
              disabled={isLoadingReservations}
              onChange={(event) => setReservationSearch(event.target.value)}
            />

            <Select
              label="الحجز النشط"
              name="reservation_id"
              value={reservationId}
              error={fieldErrors.reservation_id?.[0]}
              disabled={isLoadingReservations}
              onChange={(event) => {
                clearValidation();
                setReservationId(event.target.value);
              }}
              options={[
                {
                  value: "",
                  label: isLoadingReservations
                    ? "جارٍ تحميل الحجوزات..."
                    : "اختر حجزًا",
                },
                ...filteredReservations.map((reservation) => ({
                  value: reservation.id,
                  label: reservationLabel(reservation),
                })),
              ]}
            />

            {!isLoadingReservations &&
            !reservationLoadError &&
            reservations.length === 0 ? (
              <p className="rounded-xl bg-[var(--surface-soft)] px-4 py-3 text-sm text-[var(--text-secondary)]">
                لا توجد حجوزات نشطة متاحة للعرض حاليًا.
              </p>
            ) : null}
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
