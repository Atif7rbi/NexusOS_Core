"use client";

import { Archive, ArrowRightLeft, MessageSquareText, RotateCcw, Route } from "lucide-react";
import { useState, type FormEvent } from "react";

import {
  activityTypeKey,
  archiveReasonKey,
  lostReasonKey,
  stageKey,
} from "@/components/leads/lead-options";
import { Button } from "@/components/ui/Button";
import { ListErrorState, ListLoadingState } from "@/components/ui/crud/ListState";
import { useTranslation } from "@/hooks/useTranslation";
import { formatDateTime } from "@/lib/date-format";
import type {
  LeadActivity,
  LeadActivityType,
  LeadArchiveReason,
  LeadLostReason,
  LeadStage,
} from "@/types/lead";

type LeadActivityTimelineProps = {
  activities: LeadActivity[];
  isLoading: boolean;
  error: string | null;
  canAddNote: boolean;
  isAddingNote: boolean;
  onRetry: () => void;
  onAddNote: (body: string) => Promise<void>;
};

const activityIcons: Record<LeadActivityType, typeof MessageSquareText> = {
  note: MessageSquareText,
  stage_change: Route,
  assignment: ArrowRightLeft,
  archive: Archive,
  restore: RotateCcw,
};

export function LeadActivityTimeline({
  activities,
  isLoading,
  error,
  canAddNote,
  isAddingNote,
  onRetry,
  onAddNote,
}: LeadActivityTimelineProps) {
  const { t } = useTranslation();
  const [note, setNote] = useState("");
  const [noteError, setNoteError] = useState<string | null>(null);

  const submitNote = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    const body = note.trim();

    if (!body) {
      setNoteError(t("crm.form.required"));
      return;
    }

    setNoteError(null);
    try {
      await onAddNote(body);
      setNote("");
    } catch (caughtError) {
      setNoteError(
        caughtError instanceof Error ? caughtError.message : t("crm.genericError")
      );
    }
  };

  return (
    <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)] sm:p-6">
      <div className="flex items-center gap-2">
        <MessageSquareText size={18} className="text-[var(--brand-gold-strong)]" />
        <h2 className="font-bold text-[var(--text-primary)]">{t("crm.activity.title")}</h2>
      </div>

      {canAddNote ? (
        <form onSubmit={submitNote} className="mt-5 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] p-4">
          <label htmlFor="lead-note" className="sr-only">
            {t("crm.activity.notePlaceholder")}
          </label>
          <textarea
            id="lead-note"
            name="body"
            rows={3}
            value={note}
            onChange={(event) => {
              setNote(event.target.value);
              setNoteError(null);
            }}
            placeholder={t("crm.activity.notePlaceholder")}
            className="w-full resize-y rounded-xl border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-primary)] focus:ring-4 focus:ring-[var(--focus-ring)]"
            aria-invalid={noteError ? true : undefined}
          />
          <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
            {noteError ? (
              <p role="alert" className="text-xs font-semibold text-[var(--danger)]">
                {noteError}
              </p>
            ) : (
              <span />
            )}
            <Button type="submit" size="sm" isLoading={isAddingNote}>
              {isAddingNote ? t("crm.activity.addingNote") : t("crm.activity.addNote")}
            </Button>
          </div>
        </form>
      ) : null}

      <div className="mt-6">
        {isLoading ? (
          <ListLoadingState label={t("crm.activity.loading")} />
        ) : error ? (
          <ListErrorState
            message={error}
            action={
              <Button type="button" size="sm" variant="secondary" onClick={onRetry}>
                {t("crm.retry")}
              </Button>
            }
          />
        ) : activities.length === 0 ? (
          <p className="rounded-xl bg-[var(--surface-soft)] px-4 py-8 text-center text-sm text-[var(--text-secondary)]">
            {t("crm.activity.empty")}
          </p>
        ) : (
          <ol className="space-y-0">
            {activities.map((activity, index) => {
              const Icon = activityIcons[activity.type];
              return (
                <li key={activity.id} className="relative flex gap-4 pb-6 last:pb-0">
                  {index < activities.length - 1 ? (
                    <span className="absolute start-[18px] top-9 h-[calc(100%-20px)] w-px bg-[var(--border)]" />
                  ) : null}
                  <span className="relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-[var(--border)] bg-[var(--surface-soft)] text-[var(--brand-primary)]">
                    <Icon size={16} aria-hidden="true" />
                  </span>
                  <div className="min-w-0 flex-1 pt-0.5">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <p className="text-sm font-bold text-[var(--text-primary)]">
                        {t(activityTypeKey(activity.type))}
                      </p>
                      <time className="text-xs text-[var(--text-muted)]">
                        {formatDateTime(activity.occurred_at)}
                      </time>
                    </div>
                    {activity.body ? (
                      <p className="mt-2 whitespace-pre-wrap text-sm leading-7 text-[var(--text-secondary)]">
                        {activity.body}
                      </p>
                    ) : (
                      <ActivityPayloadSummary activity={activity} />
                    )}
                    {activity.created_by ? (
                      <p className="mt-2 text-xs text-[var(--text-muted)]">
                        {t("crm.activity.by")} {activity.created_by.name}
                      </p>
                    ) : null}
                  </div>
                </li>
              );
            })}
          </ol>
        )}
      </div>
    </section>
  );
}

function ActivityPayloadSummary({ activity }: { activity: LeadActivity }) {
  const { t } = useTranslation();
  const payload = activity.payload;

  if (!payload) {
    return null;
  }

  if (activity.type === "stage_change") {
    const from = payload.from_stage as LeadStage | undefined;
    const to = payload.to_stage as LeadStage | undefined;
    const reason = payload.lost_reason as LeadLostReason | undefined;

    return (
      <p className="mt-2 text-sm text-[var(--text-secondary)]">
        {from && to ? `${t(stageKey(from))} → ${t(stageKey(to))}` : null}
        {reason ? ` · ${t(lostReasonKey(reason))}` : null}
      </p>
    );
  }

  if (activity.type === "archive") {
    const reason = payload.archive_reason as LeadArchiveReason | undefined;
    return reason ? (
      <p className="mt-2 text-sm text-[var(--text-secondary)]">
        {t(archiveReasonKey(reason))}
      </p>
    ) : null;
  }

  return null;
}
