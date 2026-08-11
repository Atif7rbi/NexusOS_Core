"use client";

import { Check } from "lucide-react";

import { useTranslation } from "@/hooks/useTranslation";
import {
  useTheme,
  type ThemeProfile,
} from "@/providers/ThemeProvider";

type ProfilePreview = {
  id: ThemeProfile;
  label: string;
  description: { ar: string; en: string };
  canvas: string;
  surface: string;
  sidebar: string;
  action: string;
  actionText: string;
};

export const APPEARANCE_PROFILE_PREVIEWS: ProfilePreview[] = [
  {
    id: "light-1",
    label: "Light 1",
    description: {
      ar: "فاتح محايد وهادئ",
      en: "Calm neutral light",
    },
    canvas: "#f6f4fb",
    surface: "#ffffff",
    sidebar: "#f0edff",
    action: "#2563eb",
    actionText: "#ffffff",
  },
  {
    id: "light-2",
    label: "Light 2",
    description: {
      ar: "فاتح بهوية زرقاء",
      en: "Blue-led light",
    },
    canvas: "#f2f7fc",
    surface: "#ffffff",
    sidebar: "#eaf4ff",
    action: "#1671d9",
    actionText: "#ffffff",
  },
  {
    id: "dark-1",
    label: "Dark 1",
    description: {
      ar: "داكن بنفسجي مصقول",
      en: "Polished violet dark",
    },
    canvas: "#120f1d",
    surface: "#1d1830",
    sidebar: "#171326",
    action: "#e7a52b",
    actionText: "#181322",
  },
  {
    id: "dark-2",
    label: "Dark 2",
    description: {
      ar: "فحمي كحلي بلمسة دافئة",
      en: "Charcoal navy with warm accents",
    },
    canvas: "#0b111b",
    surface: "#121b28",
    sidebar: "#060d17",
    action: "#f59e0b",
    actionText: "#1c1204",
  },
];

type AppearanceProfileSelectorProps = {
  embedded?: boolean;
};

export function AppearanceProfileSelector({
  embedded = false,
}: AppearanceProfileSelectorProps) {
  const { isArabic } = useTranslation();
  const { theme, setTheme } = useTheme();

  const copy = isArabic
    ? {
        title: "المظهر",
        description:
          "اختر ملف الألوان الأنسب لك. يُحفظ الاختيار لهذا الحساب على هذا الجهاز.",
        selected: "محدد",
      }
    : {
        title: "Appearance",
        description:
          "Choose your preferred color profile. The selection is saved for this account on this device.",
        selected: "Selected",
      };

  const controls = (
    <div className={embedded ? "grid gap-3 sm:grid-cols-2 xl:grid-cols-4" : "mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"}>
      {APPEARANCE_PROFILE_PREVIEWS.map((profile) => {
        const selected = theme === profile.id;

        return (
          <button
            key={profile.id}
            type="button"
            aria-pressed={selected}
            onClick={() => setTheme(profile.id)}
            className={[
              "motion-ui overflow-hidden rounded-2xl border p-2.5 text-start",
              "focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]",
              selected
                ? "border-[var(--action-primary)] shadow-[var(--shadow-md)]"
                : "border-[var(--border)] hover:border-[var(--border-strong)] hover:shadow-[var(--shadow-sm)]",
            ].join(" ")}
          >
            <span
              className="flex h-16 overflow-hidden rounded-xl border border-black/10"
              style={{ backgroundColor: profile.canvas }}
              aria-hidden="true"
            >
              <span
                className="w-1/4"
                style={{ backgroundColor: profile.sidebar }}
              />
              <span className="flex flex-1 flex-col gap-1.5 p-2">
                <span
                  className="h-3 rounded-md"
                  style={{ backgroundColor: profile.surface }}
                />
                <span
                  className="h-5 w-2/3 rounded-lg"
                  style={{ backgroundColor: profile.action }}
                />
                <span
                  className="mt-auto h-2 rounded-md"
                  style={{ backgroundColor: profile.surface }}
                />
              </span>
            </span>

            <span className="mt-2 flex items-start justify-between gap-2">
              <span>
                <span className="block text-sm font-bold text-[var(--text-primary)]">
                  {profile.label}
                </span>
                <span className="mt-0.5 block text-xs text-[var(--text-secondary)]">
                  {profile.description[isArabic ? "ar" : "en"]}
                </span>
              </span>

              {selected ? (
                <span
                  className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--action-primary)] text-[var(--action-primary-foreground)]"
                  title={copy.selected}
                >
                  <Check size={12} aria-hidden="true" />
                  <span className="sr-only">{copy.selected}</span>
                </span>
              ) : null}
            </span>
          </button>
        );
      })}
    </div>
  );

  if (embedded) {
    return controls;
  }

  return (
    <section
      aria-labelledby="appearance-heading"
      className="rounded-3xl border border-[var(--border)] bg-[var(--surface)] p-6"
    >
      <div>
        <h2 id="appearance-heading" className="text-lg font-bold">
          {copy.title}
        </h2>
        <p className="mt-1 text-sm text-[var(--text-secondary)]">
          {copy.description}
        </p>
      </div>

      {controls}
    </section>
  );
}
