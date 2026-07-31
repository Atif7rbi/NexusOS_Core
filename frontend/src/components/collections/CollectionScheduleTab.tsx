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
import { AmendDialog } from "@/components/collections/AmendDialog";
import { FinalizeDialog } from "@/components/collections/FinalizeDialog";
import type { CollectionHistoryState } from "@/components/collections/CollectionHistoryPanel";
import {
  collectionAmountsEqual,
  CollectionScheduleView,
} from "@/components/collections/CollectionScheduleView";
import { Button } from "@/components/ui/Button";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { FormErrorBanner } from "@/components/ui/FormErrorBanner";
import { Input } from "@/components/ui/Input";
import {
  ListErrorState,
  ListLoadingState,
} from "@/components/ui/crud/ListState";
import { useTranslation } from "@/hooks/useTranslation";
import { isApiRequestError } from "@/lib/api-error";
import { useAuth } from "@/providers/AuthProvider";
import {
  amendCollectionSchedule,
  fetchCollectionSchedule,
  finalizeCollectionSchedule,
  saveDraftCollectionSchedule,
} from "@/services/collections";
import type { Contract } from "@/types/contract";
import type {
  AmendPayload,
  CancelledCollection,
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
      actionsDisabled?: boolean;
    }
  | {
      phase: "draft-edit";
      resource: CollectionScheduleResource;
      lines: CollectionLineInput[];
      baseline: CollectionLineInput[];
      enteredFromAbsent: boolean;
      isSaving: boolean;
      error: string | null;
    }
  | {
      phase: "finalize-confirm";
      resource: CollectionScheduleResource;
      isProcessing: boolean;
      error: string | null;
    }
  | {
      phase: "amend-edit";
      resource: CollectionScheduleResource;
      lines: CollectionLineInput[];
      baseline: CollectionLineInput[];
      generationToken: string[];
      cancellationReason: string;
      isPending: boolean;
      error: string | null;
    }
  | {
      phase: "amend-confirm";
      resource: CollectionScheduleResource;
      lines: CollectionLineInput[];
      baseline: CollectionLineInput[];
      generationToken: string[];
      cancellationReason: string;
      proposedTotal: string;
      isProcessing: boolean;
      error: string | null;
    };

