"use client";

import {
  Building2,
  ImageIcon,
  Palette,
  Save,
  Settings,
} from "lucide-react";
import {
  useMemo,
  useState,
  type FormEvent,
} from "react";

import { AppShell } from "@/components/layout/AppShell";
import { AppearanceProfileSelector } from "@/components/theme/AppearanceProfileSelector";
import { TypographyPreferencesSelector } from "@/components/theme/TypographyPreferencesSelector";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { useTranslation } from "@/hooks/useTranslation";
import {
  isInternationalSaudiMobile,
  normalizeNullableSaudiMobile,
} from "@/lib/phone";
import { useAuth } from "@/providers/AuthProvider";
import { useSystemSettings } from "@/providers/SystemSettingsProvider";
import { updateCompanyProfile } from "@/services/system-settings";
import type { CompanyProfileInput } from "@/types/system-settings";

const profileFields: (keyof CompanyProfileInput)[] = [
  "company_name_ar",
  "company_name_en",
  "short_name_ar",
  "short_name_en",
  "phone",
  "email",
  "address",
  "website",
  "commercial_registration",
  "vat_number",
];

function profileFromSettings(
  settings: CompanyProfileInput
): CompanyProfileInput {
  return Object.fromEntries(
    profileFields.map((field) => [field, settings[field]])
  ) as CompanyProfileInput;
}

function nullableText(value: string): string | null {
  const normalized = value.trim();
  return normalized === "" ? null : normalized;
}

