"use client";

import { CalendarClock, Mail, Phone, UserRound, UsersRound } from "lucide-react";
import { useEffect, useMemo, useState, type FormEvent } from "react";

import { leadSources, sourceKey } from "@/components/leads/lead-options";
import { Button } from "@/components/ui/Button";
import { FormShell } from "@/components/ui/FormShell";
import { Input } from "@/components/ui/Input";
import { Modal, ModalFooter, ModalHeader } from "@/components/ui/Modal";
import { Select } from "@/components/ui/Select";
import { useFormValidation } from "@/hooks/useFormValidation";
import { useTranslation } from "@/hooks/useTranslation";
import { normalizeRequiredSaudiMobile, SaudiMobileError } from "@/lib/phone";
import { fetchUnits } from "@/services/units";
import type { CreateLeadPayload, Lead, LeadSource, UpdateLeadPayload } from "@/types/lead";
import type { Project } from "@/types/project";
import type { TenantUser } from "@/types/tenant-user";
import type { Unit } from "@/types/unit";

type LeadFormDialogProps = {
  isOpen: boolean;
  lead: Lead | null;
  token: string;
  projects: Project[];
  assignees: TenantUser[];
  canChooseAssignee: boolean;
  isSubmitting: boolean;
  onClose: () => void;
  onSubmit: (payload: CreateLeadPayload | UpdateLeadPayload) => Promise<void>;
};

type LeadFormState = {
  name: string;
  phone: string;
  email: string;
  source: LeadSource | "";
  sourceDetail: string;
  projectId: string;
  unitId: string;
  assignedTo: string;
  nextFollowUpAt: string;
};

const emptyForm: LeadFormState = {
  name: "",
  phone: "",
  email: "",
  source: "",
  sourceDetail: "",
  projectId: "",
  unitId: "",
  assignedTo: "",
  nextFollowUpAt: "",
};

