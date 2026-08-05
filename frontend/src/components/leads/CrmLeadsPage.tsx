"use client";

import Link from "next/link";
import { useSearchParams } from "next/navigation";
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import {
  LeadActionDialogs,
  type LeadDialogAction,
  type LeadDialogPayload,
} from "@/components/leads/LeadActionDialogs";
import { LeadDetailsView } from "@/components/leads/LeadDetailsView";
import { LeadDuplicateDialog } from "@/components/leads/LeadDuplicateDialog";
import { LeadFormDialog } from "@/components/leads/LeadFormDialog";
import {
  LeadFollowUpDialog,
  type LeadFollowUpDialogAction,
  type LeadFollowUpDialogPayload,
} from "@/components/leads/LeadFollowUpDialog";
import { LeadsIndexView } from "@/components/leads/LeadsIndexView";
import {
  leadSources,
  leadStages,
} from "@/components/leads/lead-options";
import { AppShell } from "@/components/layout/AppShell";
import { CrudPageLayout } from "@/components/ui/crud/CrudPageLayout";
import { useTranslation } from "@/hooks/useTranslation";
import { isApiRequestError } from "@/lib/api-error";
import { useAuth } from "@/providers/AuthProvider";
import {
  addLeadNote,
  archiveLead,
  assignLead,
  cancelLeadFollowUp,
  claimLead,
  completeLeadFollowUp,
  convertLead,
  createLead,
  fetchLead,
  fetchLeadActivities,
  fetchLeads,
  LeadConversionConflictError,
  LeadDuplicateError,
  moveLeadStage,
  moveLeadToLost,
  reopenLead,
  rescheduleLeadFollowUp,
  restoreLead,
  scheduleLeadFollowUp,
  updateLead,
} from "@/services/leads";
import { fetchProjects } from "@/services/projects";
import { fetchTenantUsers } from "@/services/users";
import type {
  CreateLeadPayload,
  Lead,
  LeadActivity,
  LeadConversionConflict,
  LeadDuplicateMatch,
  FollowUpBucket,
  LeadSource,
  LeadStage,
  LeadsIndexQuery,
  LeadsIndexResponse,
  UpdateLeadPayload,
} from "@/types/lead";
import type { Project } from "@/types/project";
import type { TenantUser } from "@/types/tenant-user";

const crmRoles = ["administrator", "sales", "employee"];

const followUpBuckets: FollowUpBucket[] = [
  "overdue",
  "today",
  "tomorrow",
  "this_week",
  "unscheduled",
];

function positiveInteger(value: string | null, fallback: number): number {
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback;
}

