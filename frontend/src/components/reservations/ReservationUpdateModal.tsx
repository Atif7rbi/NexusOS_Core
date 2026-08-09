"use client";

import { Edit3 } from "lucide-react";
import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/Button";
import { FormShell } from "@/components/ui/FormShell";
import { Input } from "@/components/ui/Input";
import {
  Modal,
  ModalFooter,
  ModalHeader,
} from "@/components/ui/Modal";
import { useFormValidation } from "@/hooks/useFormValidation";
import type {
  Reservation,
  ReservationUpdatePayload,
} from "@/types/reservation";

type ReservationUpdateModalProps = {
  reservation: Reservation | null;
  isSubmitting: boolean;
  onClose: () => void;
  onSubmit: (payload: ReservationUpdatePayload) => Promise<void>;
};

function toDateTimeLocal(value: string): string {
  const date = new Date(value);
  const offset = date.getTimezoneOffset() * 60 * 1000;

  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

export function ReservationUpdateModal({
  reservation,
  isSubmitting,
  onClose,
  onSubmit,
}: ReservationUpdateModalProps) {
  const [expiresAt, setExpiresAt] = useState(
    reservation ? toDateTimeLocal(reservation.expires_at) : ""
  );
  const [notes, setNotes] = useState(reservation?.notes ?? "");
  const {
    formRef,
    fieldErrors,
    formError,
    clearValidation,
    setClientFieldErrors,
    setValidationError,
  } = useFormValidation();

  if (!reservation) {
    return null;
  }

  const handleSubmit = async (
    event: FormEvent<HTMLFormElement>
  ): Promise<void> => {
    event.preventDefault();
    clearValidation();

    if (!expiresAt) {
      setClientFieldErrors({
        expires_at: ["حدد تاريخ انتهاء الحجز."],
      });
      return;
    }

    try {
      await onSubmit({
        expires_at: expiresAt,
        notes: notes.trim() || null,
      });
    } catch (error) {
      setValidationError(error, "تعذر تحديث الحجز.");
    }
  };

  return (
    <Modal
      isOpen
      onClose={onClose}
      closeLabel="إغلاق"
      maxWidthClassName="max-w-xl"
      className="flex max-h-[94vh] flex-col"
    >
      <ModalHeader
        icon={
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
            <Edit3 size={21} />
          </span>
        }
        title="تعديل الحجز"
        description="يمكن تعديل موعد الانتهاء والملاحظات فقط."
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
              disabled={isSubmitting}
              variant="primary"
            >
              {isSubmitting ? "جارٍ الحفظ..." : "حفظ التعديلات"}
            </Button>
          </ModalFooter>
        }
      >
        <Input
          label="ينتهي الحجز في"
          name="expires_at"
          type="datetime-local"
          value={expiresAt}
          error={fieldErrors.expires_at?.[0]}
          onChange={(event) => {
            clearValidation();
            setExpiresAt(event.target.value);
          }}
        />

        <div>
          <label
            htmlFor="reservation-update-notes"
            className="mb-2 block text-sm font-semibold text-[var(--text-secondary)]"
          >
            ملاحظات
          </label>
          <textarea
            id="reservation-update-notes"
            name="notes"
            rows={4}
            value={notes}
            onChange={(event) => {
              clearValidation();
              setNotes(event.target.value);
            }}
            className="max-h-48 min-h-28 w-full resize-none overflow-y-auto rounded-xl border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
          />
          {fieldErrors.notes?.[0] ? (
            <p className="mt-2 text-sm font-medium text-[var(--danger)]">
              {fieldErrors.notes[0]}
            </p>
          ) : null}
        </div>
      </FormShell>
    </Modal>
  );
}
