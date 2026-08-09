"use client";

import {
  Building2,
  CalendarDays,
  Hash,
  MapPin,
  X,
} from "lucide-react";
import {
  useState,
  type FormEvent,
} from "react";

import { Button } from "@/components/ui/Button";
import { FormErrorBanner } from "@/components/ui/FormErrorBanner";
import { Input } from "@/components/ui/Input";
import { useFormValidation } from "@/hooks/useFormValidation";
import { useTranslation } from "@/hooks/useTranslation";
import type {
  Project,
  ProjectFormPayload,
  ProjectManager,
  ProjectType,
} from "@/types/project";

type ProjectFormModalProps = {
  isOpen: boolean;
  project: Project | null;
  projectManagers: ProjectManager[];
  isSubmitting: boolean;
  onClose: () => void;
  onSubmit: (
    payload: ProjectFormPayload
  ) => Promise<void>;
};

type ProjectFormState = {
  name: string;
  description: string;
  project_type: ProjectType;
  city: string;
  district: string;
  address_line: string;
  estimated_budget: string;
  planned_start_date: string;
  planned_end_date: string;
  actual_start_date: string;
  actual_end_date: string;
  project_manager_id: string;
};

const emptyForm: ProjectFormState = {
  name: "",
  description: "",
  project_type: "residential",
  city: "",
  district: "",
  address_line: "",
  estimated_budget: "",
  planned_start_date: "",
  planned_end_date: "",
  actual_start_date: "",
  actual_end_date: "",
  project_manager_id: "",
};

const projectTypes: ProjectType[] = [
  "residential",
  "commercial",
  "mixed_use",
  "land",
  "villa",
  "tower",
  "compound",
  "warehouse",
  "other",
];

function dateInputValue(
  value: string | null
): string {
  return value ? value.slice(0, 10) : "";
}

function createInitialForm(
  project: Project | null
): ProjectFormState {
  if (!project) {
    return { ...emptyForm };
  }

  return {
    name: project.name,
    description: project.description ?? "",
    project_type: project.project_type,
    city: project.city,
    district: project.district ?? "",
    address_line: project.address_line ?? "",
    estimated_budget: project.estimated_budget
      ? Number(project.estimated_budget).toLocaleString(
          "en-US",
          {
            useGrouping: true,
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
          }
        )
      : "",
    planned_start_date: dateInputValue(
      project.planned_start_date
    ),
    planned_end_date: dateInputValue(
      project.planned_end_date
    ),
    actual_start_date: dateInputValue(
      project.actual_start_date
    ),
    actual_end_date: dateInputValue(
      project.actual_end_date
    ),
    project_manager_id:
      project.project_manager_id?.toString() ?? "",
  };
}

