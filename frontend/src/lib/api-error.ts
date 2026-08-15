export type FieldErrors = Record<string, string[]>;

export class ApiRequestError extends Error {
  constructor(
    message: string,
    public readonly fieldErrors: FieldErrors = {},
    public readonly status: number | null = null,
    public readonly code: string | null = null
  ) {
    super(message);
    this.name = "ApiRequestError";
  }
}

export function isApiRequestError(
  error: unknown
): error is ApiRequestError {
  return error instanceof ApiRequestError;
}

export async function parseApiError(
  response: Response
): Promise<ApiRequestError> {
  try {
    const payload = (await response.json()) as unknown;

    if (!isRecord(payload)) {
      throw new Error("Invalid API error payload.");
    }

    const error = isRecord(payload.error)
      ? payload.error
      : {};
    const fieldErrors = normalizeFieldErrors(payload.errors);

    const firstValidationError = Object.values(
      fieldErrors
    )[0]?.[0];
    const errorMessage =
      typeof error.message === "string"
        ? error.message
        : null;
    const topLevelMessage =
      typeof payload.message === "string"
        ? payload.message
        : null;
    const errorCode =
      typeof error.code === "string"
        ? error.code
        : response.status === 422
          ? "validation_failed"
          : null;

    return new ApiRequestError(
      firstValidationError ??
        errorMessage ??
        topLevelMessage ??
        "تعذر إكمال العملية.",
      fieldErrors,
      response.status,
      errorCode
    );
  } catch {
    return new ApiRequestError(
      "تعذر إكمال العملية.",
      {},
      response.status
    );
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return (
    typeof value === "object" &&
    value !== null &&
    !Array.isArray(value)
  );
}

function normalizeFieldErrors(value: unknown): FieldErrors {
  if (!isRecord(value)) {
    return {};
  }

  return Object.fromEntries(
    Object.entries(value).flatMap(([field, messages]) => {
      if (!Array.isArray(messages)) {
        return [];
      }

      const normalizedMessages = messages.filter(
        (message): message is string =>
          typeof message === "string"
      );

      return normalizedMessages.length > 0
        ? [[field, normalizedMessages]]
        : [];
    })
  );
}
