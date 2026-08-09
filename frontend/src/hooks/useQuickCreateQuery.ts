"use client";

import {
  useRouter,
  useSearchParams,
} from "next/navigation";
import {
  useCallback,
} from "react";

import {
  isQuickCreateRequested,
  removeQuickCreateQuery,
} from "@/lib/quick-create-query";

type QuickCreateQuery = {
  isRequested: boolean;
  clear: () => void;
};

export function useQuickCreateQuery(): QuickCreateQuery {
  const router = useRouter();
  const searchParams = useSearchParams();
  const queryString = searchParams.toString();
  const isRequested = isQuickCreateRequested(queryString);

  const clear = useCallback((): void => {
    if (!isQuickCreateRequested(window.location.search)) {
      return;
    }

    const nextUrl = removeQuickCreateQuery(
      window.location.pathname,
      window.location.search
    );

    router.replace(nextUrl, { scroll: false });
  }, [router]);

  return {
    isRequested,
    clear,
  };
}
