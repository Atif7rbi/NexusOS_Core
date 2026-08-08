"use client";

import {
  Building2,
  CalendarClock,
  ClockAlert,
  FileSignature,
  FolderKanban,
  Users,
} from "lucide-react";

import { CollectionSummary } from "@/components/dashboard/CollectionSummary";
import { DashboardHero } from "@/components/dashboard/DashboardHero";
import { FollowUpsPanel } from "@/components/dashboard/FollowUpsPanel";
import { ProjectsOverview } from "@/components/dashboard/ProjectsOverview";
import { StatCard } from "@/components/dashboard/StatCard";
import { AppShell } from "@/components/layout/AppShell";
import { useDashboard } from "@/hooks/useDashboard";
import { useTranslation } from "@/hooks/useTranslation";
import { formatInteger } from "@/lib/number-format";
import type { DashboardResource } from "@/types/dashboard";

function metricValue<T>(
  resource: DashboardResource<T>,
  value: number | undefined
): string {
  return resource.data === null || value === undefined
    ? "—"
    : formatInteger(value);
}

function metricDescription<T>(
  resource: DashboardResource<T>,
  labels: {
    ready: string;
    loading: string;
    error: string;
  }
): string {
  if (resource.isLoading && resource.data === null) {
    return labels.loading;
  }

  if (resource.error && resource.data === null) {
    return labels.error;
  }

  return labels.ready;
}

export default function HomePage() {
  const { isArabic } = useTranslation();
  const dashboard = useDashboard();
  const copy = isArabic
    ? {
        attention: "ما يحتاج انتباهك اليوم",
        attentionDescription: "المتابعات والحجوزات والعقود التي تحدد أولوية العمل اليومية.",
        overdue: "متابعات متأخرة",
        today: "متابعات اليوم",
        activeReservations: "حجوزات نشطة",
        totalContracts: "إجمالي العقود",
        business: "الحالة التشغيلية الحالية",
        businessDescription: "لقطة مباشرة للمشاريع والعملاء والوحدات المتاحة.",
        activeProjects: "مشاريع نشطة",
        totalCustomers: "إجمالي العملاء",
        availableUnits: "وحدات متاحة",
        loading: "جارٍ التحميل",
        error: "تعذر تحميل هذا المؤشر",
        visibleFollowUps: "ضمن نطاق CRM المصرح لك",
        currentReservations: "الحجوزات الفعالة حاليًا",
        registeredContracts: "كل العقود المسجلة",
        currentProjects: "المشاريع النشطة حاليًا",
        registeredCustomers: "كل العملاء المسجلين",
        operationalUnits: "متاحة وغير مؤرشفة",
      }
    : {
        attention: "What needs your attention today",
        attentionDescription: "Follow-ups, reservations, and contracts that shape today's priorities.",
        overdue: "Overdue follow-ups",
        today: "Today's follow-ups",
        activeReservations: "Active reservations",
        totalContracts: "Total contracts",
        business: "Current operational state",
        businessDescription: "A live snapshot of projects, customers, and available units.",
        activeProjects: "Active projects",
        totalCustomers: "Total customers",
        availableUnits: "Available units",
        loading: "Loading",
        error: "Unable to load this metric",
        visibleFollowUps: "Within your authorized CRM scope",
        currentReservations: "Currently active reservations",
        registeredContracts: "All registered contracts",
        currentProjects: "Currently active projects",
        registeredCustomers: "All registered customers",
        operationalUnits: "Available and not archived",
      };

  const attentionStats = [
    ...(dashboard.hasCrmAccess
      ? [
          {
            label: copy.overdue,
            value: metricValue(
              dashboard.followUps,
              dashboard.followUps.data?.summary.overdue_follow_ups
            ),
            description: metricDescription(dashboard.followUps, {
              ready: copy.visibleFollowUps,
              loading: copy.loading,
              error: copy.error,
            }),
            icon: ClockAlert,
            tone: "red" as const,
            href: "/crm/?follow_up_state=overdue",
          },
          {
            label: copy.today,
            value: metricValue(
              dashboard.followUps,
              dashboard.followUps.data?.summary.today_follow_ups
            ),
            description: metricDescription(dashboard.followUps, {
              ready: copy.visibleFollowUps,
              loading: copy.loading,
              error: copy.error,
            }),
            icon: CalendarClock,
            tone: "gold" as const,
            href: "/crm/?follow_up_state=today",
          },
        ]
      : []),
    {
      label: copy.activeReservations,
      value: metricValue(
        dashboard.reservations,
        dashboard.reservations.data ?? undefined
      ),
      description: metricDescription(dashboard.reservations, {
        ready: copy.currentReservations,
        loading: copy.loading,
        error: copy.error,
      }),
      icon: Building2,
      tone: "green" as const,
      href: "/reservations/",
    },
    {
      label: copy.totalContracts,
      value: metricValue(
        dashboard.contracts,
        dashboard.contracts.data ?? undefined
      ),
      description: metricDescription(dashboard.contracts, {
        ready: copy.registeredContracts,
        loading: copy.loading,
        error: copy.error,
      }),
      icon: FileSignature,
      tone: "violet" as const,
      href: "/contracts/",
    },
  ];

  const businessStats = [
    {
      label: copy.activeProjects,
      value: metricValue(
        dashboard.projects,
        dashboard.projects.data?.activeTotal
      ),
      description: metricDescription(dashboard.projects, {
        ready: copy.currentProjects,
        loading: copy.loading,
        error: copy.error,
      }),
      icon: FolderKanban,
      tone: "gold" as const,
      href: "/projects/",
    },
    {
      label: copy.totalCustomers,
      value: metricValue(
        dashboard.customers,
        dashboard.customers.data ?? undefined
      ),
      description: metricDescription(dashboard.customers, {
        ready: copy.registeredCustomers,
        loading: copy.loading,
        error: copy.error,
      }),
      icon: Users,
      tone: "blue" as const,
      href: "/customers/",
    },
    {
      label: copy.availableUnits,
      value: metricValue(
        dashboard.units,
        dashboard.units.data ?? undefined
      ),
      description: metricDescription(dashboard.units, {
        ready: copy.operationalUnits,
        loading: copy.loading,
        error: copy.error,
      }),
      icon: Building2,
      tone: "green" as const,
      href: "/units/",
    },
  ];

  return (
    <AppShell>
      <div className="mx-auto max-w-[1600px] space-y-6">
        <DashboardHero />

        <section>
          <h2 className="text-xl font-bold text-[var(--text-primary)]">
            {copy.attention}
          </h2>
          <p className="mt-1 text-sm text-[var(--text-secondary)]">
            {copy.attentionDescription}
          </p>
          <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {attentionStats.map((stat) => (
              <StatCard key={stat.label} {...stat} />
            ))}
          </div>
        </section>

        {dashboard.hasCrmAccess ? (
          <FollowUpsPanel resource={dashboard.followUps} />
        ) : null}

        <CollectionSummary resource={dashboard.collections} />

        <section>
          <h2 className="text-xl font-bold text-[var(--text-primary)]">
            {copy.business}
          </h2>
          <p className="mt-1 text-sm text-[var(--text-secondary)]">
            {copy.businessDescription}
          </p>
          <div className="mt-4 grid gap-4 sm:grid-cols-3">
            {businessStats.map((stat) => (
              <StatCard key={stat.label} {...stat} />
            ))}
          </div>
        </section>

        <ProjectsOverview
          projects={dashboard.projects.data?.items ?? []}
          isLoading={dashboard.projects.isLoading}
          error={dashboard.projects.error}
        />
      </div>
    </AppShell>
  );
}
