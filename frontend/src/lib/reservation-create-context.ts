const ULID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{26}$/i;

const CREATE_PARAM = "create";
const CUSTOMER_PARAM = "customer";
const RETURN_TO_PARAM = "returnTo";

export type ReservationCreateContext = {
  customerId: string;
  returnTo: string;
};

export type ReservationCreateQuery = {
  context: ReservationCreateContext | null;
  normalizedUrl: string | null;
};

function reservationsUrl(params: URLSearchParams): string {
  return params.size > 0
    ? `/reservations/?${params.toString()}`
    : "/reservations/";
}

export function customerRecordFallback(
  customerId: string
): string {
  const params = new URLSearchParams({
    customer: customerId,
  });

  return `/customers/?${params.toString()}`;
}

export function validateCustomerReturnTo(
  candidate: string | null,
  customerId: string
): string | null {
  if (
    !candidate ||
    !candidate.startsWith("/") ||
    candidate.startsWith("//") ||
    /^https?:/i.test(candidate) ||
    candidate.split(/[?#]/, 1)[0] !== "/customers/"
  ) {
    return null;
  }

  const parsed = new URL(
    candidate,
    "https://nexusos.internal"
  );

  if (
    parsed.origin !== "https://nexusos.internal" ||
    parsed.pathname !== "/customers/" ||
    parsed.hash !== "" ||
    parsed.searchParams.get("customer") !== customerId
  ) {
    return null;
  }

  return `${parsed.pathname}${parsed.search}`;
}

export function buildContextualReservationUrl(
  customerId: string,
  customerRecordUrl: string
): string {
  const returnTo =
    validateCustomerReturnTo(
      customerRecordUrl,
      customerId
    ) ?? customerRecordFallback(customerId);
  const params = new URLSearchParams({
    [CREATE_PARAM]: "1",
    [CUSTOMER_PARAM]: customerId,
    [RETURN_TO_PARAM]: returnTo,
  });

  return reservationsUrl(params);
}

export function parseReservationCreateQuery(
  search: string
): ReservationCreateQuery {
  const params = new URLSearchParams(search);
  const hasContextParams =
    params.has(CREATE_PARAM) ||
    params.has(CUSTOMER_PARAM) ||
    params.has(RETURN_TO_PARAM);

  if (!hasContextParams) {
    return {
      context: null,
      normalizedUrl: null,
    };
  }

  const customerId = params.get(CUSTOMER_PARAM) ?? "";

  if (
    params.get(CREATE_PARAM) !== "1" ||
    !ULID_PATTERN.test(customerId)
  ) {
    params.delete(CREATE_PARAM);
    params.delete(CUSTOMER_PARAM);
    params.delete(RETURN_TO_PARAM);

    return {
      context: null,
      normalizedUrl: reservationsUrl(params),
    };
  }

  const requestedReturnTo = params.get(RETURN_TO_PARAM);
  const returnTo =
    validateCustomerReturnTo(
      requestedReturnTo,
      customerId
    ) ?? customerRecordFallback(customerId);

  params.set(CREATE_PARAM, "1");
  params.set(CUSTOMER_PARAM, customerId);
  params.set(RETURN_TO_PARAM, returnTo);

  const normalizedUrl =
    requestedReturnTo === returnTo
      ? null
      : reservationsUrl(params);

  return {
    context: {
      customerId,
      returnTo,
    },
    normalizedUrl,
  };
}

export function clearReservationCreateQuery(
  search: string
): string {
  const params = new URLSearchParams(search);

  params.delete(CREATE_PARAM);
  params.delete(CUSTOMER_PARAM);
  params.delete(RETURN_TO_PARAM);

  return reservationsUrl(params);
}

export function returnToCustomerRecord(
  returnTo: string
): void {
  window.location.replace(returnTo);
}
