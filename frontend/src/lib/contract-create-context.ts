const ULID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{26}$/i;

const CREATE_PARAM = "create";
const RESERVATION_PARAM = "reservation";
const RETURN_TO_PARAM = "returnTo";

const DEFAULT_RETURN_TO = "/reservations/";

export type ContractCreateContext = {
  reservationId: string;
  returnTo: string;
};

export type ContractCreateQuery = {
  context: ContractCreateContext | null;
  normalizedUrl: string | null;
};

export type ReservationListReturnState = {
  page: number;
  search: string;
  statusFilter: string;
  projectFilter: string;
};

function contractsUrl(params: URLSearchParams): string {
  return params.size > 0
    ? `/contracts/?${params.toString()}`
    : "/contracts/";
}

export function buildReservationListReturnTo({
  page,
  search,
  statusFilter,
  projectFilter,
}: ReservationListReturnState): string {
  const params = new URLSearchParams();
  const normalizedSearch = search.trim();

  if (Number.isInteger(page) && page > 1) {
    params.set("page", String(page));
  }
  if (normalizedSearch) {
    params.set("search", normalizedSearch);
  }
  if (statusFilter && statusFilter !== "all") {
    params.set("status", statusFilter);
  }
  if (projectFilter && projectFilter !== "all") {
    params.set("project_id", projectFilter);
  }

  return params.size > 0
    ? `/reservations/?${params.toString()}`
    : DEFAULT_RETURN_TO;
}

export function validateReservationReturnTo(
  candidate: string | null
): string | null {
  if (
    !candidate ||
    !candidate.startsWith("/") ||
    candidate.startsWith("//") ||
    /^https?:/i.test(candidate) ||
    candidate.split(/[?#]/, 1)[0] !== "/reservations/"
  ) {
    return null;
  }

  const parsed = new URL(
    candidate,
    "https://nexusos.internal"
  );

  if (
    parsed.origin !== "https://nexusos.internal" ||
    parsed.pathname !== "/reservations/" ||
    parsed.hash !== ""
  ) {
    return null;
  }

  return `${parsed.pathname}${parsed.search}`;
}

export function buildContextualContractUrl(
  reservationId: string,
  reservationListUrl: string
): string {
  const returnTo =
    validateReservationReturnTo(reservationListUrl) ??
    DEFAULT_RETURN_TO;
  const params = new URLSearchParams({
    [CREATE_PARAM]: "1",
    [RESERVATION_PARAM]: reservationId,
    [RETURN_TO_PARAM]: returnTo,
  });

  return contractsUrl(params);
}

export function parseContractCreateQuery(
  search: string
): ContractCreateQuery {
  const params = new URLSearchParams(search);
  const hasContextParams =
    params.has(CREATE_PARAM) ||
    params.has(RESERVATION_PARAM) ||
    params.has(RETURN_TO_PARAM);

  if (!hasContextParams) {
    return {
      context: null,
      normalizedUrl: null,
    };
  }

  const reservationId =
    params.get(RESERVATION_PARAM) ?? "";

  if (
    params.get(CREATE_PARAM) !== "1" ||
    !ULID_PATTERN.test(reservationId)
  ) {
    params.delete(CREATE_PARAM);
    params.delete(RESERVATION_PARAM);
    params.delete(RETURN_TO_PARAM);

    return {
      context: null,
      normalizedUrl: contractsUrl(params),
    };
  }

  const requestedReturnTo = params.get(RETURN_TO_PARAM);
  const returnTo =
    validateReservationReturnTo(requestedReturnTo) ??
    DEFAULT_RETURN_TO;

  params.set(CREATE_PARAM, "1");
  params.set(RESERVATION_PARAM, reservationId);
  params.set(RETURN_TO_PARAM, returnTo);

  return {
    context: {
      reservationId,
      returnTo,
    },
    normalizedUrl:
      requestedReturnTo === returnTo
        ? null
        : contractsUrl(params),
  };
}

export function clearContractCreateQuery(
  search: string
): string {
  const params = new URLSearchParams(search);

  params.delete(CREATE_PARAM);
  params.delete(RESERVATION_PARAM);
  params.delete(RETURN_TO_PARAM);

  return contractsUrl(params);
}

export function returnToReservationList(
  returnTo: string
): void {
  window.location.replace(returnTo);
}
