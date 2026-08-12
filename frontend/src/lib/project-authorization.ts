import type { AuthUser, UserRole } from "@/types/auth";
import type { Project } from "@/types/project";
import type { Unit } from "@/types/unit";
import { canAdministrateTenant } from "@/lib/tenant-administrator";

export function canCreateProject(
  role: UserRole | null | undefined
): boolean {
  return canAdministrateTenant(role) || role === "project_manager";
}

export function canWriteProject(
  user: AuthUser | null | undefined,
  project: Project
): boolean {
  return canAdministrateTenant(user?.role) || isOwnProject(user, project);
}

export function canManageProjectManager(
  role: UserRole | null | undefined
): boolean {
  return canAdministrateTenant(role);
}

export function canRunProjectLifecycleAction(
  user: AuthUser | null | undefined,
  project: Project,
  action: "activate" | "revert-to-draft" | "complete" | "cancel"
): boolean {
  if (action === "cancel") {
    return canAdministrateTenant(user?.role);
  }

  return canWriteProject(user, project);
}

export function canArchiveOrRestoreProject(
  role: UserRole | null | undefined
): boolean {
  return canAdministrateTenant(role);
}

export function canCreateUnitForProject(
  user: AuthUser | null | undefined,
  project: Project
): boolean {
  return canWriteProject(user, project);
}

export function canWriteUnit(
  user: AuthUser | null | undefined,
  unit: Unit
): boolean {
  if (canAdministrateTenant(user?.role)) {
    return true;
  }

  return user?.role === "project_manager" &&
    unit.project?.project_manager_id === user.id;
}

function isOwnProject(
  user: AuthUser | null | undefined,
  project: Project
): boolean {
  return user?.role === "project_manager" &&
    project.project_manager_id === user.id;
}