export function CrmLeadsPage() {
  const searchParams = useSearchParams();
  const { token, user } = useAuth();
  const { t } = useTranslation();
  const isAdministrator = user?.role === "administrator";
  const selectedLeadId = searchParams.get("lead");
  const viewMode = searchParams.get("view") === "pipeline" ? "pipeline" : "list";
  const archivedMode = isAdministrator && searchParams.get("archived") === "true";

  const query = useMemo<LeadsIndexQuery>(() => {
    const stage = searchParams.get("stage");
    const source = searchParams.get("source");
    const assignedTo = searchParams.get("assigned_to");
    const followUpBucket = searchParams.get("follow_up_bucket");
    const followUpState = searchParams.get("follow_up_state");
    const lifecycle = searchParams.get("lifecycle");

    return {
      page: positiveInteger(searchParams.get("page"), 1),
      per_page: viewMode === "pipeline" ? 100 : 20,
      search: searchParams.get("search") ?? "",
      stage: leadStages.includes(stage as LeadStage) ? (stage as LeadStage) : "",
      source: leadSources.includes(source as LeadSource)
        ? (source as LeadSource)
        : "",
      assigned_to: assignedTo ? positiveInteger(assignedTo, 0) || undefined : undefined,
      project_id: searchParams.get("project_id") ?? "",
      follow_up_bucket: followUpBuckets.includes(
        followUpBucket as FollowUpBucket
      )
        ? (followUpBucket as FollowUpBucket)
        : "",
      follow_up_state: [
        "overdue",
        "today",
        "tomorrow",
        "this_week",
        "scheduled_later",
        "unscheduled",
      ].includes(followUpState ?? "")
        ? (followUpState as LeadsIndexQuery["follow_up_state"])
        : "",
      lifecycle: ["open", "won", "lost"].includes(lifecycle ?? "")
        ? (lifecycle as LeadsIndexQuery["lifecycle"])
        : "",
      date_from: searchParams.get("date_from") ?? "",
      date_to: searchParams.get("date_to") ?? "",
      overdue: searchParams.get("overdue") === "true",
      archived: archivedMode,
    };
  }, [archivedMode, searchParams, viewMode]);

  const [searchInput, setSearchInput] = useState(query.search ?? "");
  const [index, setIndex] = useState<LeadsIndexResponse["data"] | null>(null);
  const [isLoading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshVersion, setRefreshVersion] = useState(0);
  const [renderedAt] = useState(() => Date.now());
  const indexRequest = useRef(0);

  const [projects, setProjects] = useState<Project[]>([]);
  const [assignees, setAssignees] = useState<TenantUser[]>([]);

  const [lead, setLead] = useState<Lead | null>(null);
  const [leadLoading, setLeadLoading] = useState(false);
  const [leadError, setLeadError] = useState<string | null>(null);
  const [leadNotFound, setLeadNotFound] = useState(false);
  const [activities, setActivities] = useState<LeadActivity[]>([]);
  const [activitiesLoading, setActivitiesLoading] = useState(false);
  const [activitiesError, setActivitiesError] = useState<string | null>(null);
  const detailRequest = useRef(0);

  const [formOpen, setFormOpen] = useState(false);
  const [editingLead, setEditingLead] = useState<Lead | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [duplicateMatches, setDuplicateMatches] = useState<LeadDuplicateMatch[]>([]);
  const [duplicatePayload, setDuplicatePayload] = useState<CreateLeadPayload | null>(null);
  const [duplicateError, setDuplicateError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [conversionSuccessName, setConversionSuccessName] = useState<string | null>(null);

  const [dialogAction, setDialogAction] = useState<LeadDialogAction | null>(null);
  const [followUpDialogAction, setFollowUpDialogAction] =
    useState<LeadFollowUpDialogAction | null>(null);
  const [actionProcessing, setActionProcessing] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [addingNote, setAddingNote] = useState(false);
  const [conversionConflict, setConversionConflict] = useState<LeadConversionConflict | null>(null);

  const updateUrl = useCallback(
    (
      changes: Record<string, string | number | boolean | null | undefined>,
      mode: "push" | "replace" = "push"
    ): void => {
      const next = new URLSearchParams(searchParams.toString());

      Object.entries(changes).forEach(([key, value]) => {
        if (value === undefined || value === null || value === "" || value === false) {
          next.delete(key);
        } else {
          next.set(key, String(value));
        }
      });

      const path = next.size > 0 ? `/crm/?${next.toString()}` : "/crm/";
      window.history[mode === "push" ? "pushState" : "replaceState"](null, "", path);
    },
    [searchParams]
  );

  useEffect(() => {
    queueMicrotask(() => setSearchInput(query.search ?? ""));
  }, [query.search]);

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      if (searchInput !== (query.search ?? "")) {
        updateUrl({ search: searchInput, page: 1 }, "replace");
      }
    }, 350);

    return () => window.clearTimeout(timeout);
  }, [query.search, searchInput, updateUrl]);

  useEffect(() => {
    if (!token) {
      return;
    }

    const requestId = ++indexRequest.current;
    let active = true;
    queueMicrotask(() => {
      if (active) {
        setLoading(true);
        setError(null);
      }
    });

    void fetchLeads(token, query)
      .then((response) => {
        if (active && requestId === indexRequest.current) {
          setIndex(response.data);
        }
      })
      .catch((caughtError) => {
        if (active && requestId === indexRequest.current) {
          setError(
            caughtError instanceof Error ? caughtError.message : t("crm.loadError")
          );
        }
      })
      .finally(() => {
        if (active && requestId === indexRequest.current) {
          setLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [query, refreshVersion, t, token]);

  useEffect(() => {
    if (!token) {
      return;
    }

    let active = true;
    void Promise.allSettled([
      fetchProjects(token, { page: 1, perPage: 100 }),
      fetchTenantUsers(token, { page: 1, perPage: 100 }),
    ]).then(([projectResult, userResult]) => {
      if (!active) {
        return;
      }

      setProjects(
        projectResult.status === "fulfilled" ? projectResult.value.data.data : []
      );
      setAssignees(
        userResult.status === "fulfilled"
          ? userResult.value.data.users.data.filter(
              (membership) =>
                membership.status === "active" && crmRoles.includes(membership.user.role)
            )
          : []
      );
    });

    return () => {
      active = false;
    };
  }, [token]);

  const loadActivities = useCallback(
    async (leadId: string, archived: boolean): Promise<void> => {
      if (!token) {
        return;
      }

      setActivitiesLoading(true);
      setActivitiesError(null);
      try {
        const response = await fetchLeadActivities(token, leadId, archived);
        setActivities(response.data.activities);
      } catch (caughtError) {
        setActivitiesError(
          caughtError instanceof Error ? caughtError.message : t("crm.activity.error")
        );
      } finally {
        setActivitiesLoading(false);
      }
    },
    [t, token]
  );

  useEffect(() => {
    if (!token || !selectedLeadId) {
      detailRequest.current++;
      queueMicrotask(() => {
        setLead(null);
        setLeadError(null);
        setLeadNotFound(false);
        setActivities([]);
      });
      return;
    }

    const requestId = ++detailRequest.current;
    let active = true;
    queueMicrotask(() => {
      if (active) {
        setLeadLoading(true);
        setLeadError(null);
        setLeadNotFound(false);
        setActivities([]);
      }
    });

    void fetchLead(token, selectedLeadId, archivedMode)
      .then((record) => {
        if (active && requestId === detailRequest.current) {
          setLead(record);
          void loadActivities(record.id, archivedMode);
        }
      })
      .catch((caughtError) => {
        if (active && requestId === detailRequest.current) {
          setLead(null);
          setLeadNotFound(
            isApiRequestError(caughtError) && caughtError.code === "lead_not_found"
          );
          setLeadError(
            caughtError instanceof Error ? caughtError.message : t("crm.loadError")
          );
        }
      })
      .finally(() => {
        if (active && requestId === detailRequest.current) {
          setLeadLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [archivedMode, loadActivities, refreshVersion, selectedLeadId, t, token]);

  useEffect(() => {
    if (!successMessage) {
      return;
    }
    const timeout = window.setTimeout(() => {
      setSuccessMessage(null);
      setConversionSuccessName(null);
    }, 3500);
    return () => window.clearTimeout(timeout);
  }, [successMessage]);

  const openLead = (record: Lead): void => {
    setConversionConflict(null);
    setConversionSuccessName(null);
    updateUrl({ lead: record.id });
  };

  const closeLead = (): void => {
    setDialogAction(null);
    setFollowUpDialogAction(null);
    setFormOpen(false);
    setConversionConflict(null);
    setConversionSuccessName(null);
    updateUrl({ lead: null });
  };

  const refresh = (): void => {
    setRefreshVersion((current) => current + 1);
  };

  const submitForm = async (
    payload: CreateLeadPayload | UpdateLeadPayload
  ): Promise<void> => {
    if (!token) {
      return;
    }

    setSubmitting(true);
    try {
      if (editingLead) {
        const updated = await updateLead(token, editingLead.id, payload as UpdateLeadPayload);
        setLead(updated);
        setFormOpen(false);
        setEditingLead(null);
        setSuccessMessage(t("crm.success.updated"));
        setConversionSuccessName(null);
        refresh();
        return;
      }

      try {
        const created = await createLead(token, payload as CreateLeadPayload);
        setFormOpen(false);
        setSuccessMessage(t("crm.success.created"));
        setConversionSuccessName(null);
        updateUrl({ lead: created.id });
        refresh();
      } catch (caughtError) {
        if (caughtError instanceof LeadDuplicateError) {
          setDuplicatePayload(payload as CreateLeadPayload);
          setDuplicateMatches(caughtError.matches);
          setDuplicateError(null);
          return;
        }
        throw caughtError;
      }
    } finally {
      setSubmitting(false);
    }
  };

  const confirmDuplicate = async (): Promise<void> => {
    if (!token || !duplicatePayload) {
      return;
    }

    setSubmitting(true);
    setDuplicateError(null);
    try {
      const created = await createLead(token, {
        ...duplicatePayload,
        acknowledge_duplicate: true,
      });
      setDuplicatePayload(null);
      setDuplicateMatches([]);
      setFormOpen(false);
      setSuccessMessage(t("crm.success.created"));
      setConversionSuccessName(null);
      updateUrl({ lead: created.id });
      refresh();
    } catch (caughtError) {
      setDuplicateError(
        caughtError instanceof Error ? caughtError.message : t("crm.genericError")
      );
    } finally {
      setSubmitting(false);
    }
  };

  const executeAction = async (payload: LeadDialogPayload): Promise<void> => {
    if (!token || !lead) {
      return;
    }

    setActionProcessing(true);
    setActionError(null);
    try {
      const updated = await (async (): Promise<Lead> => {
        switch (payload.action) {
          case "claim":
            return claimLead(token, lead.id);
          case "assign":
            return assignLead(token, lead.id, payload.assignedTo);
          case "stage":
            return moveLeadStage(token, lead.id, payload.stage);
          case "lost":
            return moveLeadToLost(token, lead.id, payload.reason, payload.detail);
          case "reopen":
            return reopenLead(token, lead.id);
          case "archive":
            return archiveLead(token, lead.id, payload.reason, payload.detail);
          case "restore":
            return restoreLead(token, lead.id);
          case "convert":
            return convertLead(
              token,
              lead.id,
              payload.intent === "create_new"
                ? {
                    conversion_intent: "create_new",
                    type: payload.type,
                    category: payload.category,
                  }
                : {
                    conversion_intent: "link_existing",
                    existing_customer_id: payload.existingCustomerId,
                  }
            );
        }
      })();

      setDialogAction(null);
      if (payload.action === "convert") {
        const customerName = updated.customer?.name ?? "";
        setSuccessMessage(
          customerName
            ? `${t("crm.success.converted")} ${customerName}`
            : t("crm.success.converted")
        );
        setConversionSuccessName(customerName || t("crm.success.converted"));
        setConversionConflict(null);
        setLead(updated);
        void loadActivities(updated.id, archivedMode);
      } else if (payload.action === "archive" || payload.action === "restore") {
        setSuccessMessage(t("crm.success.action"));
        setConversionSuccessName(null);
        closeLead();
      } else {
        setSuccessMessage(t("crm.success.action"));
        setConversionSuccessName(null);
        setLead(updated);
        void loadActivities(updated.id, archivedMode);
      }
      refresh();
    } catch (caughtError) {
      if (caughtError instanceof LeadConversionConflictError) {
        setConversionConflict(caughtError.conflictingCustomer);
        setDialogAction("convert");
        setActionError(null);
      } else {
        setActionError(
          caughtError instanceof Error ? caughtError.message : t("crm.genericError")
        );
      }
    } finally {
      setActionProcessing(false);
    }
  };

  const executeFollowUpAction = async (
    payload: LeadFollowUpDialogPayload
  ): Promise<void> => {
    if (!token || !lead) {
      return;
    }

    setActionProcessing(true);
    setActionError(null);

    try {
      const updated = await (async (): Promise<Lead> => {
        switch (payload.action) {
          case "schedule_follow_up":
            return scheduleLeadFollowUp(token, lead.id, {
              next_follow_up_at: payload.nextFollowUpAt,
              next_action_type: payload.nextActionType,
              next_action_note: payload.nextActionNote,
            });

          case "reschedule_follow_up":
            return rescheduleLeadFollowUp(token, lead.id, {
              next_follow_up_at: payload.nextFollowUpAt,
              next_action_type: payload.nextActionType,
              next_action_note: payload.nextActionNote,
            });

          case "complete_follow_up":
            return completeLeadFollowUp(token, lead.id, {
              note: payload.note,
            });

          case "cancel_follow_up":
            return cancelLeadFollowUp(token, lead.id, {
              note: payload.note,
            });
        }
      })();

      setFollowUpDialogAction(null);
      setLead(updated);
      setSuccessMessage(t("crm.success.followUp"));
      setConversionSuccessName(null);
      void loadActivities(updated.id, archivedMode);
      refresh();
    } catch (caughtError) {
      setActionError(
        caughtError instanceof Error
          ? caughtError.message
          : t("crm.genericError")
      );
    } finally {
      setActionProcessing(false);
    }
  };

  const submitNote = async (body: string): Promise<void> => {
    if (!token || !lead) {
      return;
    }

    setAddingNote(true);
    try {
      const response = await addLeadNote(token, lead.id, body);
      setActivities((current) => [response.data.activity, ...current]);
      setSuccessMessage(t("crm.success.action"));
      setConversionSuccessName(null);
    } finally {
      setAddingNote(false);
    }
  };

  const resetFilters = (): void => {
    setSearchInput("");
    const next = new URLSearchParams(searchParams.toString());
    [
      "search",
      "stage",
      "source",
      "assigned_to",
      "project_id",
      "follow_up_bucket",
      "follow_up_state",
      "lifecycle",
      "date_from",
      "date_to",
      "overdue",
      "archived",
      "page",
    ].forEach((key) => next.delete(key));
    const leadId = searchParams.get("lead");
    if (leadId) {
      next.set("lead", leadId);
    }
    const path = next.size > 0 ? `/crm/?${next.toString()}` : "/crm/";
    window.history.pushState(null, "", path);
  };

  const hasFilters = Boolean(
    query.search ||
      query.stage ||
      query.source ||
      query.assigned_to ||
      query.project_id ||
      query.follow_up_bucket ||
      query.follow_up_state ||
      query.lifecycle ||
      query.date_from ||
      query.date_to ||
      query.overdue ||
      query.archived
  );

  return (
    <AppShell>
      <CrudPageLayout>
        {selectedLeadId && successMessage ? (
          <div
            role="status"
            className="flex items-center justify-between gap-4 rounded-xl border border-[var(--success)]/25 bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]"
          >
            <span>{successMessage}</span>
            {conversionSuccessName ? (
              <Link
                href="/customers/"
                className="shrink-0 underline underline-offset-2 hover:opacity-80"
              >
                {t("crm.dialog.convertViewCustomers")}
              </Link>
            ) : null}
          </div>
        ) : null}
        {selectedLeadId ? (
          <LeadDetailsView
            lead={lead}
            isLoading={leadLoading}
            error={leadError}
            isNotFound={leadNotFound}
            activities={activities}
            activitiesLoading={activitiesLoading}
            activitiesError={activitiesError}
            isAddingNote={addingNote}
            onBack={closeLead}
            onRetry={refresh}
            onRetryActivities={() => {
              if (lead) {
                void loadActivities(lead.id, archivedMode);
              }
            }}
            onEdit={() => {
              if (lead?.allowed_actions.can_edit) {
                setEditingLead(lead);
                setFormOpen(true);
              }
            }}
            onAction={(action) => {
              setActionError(null);
              if (action === "convert") {
                setConversionConflict(null);
              }
              setDialogAction(action);
            }}
            onFollowUpAction={(action) => {
              setActionError(null);
              setFollowUpDialogAction(action);
            }}
            onAddNote={submitNote}
          />
        ) : (
          <LeadsIndexView
            query={query}
            viewMode={viewMode}
            index={index}
            projects={projects}
            assignees={assignees}
            searchInput={searchInput}
            successMessage={successMessage}
            isAdministrator={isAdministrator}
            isLoading={isLoading}
            error={error}
            renderedAt={renderedAt}
            hasFilters={hasFilters}
            onSearchInput={setSearchInput}
            onQueryChange={updateUrl}
            onViewChange={(nextView) =>
              updateUrl({
                view: nextView === "pipeline" ? "pipeline" : null,
                page: 1,
                lead: null,
              })
            }
            onCreate={() => {
              setEditingLead(null);
              setFormOpen(true);
            }}
            onRefresh={refresh}
            onReset={resetFilters}
            onOpen={openLead}
          />
        )}
      </CrudPageLayout>

      {token && formOpen ? (
        <LeadFormDialog
          key={editingLead?.id ?? "new-lead"}
          isOpen
          lead={editingLead}
          token={token}
          projects={projects}
          assignees={assignees}
          canChooseAssignee={isAdministrator}
          isSubmitting={submitting}
          onClose={() => {
            if (!submitting) {
              setFormOpen(false);
              setEditingLead(null);
            }
          }}
          onSubmit={submitForm}
        />
      ) : null}

      {lead && followUpDialogAction ? (
        <LeadFollowUpDialog
          key={`${lead.id}:${followUpDialogAction}`}
          action={followUpDialogAction}
          lead={lead}
          isProcessing={actionProcessing}
          error={actionError}
          onClose={() => {
            if (!actionProcessing) {
              setFollowUpDialogAction(null);
              setActionError(null);
            }
          }}
          onConfirm={(payload) => void executeFollowUpAction(payload)}
        />
      ) : null}

      <LeadDuplicateDialog
        isOpen={duplicatePayload !== null}
        matches={duplicateMatches}
        isProcessing={submitting}
        error={duplicateError}
        onCancel={() => {
          setDuplicatePayload(null);
          setDuplicateMatches([]);
          setDuplicateError(null);
        }}
        onConfirm={() => void confirmDuplicate()}
      />

      {lead && dialogAction ? (
        <LeadActionDialogs
          key={`${lead.id}:${dialogAction}`}
          action={dialogAction}
          lead={lead}
          assignees={assignees}
          isProcessing={actionProcessing}
          error={actionError}
          onClose={() => {
            if (!actionProcessing) {
              setDialogAction(null);
              setActionError(null);
              setConversionConflict(null);
            }
          }}
          onConfirm={(payload) => void executeAction(payload)}
          conversionConflict={conversionConflict}
        />
      ) : null}
    </AppShell>
  );
}
