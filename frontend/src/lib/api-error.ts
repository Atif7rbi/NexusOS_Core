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
    const payload = (await response.json()) as {
      message?: string;
      errors?: FieldErrors;
      error?: {
        code?: string;
        message?: string;
      };
    };

    const fieldErrors = payload.errors ?? {};
    const firstValidationError = Object.values(
      fieldErrors
    )[0]?.[0];

    return new ApiRequestError(
      firstValidationError ??
        payload.error?.message ??
        payload.message ??
        "تعذر إكمال العملية.",
      fieldErrors,
      response.status,
      payload.error?.code ?? null
    );
  } catch {
    return new ApiRequestError(
      "تعذر قراءة استجابة الخادم.",
      {},
      response.status
    );
  }
}
