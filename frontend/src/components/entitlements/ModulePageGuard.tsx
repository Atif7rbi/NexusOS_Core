"use client";

import type { ReactNode } from "react";

import { ModuleUnavailableState } from "@/components/entitlements/ModuleUnavailableState";
import { AppShell } from "@/components/layout/AppShell";
import { hasModuleEntitlement } from "@/lib/entitlements";
import { useAuth } from "@/providers/AuthProvider";
import type { CommercialModuleKey } from "@/types/entitlement";

type ModulePageGuardProps = {
  module: CommercialModuleKey;
  children: ReactNode;
};

export function ModulePageGuard({
  module,
  children,
}: ModulePageGuardProps) {
  const { effectiveModules, isLoading } = useAuth();

  if (isLoading) {
    return <AppShell>{null}</AppShell>;
  }

  if (!hasModuleEntitlement(effectiveModules, module)) {
    return (
      <AppShell>
        <ModuleUnavailableState />
      </AppShell>
    );
  }

  return children;
}
