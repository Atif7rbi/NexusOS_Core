"use client";

import { Eye, EyeOff, UserRound, X } from "lucide-react";
import {
  useState,
  type FormEvent,
} from "react";

import { useTranslation } from "@/hooks/useTranslation";
import {isInternationalSaudiMobile,normalizeNullableSaudiMobile} from "@/lib/phone";
import type {
  CreateTenantUserPayload,
  TenantUser,
  TenantUserStatus,
  UpdateTenantUserPayload,
  UserRole,
} from "@/types/tenant-user";

type UserFormModalProps = {
  isOpen: boolean;
  membership: TenantUser | null;
  isSubmitting: boolean;
  onClose: () => void;
  onSubmit: (
    payload:
      | CreateTenantUserPayload
      | UpdateTenantUserPayload
  ) => Promise<void>;
};

type UserFormState = {
  name: string;
  email: string;
  phone: string;
  role: UserRole;
  status: Exclude<
    TenantUserStatus,
    "invited" | "removed"
  >;
  password: string;
  password_confirmation: string;
};

function createInitialForm(
  membership: TenantUser | null
): UserFormState {
  if (!membership) {
    return {
      name: "",
      email: "",
      phone: "",
      role: "employee",
      status: "active",
      password: "",
      password_confirmation: "",
    };
  }

  return {
    name: membership.user.name,
    email: membership.user.email,
    phone: membership.user.phone ?? "",
    role: membership.user.role,
    status:
      membership.status === "paused" ||
      membership.status === "suspended"
        ? membership.status
        : "active",
    password: "",
    password_confirmation: "",
  };
}

