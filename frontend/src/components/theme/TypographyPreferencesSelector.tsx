"use client";

import { Check, Type } from "lucide-react";

import { useTranslation } from "@/hooks/useTranslation";
import {
  FONT_FAMILIES,
  TEXT_SIZE_PROFILES,
  useTypography,
  type FontFamily,
  type TextSizeProfile,
} from "@/providers/TypographyProvider";

type Choice<T extends string> = {
  id: T;
  label: { ar: string; en: string };
  className?: string;
};

const textSizeChoices: Choice<TextSizeProfile>[] = [
  { id: "normal", label: { ar: "عادي", en: "Normal" } },
  { id: "large", label: { ar: "كبير", en: "Large" } },
  { id: "extra-large", label: { ar: "كبير جدًا", en: "Extra large" } },
];

const fontChoices: Choice<FontFamily>[] = [
  {
    id: "ibm-plex-sans-arabic",
    label: { ar: "IBM Plex Sans Arabic", en: "IBM Plex Sans Arabic" },
    className: "font-preview-ibm-plex-sans-arabic",
  },
  {
    id: "noto-sans-arabic",
    label: { ar: "Noto Sans Arabic", en: "Noto Sans Arabic" },
    className: "font-preview-noto-sans-arabic",
  },
  {
    id: "tajawal",
    label: { ar: "Tajawal", en: "Tajawal" },
    className: "font-preview-tajawal",
  },
  {
    id: "tahoma",
    label: { ar: "Tahoma", en: "Tahoma" },
    className: "font-preview-tahoma",
  },
];

export { FONT_FAMILIES, TEXT_SIZE_PROFILES };

export function TypographyPreferencesSelector() {
  const { isArabic } = useTranslation();
  const {
    textSize,
    fontFamily,
    setTextSize,
    setFontFamily,
  } = useTypography();
  const copy = isArabic
    ? {
        title: "الخط وحجم النص",
        description:
          "تُحفظ هذه التفضيلات لهذا الحساب على هذا الجهاز.",
        textSize: "حجم النص",
        font: "نوع الخط",
        selected: "محدد",
      }
    : {
        title: "Text and font",
        description:
          "These preferences are saved for this account on this device.",
        textSize: "Text size",
        font: "Font family",
        selected: "Selected",
      };

  return (
    <section
      aria-labelledby="typography-heading"
      className="rounded-3xl border border-[var(--border)] bg-[var(--surface)] p-5 sm:p-6"
    >
      <div className="flex items-start gap-3">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--action-primary-soft)] text-[var(--action-primary)]">
          <Type size={19} aria-hidden="true" />
        </span>
        <div>
          <h2 id="typography-heading" className="type-section-title">
            {copy.title}
          </h2>
          <p className="mt-1 type-secondary">
            {copy.description}
          </p>
        </div>
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-2 lg:gap-6">
        <fieldset className="min-w-0">
          <legend className="type-form-label">{copy.textSize}</legend>
          <div className="mt-3 flex flex-wrap gap-2">
            {textSizeChoices.map((choice) => (
              <CompactChoice
                key={choice.id}
                choice={choice}
                selected={textSize === choice.id}
                selectedText={copy.selected}
                isArabic={isArabic}
                onSelect={() => setTextSize(choice.id)}
              />
            ))}
          </div>
        </fieldset>

        <fieldset className="min-w-0">
          <legend className="type-form-label">{copy.font}</legend>
          <div className="mt-3 grid grid-cols-2 gap-2">
            {fontChoices.map((choice) => (
              <CompactChoice
                key={choice.id}
                choice={choice}
                selected={fontFamily === choice.id}
                selectedText={copy.selected}
                isArabic={isArabic}
                onSelect={() => setFontFamily(choice.id)}
                fullWidth
              />
            ))}
          </div>
        </fieldset>
      </div>
    </section>
  );
}

type CompactChoiceProps<T extends string> = {
  choice: Choice<T>;
  selected: boolean;
  selectedText: string;
  isArabic: boolean;
  onSelect: () => void;
  fullWidth?: boolean;
};

function CompactChoice<T extends string>({
  choice,
  selected,
  selectedText,
  isArabic,
  onSelect,
  fullWidth = false,
}: CompactChoiceProps<T>) {
  return (
    <button
      type="button"
      aria-pressed={selected}
      onClick={onSelect}
      className={[
        "motion-ui inline-flex min-h-11 items-center justify-between gap-2 rounded-xl border px-3 py-2 text-start",
        "type-button font-semibold",
        "focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]",
        fullWidth ? "w-full" : "",
        choice.className ?? "",
        selected
          ? "border-[var(--action-primary)] bg-[var(--action-primary-soft)] text-[var(--text-primary)]"
          : "border-[var(--border)] bg-[var(--surface)] text-[var(--text-primary)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-soft)]",
      ].join(" ")}
    >
      <span className="min-w-0 truncate">
        {choice.label[isArabic ? "ar" : "en"]}
      </span>
      {selected ? (
        <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[var(--action-primary)] text-[var(--action-primary-foreground)]">
          <Check size={12} aria-hidden="true" />
          <span className="sr-only">{selectedText}</span>
        </span>
      ) : null}
    </button>
  );
}