export function ProjectFormModal({
  isOpen,
  project,
  projectManagers,
  isSubmitting,
  onClose,
  onSubmit,
}: ProjectFormModalProps) {
  const { isArabic } = useTranslation();

  const [form, setForm] =
    useState<ProjectFormState>(
      () => createInitialForm(project)
    );

  const {
    formRef,
    fieldErrors,
    formError,
    clearValidation,
    setClientFieldErrors,
    setValidationError,
  } = useFormValidation();

  if (!isOpen) {
    return null;
  }

  const labels = isArabic
    ? {
        createTitle: "إنشاء مشروع جديد",
        editTitle: "تعديل المشروع",
        description:
          "أدخل المعلومات الأساسية للمشروع.",
        sequence: "التسلسل",
        sequenceHint:
          "اتركه فارغًا لاستخدام الرقم التالي تلقائيًا.",
        name: "اسم المشروع",
        namePlaceholder: "مثال: مشروع الياسمين السكني",
        type: "نوع المشروع",
        descriptionField: "وصف المشروع",
        city: "المدينة",
        district: "الحي",
        address: "العنوان",
        country: "رمز الدولة",
        currency: "العملة",
        budget: "الميزانية التقديرية",
        plannedStart: "بداية مخططة",
        plannedEnd: "نهاية مخططة",
        actualStart: "بداية فعلية",
        actualEnd: "نهاية فعلية",
        projectManager: "مدير المشروع",
        noProjectManager: "بدون مدير محدد",
        cancel: "إلغاء",
        create: "إنشاء المشروع",
        update: "حفظ التعديلات",
        submitting: "جارٍ الحفظ...",
        close: "إغلاق",
        requiredError:
          "اسم المشروع والمدينة مطلوبان.",
        plannedDateError:
          "تاريخ النهاية المخطط لا يمكن أن يسبق تاريخ البداية المخطط.",
        actualDateError:
          "تاريخ النهاية الفعلي لا يمكن أن يسبق تاريخ البداية الفعلي.",
      }
    : {
        createTitle: "Create new project",
        editTitle: "Edit project",
        description:
          "Enter the project’s essential information.",
        sequence: "Sequence",
        sequenceHint:
          "Leave empty to use the next available number.",
        name: "Project name",
        namePlaceholder:
          "Example: Al Yasmin Residential Project",
        type: "Project type",
        descriptionField: "Description",
        city: "City",
        district: "District",
        address: "Address",
        country: "Country code",
        currency: "Currency",
        budget: "Estimated budget",
        plannedStart: "Planned start",
        plannedEnd: "Planned end",
        actualStart: "Actual start",
        actualEnd: "Actual end",
        projectManager: "Project manager",
        noProjectManager: "No manager assigned",
        cancel: "Cancel",
        create: "Create project",
        update: "Save changes",
        submitting: "Saving...",
        close: "Close",
        requiredError:
          "Project name and city are required.",
        plannedDateError:
          "Planned end date cannot be before planned start date.",
        actualDateError:
          "Actual end date cannot be before actual start date.",
      };

  const typeLabels: Record<ProjectType, string> =
    isArabic
      ? {
          residential: "سكني",
          commercial: "تجاري",
          mixed_use: "متعدد الاستخدامات",
          land: "أرض",
          villa: "فلل",
          tower: "برج",
          compound: "مجمع",
          warehouse: "مستودعات",
          other: "أخرى",
        }
      : {
          residential: "Residential",
          commercial: "Commercial",
          mixed_use: "Mixed use",
          land: "Land",
          villa: "Villas",
          tower: "Tower",
          compound: "Compound",
          warehouse: "Warehouses",
          other: "Other",
        };

  const updateField = <
    Key extends keyof ProjectFormState,
  >(
    key: Key,
    value: ProjectFormState[Key]
  ): void => {
    clearValidation();
    setForm((current) => ({
      ...current,
      [key]: value,
    }));
  };

  const handleSubmit = async (
    event: FormEvent<HTMLFormElement>
  ): Promise<void> => {
    event.preventDefault();
    clearValidation();

    if (!form.name.trim() || !form.city.trim()) {
      setClientFieldErrors({
        ...(!form.name.trim()
          ? { name: [labels.requiredError] }
          : {}),
        ...(!form.city.trim()
          ? { city: [labels.requiredError] }
          : {}),
      });
      return;
    }

    if (
      form.planned_start_date &&
      form.planned_end_date &&
      form.planned_end_date < form.planned_start_date
    ) {
      setClientFieldErrors({
        planned_end_date: [labels.plannedDateError],
      });
      return;
    }

    if (
      form.actual_start_date &&
      form.actual_end_date &&
      form.actual_end_date < form.actual_start_date
    ) {
      setClientFieldErrors({
        actual_end_date: [labels.actualDateError],
      });
      return;
    }

    const payload: ProjectFormPayload = {
      name: form.name.trim(),
      description:
        form.description.trim() || null,
      project_type: form.project_type,
      country_code: "SA",
      city: form.city.trim(),
      district: form.district.trim() || null,
      address_line:
        form.address_line.trim() || null,
      currency: "SAR",
      estimated_budget: form.estimated_budget
        ? Number(
            form.estimated_budget.replaceAll(",", "")
          )
        : null,
      planned_start_date:
        form.planned_start_date || null,
      planned_end_date:
        form.planned_end_date || null,
      actual_start_date:
        form.actual_start_date || null,
      actual_end_date:
        form.actual_end_date || null,
      project_manager_id: form.project_manager_id
        ? Number(form.project_manager_id)
        : null,
    };

    try {
      await onSubmit(payload);
    } catch (caughtError) {
      setValidationError(
        caughtError,
        isArabic
          ? "تعذر حفظ المشروع."
          : "Unable to save project."
      );
    }
  };

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center p-3 sm:p-6">
      <button
        type="button"
        onClick={onClose}
        aria-label={labels.close}
        className="absolute inset-0 bg-[var(--backdrop)] backdrop-blur-[2px]"
      />

      <div className="relative flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-[26px] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow-lg)]">
        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-5 sm:px-7">
          <div className="flex items-start gap-4">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
              <Building2 size={21} />
            </span>

            <div>
              <h2 className="text-lg font-bold text-[var(--text-primary)]">
                {project
                  ? labels.editTitle
                  : labels.createTitle}
              </h2>

              <p className="mt-1 text-xs leading-6 text-[var(--text-secondary)]">
                {labels.description}
              </p>
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            aria-label={labels.close}
            className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--text-muted)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]"
          >
            <X size={19} />
          </button>
        </header>

        <form
          ref={formRef}
          onSubmit={handleSubmit}
          className="min-h-0 flex-1 overflow-y-auto"
        >
          <div className="space-y-7 px-5 py-6 sm:px-7">
            <FormErrorBanner message={formError} />
            <section className="grid gap-5 md:grid-cols-2">
              <Input
                label={
                  isArabic
                    ? "رقم المشروع"
                    : "Project number"
                }
                name="project_number"
                value={
                  project?.project_number ??
                  `PRJ-${new Date().getFullYear()}-00`
                }
                readOnly
                leading={<Hash size={17} />}
                className="cursor-not-allowed bg-[var(--surface-muted)] opacity-75"
              />

              <Input
                label={labels.name}
                name="name"
                value={form.name}
                onChange={(event) =>
                  updateField(
                    "name",
                    event.target.value
                  )
                }
                placeholder={labels.namePlaceholder}
                leading={<Building2 size={17} />}
                required
                error={fieldErrors.name?.[0]}
              />

              <SelectField
                label={labels.type}
                value={form.project_type}
                onChange={(value) =>
                  updateField(
                    "project_type",
                    value as ProjectType
                  )
                }
                options={projectTypes.map((type) => ({
                  value: type,
                  label: typeLabels[type],
                }))}
                name="project_type"
                error={fieldErrors.project_type?.[0]}
              />

            </section>

            <section>
              <label
                htmlFor="project-description"
                className="mb-2 block text-sm font-semibold text-[var(--text-secondary)]"
              >
                {labels.descriptionField}
              </label>

              <textarea
                id="project-description"
                name="description"
                rows={4}
                value={form.description}
                onChange={(event) =>
                  updateField(
                    "description",
                    event.target.value
                  )
                }
                className="w-full resize-y rounded-xl border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-muted)] focus:border-[var(--brand-gold)] focus:ring-2 focus:ring-[var(--brand-gold-soft)]"
              />
              {fieldErrors.description?.[0] ? (
                <p className="mt-2 text-sm font-medium text-[var(--danger)]">
                  {fieldErrors.description[0]}
                </p>
              ) : null}
            </section>

            <section>
              <div className="mb-4 flex items-center gap-2">
                <MapPin
                  size={17}
                  className="text-[var(--brand-gold-strong)]"
                />
                <h3 className="text-sm font-bold text-[var(--text-primary)]">
                  {isArabic
                    ? "الموقع"
                    : "Location"}
                </h3>
              </div>

              <div className="grid gap-5 sm:grid-cols-2">
                <Input
                  label={labels.city}
                  name="city"
                  value={form.city}
                  onChange={(event) =>
                    updateField(
                      "city",
                      event.target.value
                    )
                  }
                  required
                  error={fieldErrors.city?.[0]}
                />

                <Input
                  label={labels.district}
                  name="district"
                  value={form.district}
                  onChange={(event) =>
                    updateField(
                      "district",
                      event.target.value
                    )
                  }
                  error={fieldErrors.district?.[0]}
                />

              </div>

              <div className="mt-5">
                <Input
                  label={labels.address}
                  name="address_line"
                  value={form.address_line}
                  onChange={(event) =>
                    updateField(
                      "address_line",
                      event.target.value
                    )
                  }
                  error={fieldErrors.address_line?.[0]}
                />
              </div>
            </section>

            <section>
              <SelectField
                label={labels.projectManager}
                value={form.project_manager_id}
                onChange={(value) =>
                  updateField("project_manager_id", value)
                }
                options={[
                  {
                    value: "",
                    label: labels.noProjectManager,
                  },
                  ...projectManagers.map((manager) => ({
                    value: String(manager.id),
                    label: `${manager.name} — ${manager.email}`,
                  })),
                ]}
                name="project_manager_id"
                error={fieldErrors.project_manager_id?.[0]}
              />
            </section>

            <section>
              <div className="grid gap-5 md:grid-cols-2">
                <div>
                  <Input
                    label={labels.budget}
                    name="estimated_budget"
                    type="text"
                    inputMode="numeric"
                    pattern="[0-9,]*"
                    value={form.estimated_budget}
                    trailing="SAR"
                    onChange={(event) => {
                      const digits = event.target.value
                        .replace(/[^0-9]/g, "")
                        .slice(0, 13);

                      const formatted = digits
                        ? Number(digits).toLocaleString(
                            "en-US",
                            {
                              useGrouping: true,
                              maximumFractionDigits: 0,
                            }
                          )
                        : "";

                      updateField(
                        "estimated_budget",
                        formatted
                      );
                    }}
                    onPaste={(event) => {
                      event.preventDefault();

                      const digits = event.clipboardData
                        .getData("text")
                        .replace(/[^0-9]/g, "")
                        .slice(0, 13);

                      updateField(
                        "estimated_budget",
                        digits
                          ? Number(digits).toLocaleString(
                              "en-US"
                            )
                          : ""
                      );
                    }}
                    placeholder="1,900,000"
                    error={fieldErrors.estimated_budget?.[0]}
                  />
                </div>
              </div>
            </section>

            <section>
              <div className="mb-4 flex items-center gap-2">
                <CalendarDays
                  size={17}
                  className="text-[var(--brand-gold-strong)]"
                />
                <h3 className="text-sm font-bold text-[var(--text-primary)]">
                  {isArabic
                    ? "الجدول الزمني"
                    : "Timeline"}
                </h3>
              </div>

              <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <Input
                  label={labels.plannedStart}
                  name="planned_start_date"
                  type="date"
                  onKeyDown={(event) => {
                    if (
                      event.key !== "Tab" &&
                      event.key !== "Escape"
                    ) {
                      event.preventDefault();
                    }
                  }}
                  onPaste={(event) =>
                    event.preventDefault()
                  }
                  error={fieldErrors.planned_start_date?.[0]}
                  onDrop={(event) =>
                    event.preventDefault()
                  }
                  value={form.planned_start_date}
                  onChange={(event) =>
                    updateField(
                      "planned_start_date",
                      event.target.value
                    )
                  }
                />

                <Input
                  label={labels.plannedEnd}
                  name="planned_end_date"
                  type="date"
                  onKeyDown={(event) => {
                    if (
                      event.key !== "Tab" &&
                      event.key !== "Escape"
                    ) {
                      event.preventDefault();
                    }
                  }}
                  onPaste={(event) =>
                    event.preventDefault()
                  }
                  onDrop={(event) =>
                    event.preventDefault()
                  }
                  min={form.planned_start_date || undefined}
                  error={fieldErrors.planned_end_date?.[0]}
                  value={form.planned_end_date}
                  onChange={(event) =>
                    updateField(
                      "planned_end_date",
                      event.target.value
                    )
                  }
                />

                <Input
                  label={labels.actualStart}
                  name="actual_start_date"
                  type="date"
                  onKeyDown={(event) => {
                    if (
                      event.key !== "Tab" &&
                      event.key !== "Escape"
                    ) {
                      event.preventDefault();
                    }
                  }}
                  onPaste={(event) =>
                    event.preventDefault()
                  }
                  onDrop={(event) =>
                    event.preventDefault()
                  }
                  value={form.actual_start_date}
                  error={fieldErrors.actual_start_date?.[0]}
                  onChange={(event) =>
                    updateField(
                      "actual_start_date",
                      event.target.value
                    )
                  }
                />

                <Input
                  label={labels.actualEnd}
                  name="actual_end_date"
                  type="date"
                  onKeyDown={(event) => {
                    if (
                      event.key !== "Tab" &&
                      event.key !== "Escape"
                    ) {
                      event.preventDefault();
                    }
                  }}
                  onPaste={(event) =>
                    event.preventDefault()
                  }
                  onDrop={(event) =>
                    event.preventDefault()
                  }
                  min={form.actual_start_date || undefined}
                  value={form.actual_end_date}
                  error={fieldErrors.actual_end_date?.[0]}
                  onChange={(event) =>
                    updateField(
                      "actual_end_date",
                      event.target.value
                    )
                  }
                />
              </div>
            </section>

          </div>

          <footer className="sticky bottom-0 flex justify-end gap-3 border-t border-[var(--border)] bg-[var(--surface)] px-5 py-4 sm:px-7">
            <Button
              type="button"
              variant="secondary"
              onClick={onClose}
              disabled={isSubmitting}
              className="!border-[var(--border)] !bg-[var(--surface)] !text-[var(--text-primary)] hover:!bg-[var(--surface-muted)]"
            >
              {labels.cancel}
            </Button>

            <Button
              type="submit"
              disabled={isSubmitting}
              variant="accent"
            >
              {isSubmitting
                ? labels.submitting
                : project
                  ? labels.update
                  : labels.create}
            </Button>
          </footer>
        </form>
      </div>
    </div>
  );
}

type SelectFieldProps = {
  label: string;
  name: string;
  value: string;
  error?: string;
  options: Array<{
    value: string;
    label: string;
  }>;
  onChange: (value: string) => void;
};

function SelectField({
  label,
  name,
  value,
  error,
  options,
  onChange,
}: SelectFieldProps) {
  return (
    <div>
      <label className="mb-2 block text-sm font-semibold text-[var(--text-secondary)]">
        {label}
      </label>

      <select
        name={name}
        aria-invalid={error ? true : undefined}
        value={value}
        onChange={(event) =>
          onChange(event.target.value)
        }
        className="h-12 w-full rounded-xl border border-[var(--border)] bg-[var(--surface)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)] focus:ring-2 focus:ring-[var(--brand-gold-soft)]"
      >
        {options.map((option) => (
          <option
            key={option.value}
            value={option.value}
          >
            {option.label}
          </option>
        ))}
      </select>
      {error ? (
        <p className="mt-2 text-sm font-medium text-[var(--danger)]">
          {error}
        </p>
      ) : null}
    </div>
  );
}