export function UserFormModal({
  isOpen,
  membership,
  isSubmitting,
  onClose,
  onSubmit,
}: UserFormModalProps) {
  const { isArabic } = useTranslation();

  const [form, setForm] = useState<UserFormState>(
    () => createInitialForm(membership)
  );

  const [error, setError] =
    useState<string | null>(null);

  const [showPassword, setShowPassword] =
    useState(false);

  const [
    isPasswordChangeEnabled,
    setPasswordChangeEnabled,
  ] = useState(false);

  if (!isOpen) {
    return null;
  }

  const isEditing = membership !== null;

  const labels = isArabic
    ? {
        createTitle: "إضافة مستخدم جديد",
        editTitle: "تعديل المستخدم",
        description:
          "أدخل بيانات المستخدم وحدد دوره وحالته.",
        name: "الاسم الكامل",
        email: "البريد الإلكتروني",
        phone: "رقم الجوال",
        role: "الدور",
        status: "الحالة",
        password: "كلمة المرور المؤقتة",
        passwordEdit:
          "كلمة مرور جديدة",
        enablePasswordChange:
          "تغيير كلمة المرور",
        cancelPasswordChange:
          "إلغاء تغيير كلمة المرور",
        passwordConfirmation:
          "تأكيد كلمة المرور",
        passwordHint:
          "8 أحرف على الأقل، تشمل حرفًا كبيرًا وصغيرًا ورقمًا.",
        cancel: "إلغاء",
        create: "إضافة المستخدم",
        update: "حفظ التعديلات",
        submitting: "جارٍ الحفظ...",
        required:
          "الاسم والبريد والدور وكلمة المرور مطلوبة.",
        passwordMismatch:
          "تأكيد كلمة المرور غير مطابق.",
        close: "إغلاق",
        phoneFormat: "استخدم رقم جوال محليًا من 10 أرقام يبدأ بـ 05.",
        phoneInternational: "استخدم الصيغة المحلية 05 بدل صيغة رمز الدولة.",
      }
    : {
        createTitle: "Add new user",
        editTitle: "Edit user",
        description:
          "Enter user details, role and status.",
        name: "Full name",
        email: "Email address",
        phone: "Phone number",
        role: "Role",
        status: "Status",
        password: "Temporary password",
        passwordEdit: "New password",
        enablePasswordChange:
          "Change password",
        cancelPasswordChange:
          "Cancel password change",
        passwordConfirmation:
          "Confirm password",
        passwordHint:
          "At least 8 characters with uppercase, lowercase and a number.",
        cancel: "Cancel",
        create: "Add user",
        update: "Save changes",
        submitting: "Saving...",
        required:
          "Name, email, role and password are required.",
        passwordMismatch:
          "Password confirmation does not match.",
        close: "Close",
        phoneFormat: "Use a 10-digit local mobile number starting with 05.",
        phoneInternational: "Use the local 05 format instead of a country-code format.",
      };

  const roleLabels: Record<UserRole, string> =
    isArabic
      ? {
          administrator: "مدير النظام",
          project_manager: "مدير مشاريع",
          sales: "مبيعات",
          accountant: "محاسب",
          employee: "موظف",
        }
      : {
          administrator: "Administrator",
          project_manager: "Project manager",
          sales: "Sales",
          accountant: "Accountant",
          employee: "Employee",
        };

  const statusLabels = isArabic
    ? {
        active: "نشط",
        paused: "متوقف مؤقتًا",
        suspended: "معلق",
      }
    : {
        active: "Active",
        paused: "Paused",
        suspended: "Suspended",
      };

  const inputClass = [
    "mt-2 h-11 w-full rounded-xl border border-[var(--border)]",
    "bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)]",
    "outline-none transition-colors",
    "placeholder:text-[var(--text-muted)]",
    "focus:border-[var(--brand-gold)]",
  ].join(" ");

  const handleSubmit = async (
    event: FormEvent<HTMLFormElement>
  ): Promise<void> => {
    event.preventDefault();
    setError(null);

    if (
      !form.name.trim() ||
      !form.email.trim() ||
      !form.role ||
      (!isEditing && !form.password)
    ) {
      setError(labels.required);
      return;
    }
    let canonicalPhone:string|null;
    try{canonicalPhone=normalizeNullableSaudiMobile(form.phone);}catch{setError(isInternationalSaudiMobile(form.phone)?labels.phoneInternational:labels.phoneFormat);return;}

    if (
      form.password &&
      form.password !== form.password_confirmation
    ) {
      setError(labels.passwordMismatch);
      return;
    }

    try {
      if (isEditing) {
        const payload: UpdateTenantUserPayload = {
          name: form.name.trim(),
          email: form.email.trim(),
          phone: canonicalPhone,
          role: form.role,
          status: form.status,
        };

        if (form.password) {
          payload.password = form.password;
          payload.password_confirmation =
            form.password_confirmation;
        }

        await onSubmit(payload);
      } else {
        await onSubmit({
          name: form.name.trim(),
          email: form.email.trim(),
          phone: canonicalPhone,
          role: form.role,
          password: form.password,
          password_confirmation:
            form.password_confirmation,
        });
      }
    } catch (caughtError) {
      setError(
        caughtError instanceof Error
          ? caughtError.message
          : isArabic
            ? "تعذر حفظ المستخدم."
            : "Unable to save user."
      );
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-4 backdrop-blur-sm">
      <div
        className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-3xl border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow-lg)]"
        role="dialog"
        aria-modal="true"
      >
        <div className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-[var(--border)] bg-[var(--surface)] px-6 py-5">
          <div className="flex items-start gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
              <UserRound size={21} />
            </span>

            <div>
              <h2 className="text-xl font-bold text-[var(--text-primary)]">
                {isEditing
                  ? labels.editTitle
                  : labels.createTitle}
              </h2>

              <p className="mt-1 text-sm text-[var(--text-secondary)]">
                {labels.description}
              </p>
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
            disabled={isSubmitting}
            className="flex h-9 w-9 items-center justify-center rounded-lg text-[var(--text-muted)] transition-colors hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]"
            aria-label={labels.close}
          >
            <X size={19} />
          </button>
        </div>

        <form
          onSubmit={handleSubmit}
          autoComplete="off"
          className="space-y-5 px-6 py-6"
        >
          <div className="grid gap-5 sm:grid-cols-2">
            <label className="text-sm font-semibold text-[var(--text-secondary)]">
              {labels.name}
              <input
                type="text"
                name="company_user_full_name"
                autoComplete="off"
                value={form.name}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    name: event.target.value,
                  }))
                }
                onBlur={()=>{try{const phone=normalizeNullableSaudiMobile(form.phone);setForm(current=>({...current,phone:phone??""}));}catch{/* Keep invalid input visible. */}}}
                className={inputClass}
                required
              />
            </label>

            <label className="text-sm font-semibold text-[var(--text-secondary)]">
              {labels.email}
              <input
                type="email"
                name="company_user_email"
                autoComplete="off"
                value={form.email}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    email: event.target.value,
                  }))
                }
                className={inputClass}
                dir="ltr"
                required
              />
            </label>

            <label className="text-sm font-semibold text-[var(--text-secondary)]">
              {labels.phone}
              <input
                type="tel"
                name="company_user_phone"
                autoComplete="off"
                value={form.phone}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    phone: event.target.value,
                  }))
                }
                className={inputClass}
                dir="ltr"
              />
            </label>

            <label className="text-sm font-semibold text-[var(--text-secondary)]">
              {labels.role}
              <select
                value={form.role}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    role: event.target.value as UserRole,
                  }))
                }
                className={inputClass}
              >
                {Object.entries(roleLabels).map(
                  ([value, label]) => (
                    <option key={value} value={value}>
                      {label}
                    </option>
                  )
                )}
              </select>
            </label>

            {isEditing ? (
              <label className="text-sm font-semibold text-[var(--text-secondary)] sm:col-span-2">
                {labels.status}
                <select
                  value={form.status}
                  onChange={(event) =>
                    setForm((current) => ({
                      ...current,
                      status: event.target
                        .value as UserFormState["status"],
                    }))
                  }
                  className={inputClass}
                >
                  {Object.entries(statusLabels).map(
                    ([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    )
                  )}
                </select>
              </label>
            ) : null}

            {isEditing && !isPasswordChangeEnabled ? (
              <div className="sm:col-span-2">
                <button
                  type="button"
                  onClick={() =>
                    setPasswordChangeEnabled(true)
                  }
                  className="h-11 rounded-xl border border-[var(--border)] px-5 text-sm font-bold text-[var(--text-secondary)] transition-colors hover:border-[var(--brand-gold)] hover:text-[var(--brand-gold-strong)]"
                >
                  {labels.enablePasswordChange}
                </button>
              </div>
            ) : (
              <>
                <label className="text-sm font-semibold text-[var(--text-secondary)]">
                  {isEditing
                    ? labels.passwordEdit
                    : labels.password}

                  <div className="relative">
                    <input
                      type={
                        showPassword
                          ? "text"
                          : "password"
                      }
                      name="company_user_new_password"
                      autoComplete="new-password"
                      value={form.password}
                      onChange={(event) =>
                        setForm((current) => ({
                          ...current,
                          password:
                            event.target.value,
                        }))
                      }
                      className={`${inputClass} pe-11`}
                      dir="ltr"
                      required={!isEditing}
                    />

                    <button
                      type="button"
                      onClick={() =>
                        setShowPassword(
                          (current) => !current
                        )
                      }
                      className="absolute end-3 top-[18px] text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                    >
                      {showPassword ? (
                        <EyeOff size={18} />
                      ) : (
                        <Eye size={18} />
                      )}
                    </button>
                  </div>
                </label>

                <label className="text-sm font-semibold text-[var(--text-secondary)]">
                  {labels.passwordConfirmation}

                  <input
                    type={
                      showPassword
                        ? "text"
                        : "password"
                    }
                    name="company_user_password_confirmation"
                    autoComplete="new-password"
                    value={
                      form.password_confirmation
                    }
                    onChange={(event) =>
                      setForm((current) => ({
                        ...current,
                        password_confirmation:
                          event.target.value,
                      }))
                    }
                    className={inputClass}
                    dir="ltr"
                    required={
                      !isEditing ||
                      Boolean(form.password)
                    }
                  />
                </label>

                {isEditing ? (
                  <div className="sm:col-span-2">
                    <button
                      type="button"
                      onClick={() => {
                        setPasswordChangeEnabled(false);
                        setForm((current) => ({
                          ...current,
                          password: "",
                          password_confirmation: "",
                        }));
                      }}
                      className="text-xs font-bold text-[var(--danger)] hover:underline"
                    >
                      {labels.cancelPasswordChange}
                    </button>
                  </div>
                ) : null}
              </>
            )}
          </div>

          {(!isEditing ||
            isPasswordChangeEnabled) ? (
            <p className="text-xs leading-6 text-[var(--text-muted)]">
              {labels.passwordHint}
            </p>
          ) : null}

          {error ? (
            <div className="rounded-xl border border-[var(--danger)]/25 bg-[var(--danger-soft)] px-4 py-3 text-sm font-semibold text-[var(--danger)]">
              {error}
            </div>
          ) : null}

          <div className="flex flex-col-reverse gap-3 border-t border-[var(--border)] pt-5 sm:flex-row sm:justify-end">
            <button
              type="button"
              onClick={onClose}
              disabled={isSubmitting}
              className="h-11 rounded-xl border border-[var(--border)] px-5 text-sm font-bold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-muted)]"
            >
              {labels.cancel}
            </button>

            <button
              type="submit"
              disabled={isSubmitting}
              className="h-11 rounded-xl bg-[var(--brand-gold)] px-6 text-sm font-bold text-white transition-all hover:bg-[var(--brand-gold-strong)] disabled:cursor-not-allowed disabled:opacity-60"
            >
              {isSubmitting
                ? labels.submitting
                : isEditing
                  ? labels.update
                  : labels.create}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
