"use client";

import {
  useEffect,
  type ReactNode,
} from "react";
import { usePathname, useRouter } from "next/navigation";

import { ModuleUnavailableState } from "@/components/entitlements/ModuleUnavailableState";
import { Sidebar } from "@/components/layout/Sidebar";
import { Topbar } from "@/components/layout/Topbar";
import {
  getCommercialModuleForPath,
  hasModuleEntitlement,
} from "@/lib/entitlements";
import { useAuth } from "@/providers/AuthProvider";

type AppShellProps = {
  children: ReactNode;
};

export function AppShell({
  children,
}: AppShellProps) {
  const router = useRouter();
  const pathname = usePathname();

  const {
    isAuthenticated,
    isLoading,
    effectiveModules,
  } = useAuth();

  const requestedModule =
    getCommercialModuleForPath(pathname);
  const isRequestedModuleAvailable =
    requestedModule === null ||
    hasModuleEntitlement(
      effectiveModules,
      requestedModule
    );

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace("/login/");
    }
  }, [isAuthenticated, isLoading, router]);

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-[var(--app-bg)]">
        <div className="text-center">
          <div className="mx-auto h-9 w-9 animate-spin rounded-full border-2 border-[var(--border)] border-t-[var(--brand-gold)]" />

          <p className="mt-4 text-sm font-medium text-[var(--text-secondary)]">
            جارٍ تحميل مساحة العمل...
          </p>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  return (
    <div className="min-h-screen bg-[var(--app-bg)] lg:flex">
      <Sidebar />

      <div className="min-w-0 flex-1">
        <Topbar />

        <main className="px-4 py-5 sm:px-6 lg:px-7 lg:py-6">
          {isRequestedModuleAvailable ? (
            children
          ) : (
            <ModuleUnavailableState />
          )}
        </main>
      </div>
    </div>
  );
}
