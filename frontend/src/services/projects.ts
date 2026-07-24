import type {
  Project,
  ProjectFormPayload,
  ProjectResponse,
  ProjectsResponse,
} from "@/types/project";
import { parseApiError } from "@/lib/api-error";

function getApiBaseUrl(): string {
  const apiBaseUrl = process.env.NEXT_PUBLIC_API_BASE_URL;

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

export async function fetchProjects(
  token: string,
  params:
    | {
        page?: number;
        perPage?: number;
        search?: string;
        status?: string;
      }
    | number = {},
  legacyPerPage?: number
): Promise<ProjectsResponse> {
  const queryParams =
    typeof params === "number"
      ? {
          page: params,
          perPage: legacyPerPage,
        }
      : params;

  const query = new URLSearchParams({
    page: String(queryParams.page ?? 1),
    per_page: String(queryParams.perPage ?? 20),
  });

  if (queryParams.search?.trim()) {
    query.set('search', queryParams.search.trim());
  }

  if (queryParams.status) {
    query.set('status', queryParams.status);
  }

  const response = await fetch(
    `${getApiBaseUrl()}/projects?${query.toString()}`,
    {
      headers: getHeaders(token),
      cache: "no-store",
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  return (await response.json()) as ProjectsResponse;
}

export async function createProject(
  token: string,
  payload: ProjectFormPayload
): Promise<Project> {
  const response = await fetch(
    `${getApiBaseUrl()}/projects`,
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
    (await response.json()) as ProjectResponse;

  return result.data.project;
}

export async function updateProject(
  token: string,
  projectId: string,
  payload: Partial<ProjectFormPayload>
): Promise<Project> {
  const response = await fetch(
    `${getApiBaseUrl()}/projects/${projectId}`,
    {
      method: "PATCH",
      headers: getHeaders(token),
      body: JSON.stringify(payload),
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result =
    (await response.json()) as ProjectResponse;

  return result.data.project;
}

export async function archiveProject(
  token: string,
  projectId: string
): Promise<Project> {
  const response = await fetch(
    `${getApiBaseUrl()}/projects/${projectId}/archive`,
    {
      method: "PATCH",
      headers: getHeaders(token),
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result =
    (await response.json()) as ProjectResponse;

  return result.data.project;
}
