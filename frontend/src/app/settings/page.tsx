"use client";

import { Phone, Save, Settings } from "lucide-react";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
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
import {
  fetchSystemSettings,
  updateSystemSettingsPhone,
} from "@/services/system-settings";

export default function SettingsPage() {
  const { token } = useAuth();
  const { isArabic } = useTranslation();
  const [phone, setPhone] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const labels = useMemo(
    () =>
      isArabic
        ? {
            title: "الإعدادات",
            description: "إدارة المظهر والخط وحجم النص ورقم التواصل العام.",
            card: "رقم التواصل",
            phone: "رقم الجوال",
            save: "حفظ رقم التواصل",
            saved: "تم تحديث رقم التواصل بنجاح.",
            load: "تعذر تحميل رقم التواصل.",
            fail: "تعذر حفظ رقم التواصل.",
            format: "استخدم رقم جوال محليًا من 10 أرقام يبدأ بـ 05.",
            international:
              "استخدم الصيغة المحلية 05 بدل صيغة رمز الدولة.",
          }
        : {
            title: "Settings",
            description: "Manage appearance, text preferences, and the system contact phone.",
            card: "Contact phone",
            phone: "Mobile number",
            save: "Save contact phone",
            saved: "Contact phone updated successfully.",
            load: "Unable to load the contact phone.",
            fail: "Unable to save the contact phone.",
            format:
              "Use a 10-digit local mobile number starting with 05.",
            international:
              "Use the local 05 format instead of a country-code format.",
          },
    [isArabic]
  );

  const load = useCallback(async () => {
    if (!token) {
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const settings = await fetchSystemSettings(token);
      setPhone(settings.phone ?? "");
    } catch {
      setError(labels.load);
    } finally {
      setLoading(false);
    }
  }, [labels.load, token]);

  useEffect(() => {
    const timeoutId = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timeoutId);
  }, [load]);

  const submit = async (): Promise<void> => {
    if (!token) {
      return;
    }

    setError(null);
    setSuccess(null);

    let value: string | null;
    try {
      value = normalizeNullableSaudiMobile(phone);
    } catch {
      setError(
        isInternationalSaudiMobile(phone)
          ? labels.international
          : labels.format
      );
      return;
    }

    setSaving(true);
    try {
      const settings = await updateSystemSettingsPhone(token, value);
      setPhone(settings.phone ?? "");
      setSuccess(labels.saved);
    } catch {
      setError(labels.fail);
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
            <h1 className="text-2xl font-bold">{labels.title}</h1>
            <p className="mt-1 text-sm text-[var(--text-secondary)]">
              {labels.description}
            </p>
          </div>
        </header>

        <AppearanceProfileSelector />
        <TypographyPreferencesSelector />

        <section className="max-w-2xl rounded-3xl border border-[var(--border)] bg-[var(--surface)] p-6">
          <h2 className="text-lg font-bold">{labels.card}</h2>

          {loading ? (
            <div
              className="mt-6 h-12 animate-pulse rounded-xl bg-[var(--surface-muted)]"
              aria-label="loading"
            />
          ) : (
            <div className="mt-6 space-y-5">
              <Input
                label={labels.phone}
                name="settings_phone"
                value={phone}
                onChange={(event) => {
                  setPhone(event.target.value);
                  setError(null);
                  setSuccess(null);
                }}
                onBlur={() => {
                  try {
                    setPhone(normalizeNullableSaudiMobile(phone) ?? "");
                  } catch {
                    // Keep invalid input visible for correction.
                  }
                }}
                inputMode="tel"
                placeholder="05xxxxxxxx"
                leading={<Phone size={17} />}
                error={error}
              />

              {success ? (
                <p
                  role="status"
                  className="text-sm font-semibold text-[var(--success)]"
                >
                  {success}
                </p>
              ) : null}

              <Button
                type="button"
                onClick={() => void submit()}
                isLoading={saving}
                leadingIcon={<Save size={17} />}
              >
                {labels.save}
              </Button>
            </div>
          )}
        </section>
      </main>
    </AppShell>
  );
}
