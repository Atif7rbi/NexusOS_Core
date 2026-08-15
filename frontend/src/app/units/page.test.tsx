import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import UnitsPage from "@/app/units/page";
import { ApiRequestError } from "@/lib/api-error";

const services = vi.hoisted(() => ({
  fetchUnits: vi.fn(),
  fetchProjects: vi.fn(),
  routerReplace: vi.fn((url: string) => {
    window.history.replaceState(null, "", url);
  }),
}));

const auth = vi.hoisted(() => ({
  user: { id: 1, role: "administrator" },
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: services.routerReplace }),
  useSearchParams: () =>
    new URLSearchParams(window.location.search),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    token: "token",
    user: auth.user,
    effectiveModules: ["units"],
    isLoading: false,
  }),
}));

vi.mock("@/hooks/useResourceInvalidation", () => ({
  useResourceInvalidation: () => undefined,
}));

vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock("@/components/units/UnitFormModal", () => ({
  UnitFormModal: ({ onClose }: { onClose: () => void }) => (
    <div>
      <span>unit-create-modal</span>
      <button type="button" onClick={onClose}>
        close-unit-create
      </button>
    </div>
  ),
}));

vi.mock("@/components/units/UnitDetailsModal", () => ({
  UnitDetailsModal: () => null,
}));

vi.mock("@/components/ui/ConfirmationDialog", () => ({
  ConfirmationDialog: () => null,
}));

vi.mock("@/services/projects", () => ({
  fetchProjects: services.fetchProjects,
}));

vi.mock("@/services/units", () => ({
  fetchUnits: services.fetchUnits,
  fetchUnit: vi.fn(),
  createUnit: vi.fn(),
  updateUnit: vi.fn(),
  archiveUnit: vi.fn(),
  restoreUnit: vi.fn(),
}));

describe("UnitsPage quick create", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(null, "", "/units/");
    services.fetchUnits.mockResolvedValue({
      data: {
        units: {
          data: [],
          current_page: 1,
          last_page: 1,
          total: 0,
        },
        summary: {
          total: 0,
          available: 0,
          reserved: 0,
          sold: 0,
        },
      },
    });
    services.fetchProjects.mockResolvedValue({
      data: { data: [] },
    });
    auth.user = { id: 1, role: "administrator" };
  });

  it("opens from create=1 and preserves other query params on close", async () => {
    window.history.replaceState(
      null,
      "",
      "/units/?project=project-1&create=1"
    );

    render(<UnitsPage />);

    expect(
      await screen.findByText("unit-create-modal")
    ).toBeTruthy();

    fireEvent.click(screen.getByText("close-unit-create"));

    expect(window.location.search).toBe("?project=project-1");
  });

  it("does not expose the create flow to read-only roles", async () => {
    auth.user = { id: 2, role: "accountant" };
    window.history.replaceState(null, "", "/units/?create=1");

    render(<UnitsPage />);

    await waitFor(() => {
      expect(window.location.search).toBe("");
    });
    expect(screen.queryByText("unit-create-modal")).toBeNull();
  });

  it("shows a normalized list fetch failure with retry", async () => {
    services.fetchUnits.mockRejectedValueOnce(
      new ApiRequestError(
        "تعذر تحميل بيانات الوحدات حاليًا.",
        {},
        500,
        "internal_error"
      )
    );

    render(<UnitsPage />);

    expect(
      await screen.findByText("تعذر تحميل بيانات الوحدات حاليًا.")
    ).toBeTruthy();
    expect(
      screen.getByRole("button", { name: "إعادة المحاولة" })
    ).toBeTruthy();
  });
});
