"use client";

import { useSearchParams } from "next/navigation";
import {
  useCallback,
  useEffect,
  useState,
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
  const searchParams = useSearchParams();
  const [isRequested, setRequested] = useState(false);
  const queryString = searchParams.toString();

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      setRequested(isQuickCreateRequested(queryString));
    }, 0);

    return () => window.clearTimeout(timeoutId);
  }, [queryString]);

  const clear = useCallback((): void => {
    if (!isQuickCreateRequested(window.location.search)) {
      return;
    }

    const nextUrl = removeQuickCreateQuery(
      window.location.pathname,
      window.location.search
    );

    window.history.replaceState(null, "", nextUrl);
    setRequested(false);
  }, []);

  return {
    isRequested,
    clear,
  };
}
