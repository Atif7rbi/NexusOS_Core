"use client";

import { CalendarCheck2 } from "lucide-react";
import { useState, type FormEvent } from "react";

import { Button } from "@/components/ui/Button";
import { FormShell } from "@/components/ui/FormShell";
import { Input } from "@/components/ui/Input";
import {
  Modal,
  ModalFooter,
  ModalHeader,
} from "@/components/ui/Modal";
import { Select } from "@/components/ui/Select";
import { useFormValidation } from "@/hooks/useFormValidation";
import type { Customer } from "@/types/customer";
import type { Project } from "@/types/project";
import type {
  AvailableReservationUnit,
  ReservationFormPayload,
} from "@/types/reservation";

type ReservationFormModalProps = {
  isOpen: boolean;
  customers: Customer[];
  projects: Project[];
  units: AvailableReservationUnit[];
  isLoadingInitialOptions: boolean;
  isLoadingUnits: boolean;
  isSubmitting: boolean;
  initialCustomerId?: string;
  isCustomerLocked?: boolean;
  onClose: () => void;
  onProjectChange: (projectId: string) => Promise<void>;
  onSubmit: (payload: ReservationFormPayload) => Promise<void>;
};

type ReservationFormState = {
  customer_id: string;
  project_id: string;
  unit_id: string;
  expires_at: string;
  notes: string;
};

function defaultExpiry(): string {
  const expiry = new Date(Date.now() + 48 * 60 * 60 * 1000);
  const offset = expiry.getTimezoneOffset() * 60 * 1000;

  return new Date(expiry.getTime() - offset).toISOString().slice(0, 16);
}

