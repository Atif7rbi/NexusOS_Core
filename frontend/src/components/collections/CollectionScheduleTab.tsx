"use client";

import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  cloneCollectionLines,
  collectionLinesEqual,
  CollectionLineEditor,
  createEmptyCollectionLine,
  isValidCalendarDate,
  type CollectionLineErrors,
} from "@/components/collections/CollectionLineEditor";
import { CollectionScheduleView } from "@/components/collections/CollectionScheduleView";
import { Button } from "@/components/ui/Button";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { FormErrorBanner } from "@/components/ui/FormErrorBanner";
import {
  ListErrorState,
  ListLoadingState,
} from "@/components/ui/crud/ListState";
import { useTranslation } from "@/hooks/useTranslation";
import { isApiRequestError } from "@/lib/api-error";
import { useAuth } from "@/providers/AuthProvider";
import {
  fetchCollectionSchedule,
  saveDraftCollectionSchedule,
} from "@/services/collections";
import type { Contract } from "@/types/contract";
import type {
  CollectionLineInput,
  CollectionScheduleResource,
  SaveDraftPayload,
} from "@/types/collection";
import { AlertTriangle } from "lucide-react";

type CollectionScheduleTabProps = {
  contract: Contract;
  onDirtyChange?: (dirty: boolean) => void;
};

type CollectionScheduleTabState =
  | { phase: "loading" }
  | { phase: "error"; message: string }
  | {
      phase: "read";
      resource: CollectionScheduleResource;
      error?: string | null;
    }
  | {
      phase: "draft-edit";
      resource: CollectionScheduleResource;
      lines: CollectionLineInput[];
      baseline: CollectionLineInput[];
      enteredFromAbsent: boolean;
      isSaving: boolean;
      error: string | null;
    };

export function CollectionScheduleTab({
  contract,
  onDirtyChange,
}: CollectionScheduleTabProps) {
  const { token } = useAuth();
  const { t } = useTranslation();
  const [state, setState] =
    useState<CollectionScheduleTabState>({
      phase: "loading",
    });
  const [showDiscardDialog, setShowDiscardDialog] =
    useState(false);

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

  useEffect(() => {
    if (state.phase !== "draft-edit") {
      return;
    }

    const handleBeforeUnload = (event: BeforeUnloadEvent) => {
      if (
        !collectionLinesEqual(state.lines, state.baseline)
      ) {
        event.preventDefault();
      }
    };

    window.addEventListener(
      "beforeunload",
      handleBeforeUnload
    );

    return () => {
      window.removeEventListener(
        "beforeunload",
        handleBeforeUnload
      );
    };
  }, [state]);

  const draftValidation = useMemo(() => {
    if (state.phase !== "draft-edit") {
      return {
        errors: {} as CollectionLineErrors,
        isValid: false,
      };
    }

    return validateDraftLines(state.lines, t);
  }, [state, t]);

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

  if (state.phase === "draft-edit") {
    const dirty = !collectionLinesEqual(
      state.lines,
      state.baseline
    );
    const canSave =
      dirty &&
      draftValidation.isValid &&
      !state.isSaving &&
      (!state.enteredFromAbsent || state.lines.length > 0);

    return (
      <>
        <div className="space-y-5">
          <div>
            <h3 className="text-lg font-bold text-[var(--text-primary)]">
              {t("collection.draft.editorTitle")}
            </h3>
            <p className="mt-1 text-sm text-[var(--text-secondary)]">
              {t("collection.draft.editorDescription")}
            </p>
          </div>

          <CollectionLineEditor
            lines={state.lines}
            errors={draftValidation.errors}
            disabled={state.isSaving}
            onChange={(key, field, value) =>
              updateDraftLine(key, field, value)
            }
            onAdd={addDraftLine}
            onDelete={deleteDraftLine}
            onMove={moveDraftLine}
          />

          <FormErrorBanner message={state.error} />

          <div className="flex flex-wrap justify-end gap-3 border-t border-[var(--border)] pt-5">
            <Button
              type="button"
              variant="secondary"
              disabled={state.isSaving}
              onClick={requestCancelDraft}
            >
              {t("collection.amend.cancel")}
            </Button>
            <Button
              type="button"
              disabled={!canSave}
              isLoading={state.isSaving}
              onClick={() => void saveDraft()}
            >
              {state.isSaving
                ? t("collection.draft.saving")
                : t("collection.draft.save")}
            </Button>
          </div>
        </div>

        <ConfirmationDialog
          isOpen={showDiscardDialog}
          title={t("collection.discard.title")}
          description={t("collection.discard.description")}
          icon={
            <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--warning-soft)] text-[var(--warning)]">
              <AlertTriangle size={21} />
            </span>
          }
          isProcessing={false}
          closeLabel={t("collection.discard.close")}
          cancelLabel={t("collection.discard.cancel")}
          confirmLabel={t("collection.discard.confirm")}
          processingLabel={t("collection.discard.confirm")}
          confirmVariant="danger"
          onCancel={() => setShowDiscardDialog(false)}
          onConfirm={discardDraftChanges}
        />
      </>
    );
  }

  return (
    <div className="space-y-4">
      <FormErrorBanner message={state.error ?? null} />
      <CollectionScheduleView
        resource={state.resource}
        onCreateDraft={() => enterDraftEditor(true)}
        onEditDraft={() => enterDraftEditor(false)}
      />
    </div>
  );

  function enterDraftEditor(fromAbsent: boolean): void {
    if (state.phase !== "read") {
      return;
    }

    const lines = fromAbsent
      ? [createEmptyCollectionLine(1)]
      : cloneCollectionLines(
          state.resource.schedule.active_collections,
          true
        );

    onDirtyChange?.(false);
    setState({
      phase: "draft-edit",
      resource: state.resource,
      lines,
      baseline: lines.map((line) => ({ ...line })),
      enteredFromAbsent: fromAbsent,
      isSaving: false,
      error: null,
    });
  }

  function updateDraftLine(
    key: string,
    field: "title" | "amount" | "due_date" | "notes",
    value: string
  ): void {
    if (state.phase !== "draft-edit") {
      return;
    }

    const lines = state.lines.map((line) =>
      line._key === key ? { ...line, [field]: value } : line
    );

    onDirtyChange?.(
      !collectionLinesEqual(lines, state.baseline)
    );
    setState({ ...state, lines, error: null });
  }

  function addDraftLine(): void {
    if (state.phase !== "draft-edit") {
      return;
    }

    const sequence =
      state.lines.length === 0
        ? 1
        : Math.max(
            ...state.lines.map((line) => line.sequence)
          ) + 1;
    const lines = [
      ...state.lines,
      createEmptyCollectionLine(sequence),
    ];

    onDirtyChange?.(
      !collectionLinesEqual(lines, state.baseline)
    );
    setState({ ...state, lines, error: null });
  }

  function deleteDraftLine(key: string): void {
    if (state.phase !== "draft-edit") {
      return;
    }

    const lines = state.lines.filter(
      (line) => line._key !== key
    );

    onDirtyChange?.(
      !collectionLinesEqual(lines, state.baseline)
    );
    setState({ ...state, lines, error: null });
  }

  function moveDraftLine(
    index: number,
    direction: -1 | 1
  ): void {
    if (state.phase !== "draft-edit") {
      return;
    }

    const targetIndex = index + direction;

    if (
      targetIndex < 0 ||
      targetIndex >= state.lines.length
    ) {
      return;
    }

    const reordered = [...state.lines];
    const [movedLine] = reordered.splice(index, 1);
    reordered.splice(targetIndex, 0, movedLine);
    const lines = reordered.map((line, lineIndex) => ({
      ...line,
      sequence: lineIndex + 1,
    }));

    onDirtyChange?.(
      !collectionLinesEqual(lines, state.baseline)
    );
    setState({ ...state, lines, error: null });
  }

  function requestCancelDraft(): void {
    if (state.phase !== "draft-edit") {
      return;
    }

    if (
      collectionLinesEqual(state.lines, state.baseline)
    ) {
      onDirtyChange?.(false);
      setState({
        phase: "read",
        resource: state.resource,
      });
      return;
    }

    setShowDiscardDialog(true);
  }

  function discardDraftChanges(): void {
    if (state.phase !== "draft-edit") {
      return;
    }

    setShowDiscardDialog(false);
    onDirtyChange?.(false);
    setState({
      phase: "read",
      resource: state.resource,
    });
  }

  async function saveDraft(): Promise<void> {
    if (
      state.phase !== "draft-edit" ||
      !token ||
      !draftValidation.isValid
    ) {
      return;
    }

    const payload: SaveDraftPayload = {
      lines: state.lines.map((line) => ({
        ...(line.id ? { id: line.id } : {}),
        sequence: line.sequence,
        title: line.title,
        amount: line.amount,
        due_date: line.due_date,
        notes: line.notes === "" ? null : line.notes,
      })),
    };

    setState({ ...state, isSaving: true, error: null });

    try {
      const resource = await saveDraftCollectionSchedule(
        token,
        contract.id,
        payload
      );

      onDirtyChange?.(false);
      setState({ phase: "read", resource });
    } catch (error) {
      setState({
        ...state,
        isSaving: false,
        error: isApiRequestError(error)
          ? error.message
          : t("collection.genericError"),
      });
    }
  }
}

