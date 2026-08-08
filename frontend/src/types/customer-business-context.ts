import type { DerivedScheduleState } from "@/types/collection";
import type { ContractStatus } from "@/types/contract";
import type { Customer } from "@/types/customer";
import type { ProjectStatus } from "@/types/project";
import type { ReservationStatus } from "@/types/reservation";
import type {
  UnitStatus,
  UnitType,
} from "@/types/unit";

export type CustomerContextProject = {
  id: string;
  project_number: string;
  name: string;
  status: ProjectStatus;
};

export type CustomerContextUnit = {
  id: string;
  unit_number: string;
  unit_type: UnitType;
  status: UnitStatus;
};

export type NextScheduledCollection = {
  id: string;
  title: string;
  amount: string;
  due_date: string;
};

export type CustomerCollectionSchedule = {
  state: DerivedScheduleState;
  scheduled_total: string;
  scheduled_lines_count: number;
  next_scheduled_collection:
    | NextScheduledCollection
    | null;
};

export type CustomerContextContract = {
  id: string;
  status: ContractStatus;
  total_amount: string;
  currency: string;
  activated_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  collection_schedule: CustomerCollectionSchedule;
};

export type CustomerContextReservation = {
  id: string;
  status: ReservationStatus;
  reserved_at: string;
  expires_at: string;
};

export type CustomerBusinessJourney = {
  reservation: CustomerContextReservation;
  project: CustomerContextProject | null;
  unit: CustomerContextUnit | null;
  contracts: CustomerContextContract[];
};

export type CustomerBusinessContext = {
  summary: {
    reservations_total: number;
    contracts_total: number;
  };
  journeys: CustomerBusinessJourney[];
};

export type CustomerRecord = {
  customer: Customer;
  business_context: CustomerBusinessContext;
};

export type CustomerRecordResponse = {
  data: CustomerRecord;
};
