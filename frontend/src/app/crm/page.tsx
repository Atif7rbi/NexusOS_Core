"use client";

import { Suspense } from "react";

import { CrmLeadsPage } from "@/components/leads/CrmLeadsPage";
import { ModulePageGuard } from "@/components/entitlements/ModulePageGuard";
import { AppShell } from "@/components/layout/AppShell";
import { useTranslation } from "@/hooks/useTranslation";
import { canAccessCrm } from "@/lib/crm-authorization";
import { useAuth } from "@/providers/AuthProvider";

export default function Page() {
  const { user, isLoading } = useAuth();
  const { isArabic } = useTranslation();

  if (isLoading) {
    return <CrmPageFallback />;
  }

  if (user && !canAccessCrm(user.role)) {
    return (
      <AppShell>
        <section
          role="alert"
          className="mx-auto max-w-3xl rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-6 text-center"
        >
          <h1 className="type-page-title">
            {isArabic ? "إدارة العملاء المحتملين" : "CRM Leads"}
          </h1>
          <p className="mt-2 type-secondary text-[var(--text-secondary)]">
            {isArabic
              ? "لا يملك هذا الحساب صلاحية الوصول إلى إدارة العملاء المحتملين."
              : "This account does not have access to CRM leads."}
          </p>
        </section>
      </AppShell>
    );
  }

  return (
    <ModulePageGuard module="crm">
      <Suspense fallback={<CrmPageFallback />}>
        <CrmLeadsPage />
      </Suspense>
    </ModulePageGuard>
  );
}

function CrmPageFallback() {
  return (
    <AppShell>
      <div
        aria-busy="true"
        className="mx-auto max-w-[1600px] space-y-5"
      >
        <div className="h-28 animate-pulse rounded-2xl border border-[var(--border)] bg-[var(--surface)]" />
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[0, 1, 2, 3].map((item) => (
            <div
              key={item}
              className="h-28 animate-pulse rounded-2xl border border-[var(--border)] bg-[var(--surface)]"
            />
          ))}
        </div>
        <div className="h-80 animate-pulse rounded-2xl border border-[var(--border)] bg-[var(--surface)]" />
      </div>
    </AppShell>
  );
}
