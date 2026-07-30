import type { Customer } from "@/types/customer";
import type { Unit } from "@/types/unit";

export type ReservationStatus =
  | "active"
  | "converted"
  | "cancelled"
  | "expired";

export type Reservation = {
  id: string;
  tenant_id: string;
  unit_id: string;
  customer_id: string;
  status: ReservationStatus;
  reserved_at: string;
  expires_at: string;
  notes: string | null;
  cancellation_reason: string | null;
  cancelled_at: string | null;
  cancelled_by: number | string | null;
  created_by: number | string | null;
  updated_by: number | string | null;
  created_at: string;
  updated_at: string;
  unit?: Unit;
  customer?: Customer;
};

export type ReservationPagination = {
  data: Reservation[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type ReservationSummary = {
  total: number;
  active: number;
  cancelled: number;
  expired: number;
};

export type AvailableReservationUnit = {
  id: string;
  unit_number: string;
  project_name: string;
};

export type ReservationFormPayload = {
  unit_id: string;
  customer_id: string;
  expires_at?: string;
  notes?: string | null;
};

export type ReservationUpdatePayload = {
  expires_at?: string;
  notes?: string | null;
};

export type ReservationCancellationPayload = {
  cancellation_reason?: string | null;
};

export type ReservationsResponse = {
  data: {
    reservations: ReservationPagination;
    summary: ReservationSummary;
  };
};

export type AvailableReservationUnitsResponse = {
  data: {
    units: AvailableReservationUnit[];
  };
};

export type ReservationResponse = {
  message?: string;
  data: {
    reservation: Reservation;
  };
};
