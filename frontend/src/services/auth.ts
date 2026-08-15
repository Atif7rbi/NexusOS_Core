import type {
  AuthUser,
  CurrentUserResponse,
  LoginPayload,
  LoginResponse,
} from "@/types/auth";
import { parseApiError } from "@/lib/api-error";

const TOKEN_STORAGE_KEY = "ufq_pilot_access_token";

function getApiBaseUrl(): string {
  const apiBaseUrl = process.env.NEXT_PUBLIC_API_BASE_URL;

  if (!apiBaseUrl) {
    throw new Error("NEXT_PUBLIC_API_BASE_URL is not configured.");
  }

  return apiBaseUrl;
}

export function getStoredToken(): string | null {
  if (typeof window === "undefined") {
    return null;
  }

  return window.localStorage.getItem(TOKEN_STORAGE_KEY);
}

export function storeToken(token: string): void {
  window.localStorage.setItem(TOKEN_STORAGE_KEY, token);
}

export function removeStoredToken(): void {
  window.localStorage.removeItem(TOKEN_STORAGE_KEY);
}

export async function login(
  payload: LoginPayload
): Promise<LoginResponse> {
  const response = await fetch(
    `${getApiBaseUrl()}/auth/login`,
    {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  return (await response.json()) as LoginResponse;
}

export async function fetchCurrentUser(
  token: string
): Promise<AuthUser> {
  const response = await fetch(
    `${getApiBaseUrl()}/auth/user`,
    {
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
      },
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const payload =
    (await response.json()) as CurrentUserResponse;

  return payload.data.user;
}

export async function logout(token: string): Promise<void> {
  await fetch(
    `${getApiBaseUrl()}/auth/logout`,
    {
      method: "POST",
      headers: {
        Accept: "application/json",
        Authorization: `Bearer ${token}`,
      },
    }
  );
}
