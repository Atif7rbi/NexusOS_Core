"use client";

import { useTranslation } from "@/hooks/useTranslation";
import { useSystemSettings } from "@/providers/SystemSettingsProvider";

type CompanyBrandProps = {
  compact?: boolean;
  hideText?: boolean;
  inlineText?: boolean;
  responsiveShortName?: boolean;
  variant?: "default" | "inverse";
};

export function shouldDisplayCompanyTagline(
  companyName: string,
  tagline: string | null
): boolean {
  const normalizedCompanyName = companyName.trim().toLocaleLowerCase();
  const normalizedTagline = tagline?.trim().toLocaleLowerCase() ?? "";

  return Boolean(normalizedTagline) &&
    !normalizedCompanyName.includes(normalizedTagline);
}

export function CompanyBrand({
  compact = false,
  hideText = false,
  inlineText = false,
  responsiveShortName = false,
  variant = "default",
}: CompanyBrandProps) {
  const settings = useSystemSettings();
  const { isArabic } = useTranslation();

  const logoSource =
    settings.logo_path || "/brand/nexusos-default-mark.svg";

  const companyName = isArabic
    ? settings.company_name_ar
    : settings.company_name_en ??
      settings.company_name_ar;

  const companyTagline = isArabic
    ? settings.company_tagline_ar
    : settings.company_tagline_en ??
      settings.company_tagline_ar;

  const shortName = isArabic
    ? settings.short_name_ar
    : settings.short_name_en ??
      settings.short_name_ar;

  const isInverse = variant === "inverse";
  const shouldShowTagline = shouldDisplayCompanyTagline(
    companyName,
    companyTagline
  );

  return (
    <div className="flex min-w-0 items-center gap-3">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={logoSource}
        alt={companyName}
        className={[
          "shrink-0 object-contain",
          compact
            ? "h-10 w-10 rounded-xl"
            : "h-12 w-12 rounded-2xl",
        ].join(" ")}
      />

      {!hideText ? (
        <div
          className={[
            "min-w-0",
            inlineText
              ? "flex items-baseline gap-2"
              : "",
          ].join(" ")}
        >
          <p
            className={[
              "truncate text-[15px] font-bold",
              isInverse
                ? "text-white"
                : "text-[var(--text-primary)]",
            ].join(" ")}
          >
            {responsiveShortName ? (
              <>
                <span className="sm:hidden">
                  {shortName}
                </span>
                <span className="hidden sm:inline">
                  {companyName}
                </span>
              </>
            ) : (
              companyName
            )}
          </p>

          {shouldShowTagline ? (
            <p
              className={[
                "truncate text-[11px] font-medium",
                inlineText
                  ? "hidden lg:block"
                  : "mt-1",
                isInverse
                  ? "text-white/65"
                  : "text-[var(--text-secondary)]",
              ].join(" ")}
            >
              {inlineText ? "— " : ""}
              {companyTagline}
            </p>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
