import type {
  CreateTenantUserPayload,
  TenantUser,
  TenantUserResponse,
  TenantUsersQuery,
  TenantUsersResponse,
  UpdateTenantUserPayload,
} from "@/types/tenant-user";
import { parseApiError } from "@/lib/api-error";

function getApiBaseUrl(): string {
  const apiBaseUrl =
    process.env.NEXT_PUBLIC_API_BASE_URL;

  if (!apiBaseUrl) {
    throw new Error(
      "NEXT_PUBLIC_API_BASE_URL is not configured."
    );
  }

  return apiBaseUrl;
}

function getHeaders(token: string): HeadersInit {
  return {
    Accept: "application/json",
    "Content-Type": "application/json",
    Authorization: `Bearer ${token}`,
  };
}

export async function fetchTenantUsers(
  token: string,
  query: TenantUsersQuery = {}
): Promise<TenantUsersResponse> {
  const searchParams = new URLSearchParams({
    page: String(query.page ?? 1),
    per_page: String(query.perPage ?? 20),
  });

  if (query.search?.trim()) {
    searchParams.set(
      "search",
      query.search.trim()
    );
  }

  if (query.status) {
    searchParams.set("status", query.status);
  }

  if (query.role) {
    searchParams.set("role", query.role);
  }

  const response = await fetch(
    `${getApiBaseUrl()}/users?${searchParams.toString()}`,
    {
      headers: getHeaders(token),
      cache: "no-store",
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  return (await response.json()) as TenantUsersResponse;
}

export async function fetchTenantUser(
  token: string,
  membershipId: string
): Promise<TenantUser> {
  const response = await fetch(
    `${getApiBaseUrl()}/users/${membershipId}`,
    {
      headers: getHeaders(token),
      cache: "no-store",
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result =
    (await response.json()) as TenantUserResponse;

  return result.data.user;
}

export async function createTenantUser(
  token: string,
  payload: CreateTenantUserPayload
): Promise<TenantUser> {
  const response = await fetch(
    `${getApiBaseUrl()}/users`,
    {
      method: "POST",
      headers: getHeaders(token),
      body: JSON.stringify(payload),
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result =
    (await response.json()) as TenantUserResponse;

  return result.data.user;
}

export async function updateTenantUser(
  token: string,
  membershipId: string,
  payload: UpdateTenantUserPayload
): Promise<TenantUser> {
  const response = await fetch(
    `${getApiBaseUrl()}/users/${membershipId}`,
    {
      method: "PUT",
      headers: getHeaders(token),
      body: JSON.stringify(payload),
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result =
    (await response.json()) as TenantUserResponse;

  return result.data.user;
}

export async function removeTenantUser(
  token: string,
  membershipId: string
): Promise<void> {
  const response = await fetch(
    `${getApiBaseUrl()}/users/${membershipId}`,
    {
      method: "DELETE",
      headers: getHeaders(token),
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }
}