function toLocalDateTime(value: string | null): string {
  if (!value) {
    return "";
  }

  const date = new Date(value);
  const offset = date.getTimezoneOffset() * 60_000;

  return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function createForm(lead: Lead | null): LeadFormState {
  if (!lead) {
    return { ...emptyForm };
  }

  return {
    name: lead.name,
    phone: lead.phone,
    email: lead.email ?? "",
    source: lead.source,
    sourceDetail: lead.source_detail ?? "",
    projectId: lead.project_id ?? "",
    unitId: lead.unit_id ?? "",
    assignedTo: lead.assigned_to ? String(lead.assigned_to.id) : "",
    nextFollowUpAt: toLocalDateTime(lead.next_follow_up_at),
  };
}

export function LeadFormDialog({
  isOpen,
  lead,
  token,
  projects,
  assignees,
  canChooseAssignee,
  isSubmitting,
  onClose,
  onSubmit,
}: LeadFormDialogProps) {
  const { t } = useTranslation();
  const [form, setForm] = useState<LeadFormState>(() => createForm(lead));
  const [units, setUnits] = useState<Unit[]>([]);
  const [unitsLoading, setUnitsLoading] = useState(false);
  const [projectNotice, setProjectNotice] = useState<string | null>(null);
  const {
    formRef,
    fieldErrors,
    formError,
    clearValidation,
    setClientFieldErrors,
    setValidationError,
  } = useFormValidation();

  useEffect(() => {
    if (!isOpen || !form.projectId) {
      return;
    }

    let active = true;
    queueMicrotask(() => {
      if (active) {
        setUnitsLoading(true);
      }
    });

    void fetchUnits(token, {
      page: 1,
      per_page: 100,
      project_id: form.projectId,
    })
      .then((response) => {
        if (active) {
          setUnits(
            response.data.units.data.filter((unit) => unit.archived_at === null)
          );
        }
      })
      .catch(() => {
        if (active) {
          setUnits([]);
        }
      })
      .finally(() => {
        if (active) {
          setUnitsLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [form.projectId, isOpen, token]);

  const projectOptions = useMemo(
    () => [
      { value: "", label: t("crm.form.selectProject") },
      ...projects
        .filter((project) => project.archived_at === null)
        .map((project) => ({ value: project.id, label: project.name })),
    ],
    [projects, t]
  );

  const unitOptions = useMemo(
    () => [
      {
        value: "",
        label: unitsLoading ? t("common.loading") : t("crm.form.selectUnit"),
      },
      ...units.map((unit) => ({
        value: unit.id,
        label: unit.unit_number,
      })),
    ],
    [t, units, unitsLoading]
  );

  const assigneeOptions = useMemo(
    () => [
      { value: "", label: t("crm.form.selectAssignee") },
      ...assignees.map((membership) => ({
        value: String(membership.user.id),
        label: membership.user.name,
      })),
    ],
    [assignees, t]
  );

  if (!isOpen) {
    return null;
  }

  const update = <Key extends keyof LeadFormState>(
    key: Key,
    value: LeadFormState[Key]
  ): void => {
    clearValidation();
    setForm((current) => ({ ...current, [key]: value }));
  };

  const changeProject = (projectId: string): void => {
    const clearedUnit = form.unitId !== "";
    clearValidation();
    setForm((current) => ({
      ...current,
      projectId,
      unitId: "",
    }));
    setUnits([]);
    setProjectNotice(clearedUnit ? t("crm.form.projectClearedUnit") : null);
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
    event.preventDefault();
    clearValidation();

    const errors: Record<string, string[]> = {};
    if (!form.name.trim()) {
      errors.name = [t("crm.form.required")];
    }
    if (!form.source) {
      errors.source = [t("crm.form.required")];
    }
    if (form.source === "other" && !form.sourceDetail.trim()) {
      errors.source_detail = [t("crm.form.sourceDetailRequired")];
    }

    let phone = form.phone;
    try {
      phone = normalizeRequiredSaudiMobile(form.phone);
    } catch (error) {
      if (error instanceof SaudiMobileError) {
        errors.phone = [t("crm.form.phoneInvalid")];
      }
    }

    if (Object.keys(errors).length > 0) {
      setClientFieldErrors(errors);
      return;
    }

    const commonPayload = {
      name: form.name.trim(),
      phone,
      email: form.email.trim() || null,
      source: form.source as LeadSource,
      source_detail:
        form.source === "other" ? form.sourceDetail.trim() : null,
      project_id: form.projectId || null,
      unit_id: form.unitId || null,
      next_follow_up_at: form.nextFollowUpAt
        ? new Date(form.nextFollowUpAt).toISOString()
        : null,
    };

    const payload: CreateLeadPayload | UpdateLeadPayload = lead
      ? commonPayload
      : {
          ...commonPayload,
          ...(canChooseAssignee
            ? {
                assigned_to: form.assignedTo
                  ? Number(form.assignedTo)
                  : null,
              }
            : {}),
        };

    try {
      await onSubmit(payload);
    } catch (error) {
      setValidationError(error, t("crm.genericError"));
    }
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      closeLabel={t("crm.form.close")}
      maxWidthClassName="max-w-4xl"
      className="flex max-h-[94vh] flex-col"
    >
      <ModalHeader
        icon={
          <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
            <UsersRound size={21} aria-hidden="true" />
          </span>
        }
        title={lead ? t("crm.form.editTitle") : t("crm.form.createTitle")}
        description={t("crm.form.description")}
        closeLabel={t("crm.form.close")}
        onClose={onClose}
      />

      <FormShell
        formRef={formRef}
        error={formError}
        onSubmit={handleSubmit}
        footer={
          <ModalFooter>
            <Button
              type="button"
              variant="secondary"
              disabled={isSubmitting}
              onClick={onClose}
            >
              {t("crm.form.cancel")}
            </Button>
            <Button type="submit" isLoading={isSubmitting}>
              {isSubmitting ? t("crm.form.saving") : t("crm.form.save")}
            </Button>
          </ModalFooter>
        }
      >
        <div className="grid gap-5 md:grid-cols-2">
          <Input
            autoFocus
            name="name"
            label={t("crm.form.name")}
            value={form.name}
            onChange={(event) => update("name", event.target.value)}
            leading={<UserRound size={17} />}
            error={fieldErrors.name?.[0]}
            required
          />
          <Input
            name="phone"
            label={t("crm.form.phone")}
            value={form.phone}
            onChange={(event) => update("phone", event.target.value)}
            onBlur={() => {
              try {
                update("phone", normalizeRequiredSaudiMobile(form.phone));
              } catch {
                // Preserve invalid input for correction and backend parity.
              }
            }}
            inputMode="tel"
            dir="ltr"
            leading={<Phone size={17} />}
            error={fieldErrors.phone?.[0]}
            required
          />
          <Input
            name="email"
            type="email"
            label={t("crm.form.email")}
            value={form.email}
            onChange={(event) => update("email", event.target.value)}
            leading={<Mail size={17} />}
            error={fieldErrors.email?.[0]}
          />
          <Select
            name="source"
            label={t("crm.form.source")}
            value={form.source}
            onChange={(event) => {
              const source = event.target.value as LeadSource | "";
              update("source", source);
              if (source !== "other") {
                update("sourceDetail", "");
              }
            }}
            options={[
              { value: "", label: t("crm.form.selectSource") },
              ...leadSources.map((source) => ({
                value: source,
                label: t(sourceKey(source)),
              })),
            ]}
            error={fieldErrors.source?.[0]}
            required
          />
        </div>

        {form.source === "other" ? (
          <Input
            name="source_detail"
            label={t("crm.form.sourceDetail")}
            value={form.sourceDetail}
            onChange={(event) => update("sourceDetail", event.target.value)}
            error={fieldErrors.source_detail?.[0]}
            required
          />
        ) : null}

        <div className="grid gap-5 md:grid-cols-2">
          <Select
            name="project_id"
            label={t("crm.form.project")}
            value={form.projectId}
            onChange={(event) => changeProject(event.target.value)}
            options={projectOptions}
            error={fieldErrors.project_id?.[0]}
          />
          <Select
            name="unit_id"
            label={t("crm.form.unit")}
            value={form.unitId}
            onChange={(event) => update("unitId", event.target.value)}
            options={unitOptions}
            disabled={!form.projectId || unitsLoading}
            error={fieldErrors.unit_id?.[0]}
          />
        </div>

        {projectNotice ? (
          <p role="status" className="text-xs font-semibold text-[var(--info)]">
            {projectNotice}
          </p>
        ) : null}

        <div className="grid gap-5 md:grid-cols-2">
          {!lead && canChooseAssignee ? (
            <Select
              name="assigned_to"
              label={t("crm.form.assignee")}
              value={form.assignedTo}
              onChange={(event) => update("assignedTo", event.target.value)}
              options={assigneeOptions}
              error={fieldErrors.assigned_to?.[0]}
            />
          ) : null}
          <Input
            name="next_follow_up_at"
            type="datetime-local"
            label={t("crm.form.followUp")}
            value={form.nextFollowUpAt}
            onChange={(event) => update("nextFollowUpAt", event.target.value)}
            leading={<CalendarClock size={17} />}
            error={fieldErrors.next_follow_up_at?.[0]}
          />
        </div>
      </FormShell>
    </Modal>
  );
}
