import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import UsersPage from "@/app/users/page";
import type { UserRole } from "@/types/auth";

const auth = vi.hoisted(() => ({
  role: "administrator" as UserRole,
}));
const services = vi.hoisted(() => ({
  fetchTenantUsers: vi.fn(),
  createTenantUser: vi.fn(),
  updateTenantUser: vi.fn(),
  removeTenantUser: vi.fn(),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    token: "token",
    user: { id: 1, role: auth.role },
  }),
}));
vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));
vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/users/UsersOverview", () => ({
  UsersOverview: () => <div>users-overview</div>,
}));
vi.mock("@/components/users/UserFormModal", () => ({
  UserFormModal: () => null,
}));
vi.mock("@/components/users/DeleteUserDialog", () => ({
  DeleteUserDialog: () => null,
}));
vi.mock("@/services/users", () => services);

describe("UsersPage authorization", () => {
  beforeEach(() => {
    auth.role = "administrator";
    services.fetchTenantUsers.mockResolvedValue({
      data: {
        users: { data: [] },
        summary: { total: 0, active: 0, paused: 0, limit: 3 },
      },
    });
  });

  it.each<UserRole>(["system_owner", "administrator"])(
    "loads user management for %s",
    async (role) => {
      auth.role = role;
      render(<UsersPage />);

      await waitFor(() => {
        expect(services.fetchTenantUsers).toHaveBeenCalledWith("token", {
          page: 1,
          perPage: 100,
        });
      });
      expect(screen.getByText("إضافة مستخدم")).toBeTruthy();
    }
  );

  it.each<UserRole>([
    "project_manager",
    "sales",
    "accountant",
    "employee",
  ])("blocks user management for %s without loading tenant users", (role) => {
    auth.role = role;
    render(<UsersPage />);

    expect(screen.getByRole("alert").textContent).toContain(
      "إدارة المستخدمين غير متاحة"
    );
    expect(screen.queryByText("إضافة مستخدم")).toBeNull();
    expect(services.fetchTenantUsers).not.toHaveBeenCalled();
  });
});
