import {
  createQueryString,
  requestJson,
} from "@/lib/http";
import type { ContractStatus } from "@/types/contract";

export type CollectionStatus =
  | "draft"
  | "scheduled"
  | "cancelled";

export type CollectionScheduleState =
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
  status: "draft" | "scheduled";
  scheduled_at: string | null;
  scheduled_by: CollectionActor | null;
};

export type CancelledCollection = {
  id: string;
  sequence: number;
  title: string;
  amount: string;
  due_date: string;
  notes: string | null;
  status: "cancelled";
  scheduled_at: string | null;
  scheduled_by: CollectionActor | null;
  cancelled_at: string;
  cancelled_by: CollectionActor;
  cancellation_reason: string;
};

export type CollectionScheduleResource = {
  contract: {
    id: string;
    status: ContractStatus;
    currency: string;
    total_amount: string;
  };
  schedule: {
    derived_state: CollectionScheduleState;
    active_total: string;
    active_collections: ActiveCollection[];
    cancelled_history?: CancelledCollection[];
  };
  allowed_actions: {
    can_save_draft: boolean;
    can_finalize: boolean;
    can_amend: boolean;
  };
};

export type CollectionDraftLinePayload = {
  id?: string | null;
  sequence: number;
  title: string;
  amount: string;
  due_date: string;
  notes?: string | null;
};

export type SaveDraftCollectionSchedulePayload = {
  lines: CollectionDraftLinePayload[];
};

export type CollectionAmendmentLinePayload = {
  sequence: number;
  title: string;
  amount: string;
  due_date: string;
  notes?: string | null;
};

export type AmendCollectionSchedulePayload = {
  expected_active_collection_ids: string[];
  lines: CollectionAmendmentLinePayload[];
  cancellation_reason: string;
};

export type CollectionScheduleResponse = {
  data: CollectionScheduleResource;
};

export type CollectionScheduleCommandResponse = {
  message: string;
  data: CollectionScheduleResource;
};

export type CollectionScheduleQuery = {
  include_history?: boolean;
};

function schedulePath(contractId: string): string {
  return `/contracts/${contractId}/collection-schedule`;
}

export async function fetchCollectionSchedule(
  token: string,
  contractId: string,
  query: CollectionScheduleQuery = {}
): Promise<CollectionScheduleResponse> {
  const queryString = createQueryString(query);
  const path = queryString
    ? `${schedulePath(contractId)}?${queryString}`
    : schedulePath(contractId);

  return requestJson<CollectionScheduleResponse>(path, { token });
}

export async function saveDraftCollectionSchedule(
  token: string,
  contractId: string,
  payload: SaveDraftCollectionSchedulePayload
): Promise<CollectionScheduleCommandResponse> {
  return requestJson<CollectionScheduleCommandResponse>(
    `${schedulePath(contractId)}/draft`,
    {
      token,
      method: "POST",
      body: payload,
    }
  );
}

export async function finalizeCollectionSchedule(
  token: string,
  contractId: string
): Promise<CollectionScheduleCommandResponse> {
  return requestJson<CollectionScheduleCommandResponse>(
    `${schedulePath(contractId)}/finalize`,
    {
      token,
      method: "POST",
    }
  );
}

export async function amendCollectionSchedule(
  token: string,
  contractId: string,
  payload: AmendCollectionSchedulePayload
): Promise<CollectionScheduleCommandResponse> {
  return requestJson<CollectionScheduleCommandResponse>(
    `${schedulePath(contractId)}/amend`,
    {
      token,
      method: "POST",
      body: payload,
    }
  );
}
