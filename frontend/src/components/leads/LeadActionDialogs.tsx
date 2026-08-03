"use client";

import { Archive, ArrowLeftRight, RotateCcw, Route, UserCheck, UserRoundCog, UserX } from "lucide-react";
import { useMemo, useState } from "react";

import {
  archiveReasonKey,
  leadArchiveReasons,
  leadLostReasons,
  lostReasonKey,
  openLeadStages,
  stageKey,
  stageRanks,
} from "@/components/leads/lead-options";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import { Input } from "@/components/ui/Input";
import { Select } from "@/components/ui/Select";
import { useTranslation } from "@/hooks/useTranslation";
import type {
  Lead,
  LeadArchiveReason,
  LeadLostReason,
  OpenLeadStage,
} from "@/types/lead";
import type { TranslationKey } from "@/i18n/types";
import type { TenantUser } from "@/types/tenant-user";

export type LeadDialogAction =
  | "claim"
  | "assign"
  | "stage"
  | "lost"
  | "reopen"
  | "archive"
  | "restore"
  | "convert";

export type LeadDialogPayload =
  | { action: "claim" | "reopen" | "restore" }
  | { action: "assign"; assignedTo: number | null }
  | { action: "stage"; stage: OpenLeadStage }
  | {
      action: "lost";
      reason: LeadLostReason;
      detail: string | null;
    }
  | {
      action: "archive";
      reason: LeadArchiveReason;
      detail: string | null;
    }
  | {
      action: "convert";
      intent: "create_new";
      type: "individual" | "company";
      category: "investor" | "buyer" | "broker" | "owner" | "other";
    }
  | {
      action: "convert";
      intent: "link_existing";
      existingCustomerId: string;
    };

type LeadActionDialogsProps = {
  action: LeadDialogAction | null;
  lead: Lead;
  assignees: TenantUser[];
  isProcessing: boolean;
  error: string | null;
  onClose: () => void;
  onConfirm: (payload: LeadDialogPayload) => void;
};

