import type {
  Customer,
  CustomerFormPayload,
  CustomerPagination,
  CustomerResponse,
  CustomerSummary,
  CustomersResponse,
} from "@/types/customer";
import { parseApiError } from "@/lib/api-error";
import type {
  CustomerRecord,
  CustomerRecordResponse,
} from "@/types/customer-business-context";

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

function getHeaders(
  token: string
): HeadersInit {
  return {
    Accept: "application/json",
    "Content-Type": "application/json",
    Authorization: `Bearer ${token}`,
  };
}

export async function fetchCustomers(
  token: string,
  params: {
    page?: number;
    per_page?: number;
    search?: string;
    type?: string;
    category?: string;
    status?: string;
  } = {}
): Promise<CustomersResponse> {
  const query = new URLSearchParams();

  Object.entries(params).forEach(
    ([key, value]) => {
      if (
        value !== undefined &&
        value !== null &&
        value !== ""
      ) {
        query.append(key, String(value));
      }
    }
  );

  const response = await fetch(
    `${getApiBaseUrl()}/customers?${query.toString()}`,
    {
      headers: getHeaders(token),
      cache: "no-store",
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result = (await response.json()) as {
    data: {
      customers: CustomerPagination;
      summary: CustomerSummary;
    };
  };

  return {
    data: result.data,
  };
}

export async function fetchCustomer(
  token: string,
  customerId: string
): Promise<CustomerRecord> {
  const response = await fetch(
    `${getApiBaseUrl()}/customers/${customerId}`,
    {
      headers: getHeaders(token),
      cache: "no-store",
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result =
    (await response.json()) as CustomerRecordResponse;

  return result.data;
}

export async function createCustomer(
  token: string,
  payload: CustomerFormPayload
): Promise<Customer> {
  const response = await fetch(
    `${getApiBaseUrl()}/customers`,
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
    (await response.json()) as CustomerResponse;

  return result.data.customer;
}

export async function updateCustomer(
  token: string,
  customerId: string,
  payload: Partial<CustomerFormPayload>
): Promise<Customer> {
  const response = await fetch(
    `${getApiBaseUrl()}/customers/${customerId}`,
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
    (await response.json()) as CustomerResponse;

  return result.data.customer;
}

export async function archiveCustomer(
  token: string,
  customerId: string
): Promise<Customer> {
  const response = await fetch(
    `${getApiBaseUrl()}/customers/${customerId}/archive`,
    {
      method: "PATCH",
      headers: getHeaders(token),
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result =
    (await response.json()) as CustomerResponse;

  return result.data.customer;
}

export async function restoreCustomer(
  token: string,
  customerId: string
): Promise<Customer> {
  const response = await fetch(
    `${getApiBaseUrl()}/customers/${customerId}/restore`,
    {
      method: "PATCH",
      headers: getHeaders(token),
    }
  );

  if (!response.ok) {
    throw await parseApiError(response);
  }

  const result =
    (await response.json()) as CustomerResponse;

  return result.data.customer;
}
