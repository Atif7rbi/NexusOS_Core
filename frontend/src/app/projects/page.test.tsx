import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import ProjectsPage from "@/app/projects/page";
import type { ProjectFormPayload } from "@/types/project";

const services = vi.hoisted(() => ({
  fetchProjects: vi.fn(),
  createProject: vi.fn(),
  fetchTenantUsers: vi.fn(),
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
  }),
}));

vi.mock("@/hooks/useTranslation", () => ({
  useTranslation: () => ({ isArabic: true }),
}));

vi.mock("@/hooks/useResourceInvalidation", () => ({
  useResourceInvalidation: () => undefined,
}));

vi.mock("@/components/layout/AppShell", () => ({
  AppShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock("@/components/projects/DeleteProjectDialog", () => ({
  ArchiveProjectDialog: () => null,
}));

vi.mock("@/components/projects/ProjectFormModal", () => ({
  ProjectFormModal: ({
    onClose,
    onSubmit,
  }: {
    onClose: () => void;
    onSubmit: (payload: ProjectFormPayload) => Promise<void>;
  }) => (
    <div>
      <span>project-create-modal</span>
      <button type="button" onClick={onClose}>
        close-project-create
      </button>
      <button
        type="button"
        onClick={() =>
          void onSubmit({
            name: "مشروع جديد",
            project_type: "residential",
            city: "الرياض",
          })
        }
      >
        submit-project-create
      </button>
    </div>
  ),
}));

vi.mock("@/services/projects", () => ({
  fetchProjects: services.fetchProjects,
  createProject: services.createProject,
  updateProject: vi.fn(),
  archiveProject: vi.fn(),
  restoreProject: vi.fn(),
  executeProjectLifecycleAction: vi.fn(),
}));

vi.mock("@/services/users", () => ({
  fetchTenantUsers: services.fetchTenantUsers,
}));

const projectsResponse = {
  data: {
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
  },
};

describe("ProjectsPage quick create", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(null, "", "/projects/");
    services.fetchProjects.mockResolvedValue(projectsResponse);
    services.fetchTenantUsers.mockResolvedValue({
      data: { users: { data: [] } },
    });
    services.createProject.mockResolvedValue({});
    auth.user = { id: 1, role: "administrator" };
  });

  it("keeps the regular projects route out of create mode", async () => {
    render(<ProjectsPage />);

    await waitFor(() => {
      expect(services.fetchProjects).toHaveBeenCalled();
    });

    expect(screen.queryByText("project-create-modal")).toBeNull();
  });

  it("does not reopen create after cancel and returning to projects", async () => {
    window.history.replaceState(null, "", "/projects/?create=1");

    const firstVisit = render(<ProjectsPage />);

    fireEvent.click(
      await screen.findByText("close-project-create")
    );

    await waitFor(() => {
      expect(window.location.pathname).toBe("/projects/");
      expect(window.location.search).toBe("");
      expect(screen.queryByText("project-create-modal")).toBeNull();
    });

    firstVisit.unmount();
    window.history.replaceState(null, "", "/");
    window.history.replaceState(null, "", "/projects/");

    render(<ProjectsPage />);

    await waitFor(() => {
      expect(services.fetchProjects).toHaveBeenCalled();
    });
    expect(screen.queryByText("project-create-modal")).toBeNull();
  });

  it("opens from create=1 and clears only create after success", async () => {
    window.history.replaceState(
      null,
      "",
      "/projects/?status=active&create=1&page=2"
    );

    render(<ProjectsPage />);

    expect(
      await screen.findByText("project-create-modal")
    ).toBeTruthy();

    fireEvent.click(screen.getByText("submit-project-create"));

    await waitFor(() => {
      expect(services.createProject).toHaveBeenCalledTimes(1);
      expect(window.location.search).toBe("?status=active&page=2");
    });
  });

  it("does not expose the create flow to read-only roles", async () => {
    auth.user = { id: 2, role: "sales" };
    window.history.replaceState(null, "", "/projects/?create=1");

    render(<ProjectsPage />);

    await waitFor(() => {
      expect(window.location.search).toBe("");
    });
    expect(screen.queryByText("project-create-modal")).toBeNull();
  });
});
