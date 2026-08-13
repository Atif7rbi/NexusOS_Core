import { describe, expect, it } from "vitest";

import {
  canArchiveOrRestoreProject,
  canCreateProject,
  canRunProjectLifecycleAction,
  canWriteProject,
  canWriteUnit,
} from "@/lib/project-authorization";
import type { AuthUser } from "@/types/auth";
import type { Project } from "@/types/project";
import type { Unit } from "@/types/unit";

const manager: AuthUser = {
  id: 10,
  name: "Project Manager",
  email: "manager@example.test",
  role: "project_manager",
  status: "active",
  phone: null,
  email_verified_at: null,
  last_login_at: null,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

const project = {
  id: "project-1",
  project_manager_id: 10,
} as Project;

const unit = {
  id: "unit-1",
  project: {
    id: "project-1",
    project_number: "PRJ-2026-001",
    name: "مشروع الاختبار",
    currency: "SAR",
    project_manager_id: 10,
  },
} as Unit;

describe("project authorization UX mirror", () => {
  it("allows only owner, administrator, and project manager to create projects", () => {
    expect(canCreateProject("system_owner")).toBe(true);
    expect(canCreateProject("administrator")).toBe(true);
    expect(canCreateProject("project_manager")).toBe(true);
    expect(canCreateProject("sales")).toBe(false);
  });

  it("limits project manager writes to assigned projects", () => {
    expect(canWriteProject(manager, project)).toBe(true);
    expect(canWriteProject(manager, { ...project, project_manager_id: 22 })).toBe(false);
  });

  it("keeps cancellation and archive authority administrative", () => {
    expect(canRunProjectLifecycleAction(manager, project, "complete")).toBe(true);
    expect(canRunProjectLifecycleAction(manager, project, "cancel")).toBe(false);
    expect(canArchiveOrRestoreProject("project_manager")).toBe(false);
  });

  it("limits unit actions to the project manager who owns the unit project", () => {
    expect(canWriteUnit(manager, unit)).toBe(true);
    expect(
      canWriteUnit(manager, {
        ...unit,
        project: { ...unit.project!, project_manager_id: 22 },
      })
    ).toBe(false);
  });
});
