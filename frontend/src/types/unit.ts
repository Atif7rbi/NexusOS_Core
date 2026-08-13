import type { PaginatedData } from "@/types/pagination";

export type UnitType =
  | "apartment"
  | "villa"
  | "office"
  | "shop"
  | "land"
  | "other";

export type UnitStatus = "available" | "reserved" | "sold";

export type UnitProject = {
  id: string;
  project_number: string;
  name: string;
  currency: string;
  project_manager_id: number | null;
};

export type Unit = {
  id: string;
  tenant_id: string;
  project_id: string;
  project?: UnitProject;
  unit_number: string;
  unit_type: UnitType;
  status: UnitStatus;
  selling_price: string;
  area: string | null;
  floor: number | null;
  bedrooms: number | null;
  bathrooms: number | null;
  notes: string | null;
  archived_at: string | null;
  created_at: string;
  updated_at: string;
};

export type UnitFormPayload = {
  project_id: string;
  unit_number: string;
  unit_type: UnitType;
  selling_price: number;
  area?: number | null;
  floor?: number | null;
  bedrooms?: number | null;
  bathrooms?: number | null;
  notes?: string | null;
};

export type UnitPagination = PaginatedData<Unit>;

export type UnitSummary = {
  total: number;
  available: number;
  reserved: number;
  sold: number;
};

export type UnitsResponse = {
  data: {
    units: UnitPagination;
    summary: UnitSummary;
  };
};

export type UnitResponse = {
  message?: string;
  data: {
    unit: Unit;
  };
};
