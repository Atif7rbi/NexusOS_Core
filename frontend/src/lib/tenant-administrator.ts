import type { UserRole } from "@/types/auth";

export function canAdministrateTenant(
  role: UserRole | null | undefined
): boolean {
  return role === "system_owner" || role === "administrator";
}
