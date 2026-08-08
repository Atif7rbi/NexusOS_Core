"use client";

import Link from "next/link";
import {
  CalendarPlus,
  FilePlus2,
  FolderPlus,
  UserPlus,
} from "lucide-react";

import { useTranslation } from "@/hooks/useTranslation";
import { useAuth } from "@/providers/AuthProvider";
import { useSystemSettings } from "@/providers/SystemSettingsProvider";

function getGreeting(
  hour: number,
  isArabic: boolean
): string {
  if (hour >= 5 && hour < 12) {
    return isArabic
      ? "صباح الخير"
      : "Good morning";
  }

  if (hour >= 12 && hour < 17) {
    return isArabic
      ? "مساء الخير"
      : "Good afternoon";
  }

  return isArabic
    ? "أهلاً بك"
    : "Welcome back";
}

function formatCurrentDate(
  date: Date,
  isArabic: boolean
): string {
  return new Intl.DateTimeFormat(
    isArabic ? "ar-SA-u-nu-latn" : "en-US",
    {
      weekday: "long",
      day: "numeric",
      month: "long",
      calendar: "gregory",
    }
  ).format(date);
}

export function DashboardHero() {
  const { t, isArabic } = useTranslation();
  const { user } = useAuth();
  const settings = useSystemSettings();

  const now = new Date();
  const greeting = getGreeting(
    now.getHours(),
    isArabic
  );

  const actions = [
    {
      label: t("dashboard.quickActions.newProject"),
      icon: FolderPlus,
      href: "/projects/",
      enabled: true,
    },
    {
      label: t("dashboard.quickActions.newCustomer"),
      icon: UserPlus,
      href: "/customers/",
      enabled: true,
    },
    {
      label: t("dashboard.quickActions.newContract"),
      icon: FilePlus2,
      href: "/contracts/",
      enabled: true,
    },
  ];

  return (
    <section className="overflow-hidden rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow-md)]">
      <div className="relative px-6 py-8 sm:px-8">
        <div className="absolute inset-y-0 start-0 hidden w-72 bg-gradient-to-end from-[var(--brand-gold-soft)] to-transparent lg:block" />

        <div className="relative flex flex-col justify-between gap-6 xl:flex-row xl:items-center">
          <div>
            <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-[var(--border)] bg-[var(--surface-soft)] px-4 py-2 text-[11px] font-bold text-[var(--text-secondary)]">
              <CalendarPlus size={14} />

              <span suppressHydrationWarning>
                {formatCurrentDate(now, isArabic)}
              </span>
            </div>

            <h2
              suppressHydrationWarning
              className="text-2xl font-bold text-[var(--text-primary)] sm:text-3xl"
            >
              {greeting}
              {user?.name ? `، ${user.name}` : ""}
            </h2>

            <p className="mt-2 text-sm leading-7 text-[var(--text-secondary)]">
              {isArabic
                ? settings.company_name_ar
                : settings.company_name_en ??
                  settings.company_name_ar}

              {(isArabic
                ? settings.company_tagline_ar
                : settings.company_tagline_en) ? (
                <>
                  {" — "}
                  {isArabic
                    ? settings.company_tagline_ar
                    : settings.company_tagline_en}
                </>
              ) : null}
            </p>
          </div>

          <div className="grid grid-cols-3 gap-3">
            {actions.map((action) => {
              const Icon = action.icon;

              const classes = [
                "motion-ui flex min-h-24 flex-col items-center justify-center gap-3",
                "rounded-[var(--radius-md)] border border-[var(--border)]",
                "bg-[var(--surface-soft)] px-4",
                "text-sm font-bold text-[var(--text-primary)]",
                action.enabled
                  ? "hover:-translate-y-1 hover:border-[var(--brand-primary)] hover:bg-[var(--brand-primary-soft)] hover:shadow-[var(--shadow-md)]"
                  : "cursor-default opacity-65",
              ].join(" ");

              const content = (
                <>
                  <span className="flex h-12 w-12 items-center justify-center rounded-[var(--radius-md)] bg-[var(--surface)] text-[var(--brand-gold-strong)] shadow-[var(--shadow-sm)]">
                    <Icon size={19} strokeWidth={1.9} />
                  </span>

                  <span>{action.label}</span>
                </>
              );

              return action.enabled ? (
                <Link
                  key={action.label}
                  href={action.href}
                  className={classes}
                >
                  {content}
                </Link>
              ) : (
                <div
                  key={action.label}
                  className={classes}
                >
                  {content}
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </section>
  );
}