export function LeadActionDialogs({
  action,
  lead,
  assignees,
  isProcessing,
  error,
  onClose,
  onConfirm,
}: LeadActionDialogsProps) {
  const { t } = useTranslation();
  const stageOptions = useMemo(() => {
    const currentRank = lead.stage in stageRanks
      ? stageRanks[lead.stage as OpenLeadStage]
      : -1;

    return openLeadStages.filter((candidate) => {
      if (candidate === lead.stage) {
        return false;
      }

      return lead.allowed_actions.can_move_backward || stageRanks[candidate] > currentRank;
    });
  }, [lead.allowed_actions.can_move_backward, lead.stage]);
  const [assignedTo, setAssignedTo] = useState(
    lead.assigned_to ? String(lead.assigned_to.id) : ""
  );
  const [stage, setStage] = useState<OpenLeadStage>(stageOptions[0] ?? "qualified");
  const [lostReason, setLostReason] = useState<LeadLostReason>("not_ready");
  const [lostDetail, setLostDetail] = useState("");
  const [archiveReason, setArchiveReason] = useState<LeadArchiveReason>("duplicate");
  const [archiveDetail, setArchiveDetail] = useState("");
  const [localError, setLocalError] = useState<string | null>(null);

  if (!action) {
    return null;
  }

  const base = {
    isOpen: true,
    error: localError ?? error,
    isProcessing,
    closeLabel: t("crm.dialog.close"),
    cancelLabel: t("crm.dialog.cancel"),
    processingLabel: t("crm.actions.processing"),
    onCancel: onClose,
  };

  if (action === "claim") {
    return (
      <ConfirmationDialog
        {...base}
        title={t("crm.dialog.claimTitle")}
        description={t("crm.dialog.claimDescription")}
        confirmLabel={t("crm.actions.claim")}
        icon={<DialogIcon icon={UserCheck} tone="info" />}
        onConfirm={() => onConfirm({ action: "claim" })}
      />
    );
  }

  if (action === "assign") {
    return (
      <ConfirmationDialog
        {...base}
        title={t("crm.dialog.assignTitle")}
        description={t("crm.dialog.assignDescription")}
        confirmLabel={t("crm.actions.assign")}
        icon={<DialogIcon icon={UserRoundCog} tone="info" />}
        onConfirm={() =>
          onConfirm({
            action: "assign",
            assignedTo: assignedTo ? Number(assignedTo) : null,
          })
        }
      >
        <Select
          name="assigned_to"
          label={t("crm.form.assignee")}
          value={assignedTo}
          onChange={(event) => setAssignedTo(event.target.value)}
          options={[
            { value: "", label: t("crm.dialog.unassign") },
            ...assignees.map((membership) => ({
              value: String(membership.user.id),
              label: membership.user.name,
            })),
          ]}
        />
      </ConfirmationDialog>
    );
  }

  if (action === "stage") {
    return (
      <ConfirmationDialog
        {...base}
        title={t("crm.dialog.stageTitle")}
        description={t("crm.dialog.stageDescription")}
        confirmLabel={t("crm.actions.changeStage")}
        icon={<DialogIcon icon={Route} tone="info" />}
        onConfirm={() => onConfirm({ action: "stage", stage })}
      >
        <Select
          name="stage"
          label={t("crm.table.stage")}
          value={stage}
          onChange={(event) => setStage(event.target.value as OpenLeadStage)}
          options={stageOptions.map((candidate) => ({
            value: candidate,
            label: t(stageKey(candidate)),
          }))}
        />
      </ConfirmationDialog>
    );
  }

  if (action === "lost") {
    return (
      <ConfirmationDialog
        {...base}
        title={t("crm.dialog.lostTitle")}
        description={t("crm.dialog.lostDescription")}
        confirmLabel={t("crm.actions.moveToLost")}
        confirmVariant="danger"
        icon={<DialogIcon icon={UserX} tone="danger" />}
        onConfirm={() => {
          if (lostReason === "other" && !lostDetail.trim()) {
            setLocalError(t("crm.form.required"));
            return;
          }
          onConfirm({
            action: "lost",
            reason: lostReason,
            detail: lostReason === "other" ? lostDetail.trim() : null,
          });
        }}
      >
        <div className="space-y-4">
          <Select
            name="lost_reason"
            label={t("crm.dialog.lostReason")}
            value={lostReason}
            onChange={(event) => {
              setLostReason(event.target.value as LeadLostReason);
              setLocalError(null);
            }}
            options={leadLostReasons.map((reason) => ({
              value: reason,
              label: t(lostReasonKey(reason)),
            }))}
          />
          {lostReason === "other" ? (
            <Input
              name="lost_reason_detail"
              label={t("crm.dialog.lostReasonDetail")}
              value={lostDetail}
              onChange={(event) => {
                setLostDetail(event.target.value);
                setLocalError(null);
              }}
            />
          ) : null}
        </div>
      </ConfirmationDialog>
    );
  }

  if (action === "reopen") {
    return (
      <ConfirmationDialog
        {...base}
        title={t("crm.dialog.reopenTitle")}
        description={t("crm.dialog.reopenDescription")}
        confirmLabel={t("crm.actions.reopen")}
        icon={<DialogIcon icon={RotateCcw} tone="info" />}
        onConfirm={() => onConfirm({ action: "reopen" })}
      />
    );
  }

  if (action === "archive") {
    return (
      <ConfirmationDialog
        {...base}
        title={t("crm.dialog.archiveTitle")}
        description={t("crm.dialog.archiveDescription")}
        confirmLabel={t("crm.actions.archive")}
        confirmVariant="danger"
        icon={<DialogIcon icon={Archive} tone="danger" />}
        onConfirm={() => {
          if (archiveReason === "other" && !archiveDetail.trim()) {
            setLocalError(t("crm.form.required"));
            return;
          }
          onConfirm({
            action: "archive",
            reason: archiveReason,
            detail: archiveReason === "other" ? archiveDetail.trim() : null,
          });
        }}
      >
        <div className="space-y-4">
          <Select
            name="archive_reason"
            label={t("crm.dialog.archiveReason")}
            value={archiveReason}
            onChange={(event) => {
              setArchiveReason(event.target.value as LeadArchiveReason);
              setLocalError(null);
            }}
            options={leadArchiveReasons.map((reason) => ({
              value: reason,
              label: t(archiveReasonKey(reason)),
            }))}
          />
          {archiveReason === "other" ? (
            <Input
              name="archive_reason_detail"
              label={t("crm.dialog.archiveReasonDetail")}
              value={archiveDetail}
              onChange={(event) => {
                setArchiveDetail(event.target.value);
                setLocalError(null);
              }}
            />
          ) : null}
        </div>
      </ConfirmationDialog>
    );
  }

  if (action === "convert") {
    return (
      <ConvertLeadDialog
        lead={lead}
        base={base}
        onConfirm={onConfirm}
        t={t}
      />
    );
  }

  return (
    <ConfirmationDialog
      {...base}
      title={t("crm.dialog.restoreTitle")}
      description={t("crm.dialog.restoreDescription")}
      confirmLabel={t("crm.actions.restore")}
      icon={<DialogIcon icon={RotateCcw} tone="info" />}
      onConfirm={() => onConfirm({ action: "restore" })}
    />
  );
}

