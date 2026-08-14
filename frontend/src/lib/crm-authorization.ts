import type { UserRole } from "@/types/auth";

export const crmAccessRoles: UserRole[] = [
  "system_owner",
  "administrator",
  "project_manager",
  "sales",
];

export function canAccessCrm(
  role: UserRole | null | undefined
): boolean {
  return role !== null && role !== undefined && crmAccessRoles.includes(role);
}