const initialHistoryState: CollectionHistoryState = {
  status: "not_loaded",
  isVisible: false,
  rows: [],
  error: null,
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
  const [historyState, setHistoryState] =
    useState<CollectionHistoryState>(initialHistoryState);

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

      setHistoryState(initialHistoryState);
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
          setHistoryState(initialHistoryState);
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

  const cancelledScheduleId =
    state.phase === "read" &&
    state.resource.schedule.derived_state === "cancelled"
      ? state.resource.contract.id
      : null;

  useEffect(() => {
    if (
      !token ||
      !cancelledScheduleId ||
      historyState.status !== "not_loaded"
    ) {
      return;
    }

    let isCurrent = true;

    void fetchCollectionSchedule(token, cancelledScheduleId, {
      include_history: true,
    }).then(
      (resource) => {
        if (!isCurrent) {
          return;
        }

        setState({ phase: "read", resource });
        setHistoryState({
          status: "valid",
          isVisible: true,
          rows: getCancelledHistory(resource),
          error: null,
        });
      },
      (error: unknown) => {
        if (!isCurrent) {
          return;
        }

        setHistoryState({
          status: "error",
          isVisible: true,
          rows: [],
          error: getCollectionErrorMessage(error, t),
        });
      }
    );

    return () => {
      isCurrent = false;
    };
  }, [
    cancelledScheduleId,
    historyState.status,
    t,
    token,
  ]);

  useEffect(() => {
    if (
      state.phase !== "draft-edit" &&
      state.phase !== "amend-edit" &&
      state.phase !== "amend-confirm"
    ) {
      return;
    }

    const handleBeforeUnload = (event: BeforeUnloadEvent) => {
      if (
        !collectionLinesEqual(state.lines, state.baseline) ||
        ((state.phase === "amend-edit" ||
          state.phase === "amend-confirm") &&
          state.cancellationReason !== "")
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

  const amendValidation = useMemo(() => {
    if (state.phase !== "amend-edit") {
      return {
        errors: {} as CollectionLineErrors,
        isValid: false,
        proposedTotal: "0.00",
        totalMatches: false,
        reasonError: null as string | null,
      };
    }

    const lineValidation = validateDraftLines(
      state.lines,
      t
    );
    const proposedTotal = calculateCollectionTotal(
      state.lines
    );
    const trimmedReason =
      state.cancellationReason.trim();
    const reasonError =
      trimmedReason.length === 0
        ? t("collection.validation.reasonRequired")
        : trimmedReason.length > 500
          ? t("collection.validation.reasonTooLong")
          : null;

    return {
      ...lineValidation,
      isValid:
        lineValidation.isValid &&
        state.lines.length > 0 &&
        reasonError === null,
      proposedTotal,
      totalMatches: collectionAmountsEqual(
        proposedTotal,
        state.resource.contract.total_amount
      ),
      reasonError,
    };
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

  if (state.phase === "finalize-confirm") {
    return (
      <>
        <CollectionScheduleView
          resource={state.resource}
          actionsDisabled
        />
        <FinalizeDialog
          resource={state.resource}
          error={state.error}
          isProcessing={state.isProcessing}
          onCancel={cancelFinalize}
          onConfirm={() => void confirmFinalize()}
        />
      </>
    );
  }

  if (state.phase === "amend-edit") {
    const dirty =
      !collectionLinesEqual(
        state.lines,
        state.baseline
      ) || state.cancellationReason !== "";
    const canProceed =
      dirty &&
      amendValidation.isValid &&
      amendValidation.totalMatches &&
      !state.isPending;

    return (
      <>
        <div className="space-y-5">
          <div>
            <h3 className="text-lg font-bold text-[var(--text-primary)]">
              {t("collection.amend.editorTitle")}
            </h3>
            <p className="mt-1 text-sm leading-7 text-[var(--text-secondary)]">
              {t("collection.amend.editorDescription")}
            </p>
          </div>

          <div className="rounded-xl border border-[var(--info)]/20 bg-[var(--info-soft)] px-4 py-3 text-sm font-semibold leading-7 text-[var(--info)]">
            {t("collection.amend.replacementNotice")}
          </div>

          <CollectionLineEditor
            lines={state.lines}
            errors={amendValidation.errors}
            disabled={state.isPending}
            onChange={(key, field, value) =>
              updateAmendLine(key, field, value)
            }
            onAdd={addAmendLine}
            onDelete={deleteAmendLine}
            onMove={moveAmendLine}
          />

          <Input
            label={t("collection.amend.reason.label")}
            value={state.cancellationReason}
            error={amendValidation.reasonError}
            maxLength={500}
            disabled={state.isPending}
            onChange={(event) =>
              updateCancellationReason(event.target.value)
            }
          />

          {!amendValidation.totalMatches ? (
            <p className="rounded-xl border border-[var(--warning)]/20 bg-[var(--warning-soft)] px-4 py-3 text-sm font-semibold text-[var(--warning)]">
              {t("collection.amend.totalsMismatch")}
            </p>
          ) : null}

          <FormErrorBanner message={state.error} />

          <div className="flex flex-wrap justify-end gap-3 border-t border-[var(--border)] pt-5">
            <Button
              type="button"
              variant="secondary"
              disabled={state.isPending}
              onClick={requestCancelAmend}
            >
              {t("collection.amend.cancel")}
            </Button>
            <Button
              type="button"
              disabled={!canProceed}
              onClick={enterAmendConfirmation}
            >
              {t("collection.amend.proceed")}
            </Button>
          </div>
        </div>

        <ConfirmationDialog
          isOpen={showDiscardDialog}
          title={t("collection.discard.title")}
          description={t(
            "collection.amend.discardDescription"
          )}
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
          onConfirm={discardAmendChanges}
        />
      </>
    );
  }

  if (state.phase === "amend-confirm") {
    return (
      <>
        <CollectionScheduleView
          resource={state.resource}
          actionsDisabled
        />
        <AmendDialog
          resource={state.resource}
          proposedLines={state.lines}
          proposedTotal={state.proposedTotal}
          cancellationReason={state.cancellationReason}
          error={state.error}
          isProcessing={state.isProcessing}
          onBack={backToAmendEditor}
          onConfirm={() => void confirmAmend()}
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
        onFinalize={enterFinalizeConfirmation}
        onAmend={enterAmendEditor}
        actionsDisabled={state.actionsDisabled}
        history={{
          state: historyState,
          onToggle: toggleHistory,
          onRetry: () => void loadHistory(),
        }}
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

  function toggleHistory(): void {
    if (state.phase !== "read") {
      return;
    }

    if (historyState.status === "valid") {
      setHistoryState({
        ...historyState,
        isVisible: !historyState.isVisible,
      });
      return;
    }

    if (historyState.status !== "loading") {
      void loadHistory();
    }
  }

  async function loadHistory(): Promise<void> {
    if (
      state.phase !== "read" ||
      !token ||
      historyState.status === "loading"
    ) {
      return;
    }

    setHistoryState({
      status: "loading",
      isVisible: true,
      rows: [],
      error: null,
    });

    try {
      const resource = await fetchCollectionSchedule(
        token,
        contract.id,
        { include_history: true }
      );

      setState({ phase: "read", resource });
      setHistoryState({
        status: "valid",
        isVisible: true,
        rows: getCancelledHistory(resource),
        error: null,
      });
    } catch (error) {
      setHistoryState({
        status: "error",
        isVisible: true,
        rows: [],
        error: getCollectionErrorMessage(error, t),
      });
    }
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

  function enterFinalizeConfirmation(): void {
    if (
      state.phase !== "read" ||
      !state.resource.allowed_actions.can_finalize ||
      state.actionsDisabled ||
      state.resource.schedule.derived_state !== "draft" ||
      !collectionAmountsEqual(
        state.resource.schedule.active_total,
        state.resource.contract.total_amount
      )
    ) {
      return;
    }

    setState({
      phase: "finalize-confirm",
      resource: state.resource,
      isProcessing: false,
      error: null,
    });
  }

  function cancelFinalize(): void {
    if (
      state.phase !== "finalize-confirm" ||
      state.isProcessing
    ) {
      return;
    }

    setState({
      phase: "read",
      resource: state.resource,
    });
  }

  async function confirmFinalize(): Promise<void> {
    if (
      state.phase !== "finalize-confirm" ||
      state.isProcessing ||
      !token
    ) {
      return;
    }

    setState({ ...state, isProcessing: true, error: null });

    try {
      const commandResource =
        await finalizeCollectionSchedule(
          token,
          contract.id
        );
      let resource = commandResource;

      try {
        resource = await fetchCollectionSchedule(
          token,
          contract.id
        );
      } catch {
        resource = commandResource;
      }

      setState({ phase: "read", resource });
    } catch (error) {
      if (
        isApiRequestError(error) &&
        error.code ===
          "collection_schedule_integrity_violation"
      ) {
        setState({
          phase: "read",
          resource: state.resource,
          error: getCollectionErrorMessage(error, t),
          actionsDisabled: true,
        });
        return;
      }

      if (
        isApiRequestError(error) &&
        error.status === 403
      ) {
        const message = getCollectionErrorMessage(error, t);

        try {
          const resource = await fetchCollectionSchedule(
            token,
            contract.id
          );
          setState({
            phase: "read",
            resource,
            error: message,
          });
        } catch {
          setState({
            phase: "read",
            resource: state.resource,
            error: message,
            actionsDisabled: true,
          });
        }
        return;
      }

      setState({
        ...state,
        isProcessing: false,
        error: getCollectionErrorMessage(error, t),
      });
    }
  }

  function enterAmendEditor(): void {
    if (
      state.phase !== "read" ||
      !state.resource.allowed_actions.can_amend ||
      state.actionsDisabled ||
      state.resource.schedule.derived_state !==
        "scheduled"
    ) {
      return;
    }

    const lines = cloneCollectionLines(
      state.resource.schedule.active_collections,
      false
    );

    onDirtyChange?.(false);
    setState({
      phase: "amend-edit",
      resource: state.resource,
      lines,
      baseline: lines.map((line) => ({ ...line })),
      generationToken:
        state.resource.schedule.active_collections.map(
          (collection) => collection.id
        ),
      cancellationReason: "",
      isPending: false,
      error: null,
    });
  }

  function updateAmendLine(
    key: string,
    field: "title" | "amount" | "due_date" | "notes",
    value: string
  ): void {
    if (state.phase !== "amend-edit") {
      return;
    }

    const lines = state.lines.map((line) =>
      line._key === key ? { ...line, [field]: value } : line
    );

    notifyAmendDirty(lines, state.cancellationReason);
    setState({ ...state, lines, error: null });
  }

  function addAmendLine(): void {
    if (state.phase !== "amend-edit") {
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

    notifyAmendDirty(lines, state.cancellationReason);
    setState({ ...state, lines, error: null });
  }

  function deleteAmendLine(key: string): void {
    if (state.phase !== "amend-edit") {
      return;
    }

    const lines = state.lines.filter(
      (line) => line._key !== key
    );

    notifyAmendDirty(lines, state.cancellationReason);
    setState({ ...state, lines, error: null });
  }

  function moveAmendLine(
    index: number,
    direction: -1 | 1
  ): void {
    if (state.phase !== "amend-edit") {
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

    notifyAmendDirty(lines, state.cancellationReason);
    setState({ ...state, lines, error: null });
  }

  function updateCancellationReason(value: string): void {
    if (state.phase !== "amend-edit") {
      return;
    }

    notifyAmendDirty(state.lines, value);
    setState({
      ...state,
      cancellationReason: value,
      error: null,
    });
  }

  function notifyAmendDirty(
    lines: CollectionLineInput[],
    reason: string
  ): void {
    if (state.phase !== "amend-edit") {
      return;
    }

    onDirtyChange?.(
      !collectionLinesEqual(lines, state.baseline) ||
        reason !== ""
    );
  }

  function requestCancelAmend(): void {
    if (state.phase !== "amend-edit") {
      return;
    }

    const dirty =
      !collectionLinesEqual(
        state.lines,
        state.baseline
      ) || state.cancellationReason !== "";

    if (!dirty) {
      onDirtyChange?.(false);
      setState({
        phase: "read",
        resource: state.resource,
      });
      return;
    }

    setShowDiscardDialog(true);
  }

  function discardAmendChanges(): void {
    if (state.phase !== "amend-edit") {
      return;
    }

    setShowDiscardDialog(false);
    onDirtyChange?.(false);
    setState({
      phase: "read",
      resource: state.resource,
    });
  }

  function enterAmendConfirmation(): void {
    if (
      state.phase !== "amend-edit" ||
      !amendValidation.isValid ||
      !amendValidation.totalMatches
    ) {
      return;
    }

    setState({
      phase: "amend-confirm",
      resource: state.resource,
      lines: state.lines.map((line) => ({ ...line })),
      baseline: state.baseline.map((line) => ({ ...line })),
      generationToken: [...state.generationToken],
      cancellationReason: state.cancellationReason,
      proposedTotal: amendValidation.proposedTotal,
      isProcessing: false,
      error: null,
    });
  }

  function backToAmendEditor(): void {
    if (
      state.phase !== "amend-confirm" ||
      state.isProcessing
    ) {
      return;
    }

    const dirty =
      !collectionLinesEqual(
        state.lines,
        state.baseline
      ) || state.cancellationReason !== "";

    onDirtyChange?.(dirty);
    setState({
      phase: "amend-edit",
      resource: state.resource,
      lines: state.lines,
      baseline: state.baseline,
      generationToken: state.generationToken,
      cancellationReason: state.cancellationReason,
      isPending: false,
      error: state.error,
    });
  }

  async function confirmAmend(): Promise<void> {
    if (
      state.phase !== "amend-confirm" ||
      state.isProcessing ||
      !token
    ) {
      return;
    }

    const payload: AmendPayload = {
      expected_active_collection_ids: [
        ...state.generationToken,
      ],
      lines: state.lines.map((line) => ({
        sequence: line.sequence,
        title: line.title,
        amount: line.amount,
        due_date: line.due_date,
        notes: line.notes === "" ? null : line.notes,
      })),
      cancellation_reason:
        state.cancellationReason.trim(),
    };

    setState({ ...state, isProcessing: true, error: null });

    try {
      const commandResource =
        await amendCollectionSchedule(
          token,
          contract.id,
          payload
        );
      let resource = commandResource;

      try {
        resource = await fetchCollectionSchedule(
          token,
          contract.id
        );
      } catch {
        resource = commandResource;
      }

      onDirtyChange?.(false);
      setHistoryState(initialHistoryState);
      setState({ phase: "read", resource });
    } catch (error) {
      if (
        isApiRequestError(error) &&
        error.code ===
          "collection_schedule_changed_since_loaded"
      ) {
        const message = getCollectionErrorMessage(error, t);

        onDirtyChange?.(false);
        try {
          const resource = await fetchCollectionSchedule(
            token,
            contract.id
          );
          setState({ phase: "read", resource });
        } catch {
          setState({
            phase: "read",
            resource: state.resource,
            error: message,
            actionsDisabled: true,
          });
        }
        return;
      }

      if (
        isApiRequestError(error) &&
        error.code ===
          "collection_schedule_integrity_violation"
      ) {
        onDirtyChange?.(false);
        setState({
          phase: "read",
          resource: state.resource,
          error: getCollectionErrorMessage(error, t),
          actionsDisabled: true,
        });
        return;
      }

      if (
        isApiRequestError(error) &&
        error.status === 403
      ) {
        const message = getCollectionErrorMessage(error, t);

        onDirtyChange?.(false);
        try {
          const resource = await fetchCollectionSchedule(
            token,
            contract.id
          );
          setState({
            phase: "read",
            resource,
            error: message,
          });
        } catch {
          setState({
            phase: "read",
            resource: state.resource,
            error: message,
            actionsDisabled: true,
          });
        }
        return;
      }

      setState({
        ...state,
        isProcessing: false,
        error: getCollectionErrorMessage(error, t),
      });
    }
  }
}

function getCollectionErrorMessage(
  error: unknown,
  t: (key: import("@/i18n/types").TranslationKey) => string
): string {
  if (!isApiRequestError(error)) {
    return t("collection.genericError");
  }

  const errorKeys: Record<
    string,
    import("@/i18n/types").TranslationKey
  > = {
    contract_not_eligible_for_draft_edit:
      "collection.error.draftNotEligible",
    schedule_not_in_draft_state:
      "collection.error.notDraft",
    contract_not_eligible_for_finalization:
      "collection.error.finalizeNotEligible",
    contract_not_eligible_for_amendment:
      "collection.error.amendNotEligible",
    no_active_schedule_to_amend:
      "collection.error.noActiveSchedule",
    collection_schedule_changed_since_loaded:
      "collection.error.scheduleChanged",
    collection_schedule_integrity_violation:
      "collection.error.integrity",
    invalid_expected_active_collection_ids:
      "collection.error.invalidGeneration",
    schedule_total_mismatch:
      "collection.error.totalMismatch",
    duplicate_collection_sequence:
      "collection.error.duplicateSequence",
    non_decreasing_due_date_violation:
      "collection.error.dueDateOrder",
    blank_collection_title:
      "collection.error.blankTitle",
    invalid_collection_amount:
      "collection.error.invalidAmount",
    excess_decimal_precision:
      "collection.error.decimalPrecision",
    invalid_cancellation_reason:
      "collection.error.invalidReason",
    role_not_authorized:
      "collection.error.notAuthorized",
    service_unavailable:
      "collection.error.serviceUnavailable",
    invalid_query_parameter:
      "collection.error.invalidQuery",
  };
  const key = error.code ? errorKeys[error.code] : undefined;

  return key ? t(key) : error.message;
}

function getCancelledHistory(
  resource: CollectionScheduleResource
): CancelledCollection[] {
  return Array.isArray(resource.schedule.cancelled_history)
    ? resource.schedule.cancelled_history
    : [];
}

function calculateCollectionTotal(
  lines: CollectionLineInput[]
): string {
  let totalCents = 0;

  for (const line of lines) {
    if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(line.amount)) {
      return "0.00";
    }

    const [whole, fraction = ""] = line.amount.split(".");
    totalCents +=
      Number(whole) * 100 +
      Number(fraction.padEnd(2, "0"));
  }

  const whole = Math.floor(totalCents / 100);
  const fraction = String(totalCents % 100).padStart(
    2,
    "0"
  );

  return `${whole}.${fraction}`;
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
