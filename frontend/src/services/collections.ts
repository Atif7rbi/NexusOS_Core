import {
  createQueryString,
  requestJson,
} from "@/lib/http";
import type {
  AmendPayload,
  CollectionCommandResponse,
  CollectionsIndexQuery,
  CollectionsIndexResponse,
  CollectionScheduleQuery,
  CollectionScheduleResource,
  GetCollectionScheduleResponse,
  SaveDraftPayload,
} from "@/types/collection";

export async function fetchCollectionsIndex(
  token: string,
  query: CollectionsIndexQuery
): Promise<CollectionsIndexResponse> {
  const queryString = createQueryString(query);
  const path = queryString
    ? `/collections?${queryString}`
    : "/collections";

  return requestJson<CollectionsIndexResponse>(path, { token });
}

function schedulePath(contractId: string): string {
  return `/contracts/${contractId}/collection-schedule`;
}

export async function fetchCollectionSchedule(
  token: string,
  contractId: string,
  query: CollectionScheduleQuery = {}
): Promise<CollectionScheduleResource> {
  const queryString = createQueryString(query);
  const path = queryString
    ? `${schedulePath(contractId)}?${queryString}`
    : schedulePath(contractId);
  const response = await requestJson<GetCollectionScheduleResponse>(
    path,
    { token }
  );

  return response.data;
}

export async function saveDraftCollectionSchedule(
  token: string,
  contractId: string,
  payload: SaveDraftPayload
): Promise<CollectionScheduleResource> {
  const response = await requestJson<CollectionCommandResponse>(
    `${schedulePath(contractId)}/draft`,
    {
      token,
      method: "POST",
      body: payload,
    }
  );

  return response.data;
}

export async function finalizeCollectionSchedule(
  token: string,
  contractId: string
): Promise<CollectionScheduleResource> {
  const response = await requestJson<CollectionCommandResponse>(
    `${schedulePath(contractId)}/finalize`,
    {
      token,
      method: "POST",
      body: {},
    }
  );

  return response.data;
}

export async function amendCollectionSchedule(
  token: string,
  contractId: string,
  payload: AmendPayload
): Promise<CollectionScheduleResource> {
  const response = await requestJson<CollectionCommandResponse>(
    `${schedulePath(contractId)}/amend`,
    {
      token,
      method: "POST",
      body: payload,
    }
  );

  return response.data;
}