export default function SettingsPage() {
  const { token, user } = useAuth();
  const { isArabic } = useTranslation();
  const settings = useSystemSettings();
  const [form, setForm] = useState<CompanyProfileInput>(() =>
    profileFromSettings(settings)
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const settingsSignature = profileFields
    .map((field) => settings[field] ?? "")
    .join("\u0000");
  const [formSignature, setFormSignature] = useState(settingsSignature);

  if (formSignature !== settingsSignature) {
    setFormSignature(settingsSignature);
    setForm(profileFromSettings(settings));
  }

  const canManageCompany = Boolean(
    user?.role === "administrator" || user?.role === "system_owner"
  );
  const canEditCompany =
    canManageCompany &&
    (settings.isDemoMode ||
      (settings.hasLoadedSettings && !settings.loadError));

  const labels = useMemo(
    () =>
      isArabic
        ? {
            title: "الإعدادات",
            description: "إدارة المظهر والخط وبيانات الشركة.",
            appearance: "المظهر والخط",
            appearanceDescription:
              "تُحفظ تفضيلات المظهر والخط لهذا الحساب على هذا الجهاز.",
            profiles: "ملفات الألوان",
            company: "بيانات الشركة",
            companyDescription:
              "هوية الشركة التي ستُستخدم لاحقًا في العقود والتقارير والمستندات.",
            companyNameAr: "اسم الشركة بالعربية",
            companyNameEn: "اسم الشركة بالإنجليزية",
            shortNameAr: "الاسم المختصر بالعربية",
            shortNameEn: "الاسم المختصر بالإنجليزية",
            phone: "رقم التواصل",
            email: "البريد الإلكتروني",
            address: "عنوان الشركة",
            website: "الموقع الإلكتروني",
            registration: "السجل التجاري",
            vat: "الرقم الضريبي",
            logo: "شعار الشركة",
            logoPending:
              "رفع شعار الشركة غير متاح بعد لأن خدمة التخزين والرفع لم تُعتمد ضمن هذه المرحلة.",
            logoConfigured: "يوجد مسار شعار مُهيأ في إعدادات الشركة الحالية.",
            save: "حفظ التغييرات",
            saved: "تم حفظ بيانات الشركة بنجاح.",
            saveFailed: "تعذر حفظ بيانات الشركة.",
            load: "جارٍ تحميل بيانات الشركة...",
            loadFailed:
              "تعذر تحميل بيانات الشركة. لن يتم تمكين الحفظ حتى تنجح إعادة تحميل الإعدادات.",
            noPermission: "بيانات الشركة متاحة للعرض فقط. يلزم حساب مسؤول لتعديلها.",
            demoSaved:
              "تم حفظ تخصيصك محليًا لهذا المتصفح فقط. لم تتغير بيانات العرض المشتركة.",
            required: "اسم الشركة والاسم المختصر بالعربية مطلوبان.",
            phoneFormat: "استخدم رقم جوال محليًا من 10 أرقام يبدأ بـ 05.",
            phoneInternational:
              "استخدم الصيغة المحلية 05 بدل صيغة رمز الدولة.",
          }
        : {
            title: "Settings",
            description: "Manage appearance, typography, and company profile.",
            appearance: "Appearance and typography",
            appearanceDescription:
              "Appearance and typography preferences are saved for this account on this device.",
            profiles: "Color profiles",
            company: "Company profile",
            companyDescription:
              "Company identity to be reused later in contracts, reports, and documents.",
            companyNameAr: "Company name (Arabic)",
            companyNameEn: "Company name (English)",
            shortNameAr: "Short name (Arabic)",
            shortNameEn: "Short name (English)",
            phone: "Contact number",
            email: "Email",
            address: "Company address",
            website: "Website",
            registration: "Commercial registration",
            vat: "VAT / tax number",
            logo: "Company logo",
            logoPending:
              "Company logo upload is not available yet because a storage and upload service is outside this phase.",
            logoConfigured: "A logo path is configured in the current company settings.",
            save: "Save changes",
            saved: "Company profile saved successfully.",
            saveFailed: "Unable to save company profile.",
            load: "Loading company profile...",
            loadFailed:
              "Unable to load the company profile. Saving stays disabled until settings load successfully.",
            noPermission:
              "Company profile is read-only. An administrator account is required to make changes.",
            demoSaved:
              "Your customization was saved only in this browser. Shared Demo data was not changed.",
            required:
              "Company name and Arabic short name are required.",
            phoneFormat:
              "Use a 10-digit local mobile number starting with 05.",
            phoneInternational:
              "Use the local 05 format instead of a country-code format.",
          },
    [isArabic]
  );

  const updateField = <Key extends keyof CompanyProfileInput>(
    key: Key,
    value: CompanyProfileInput[Key]
  ): void => {
    setForm((current) => ({ ...current, [key]: value }));
    setError(null);
    setSuccess(null);
  };

  const submit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();

    if (
      !canManageCompany ||
      !token ||
      (!settings.isDemoMode &&
        (!settings.hasLoadedSettings || settings.loadError))
    ) {
      if (
        !settings.isDemoMode &&
        (!settings.hasLoadedSettings || settings.loadError)
      ) {
        setError(labels.loadFailed);
      }

      return;
    }

    const companyNameAr = form.company_name_ar.trim();
    const shortNameAr = form.short_name_ar.trim();

    if (!companyNameAr || !shortNameAr) {
      setError(labels.required);
      return;
    }

    let phone: string | null;
    try {
      phone = normalizeNullableSaudiMobile(form.phone ?? "");
    } catch {
      setError(
        isInternationalSaudiMobile(form.phone ?? "")
          ? labels.phoneInternational
          : labels.phoneFormat
      );
      return;
    }

    const payload: CompanyProfileInput = {
      company_name_ar: companyNameAr,
      company_name_en: nullableText(form.company_name_en ?? ""),
      short_name_ar: shortNameAr,
      short_name_en: nullableText(form.short_name_en ?? ""),
      phone,
      email: nullableText(form.email ?? ""),
      address: nullableText(form.address ?? ""),
      website: nullableText(form.website ?? ""),
      commercial_registration: nullableText(
        form.commercial_registration ?? ""
      ),
      vat_number: nullableText(form.vat_number ?? ""),
    };

    setSaving(true);
    setError(null);
    setSuccess(null);

    try {
      if (settings.isDemoMode) {
        settings.saveDemoCompanyProfile(payload);
        setSuccess(labels.demoSaved);
      } else {
        const nextSettings = await updateCompanyProfile(token, payload);
        settings.applyCompanyProfile(nextSettings);
        setSuccess(labels.saved);
      }
    } catch {
      setError(labels.saveFailed);
    } finally {
      setSaving(false);
    }
  };

  return (
    <AppShell>
      <main className="space-y-6 p-4 sm:p-6 lg:p-8">
        <header className="flex items-start gap-4">
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)]">
            <Settings size={22} />
          </span>
          <div>
            <h1 className="type-page-title">{labels.title}</h1>
            <p className="mt-1 type-secondary text-[var(--text-secondary)]">
              {labels.description}
            </p>
          </div>
        </header>

        <section
          aria-labelledby="appearance-and-typography-heading"
          className="rounded-3xl border border-[var(--border)] bg-[var(--surface)] p-5 sm:p-6"
        >
          <div className="flex items-start gap-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--action-primary-soft)] text-[var(--action-primary)]">
              <Palette size={19} aria-hidden="true" />
            </span>
            <div>
              <h2
                id="appearance-and-typography-heading"
                className="type-section-title"
              >
                {labels.appearance}
              </h2>
              <p className="mt-1 type-secondary text-[var(--text-secondary)]">
                {labels.appearanceDescription}
              </p>
            </div>
          </div>

          <div className="mt-5">
            <h3 className="type-form-label mb-3">{labels.profiles}</h3>
            <AppearanceProfileSelector embedded />
          </div>

          <div className="mt-5 border-t border-[var(--border)] pt-5">
            <TypographyPreferencesSelector embedded />
          </div>
        </section>

        <section
          aria-labelledby="company-profile-heading"
          className="rounded-3xl border border-[var(--border)] bg-[var(--surface)] p-5 sm:p-6"
        >
          <div className="flex items-start gap-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold)]">
              <Building2 size={19} aria-hidden="true" />
            </span>
            <div>
              <h2 id="company-profile-heading" className="type-section-title">
                {labels.company}
              </h2>
              <p className="mt-1 type-secondary text-[var(--text-secondary)]">
                {labels.companyDescription}
              </p>
            </div>
          </div>

          <div className="mt-5 flex items-center gap-3 rounded-2xl border border-dashed border-[var(--border-strong)] bg-[var(--surface-soft)] p-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--surface-muted)] text-[var(--text-secondary)]">
              <ImageIcon size={18} aria-hidden="true" />
            </span>
            <div>
              <h3 className="type-form-label">{labels.logo}</h3>
              <p className="type-helper mt-0.5 text-[var(--text-secondary)]">
                {settings.logo_path ? labels.logoConfigured : labels.logoPending}
              </p>
            </div>
          </div>

          {settings.isLoading ? (
            <div
              className="mt-6 h-36 animate-pulse rounded-2xl bg-[var(--surface-muted)]"
              aria-label={labels.load}
            />
          ) : !settings.isDemoMode && settings.loadError ? (
            <p
              role="alert"
              className="type-secondary mt-6 font-semibold text-[var(--danger)]"
            >
              {labels.loadFailed}
            </p>
          ) : (
            <form className="mt-6 space-y-5" onSubmit={(event) => void submit(event)}>
              <fieldset disabled={!canEditCompany || saving} className="grid gap-4 md:grid-cols-2">
                <Input label={labels.companyNameAr} name="company_name_ar" value={form.company_name_ar} onChange={(event) => updateField("company_name_ar", event.target.value)} required />
                <Input label={labels.companyNameEn} name="company_name_en" value={form.company_name_en ?? ""} onChange={(event) => updateField("company_name_en", event.target.value)} />
                <Input label={labels.shortNameAr} name="short_name_ar" value={form.short_name_ar} onChange={(event) => updateField("short_name_ar", event.target.value)} required />
                <Input label={labels.shortNameEn} name="short_name_en" value={form.short_name_en ?? ""} onChange={(event) => updateField("short_name_en", event.target.value)} />
                <Input label={labels.phone} name="company_phone" value={form.phone ?? ""} onChange={(event) => updateField("phone", event.target.value)} onBlur={() => { try { updateField("phone", normalizeNullableSaudiMobile(form.phone ?? "")); } catch { /* Keep invalid input visible. */ } }} inputMode="tel" placeholder="05xxxxxxxx" />
                <Input label={labels.email} name="company_email" type="email" value={form.email ?? ""} onChange={(event) => updateField("email", event.target.value)} />
                <Input label={labels.website} name="company_website" type="url" value={form.website ?? ""} onChange={(event) => updateField("website", event.target.value)} placeholder="https://example.com" />
                <Input label={labels.registration} name="commercial_registration" value={form.commercial_registration ?? ""} onChange={(event) => updateField("commercial_registration", event.target.value)} />
                <Input label={labels.vat} name="vat_number" value={form.vat_number ?? ""} onChange={(event) => updateField("vat_number", event.target.value)} />
                <div className="space-y-2 md:col-span-2">
                  <label htmlFor="company_address" className="type-form-label block">{labels.address}</label>
                  <textarea id="company_address" name="company_address" value={form.address ?? ""} onChange={(event) => updateField("address", event.target.value)} rows={3} className="motion-ui w-full rounded-[var(--radius-md)] border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-[var(--text-primary)] shadow-[var(--shadow-sm)] outline-none focus:border-[var(--brand-primary)] focus:ring-4 focus:ring-[var(--focus-ring)]" />
                </div>
              </fieldset>

              {!canManageCompany ? (
                <p role="status" className="type-secondary font-semibold text-[var(--text-secondary)]">
                  {labels.noPermission}
                </p>
              ) : null}

              {error ? <p role="alert" className="type-secondary font-semibold text-[var(--danger)]">{error}</p> : null}
              {success ? <p role="status" className="type-secondary font-semibold text-[var(--success)]">{success}</p> : null}

              {canEditCompany ? (
                <Button type="submit" isLoading={saving} leadingIcon={<Save size={17} />}>
                  {labels.save}
                </Button>
              ) : null}
            </form>
          )}
        </section>
      </main>
    </AppShell>
  );
}
