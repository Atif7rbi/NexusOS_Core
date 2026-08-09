import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import UnitsPage from "@/app/units/page";

const services = vi.hoisted(() => ({
  fetchUnits: vi.fn(),
  fetchProjects: vi.fn(),
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () =>
    new URLSearchParams(window.location.search),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({ token: "token" }),
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
});
