"use client";

import {
  useEffect,
  useRef,
} from "react";

export type FrontendResource =
  | "projects"
  | "units"
  | "reservations"
  | "contracts";

type ResourceInvalidation = {
  resources: FrontendResource[];
  occurred_at: number;
  nonce: string;
};

const storageKey = "nexusos:resource-invalidation";

export function invalidateFrontendResources(
  resources: FrontendResource[]
): void {
  if (typeof window === "undefined") {
    return;
  }

  const invalidation: ResourceInvalidation = {
    resources: [...new Set(resources)],
    occurred_at: Date.now(),
    nonce: crypto.randomUUID(),
  };

  window.localStorage.setItem(storageKey, JSON.stringify(invalidation));
}

export function useResourceInvalidation(
  resource: FrontendResource,
  refresh: () => void | Promise<void>
): void {
  const refreshRef = useRef(refresh);

  useEffect(() => {
    refreshRef.current = refresh;
  }, [refresh]);

  useEffect(() => {
    const handleStorage = (event: StorageEvent): void => {
      if (event.key !== storageKey || !event.newValue) {
        return;
      }

      try {
        const invalidation = JSON.parse(
          event.newValue
        ) as ResourceInvalidation;

        if (invalidation.resources.includes(resource)) {
          void refreshRef.current();
        }
      } catch {
        // Ignore malformed local UI metadata and wait for the next refresh.
      }
    };

    window.addEventListener("storage", handleStorage);

    return () => window.removeEventListener("storage", handleStorage);
  }, [resource]);
}
