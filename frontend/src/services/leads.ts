import {
  createAuthHeaders,
  createQueryString,
  getApiBaseUrl,
  requestJson,
} from "@/lib/http";
import { ApiRequestError, parseApiError } from "@/lib/api-error";
import type {
  ConvertLeadPayload,
  CreateLeadPayload,
  Lead,
  LeadActivitiesResponse,
  LeadActivityResponse,
  LeadArchiveReason,
  LeadConversionConflict,
  LeadDuplicateMatch,
  LeadLostReason,
  LeadResponse,
  LeadsIndexQuery,
  LeadsIndexResponse,
  OpenLeadStage,
  UpdateLeadPayload,
} from "@/types/lead";

export class LeadDuplicateError extends ApiRequestError {
  constructor(
    message: string,
    public readonly matches: LeadDuplicateMatch[]
  ) {
    super(message, {}, 409, "lead_phone_duplicate_detected");
    this.name = "LeadDuplicateError";
  }
}

export class LeadConversionConflictError extends ApiRequestError {
  constructor(
    message: string,
    public readonly conflictingCustomer: LeadConversionConflict
  ) {
    super(message, {}, 409, "lead_conversion_new_customer_conflict");
    this.name = "LeadConversionConflictError";
  }
}

export async function convertLead(
  token: string,
  leadId: string,
  payload: ConvertLeadPayload
): Promise<Lead> {
  const response = await fetch(
    `${getApiBaseUrl()}/leads/${leadId}/convert`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify(payload),
    }
  );

  if (!response.ok) {
    // For the conflict case we need the full body, so we parse it ourselves
    // before delegating to parseApiError for other errors.
    if (response.status === 409) {
      let body: Record<string, unknown>;
      try {
        body = (await response.json()) as Record<string, unknown>;
      } catch {
        throw await parseApiError(new Response(null, { status: 409 }));
      }
      const errorObj = body.error as Record<string, unknown> | undefined;
      if (errorObj?.code === "lead_conversion_new_customer_conflict") {
        const conflicting = errorObj.conflicting_customer as LeadConversionConflict;
        throw new LeadConversionConflictError(
          (errorObj.message as string) ?? "تعارض في التحويل",
          conflicting
        );
      }
      // For other 409s, re-construct a Response to feed parseApiError
      const err = new ApiRequestError(
        (errorObj?.message as string) ?? "تعذر إكمال العملية.",
        {},
        409,
        (errorObj?.code as string) ?? null
      );
      throw err;
    }
    throw await parseApiError(response);
  }

  const result = (await response.json()) as LeadResponse;
  return result.data.lead;
}

export async function fetchLeads(
  token: string,
  query: LeadsIndexQuery = {}
): Promise<LeadsIndexResponse> {
  const search = createQueryString(query);

  return requestJson<LeadsIndexResponse>(
    search ? `/leads?${search}` : "/leads",
    { token }
  );
}

export async function fetchLead(
  token: string,
  leadId: string,
  archived = false
): Promise<Lead> {
  const query = archived ? "?archived=true" : "";
  const response = await requestJson<LeadResponse>(
    `/leads/${leadId}${query}`,
    { token }
  );

  return response.data.lead;
}

export async function createLead(
  token: string,
  payload: CreateLeadPayload
): Promise<Lead> {
  const response = await fetch(`${getApiBaseUrl()}/leads`, {
    method: "POST",
    headers: createAuthHeaders(token, true),
    body: JSON.stringify(payload),
    cache: "no-store",
  });

  if (!response.ok) {
    if (response.status === 409) {
      const duplicatePayload = (await response.clone().json().catch(() => null)) as {
        message?: string;
        error?: {
          code?: string;
          message?: string;
          matches?: LeadDuplicateMatch[];
        };
      } | null;

      if (duplicatePayload?.error?.code === "lead_phone_duplicate_detected") {
        throw new LeadDuplicateError(
          duplicatePayload.error.message ??
            duplicatePayload.message ??
            "A Lead with this phone number already exists.",
          duplicatePayload.error.matches ?? []
        );
      }
    }

    throw await parseApiError(response);
  }

  return ((await response.json()) as LeadResponse).data.lead;
}

export async function updateLead(
  token: string,
  leadId: string,
  payload: UpdateLeadPayload
): Promise<Lead> {
  return mutateLead(token, leadId, "", payload);
}

export async function moveLeadStage(
  token: string,
  leadId: string,
  stage: OpenLeadStage
): Promise<Lead> {
  return mutateLead(token, leadId, "/stage", { stage });
}

export async function claimLead(
  token: string,
  leadId: string
): Promise<Lead> {
  return mutateLead(token, leadId, "/claim");
}

export async function assignLead(
  token: string,
  leadId: string,
  assignedTo: number | null
): Promise<Lead> {
  return mutateLead(token, leadId, "/assign", {
    assigned_to: assignedTo,
  });
}

export async function moveLeadToLost(
  token: string,
  leadId: string,
  lostReason: LeadLostReason,
  lostReasonDetail: string | null
): Promise<Lead> {
  return mutateLead(token, leadId, "/lose", {
    lost_reason: lostReason,
    lost_reason_detail: lostReasonDetail,
  });
}

export async function reopenLead(
  token: string,
  leadId: string
): Promise<Lead> {
  return mutateLead(token, leadId, "/reopen");
}

export async function archiveLead(
  token: string,
  leadId: string,
  archiveReason: LeadArchiveReason,
  archiveReasonDetail: string | null
): Promise<Lead> {
  return mutateLead(token, leadId, "/archive", {
    archive_reason: archiveReason,
    archive_reason_detail: archiveReasonDetail,
  });
}

export async function restoreLead(
  token: string,
  leadId: string
): Promise<Lead> {
  return mutateLead(token, leadId, "/restore");
}

export async function fetchLeadActivities(
  token: string,
  leadId: string,
  archived = false
): Promise<LeadActivitiesResponse> {
  const query = createQueryString({
    per_page: 100,
    archived: archived || undefined,
  });

  return requestJson<LeadActivitiesResponse>(
    `/leads/${leadId}/activities?${query}`,
    { token }
  );
}

export async function addLeadNote(
  token: string,
  leadId: string,
  body: string
): Promise<LeadActivityResponse> {
  return requestJson<LeadActivityResponse>(`/leads/${leadId}/notes`, {
    token,
    method: "POST",
    body: { body },
  });
}

async function mutateLead(
  token: string,
  leadId: string,
  suffix: string,
  body?: unknown
): Promise<Lead> {
  const response = await requestJson<LeadResponse>(
    `/leads/${leadId}${suffix}`,
    {
      token,
      method: "PATCH",
      ...(body === undefined ? {} : { body }),
    }
  );

  return response.data.lead;
}
