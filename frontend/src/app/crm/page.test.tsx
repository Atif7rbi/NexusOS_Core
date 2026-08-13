import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import Page from "@/app/crm/page";
import type { UserRole } from "@/types/auth";

const auth = vi.hoisted(() => ({
  role: "administrator" as UserRole,
  isLoading: false,
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    user: { role: auth.role },
    isLoading: auth.isLoading,
  }),
}));
vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));
vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/leads/CrmLeadsPage", () => ({
  CrmLeadsPage: () => <div>crm-workspace</div>,
}));

describe("CRM route authorization", () => {
  beforeEach(() => {
    auth.role = "administrator";
    auth.isLoading = false;
  });

  it.each<UserRole>([
    "system_owner",
    "administrator",
    "project_manager",
    "sales",
  ])("renders CRM for %s", (role) => {
    auth.role = role;
    render(<Page />);

    expect(screen.getByText("crm-workspace")).toBeTruthy();
  });

  it.each<UserRole>(["accountant", "employee"])(
    "does not mount CRM for %s",
    (role) => {
      auth.role = role;
      render(<Page />);

      expect(screen.queryByText("crm-workspace")).toBeNull();
      expect(screen.getByRole("alert").textContent).toContain(
        "لا يملك هذا الحساب صلاحية"
      );
    }
  );
});
