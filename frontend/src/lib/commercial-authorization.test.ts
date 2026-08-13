import { describe, expect, it } from "vitest";

import {
  canCancelContract,
  canCompleteContract,
  canCreateContract,
  canCreateCustomer,
  canCreateReservation,
  canEditOrActivateContract,
  canEditOrArchiveCustomer,
  canEditOrCancelReservation,
  canRestoreCustomer,
} from "@/lib/commercial-authorization";
import type { AuthUser } from "@/types/auth";
import type { Contract } from "@/types/contract";
import type { Customer } from "@/types/customer";
import type { Reservation } from "@/types/reservation";

const user = (role: AuthUser["role"], id = 1): AuthUser => ({ id, role, name: "User", email: "user@example.test", status: "active", phone: null, email_verified_at: null, last_login_at: null, created_at: "", updated_at: "" });
const reservation = (creator = 1, manager = 2): Reservation => ({ id: "r", tenant_id: "t", unit_id: "u", customer_id: "c", status: "active", reserved_at: "", expires_at: "", notes: null, cancellation_reason: null, cancelled_at: null, cancelled_by: null, created_by: creator, updated_by: creator, created_at: "", updated_at: "", unit: { id: "u", tenant_id: "t", project_id: "p", unit_number: "1", unit_type: "apartment", status: "reserved", selling_price: "1", area: null, floor: null, bedrooms: null, bathrooms: null, notes: null, archived_at: null, created_at: "", updated_at: "", project: { id: "p", project_number: "P", name: "Project", currency: "SAR", project_manager_id: manager } } });
const customer = (creator = 1): Customer => ({ id: "c", type: "individual", category: "buyer", status: "customer", name: "Customer", phone: "0500000000", email: null, national_id: null, commercial_registration_number: null, city: null, address: null, notes: null, archived_at: null, created_by: creator, created_at: "", updated_at: "" });
const contract = (creator = 1, manager = 2, status: Contract["status"] = "draft"): Contract => ({ id: "ct", tenant_id: "t", reservation_id: "r", status, total_amount: "1", activated_at: null, completed_at: null, cancelled_at: null, created_by: creator, updated_by: creator, created_at: "", updated_at: "", reservation: { id: "r", status: "active", created_by: creator, unit: { id: "u", project_id: "p", unit_number: "1", status: "reserved", project: { id: "p", project_manager_id: manager } }, customer: null } });

describe("commercial authorization UX mirrors", () => {
  it("keeps reservation write actions scoped to admin, owner, own PM project, or own sales record", () => {
    expect(canCreateReservation("sales")).toBe(true);
    expect(canCreateReservation("accountant")).toBe(false);
    expect(canEditOrCancelReservation(user("project_manager", 2), reservation(1, 2))).toBe(true);
    expect(canEditOrCancelReservation(user("project_manager", 3), reservation(1, 2))).toBe(false);
    expect(canEditOrCancelReservation(user("sales", 1), reservation(1))).toBe(true);
    expect(canEditOrCancelReservation(user("sales", 2), reservation(1))).toBe(false);
    expect(canEditOrCancelReservation(user("employee"), reservation())).toBe(false);
  });

  it("keeps terminal contract actions administrative while draft operations honor the journey", () => {
    expect(canCreateContract("sales")).toBe(true);
    expect(canCreateContract("accountant")).toBe(false);
    expect(canEditOrActivateContract(user("sales", 1), contract(1))).toBe(true);
    expect(canEditOrActivateContract(user("sales", 2), contract(1))).toBe(false);
    expect(canEditOrActivateContract(user("project_manager", 2), contract(1, 2))).toBe(true);
    expect(canCompleteContract(user("project_manager", 2), contract(1, 2, "active"))).toBe(false);
    expect(canCancelContract(user("sales", 1), contract(1, 2, "active"))).toBe(false);
    expect(canCancelContract(user("system_owner"), contract(1, 2, "active"))).toBe(true);
  });

  it("keeps customer create, write, and restore contracts distinct", () => {
    expect(canCreateCustomer("project_manager")).toBe(true);
    expect(canCreateCustomer("employee")).toBe(false);
    expect(canEditOrArchiveCustomer(user("sales", 1), customer(1))).toBe(true);
    expect(canEditOrArchiveCustomer(user("sales", 2), customer(1))).toBe(false);
    expect(canEditOrArchiveCustomer(user("project_manager", 1), customer(1))).toBe(false);
    expect(canRestoreCustomer("administrator")).toBe(true);
    expect(canRestoreCustomer("sales")).toBe(false);
  });
});