export function ReservationFormModal({
  isOpen,
  customers,
  projects,
  units,
  isLoadingInitialOptions,
  isLoadingUnits,
  isSubmitting,
  initialCustomerId = "",
  isCustomerLocked = false,
  onClose,
  onProjectChange,
  onSubmit,
}: ReservationFormModalProps) {
  const [form, setForm] = useState<ReservationFormState>({
    customer_id: initialCustomerId,
    project_id: "",
    unit_id: "",
    expires_at: defaultExpiry(),
    notes: "",
  });
  const {
    formRef,
    fieldErrors,
    formError,
    clearValidation,
    setClientFieldErrors,
    setValidationError,
  } = useFormValidation();
  const selectedCustomer = customers.find(
    (customer) =>
      customer.id === form.customer_id
  );

  const updateField = <Key extends keyof ReservationFormState>(
    key: Key,
    value: ReservationFormState[Key]
  ): void => {
    clearValidation();
    setForm((current) => ({ ...current, [key]: value }));
  };

  const selectProject = async (projectId: string): Promise<void> => {
    updateField("project_id", projectId);
    setForm((current) => ({ ...current, unit_id: "" }));

    if (!projectId) {
      return;
    }

    try {
      await onProjectChange(projectId);
    } catch (error) {
      setValidationError(error, "تعذر تحميل الوحدات المتاحة.");
    }
  };

  const handleSubmit = async (
    event: FormEvent<HTMLFormElement>
  ): Promise<void> => {
    event.preventDefault();
    clearValidation();

    const errors = {
      ...(!form.customer_id ? { customer_id: ["اختر العميل."] } : {}),
      ...(!form.project_id ? { project_id: ["اختر المشروع."] } : {}),
      ...(!form.unit_id ? { unit_id: ["اختر الوحدة."] } : {}),
      ...(!form.expires_at ? { expires_at: ["حدد تاريخ انتهاء الحجز."] } : {}),
    };

    if (Object.keys(errors).length > 0) {
      setClientFieldErrors(errors);
      return;
    }

    try {
      await onSubmit({
        customer_id: form.customer_id,
        unit_id: form.unit_id,
        expires_at: form.expires_at,
        notes: form.notes.trim() || null,
      });
    } catch (error) {
      setValidationError(error, "تعذر إنشاء الحجز.");
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
            <CalendarCheck2 size={21} />
          </span>
        }
        title="إضافة حجز"
        description="اختر العميل والوحدة وحدد موعد انتهاء الحجز."
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
              disabled={isSubmitting || isLoadingInitialOptions || isLoadingUnits}
              className="!bg-[var(--brand-gold)] !text-white hover:!bg-[var(--brand-gold-strong)]"
            >
              {isSubmitting ? "جارٍ الحفظ..." : "حفظ الحجز"}
            </Button>
          </ModalFooter>
        }
      >
        {isLoadingInitialOptions ? (
          <p className="text-sm text-[var(--text-secondary)]">
            جارٍ تحميل خيارات الحجز...
          </p>
        ) : null}

        {isCustomerLocked ? (
          <div className="space-y-2">
            <p className="text-sm font-semibold text-[var(--text-secondary)]">
              العميل
            </p>
            <div className="min-h-12 rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--surface-muted)] px-4 py-3 text-sm text-[var(--text-primary)] shadow-[var(--shadow-sm)]">
              {selectedCustomer
                ? `${selectedCustomer.name} — ${selectedCustomer.phone}`
                : "جارٍ تحميل العميل..."}
            </div>
            <input
              type="hidden"
              name="customer_id"
              value={form.customer_id}
            />
            {fieldErrors.customer_id?.[0] ? (
              <p className="text-xs font-semibold text-[var(--danger)]">
                {fieldErrors.customer_id[0]}
              </p>
            ) : null}
          </div>
        ) : (
          <Select
            label="العميل"
            name="customer_id"
            value={form.customer_id}
            error={fieldErrors.customer_id?.[0]}
            disabled={isLoadingInitialOptions}
            onChange={(event) => updateField("customer_id", event.target.value)}
            options={[
              { value: "", label: "اختر العميل" },
              ...customers.map((customer) => ({
                value: customer.id,
                label: `${customer.name} — ${customer.phone}`,
              })),
            ]}
          />
        )}

        <Select
          label="المشروع"
          name="project_id"
          value={form.project_id}
          error={fieldErrors.project_id?.[0]}
          disabled={isLoadingInitialOptions}
          onChange={(event) => void selectProject(event.target.value)}
          options={[
            { value: "", label: "اختر المشروع" },
            ...projects.map((project) => ({
              value: project.id,
              label: `${project.project_number} — ${project.name}`,
            })),
          ]}
        />

        <Select
          label="الوحدة المتاحة"
          name="unit_id"
          value={form.unit_id}
          error={fieldErrors.unit_id?.[0]}
          disabled={!form.project_id || isLoadingUnits || isLoadingInitialOptions}
          onChange={(event) => updateField("unit_id", event.target.value)}
          options={[
            { value: "", label: "اختر الوحدة" },
            ...units.map((unit) => ({
              value: unit.id,
              label: `${unit.unit_number} — ${unit.project_name}`,
            })),
          ]}
        />
        {isLoadingUnits ? (
          <p className="-mt-3 text-xs text-[var(--text-secondary)]">
            جارٍ تحميل الوحدات المتاحة للمشروع...
          </p>
        ) : null}

        <Input
          label="ينتهي الحجز في"
          name="expires_at"
          type="datetime-local"
          value={form.expires_at}
          error={fieldErrors.expires_at?.[0]}
          hint="المدة الافتراضية 48 ساعة ويمكن تعديلها."
          onChange={(event) => updateField("expires_at", event.target.value)}
        />

        <div>
          <label
            htmlFor="reservation-notes"
            className="mb-2 block text-sm font-semibold text-[var(--text-secondary)]"
          >
            ملاحظات
          </label>
          <textarea
            id="reservation-notes"
            name="notes"
            rows={4}
            value={form.notes}
            onChange={(event) => updateField("notes", event.target.value)}
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
