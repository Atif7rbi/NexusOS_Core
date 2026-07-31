import type { ContractStatus } from "@/types/contract";

export type { ContractStatus };

export type CollectionStatus =
  | "draft"
  | "scheduled"
  | "cancelled";

export type DerivedScheduleState =
  | "absent"
  | "draft"
  | "scheduled"
  | "cancelled";

export type CollectionActor = {
  id: number;
  name: string;
};

export type ActiveCollection = {
  id: string;
  sequence: number;
  title: string;
  amount: string;
  due_date: string;
  notes: string | null;
  status: CollectionStatus;
  scheduled_at: string | null;
  scheduled_by: CollectionActor | null;
};

export type CancelledCollection = ActiveCollection & {
  cancelled_at: string;
  cancelled_by: CollectionActor;
  cancellation_reason: string;
};

export type CollectionAllowedActions = {
  can_save_draft: boolean;
  can_finalize: boolean;
  can_amend: boolean;
};

export type CollectionSchedule = {
  derived_state: DerivedScheduleState;
  active_total: string;
  active_collections: ActiveCollection[];
  cancelled_history?: CancelledCollection[];
};

export type CollectionContractInfo = {
  id: string;
  status: string;
  currency: string;
  total_amount: string;
};

export type CollectionScheduleResource = {
  contract: CollectionContractInfo;
  schedule: CollectionSchedule;
  allowed_actions: CollectionAllowedActions;
};

export type GetCollectionScheduleResponse = {
  data: CollectionScheduleResource;
};

export type CollectionCommandResponse = {
  message: string;
  data: CollectionScheduleResource;
};

export type CollectionLineInput = {
  _key: string;
  id: string | null;
  sequence: number;
  title: string;
  amount: string;
  due_date: string;
  notes: string;
};

export type CollectionDraftLinePayload = {
  id?: string;
  sequence: number;
  title: string;
  amount: string;
  due_date: string;
  notes: string | null;
};

export type SaveDraftPayload = {
  lines: CollectionDraftLinePayload[];
};

export type FinalizePayload = Record<string, never>;

export type CollectionAmendmentLinePayload = {
  sequence: number;
  title: string;
  amount: string;
  due_date: string;
  notes: string | null;
};

export type AmendPayload = {
  expected_active_collection_ids: string[];
  lines: CollectionAmendmentLinePayload[];
  cancellation_reason: string;
};

export type CollectionScheduleQuery = {
  include_history?: boolean;
};

export type CollectionsIndexItem = {
  contract_id: string;
  contract_status: ContractStatus;
  contract_total_amount: string;
  currency: string;
  customer_name: string | null;
  unit_number: string | null;
  project_name: string | null;
  schedule_state: DerivedScheduleState;
  schedule_active_total: string;
};

export type CollectionsIndexPagination = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type CollectionsIndexSummary = {
  total_contracts: number;
  scheduled_count: number;
  draft_count: number;
  absent_count: number;
  cancelled_count: number;
};

export type CollectionsIndexResponse = {
  data: {
    items: CollectionsIndexItem[];
    pagination: CollectionsIndexPagination;
    summary: CollectionsIndexSummary;
  };
};

export type CollectionsIndexQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: ContractStatus | "";
  schedule_state?: DerivedScheduleState | "";
};
