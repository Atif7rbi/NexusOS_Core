"use client";

import Link from "next/link";
import {
  Building2,
  ChevronLeft,
  ChevronRight,
  Coins,
  MapPin,
} from "lucide-react";

import { EmptyState } from "@/components/ui/EmptyState";
import { useTranslation } from "@/hooks/useTranslation";
import type {
  Project,
  ProjectStatus,
  ProjectType,
} from "@/types/project";

type ProjectsOverviewProps = {
  projects: Project[];
  isLoading: boolean;
  error: string | null;
};

const projectTypeLabels: Record<
  ProjectType,
  {
    ar: string;
    en: string;
  }
> = {
  residential: { ar: "سكني", en: "Residential" },
  commercial: { ar: "تجاري", en: "Commercial" },
  mixed_use: { ar: "متعدد الاستخدامات", en: "Mixed use" },
  land: { ar: "أرض", en: "Land" },
  villa: { ar: "فلل", en: "Villas" },
  tower: { ar: "برج", en: "Tower" },
  compound: { ar: "مجمع", en: "Compound" },
  warehouse: { ar: "مستودعات", en: "Warehouses" },
  other: { ar: "أخرى", en: "Other" },
};

const projectStatusLabels: Record<
  ProjectStatus,
  {
    ar: string;
    en: string;
  }
> = {
  draft: { ar: "مسودة", en: "Draft" },
  active: { ar: "نشط", en: "Active" },
  completed: { ar: "مكتمل", en: "Completed" },
  cancelled: { ar: "ملغي", en: "Cancelled" },
};

const statusClasses: Record<ProjectStatus, string> = {
  draft:
    "bg-[var(--surface-muted)] text-[var(--text-secondary)]",

  active:
    "bg-[var(--success-soft)] text-[var(--success)]",

  completed:
    "bg-[var(--brand-primary-soft)] text-[var(--brand-primary)]",

  cancelled:
    "bg-[var(--danger-soft)] text-[var(--danger)]",
};

export function ProjectsOverview({
  projects,
  isLoading,
  error,
}: ProjectsOverviewProps) {
  const { t, isArabic } = useTranslation();

  const ArrowIcon = isArabic
    ? ChevronLeft
    : ChevronRight;

  return (
    <section
      className={[
        "rounded-[var(--radius-lg)]",
        "border border-[var(--border)]",
        "bg-[var(--surface)]",
        "p-6",
        "shadow-[var(--shadow-sm)]",
      ].join(" ")}
    >
      <div className="flex items-start justify-between gap-5">
        <div>
          <h3 className="text-lg font-bold text-[var(--text-primary)]">
            {t("dashboard.currentProjects")}
          </h3>

          <p className="mt-1 text-sm text-[var(--text-secondary)]">
            {t("dashboard.currentProjectsDescription")}
          </p>
        </div>

        <Link
          href="/projects/"
          className={[
            "motion-ui inline-flex items-center gap-2",
            "rounded-full",
            "bg-[var(--brand-primary-soft)]",
            "px-4 py-2",
            "text-xs font-bold",
            "text-[var(--brand-primary)]",
            "hover:opacity-90",
          ].join(" ")}
        >
          {t("common.showAll")}
          <ArrowIcon size={14} />
        </Link>
      </div>

      {isLoading ? (
        <div className="mt-8 flex min-h-64 items-center justify-center">
          <div
            className={[
              "h-10 w-10 animate-spin rounded-full",
              "border-2 border-[var(--border)]",
              "border-t-[var(--brand-primary)]",
            ].join(" ")}
          />
        </div>
      ) :
error ? (
  <div className="mt-8 rounded-[var(--radius-md)] border border-[var(--danger)]/20 bg-[var(--danger-soft)] p-10 text-center">
    <p className="font-semibold text-[var(--danger)]">
      {isArabic
        ? "تعذر تحميل المشاريع."
        : "Unable to load projects."}
    </p>
  </div>
) : projects.length === 0 ? (
  <div className="mt-8">
    <EmptyState
      icon={<Building2 size={24} strokeWidth={1.7} />}
      title={t("dashboard.noProjects")}
      description={t(
        "dashboard.noProjectsDescription"
      )}
    />
  </div>
) : (
  <div className="mt-8 space-y-4">
    {projects.map((project) => (
      <article
        key={project.id}
        className={[
          "motion-ui group",
          "rounded-[var(--radius-md)]",
          "border border-[var(--border)]",
          "bg-[var(--surface-soft)]",
          "p-5",
          "hover:border-[var(--border-strong)]",
          "hover:bg-[var(--surface)]",
        ].join(" ")}
      >
        <div className="flex items-start justify-between gap-5">
          <div className="flex min-w-0 gap-4">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-[var(--radius-md)] bg-[var(--brand-primary-soft)] text-[var(--brand-primary)]">
              <Building2 size={20} />
            </div>

            <div className="min-w-0">
              <h4 className="truncate text-base font-bold text-[var(--text-primary)]">
                {project.name}
              </h4>

              <p className="mt-1 text-sm text-[var(--text-secondary)]">
                {
                  projectTypeLabels[
                    project.project_type
                  ][isArabic ? "ar" : "en"]
                }
              </p>

              <div className="mt-4 flex flex-wrap gap-5 text-xs">
                <span className="inline-flex items-center gap-2 text-[var(--text-secondary)]">
                  <MapPin size={14} />
                  <span>
                    {project.city}
                    {project.district
                      ? `، ${project.district}`
                      : ""}
                  </span>
                </span>

                <span
                  className="inline-flex items-center gap-2 font-semibold text-[var(--brand-primary)]"
                  dir="ltr"
                >
                  <Coins size={14} />

                  {project.estimated_budget
                    ? `${new Intl.NumberFormat(
                        "en-US",
                        {
                          maximumFractionDigits: 0,
                        }
                      ).format(
                        Number(
                          project.estimated_budget
                        )
                      )} SAR`
                    : isArabic
                      ? "غير محددة"
                      : "Not specified"}
                </span>
              </div>
            </div>
          </div>

          <span
            className={[
              "rounded-full px-3 py-1",
              "text-xs font-bold",
              statusClasses[project.status],
            ].join(" ")}
          >
            {
              projectStatusLabels[
                project.status
              ][isArabic ? "ar" : "en"]
            }
          </span>
        </div>
      </article>
    ))}
  </div>
)}
    </section>
  );
}
