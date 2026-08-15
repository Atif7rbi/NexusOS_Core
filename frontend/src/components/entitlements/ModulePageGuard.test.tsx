import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ModulePageGuard } from "@/components/entitlements/ModulePageGuard";

const auth = vi.hoisted(() => ({
  effectiveModules: [] as string[],
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    effectiveModules: auth.effectiveModules,
    isLoading: false,
    isAuthenticated: true,
  }),
}));

vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));

describe("ModulePageGuard", () => {
  beforeEach(() => {
    auth.effectiveModules = [];
  });

  it("renders an entitled module page", () => {
    auth.effectiveModules = ["projects"];

    render(
      <ModulePageGuard module="projects">
        <div>projects-content</div>
      </ModulePageGuard>
    );

    expect(screen.getByText("projects-content")).toBeTruthy();
  });

  it("fails safely without mounting unavailable page content", () => {
    const PageContent = vi.fn(() => <div>projects-content</div>);

    render(
      <ModulePageGuard module="projects">
        <PageContent />
      </ModulePageGuard>
    );

    expect(PageContent).not.toHaveBeenCalled();
    expect(screen.getByRole("alert").textContent).toContain(
      "الوحدة غير متاحة"
    );
  });
});
