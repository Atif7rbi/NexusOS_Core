import { Suspense } from "react";

import { CrmLeadsPage } from "@/components/leads/CrmLeadsPage";
import { AppShell } from "@/components/layout/AppShell";

export default function Page() {
  return (
    <Suspense fallback={<CrmPageFallback />}>
      <CrmLeadsPage />
    </Suspense>
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
