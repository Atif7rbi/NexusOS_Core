import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { Sidebar } from "@/components/layout/Sidebar";

const shellState = vi.hoisted(() => ({
  collapsed: false,
}));

vi.mock("next/navigation", () => ({
  usePathname: () => "/",
}));

vi.mock("@/components/brand/NexusBrand", () => ({
  NexusBrand: ({
    compact,
    inverse,
  }: {
    compact?: boolean;
    inverse?: boolean;
  }) => (
    <div
      data-testid="nexus-brand"
      data-compact={String(Boolean(compact))}
      data-inverse={String(Boolean(inverse))}
    >
      NexusOS
    </div>
  ),
}));

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({
    isArabic: true,
    t: (key: string) => key,
  }),
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => ({
    user: { role: "administrator" },
  }),
}));

vi.mock("@/providers/AppShellProvider", () => ({
  useAppShell: () => ({
    isSidebarCollapsed: shellState.collapsed,
    isMobileSidebarOpen: false,
    toggleSidebar: vi.fn(),
    closeMobileSidebar: vi.fn(),
  }),
}));

describe("Sidebar brand identity", () => {
  beforeEach(() => {
    shellState.collapsed = false;
  });

  it("uses the sidebar semantic surface and inverse NexusOS identity", () => {
    const { container } = render(<Sidebar />);
    const sidebar = container.querySelector("aside");

    expect(sidebar?.className).toContain("bg-[var(--sidebar-bg)]");
    expect(sidebar?.className).toContain("border-[var(--sidebar-border)]");
    expect(
      screen.getByTestId("nexus-brand").getAttribute("data-inverse")
    ).toBe("true");
    expect(
      screen.getByTestId("nexus-brand").getAttribute("data-compact")
    ).toBe("false");
    expect(container.innerHTML).toContain("--sidebar-active-indicator");
  });

  it("retains the NexusOS mark when the sidebar is collapsed", () => {
    shellState.collapsed = true;
    const { container } = render(<Sidebar />);
    const sidebar = container.querySelector("aside");

    expect(sidebar?.className).toContain("w-[82px]");
    expect(
      screen.getByTestId("nexus-brand").getAttribute("data-compact")
    ).toBe("true");
    expect(
      screen.getByTestId("nexus-brand").getAttribute("data-inverse")
    ).toBe("true");
  });
});
