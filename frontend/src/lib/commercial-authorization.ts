import { canAdministrateTenant } from "@/lib/tenant-administrator";
import type { AuthUser, UserRole } from "@/types/auth";
import type { Contract } from "@/types/contract";
import type { Customer } from "@/types/customer";
import type { Reservation } from "@/types/reservation";

function sameId(left: number | string | null | undefined, right: number | string | null | undefined): boolean {
  return left !== null && left !== undefined && String(left) === String(right);
}

export function canCreateReservation(role: UserRole | null | undefined): boolean {
  return canAdministrateTenant(role) || role === "project_manager" || role === "sales";
}

export function canCreateContract(role: UserRole | null | undefined): boolean {
  return canCreateReservation(role);
}

export function canEditOrCancelReservation(user: AuthUser | null | undefined, reservation: Reservation): boolean {
  if (reservation.status !== "active" || !user) return false;
  return canAdministrateTenant(user.role) ||
    (user.role === "sales" && sameId(reservation.created_by, user.id)) ||
    (user.role === "project_manager" && sameId(reservation.unit?.project?.project_manager_id, user.id));
}

function canOperateContract(user: AuthUser | null | undefined, contract: Contract): boolean {
  if (!user) return false;
  return canAdministrateTenant(user.role) ||
    (user.role === "sales" && sameId(contract.reservation?.created_by, user.id)) ||
    (user.role === "project_manager" && sameId(contract.reservation?.unit?.project?.project_manager_id, user.id));
}

export function canEditOrActivateContract(user: AuthUser | null | undefined, contract: Contract): boolean {
  return contract.status === "draft" && canOperateContract(user, contract);
}

export function canCancelContract(user: AuthUser | null | undefined, contract: Contract): boolean {
  return contract.status === "draft"
    ? canOperateContract(user, contract)
    : contract.status === "active" && canAdministrateTenant(user?.role);
}

export function canCompleteContract(user: AuthUser | null | undefined, contract: Contract): boolean {
  return contract.status === "active" && canAdministrateTenant(user?.role);
}

export function canCreateCustomer(role: UserRole | null | undefined): boolean {
  return canAdministrateTenant(role) || role === "sales" || role === "project_manager";
}

export function canEditOrArchiveCustomer(user: AuthUser | null | undefined, customer: Customer): boolean {
  return canAdministrateTenant(user?.role) ||
    (user?.role === "sales" && sameId(customer.created_by, user.id));
}

export function canRestoreCustomer(role: UserRole | null | undefined): boolean {
  return canAdministrateTenant(role);
}
