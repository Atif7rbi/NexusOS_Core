"use client";

import { useCallback, useEffect, useState } from "react";

import { CollectionScheduleView } from "@/components/collections/CollectionScheduleView";
import { Button } from "@/components/ui/Button";
import {
  ListErrorState,
  ListLoadingState,
} from "@/components/ui/crud/ListState";
import { useTranslation } from "@/hooks/useTranslation";
import { isApiRequestError } from "@/lib/api-error";
import { useAuth } from "@/providers/AuthProvider";
import { fetchCollectionSchedule } from "@/services/collections";
import type { Contract } from "@/types/contract";
import type { CollectionScheduleResource } from "@/types/collection";

type CollectionScheduleTabProps = {
  contract: Contract;
};

type CollectionScheduleTabState =
  | { phase: "loading" }
  | { phase: "error"; message: string }
  | {
      phase: "read";
      resource: CollectionScheduleResource;
    };

export function CollectionScheduleTab({
  contract,
}: CollectionScheduleTabProps) {
  const { token } = useAuth();
  const { t } = useTranslation();
  const [state, setState] =
    useState<CollectionScheduleTabState>({
      phase: "loading",
    });

  const retryLoad = useCallback(async (): Promise<void> => {
    setState({ phase: "loading" });

    if (!token) {
      setState({
        phase: "error",
        message: t("collection.loadError"),
      });
      return;
    }

    try {
      const resource = await fetchCollectionSchedule(
        token,
        contract.id
      );

      setState({ phase: "read", resource });
    } catch (error) {
      setState({
        phase: "error",
        message: isApiRequestError(error)
          ? error.message
          : t("collection.loadError"),
      });
    }
  }, [contract.id, t, token]);

  useEffect(() => {
    let isCurrent = true;

    if (!token) {
      return () => {
        isCurrent = false;
      };
    }

    void fetchCollectionSchedule(token, contract.id).then(
      (resource) => {
        if (isCurrent) {
          setState({ phase: "read", resource });
        }
      },
      (error: unknown) => {
        if (isCurrent) {
          setState({
            phase: "error",
            message: isApiRequestError(error)
              ? error.message
              : t("collection.loadError"),
          });
        }
      }
    );

    return () => {
      isCurrent = false;
    };
  }, [contract.id, t, token]);

  if (!token) {
    return (
      <ListErrorState message={t("collection.loadError")} />
    );
  }

  if (state.phase === "loading") {
    return (
      <ListLoadingState label={t("collection.loading")} />
    );
  }

  if (state.phase === "error") {
    return (
      <ListErrorState
        message={state.message}
        action={
          <Button
            type="button"
            size="sm"
            variant="secondary"
            onClick={() => void retryLoad()}
          >
            {t("collection.retry")}
          </Button>
        }
      />
    );
  }

  return <CollectionScheduleView resource={state.resource} />;
}