function ConvertLeadDialog({
  lead,
  base,
  onConfirm,
  t,
}: {
  lead: Lead;
  base: Record<string, unknown>;
  onConfirm: (payload: LeadDialogPayload) => void;
  t: (key: TranslationKey) => string;
}) {
  const [type, setType] = useState<"individual" | "company">("individual");
  const [category, setCategory] = useState<
    "investor" | "buyer" | "broker" | "owner" | "other"
  >("buyer");

  return (
    <ConfirmationDialog
      {...(base as Parameters<typeof ConfirmationDialog>[0])}
      title={t("crm.dialog.convertTitle")}
      description={t("crm.dialog.convertDescription")}
      confirmLabel={t("crm.actions.convert")}
      icon={<DialogIcon icon={ArrowLeftRight} tone="info" />}
      onConfirm={() =>
        onConfirm({
          action: "convert",
          intent: "create_new",
          type,
          category,
        })
      }
    >
      <div className="space-y-4">
        <p className="text-sm text-[var(--text-secondary)]">
          {t("crm.dialog.convertLeadInfo")}
          <span className="font-semibold text-[var(--text-primary)]">
            {" "}{lead.name}
          </span>
        </p>
        <Select
          name="customer_type"
          label={t("crm.form.customerType")}
          value={type}
          onChange={(e) => setType(e.target.value as "individual" | "company")}
          options={[
            { value: "individual", label: t("crm.customerType.individual") },
            { value: "company", label: t("crm.customerType.company") },
          ]}
        />
        <Select
          name="customer_category"
          label={t("crm.form.customerCategory")}
          value={category}
          onChange={(e) =>
            setCategory(
              e.target.value as
                | "investor"
                | "buyer"
                | "broker"
                | "owner"
                | "other"
            )
          }
          options={[
            { value: "buyer", label: t("crm.customerCategory.buyer") },
            { value: "investor", label: t("crm.customerCategory.investor") },
            { value: "broker", label: t("crm.customerCategory.broker") },
            { value: "owner", label: t("crm.customerCategory.owner") },
            { value: "other", label: t("crm.customerCategory.other") },
          ]}
        />
      </div>
    </ConfirmationDialog>
  );
}

function DialogIcon({
  icon: Icon,
  tone,
}: {
  icon: typeof Archive;
  tone: "info" | "danger";
}) {
  return (
    <span
      className={[
        "flex h-11 w-11 items-center justify-center rounded-2xl",
        tone === "danger"
          ? "bg-[var(--danger-soft)] text-[var(--danger)]"
          : "bg-[var(--info-soft)] text-[var(--info)]",
      ].join(" ")}
    >
      <Icon size={21} aria-hidden="true" />
    </span>
  );
}
