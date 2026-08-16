import type { CommercialModuleKey } from "@/types/entitlement";

export type UserRole =
  | "system_owner"
  | "administrator"
  | "project_manager"
  | "sales"
  | "accountant"
  | "employee";

export type UserStatus =
  | "active"
  | "suspended"
  | "archived";

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  status: UserStatus;
  phone: string | null;
  email_verified_at: string | null;
  last_login_at: string | null;
  created_at: string;
  updated_at: string;
};

export type LoginPayload = {
  email: string;
  password: string;
};

export type LoginResponse = {
  message: string;
  data: {
    token: string;
    user: AuthUser;
    effective_modules: CommercialModuleKey[];
  };
};

export type CurrentUserResponse = {
  data: {
    user: AuthUser;
    effective_modules: CommercialModuleKey[];
  };
};