function validateDraftLines(
  lines: CollectionLineInput[],
  t: (key: import("@/i18n/types").TranslationKey) => string
): {
  errors: CollectionLineErrors;
  isValid: boolean;
} {
  const errors: CollectionLineErrors = {};
  const sequenceCounts = new Map<number, number>();

  lines.forEach((line) => {
    sequenceCounts.set(
      line.sequence,
      (sequenceCounts.get(line.sequence) ?? 0) + 1
    );
  });

  lines.forEach((line) => {
    const lineErrors: CollectionLineErrors[string] = {};

    if (line.title.trim() === "") {
      lineErrors.title = t(
        "collection.validation.titleRequired"
      );
    } else if (line.title.length > 150) {
      lineErrors.title = t(
        "collection.validation.titleTooLong"
      );
    }

    if (
      !/^\d{1,10}(?:\.\d{1,2})?$/.test(line.amount) ||
      Number(line.amount) <= 0
    ) {
      lineErrors.amount = t(
        "collection.validation.amountInvalid"
      );
    }

    if (!isValidCalendarDate(line.due_date)) {
      lineErrors.due_date = t(
        "collection.validation.dateInvalid"
      );
    }

    if ((sequenceCounts.get(line.sequence) ?? 0) > 1) {
      lineErrors.sequence = t(
        "collection.validation.sequenceDuplicate"
      );
    }

    if (Object.keys(lineErrors).length > 0) {
      errors[line._key] = lineErrors;
    }
  });

  const ordered = [...lines].sort(
    (left, right) => left.sequence - right.sequence
  );

  for (let index = 1; index < ordered.length; index += 1) {
    const previous = ordered[index - 1];
    const current = ordered[index];

    if (
      isValidCalendarDate(previous.due_date) &&
      isValidCalendarDate(current.due_date) &&
      current.due_date < previous.due_date
    ) {
      errors[current._key] = {
        ...(errors[current._key] ?? {}),
        due_date: t(
          "collection.validation.dueDateOrder"
        ),
      };
    }
  }

  return {
    errors,
    isValid:
      lines.length === 0 ||
      Object.keys(errors).length === 0,
  };
}
