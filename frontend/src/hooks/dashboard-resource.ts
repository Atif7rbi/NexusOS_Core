import type {
  Dispatch,
  SetStateAction,
} from "react";

import type { DashboardResource } from "@/types/dashboard";

export function initialDashboardResource<T>(): DashboardResource<T> {
  return {
    data: null,
    isLoading: true,
    error: null,
  };
}

type ResourceSetter<T> = Dispatch<
  SetStateAction<DashboardResource<T>>
>;

export async function loadDashboardResource<T>(
  request: () => Promise<T>,
  setResource: ResourceSetter<T>,
  isCurrent: () => boolean
): Promise<void> {
  if (isCurrent()) {
    setResource((current) => ({
      ...current,
      isLoading: true,
      error: null,
    }));
  }

  try {
    const data = await request();

    if (isCurrent()) {
      setResource({
        data,
        isLoading: false,
        error: null,
      });
    }
  } catch (caughtError) {
    if (isCurrent()) {
      setResource((current) => ({
        ...current,
        isLoading: false,
        error:
          caughtError instanceof Error
            ? caughtError.message
            : "Unable to load dashboard section.",
      }));
    }
  }
}
