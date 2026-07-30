export type ContractStatus =
  | "draft"
  | "active"
  | "completed"
  | "cancelled";

export type ContractUnit = {
  id: string;
  project_id: string;
  unit_number: string;
  status: string;
};

export type ContractCustomer = {
  id: string;
  name: string;
  status: string;
};

export type ContractReservation = {
  id: string;
  status: string;
  unit: ContractUnit | null;
  customer: ContractCustomer | null;
};

export type Contract = {
  id: string;
  tenant_id: string;
  reservation_id: string;
  status: ContractStatus;
  total_amount: string;
  activated_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  created_by: number | string | null;
  updated_by: number | string | null;
  created_at: string;
  updated_at: string;
  reservation?: ContractReservation;
};

export type ContractSummary = {
  total: number;
  draft: number;
  active: number;
  completed: number;
  cancelled: number;
};

export type ContractFormPayload = {
  reservation_id: string;
  total_amount: string;
};

export type ContractUpdatePayload = {
  total_amount: string;
};

export type ContractPagination = {
  data: Contract[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export type ContractsResponse = {
  data: {
    contracts: ContractPagination;
    summary: ContractSummary;
  };
};

export type ContractResponse = {
  message?: string;
  data: {
    contract: Contract;
  };
};

export type ContractLifecycleAction =
  | "activate"
  | "complete"
  | "cancel";
