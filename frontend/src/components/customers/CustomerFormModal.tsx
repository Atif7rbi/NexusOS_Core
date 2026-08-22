"use client";

import {
  Building2,
  FileText,
  Mail,
  MapPin,
  Phone,
  UserRound,
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
import {isInternationalSaudiMobile,normalizeRequiredSaudiMobile,SaudiMobileError} from "@/lib/phone";
import type {
  Customer,
  CustomerCategory,
  CustomerFormPayload,
  CustomerType,
  EditableCustomerStatus,
} from "@/types/customer";

type CustomerFormModalProps = {
  isOpen: boolean;
  customer: Customer | null;
  isSubmitting: boolean;
  onClose: () => void;
  onSubmit: (
    payload: CustomerFormPayload
  ) => Promise<void>;
};

type CustomerFormState = {
  type: CustomerType;
  category: CustomerCategory;
  status: EditableCustomerStatus;
  name: string;
  phone: string;
  email: string;
  national_id: string;
  commercial_registration_number: string;
  city: string;
  address: string;
  notes: string;
};

const emptyForm: CustomerFormState = {
  type: "individual",
  category: "buyer",
  status: "lead",
  name: "",
  phone: "",
  email: "",
  national_id: "",
  commercial_registration_number: "",
  city: "",
  address: "",
  notes: "",
};

const customerTypes: CustomerType[] = [
  "individual",
  "company",
];

const customerCategories: CustomerCategory[] = [
  "investor",
  "buyer",
  "broker",
  "owner",
  "other",
];

const customerStatuses: EditableCustomerStatus[] = [
  "lead",
  "customer",
  "inactive",
];

function createInitialForm(
  customer: Customer | null
): CustomerFormState {
  if (!customer) {
    return { ...emptyForm };
  }

  return {
    type: customer.type,
    category: customer.category,
    status:
      customer.status === "archived"
        ? "inactive"
        : customer.status,
    name: customer.name,
    phone: customer.phone,
    email: customer.email ?? "",
    national_id: customer.national_id ?? "",
    commercial_registration_number:
      customer.commercial_registration_number ??
      "",
    city: customer.city ?? "",
    address: customer.address ?? "",
    notes: customer.notes ?? "",
  };
}

export function CustomerFormModal({
  isOpen,
  customer,
  isSubmitting,
  onClose,
  onSubmit,
}: CustomerFormModalProps) {
  const { isArabic } = useTranslation();

  const [form, setForm] =
    useState<CustomerFormState>(
      () => createInitialForm(customer)
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
        createTitle: "إضافة عميل جديد",
        editTitle: "تعديل بيانات العميل",
        description:
          "أدخل بيانات العميل الأساسية وبيانات التواصل والتصنيف.",
        basicInformation: "المعلومات الأساسية",
        contactInformation: "بيانات التواصل",
        additionalInformation: "معلومات إضافية",
        type: "نوع العميل",
        category: "التصنيف",
        status: "الحالة",
        name: "اسم العميل",
        namePlaceholder:
          "مثال: محمد أحمد أو شركة آفاق العقارية",
        phone: "رقم الجوال",
        phonePlaceholder: "05xxxxxxxx",
        email: "البريد الإلكتروني",
        emailPlaceholder: "name@example.com",
        nationalId: "رقم الهوية الوطنية",
        commercialRegistration:
          "رقم السجل التجاري",
        city: "المدينة",
        address: "العنوان",
        notes: "ملاحظات",
        notesPlaceholder:
          "أي تفاصيل أو ملاحظات مهمة عن العميل",
        cancel: "إلغاء",
        create: "إضافة العميل",
        update: "حفظ التعديلات",
        submitting: "جارٍ الحفظ...",
        close: "إغلاق",
        requiredError:
          "اسم العميل ورقم الجوال مطلوبان.",
        phoneFormat: "استخدم رقم جوال محليًا من 10 أرقام يبدأ بـ 05.",
        phoneInternational: "استخدم الصيغة المحلية 05 بدل صيغة رمز الدولة.",
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
      }
    : {
        createTitle: "Add new customer",
        editTitle: "Edit customer",
        description:
          "Enter the customer’s basic information, contact details and classification.",
        basicInformation: "Basic information",
        contactInformation: "Contact information",
        additionalInformation:
          "Additional information",
        type: "Customer type",
        category: "Category",
        status: "Status",
        name: "Customer name",
        namePlaceholder:
          "Example: Mohammed Ahmed or Example Real Estate",
        phone: "Phone number",
        phonePlaceholder: "05xxxxxxxx",
        email: "Email",
        emailPlaceholder: "name@example.com",
        nationalId: "National ID",
        commercialRegistration:
          "Commercial registration number",
        city: "City",
        address: "Address",
        notes: "Notes",
        notesPlaceholder:
          "Any important details or notes about the customer",
        cancel: "Cancel",
        create: "Add customer",
        update: "Save changes",
        submitting: "Saving...",
        close: "Close",
        requiredError:
          "Customer name and phone number are required.",
        phoneFormat: "Use a 10-digit local mobile number starting with 05.",
        phoneInternational: "Use the local 05 format instead of a country-code format.",
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
      };

  const typeLabels: Record<CustomerType, string> = {
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

  const statusLabels: Record<
    EditableCustomerStatus,
    string
  > = {
    lead: labels.lead,
    customer: labels.customer,
    inactive: labels.inactive,
  };

  const updateField = <
    Key extends keyof CustomerFormState,
  >(
    key: Key,
    value: CustomerFormState[Key]
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

    if (!form.name.trim() || form.phone === "") {
      setClientFieldErrors({
        ...(!form.name.trim()
          ? { name: [labels.requiredError] }
          : {}),
        ...(form.phone === ""
          ? { phone: [labels.requiredError] }
          : {}),
      });
      return;
    }
    let canonicalPhone:string;
    try { canonicalPhone=normalizeRequiredSaudiMobile(form.phone); }
    catch(error){ setClientFieldErrors({phone:[error instanceof SaudiMobileError&&error.reason==="required"?labels.requiredError:isInternationalSaudiMobile(form.phone)?labels.phoneInternational:labels.phoneFormat]}); return; }

    const payload: CustomerFormPayload = {
      type: form.type,
      category: form.category,
      status: form.status,
      name: form.name.trim(),
      phone: canonicalPhone,
      email: form.email.trim() || null,
      national_id:
        form.type === "individual"
          ? form.national_id.trim() || null
          : null,
      commercial_registration_number:
        form.type === "company"
          ? form.commercial_registration_number.trim() ||
            null
          : null,
      city: form.city.trim() || null,
      address: form.address.trim() || null,
      notes: form.notes.trim() || null,
    };

    try {
      await onSubmit(payload);
    } catch (caughtError) {
      setValidationError(
        caughtError,
        isArabic
          ? "تعذر حفظ بيانات العميل."
          : "Unable to save customer."
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
              {form.type === "company" ? (
                <Building2 size={21} />
              ) : (
                <UserRound size={21} />
              )}
            </span>

            <div>
              <h2 className="text-lg font-bold text-[var(--text-primary)]">
                {customer
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
            <section>
              <SectionTitle
                icon={<UserRound size={17} />}
                title={labels.basicInformation}
              />

              <div className="grid gap-5 sm:grid-cols-2">
                <SelectField
                  label={labels.type}
                  value={form.type}
                  onChange={(value) =>
                    updateField(
                      "type",
                      value as CustomerType
                    )
                  }
                  options={customerTypes.map(
                    (type) => ({
                      value: type,
                      label: typeLabels[type],
                    })
                  )}
                  name="type"
                  error={fieldErrors.type?.[0]}
                />

                <SelectField
                  label={labels.category}
                  value={form.category}
                  onChange={(value) =>
                    updateField(
                      "category",
                      value as CustomerCategory
                    )
                  }
                  options={customerCategories.map(
                    (category) => ({
                      value: category,
                      label:
                        categoryLabels[category],
                    })
                  )}
                  name="category"
                  error={fieldErrors.category?.[0]}
                />

                <SelectField
                  label={labels.status}
                  value={form.status}
                  onChange={(value) =>
                    updateField(
                      "status",
                      value as EditableCustomerStatus
                    )
                  }
                  options={customerStatuses.map(
                    (status) => ({
                      value: status,
                      label: statusLabels[status],
                    })
                  )}
                  name="status"
                  error={fieldErrors.status?.[0]}
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
                  leading={<UserRound size={17} />}
                  required
                  error={fieldErrors.name?.[0]}
                />

                {form.type === "individual" ? (
                  <Input
                    label={labels.nationalId}
                    name="national_id"
                    value={form.national_id}
                    onChange={(event) =>
                      updateField(
                        "national_id",
                        event.target.value
                          .replace(/\D/g, "")
                          .slice(0, 10)
                      )
                    }
                    inputMode="numeric"
                    leading={<FileText size={17} />}
                    error={fieldErrors.national_id?.[0]}
                  />
                ) : (
                  <Input
                    label={
                      labels.commercialRegistration
                    }
                    name="commercial_registration_number"
                    value={
                      form.commercial_registration_number
                    }
                    onChange={(event) =>
                      updateField(
                        "commercial_registration_number",
                        event.target.value
                          .replace(/\D/g, "")
                          .slice(0, 20)
                      )
                    }
                    inputMode="numeric"
                    leading={<FileText size={17} />}
                    error={
                      fieldErrors
                        .commercial_registration_number?.[0]
                    }
                  />
                )}
              </div>
            </section>

            <section>
              <SectionTitle
                icon={<Phone size={17} />}
                title={labels.contactInformation}
              />

              <div className="grid gap-5 sm:grid-cols-2">
                <Input
                  label={labels.phone}
                  name="phone"
                  value={form.phone}
                  onChange={(event) =>
                    updateField(
                      "phone",
                      event.target.value
                    )
                  }
                  onBlur={()=>{try{updateField("phone",normalizeRequiredSaudiMobile(form.phone));}catch{/* Keep invalid input visible. */}}}
                  placeholder={labels.phonePlaceholder}
                  inputMode="tel"
                  leading={<Phone size={17} />}
                  required
                  error={fieldErrors.phone?.[0]}
                />

                <Input
                  label={labels.email}
                  name="email"
                  type="email"
                  value={form.email}
                  onChange={(event) =>
                    updateField(
                      "email",
                      event.target.value
                    )
                  }
                  placeholder={labels.emailPlaceholder}
                  leading={<Mail size={17} />}
                  error={fieldErrors.email?.[0]}
                />

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
                  leading={<MapPin size={17} />}
                  error={fieldErrors.city?.[0]}
                />

                <Input
                  label={labels.address}
                  name="address"
                  value={form.address}
                  onChange={(event) =>
                    updateField(
                      "address",
                      event.target.value
                    )
                  }
                  leading={<MapPin size={17} />}
                  error={fieldErrors.address?.[0]}
                />
              </div>
            </section>

            <section>
              <SectionTitle
                icon={<FileText size={17} />}
                title={
                  labels.additionalInformation
                }
              />

              <label
                htmlFor="customer-notes"
                className="mb-2 block text-sm font-semibold text-[var(--text-secondary)]"
              >
                {labels.notes}
              </label>

              <textarea
                id="customer-notes"
                name="notes"
                rows={5}
                value={form.notes}
                onChange={(event) =>
                  updateField(
                    "notes",
                    event.target.value
                  )
                }
                placeholder={labels.notesPlaceholder}
                className="w-full resize-y rounded-xl border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-muted)] focus:border-[var(--brand-gold)] focus:ring-2 focus:ring-[var(--brand-gold-soft)]"
              />
              {fieldErrors.notes?.[0] ? (
                <p className="mt-2 text-sm font-medium text-[var(--danger)]">
                  {fieldErrors.notes[0]}
                </p>
              ) : null}
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
              variant="primary"
            >
              {isSubmitting
                ? labels.submitting
                : customer
                  ? labels.update
                  : labels.create}
            </Button>
          </footer>
        </form>
      </div>
    </div>
  );
}

type SectionTitleProps = {
  icon: React.ReactNode;
  title: string;
};

function SectionTitle({
  icon,
  title,
}: SectionTitleProps) {
  return (
    <div className="mb-4 flex items-center gap-2">
      <span className="text-[var(--brand-gold-strong)]">
        {icon}
      </span>

      <h3 className="text-sm font-bold text-[var(--text-primary)]">
        {title}
      </h3>
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
