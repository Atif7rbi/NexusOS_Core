"use client";

import { CalendarClock, CircleCheckBig, RotateCcw, XCircle } from "lucide-react";
import { useMemo, useState } from "react";

import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { useTranslation } from "@/hooks/useTranslation";
import type { Lead, NextActionType } from "@/types/lead";

export type LeadFollowUpDialogAction =
  | "schedule_follow_up"
  | "reschedule_follow_up"
  | "complete_follow_up"
  | "cancel_follow_up";

export type LeadFollowUpDialogPayload =
  | {
      action: "schedule_follow_up" | "reschedule_follow_up";
      nextFollowUpAt: string;
      nextActionType: NextActionType;
      nextActionNote: string | null;
    }
  | {
      action: "complete_follow_up" | "cancel_follow_up";
      note: string | null;
    };

type LeadFollowUpDialogProps = {
  action: LeadFollowUpDialogAction;
  lead: Lead;
  isProcessing: boolean;
  error: string | null;
  onClose: () => void;
  onConfirm: (payload: LeadFollowUpDialogPayload) => void;
};

const nextActionTypes: NextActionType[] = [
  "call",
  "whatsapp",
  "meeting",
  "site_visit",
  "send_offer",
  "waiting_response",
  "other",
];

const RIYADH_TIME_ZONE = "Asia/Riyadh";
const RIYADH_UTC_OFFSET = "+03:00";

function toRiyadhLocalDateTime(value: string | null): string {
  if (!value) {
    return "";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "";
  }

  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: RIYADH_TIME_ZONE,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(date);

  const values = Object.fromEntries(
    parts.map((part) => [part.type, part.value])
  );

  return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}`;
}

function parseRiyadhLocalDateTime(value: string): Date | null {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) {
    return null;
  }

  const parsed = new Date(`${value}:00${RIYADH_UTC_OFFSET}`);

  return Number.isNaN(parsed.getTime()) ? null : parsed;
}

export function LeadFollowUpDialog({
  action,
  lead,
  isProcessing,
  error,
  onClose,
  onConfirm,
}: LeadFollowUpDialogProps) {
  const { t } = useTranslation();
  const isWrite = action === "schedule_follow_up" || action === "reschedule_follow_up";
  const [dateTime, setDateTime] = useState(() =>
    toRiyadhLocalDateTime(lead.next_follow_up_at)
  );
  const [actionType, setActionType] = useState<NextActionType>(
    lead.next_action_type ?? "call"
  );
  const [note, setNote] = useState(lead.next_action_note ?? "");
  const [localError, setLocalError] = useState<string | null>(null);

  const config = useMemo(() => {
    switch (action) {
      case "schedule_follow_up":
        return {
          title: t("crm.followUp.scheduleTitle"),
          description: t("crm.followUp.scheduleDescription"),
          confirmLabel: t("crm.followUp.schedule"),
          icon: CalendarClock,
          danger: false,
        };
      case "reschedule_follow_up":
        return {
          title: t("crm.followUp.rescheduleTitle"),
          description: t("crm.followUp.rescheduleDescription"),
          confirmLabel: t("crm.followUp.reschedule"),
          icon: RotateCcw,
          danger: false,
        };
      case "complete_follow_up":
        return {
          title: t("crm.followUp.completeTitle"),
          description: t("crm.followUp.completeDescription"),
          confirmLabel: t("crm.followUp.complete"),
          icon: CircleCheckBig,
          danger: false,
        };
      case "cancel_follow_up":
        return {
          title: t("crm.followUp.cancelTitle"),
          description: t("crm.followUp.cancelDescription"),
          confirmLabel: t("crm.followUp.cancel"),
          icon: XCircle,
          danger: true,
        };
    }
  }, [action, t]);

  const Icon = config.icon;

  return (
    <ConfirmationDialog
      isOpen
      title={config.title}
      description={config.description}
      confirmLabel={config.confirmLabel}
      confirmVariant={config.danger ? "danger" : undefined}
      cancelLabel={t("crm.dialog.cancel")}
      closeLabel={t("crm.dialog.close")}
      processingLabel={t("crm.actions.processing")}
      isProcessing={isProcessing}
      error={localError ?? error}
      icon={
        <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--info-soft)] text-[var(--info)]">
          <Icon size={21} aria-hidden="true" />
        </span>
      }
      onCancel={onClose}
      onConfirm={() => {
        setLocalError(null);

        if (isWrite) {
          if (!dateTime) {
            setLocalError(t("crm.followUp.dateRequired"));
            return;
          }
          if (actionType === "other" && !note.trim()) {
            setLocalError(t("crm.followUp.otherNoteRequired"));
            return;
          }

          const parsed = parseRiyadhLocalDateTime(dateTime);
          if (!parsed) {
            setLocalError(t("crm.followUp.dateInvalid"));
            return;
          }

          onConfirm({
            action,
            nextFollowUpAt: parsed.toISOString(),
            nextActionType: actionType,
            nextActionNote: note.trim() || null,
          });
          return;
        }

        onConfirm({ action, note: note.trim() || null });
      }}
    >
      <div className="space-y-4">
        {isWrite ? (
          <>
            <Input
              type="datetime-local"
              name="next_follow_up_at"
              label={t("crm.followUp.date")}
              value={dateTime}
              onChange={(event) => {
                setDateTime(event.target.value);
                setLocalError(null);
              }}
            />
            <Select
              name="next_action_type"
              label={t("crm.followUp.actionType")}
              value={actionType}
              onChange={(event) => {
                setActionType(event.target.value as NextActionType);
                setLocalError(null);
              }}
              options={nextActionTypes.map((value) => ({
                value,
                label: t(`crm.nextAction.${value}`),
              }))}
            />
          </>
        ) : null}

        <label className="block space-y-2">
          <span className="block text-sm font-semibold text-[var(--text-secondary)]">
            {isWrite ? t("crm.followUp.actionNote") : t("crm.followUp.closeNote")}
          </span>
          <textarea
            name="note"
            rows={4}
            maxLength={2000}
            value={note}
            onChange={(event) => {
              setNote(event.target.value);
              setLocalError(null);
            }}
            className="w-full resize-y rounded-xl border border-[var(--border)] bg-[var(--surface)] px-4 py-3 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-primary)] focus:ring-4 focus:ring-[var(--focus-ring)]"
          />
        </label>
      </div>
    </ConfirmationDialog>
  );
}
