"use client";

import {
  Archive,
  Building2,
  CalendarDays,
  Edit3,
  MapPin,
  Plus,
  RefreshCw,
  RotateCcw,
  Search,
} from "lucide-react";
import {
  useCallback,
  useEffect,
  useState,
} from "react";

import { ArchiveProjectDialog } from "@/components/projects/DeleteProjectDialog";
import { ProjectFormModal } from "@/components/projects/ProjectFormModal";
import { Button } from "@/components/ui/Button";
import { AppShell } from "@/components/layout/AppShell";
import { useTranslation } from "@/hooks/useTranslation";
import { useAuth } from "@/providers/AuthProvider";
import {
  createProject,
  archiveProject,
  fetchProjects,
  restoreProject,
  updateProject,
} from "@/services/projects";
import { fetchTenantUsers } from "@/services/users";
import type {
  Project,
  ProjectFormPayload,
  ProjectManager,
  ProjectPagination,
  ProjectStatus,
  ProjectType,
} from "@/types/project";

export default function ProjectsPage() {
  const { isArabic } = useTranslation();
  const { token } = useAuth();

  const [projects, setProjects] = useState<Project[]>(
    []
  );

  const [pagination, setPagination] =
    useState<Pick<
      ProjectPagination,
      "current_page" | "last_page" | "total"
    >>({
      current_page: 1,
      last_page: 1,
      total: 0,
    });

  const [projectManagers, setProjectManagers] =
    useState<ProjectManager[]>([]);

  const [isLoading, setIsLoading] =
    useState(true);

  const [error, setError] =
    useState<string | null>(null);

  const [searchQuery, setSearchQuery] =
    useState("");

  const [statusFilter, setStatusFilter] =
    useState<ProjectStatus | "all">("all");

  const [formProject, setFormProject] =
    useState<Project | null>(null);

  const [isFormOpen, setFormOpen] =
    useState(false);

  const [isSubmitting, setSubmitting] =
    useState(false);

  const [projectForArchiveAction, setProjectForArchiveAction] =
    useState<Project | null>(null);

  const [archiveAction, setArchiveAction] = useState<
    "archive" | "restore"
  >("archive");

  const [isProcessingArchiveAction, setProcessingArchiveAction] =
    useState(false);

  const labels = isArabic
    ? {
        title: "المشاريع",
        description:
          "إدارة المشاريع والميزانيات والجداول الزمنية",
        newProject: "مشروع جديد",
        search: "البحث بالاسم أو رقم المشروع",
        allStatuses: "كل الحالات",
        refresh: "تحديث",
        loading: "جارٍ تحميل المشاريع...",
        emptyTitle: "لا توجد مشاريع",
        emptyDescription:
          "ابدأ بإنشاء أول مشروع داخل النظام.",
        loadError: "تعذر تحميل المشاريع.",
        number: "رقم المشروع",
        project: "المشروع",
        type: "النوع",
        location: "الموقع",
        budget: "الميزانية",
        status: "الحالة",
        dates: "التواريخ",
        actions: "الإجراءات",
        noBudget: "غير محددة",
        edit: "تعديل",
        archive: "أرشفة",
        restore: "استعادة",
        total: "إجمالي المشاريع",
        previous: "السابق",
        next: "التالي",
        page: "الصفحة",
        of: "من",
      }
    : {
        title: "Projects",
        description:
          "Manage projects, budgets and timelines",
        newProject: "New project",
        search: "Search by name or project number",
        allStatuses: "All statuses",
        refresh: "Refresh",
        loading: "Loading projects...",
        emptyTitle: "No projects",
        emptyDescription:
          "Create the first project in the system.",
        loadError: "Unable to load projects.",
        number: "Project number",
        project: "Project",
        type: "Type",
        location: "Location",
        budget: "Budget",
        status: "Status",
        dates: "Dates",
        actions: "Actions",
        noBudget: "Not specified",
        edit: "Edit",
        archive: "Archive",
        restore: "Restore",
        total: "Total projects",
        previous: "Previous",
        next: "Next",
        page: "Page",
        of: "of",
      };

  const typeLabels: Record<ProjectType, string> =
    isArabic
      ? {
          residential: "سكني",
          commercial: "تجاري",
          mixed_use: "متعدد الاستخدامات",
          land: "أرض",
          villa: "فلل",
          tower: "برج",
          compound: "مجمع",
          warehouse: "مستودعات",
          other: "أخرى",
        }
      : {
          residential: "Residential",
          commercial: "Commercial",
          mixed_use: "Mixed use",
          land: "Land",
          villa: "Villas",
          tower: "Tower",
          compound: "Compound",
          warehouse: "Warehouses",
          other: "Other",
        };

  const statusLabels: Record<
    ProjectStatus,
    string
  > = isArabic
    ? {
        draft: "مسودة",
        planning: "تخطيط",
        active: "نشط",
        completed: "مكتمل",
        archived: "مؤرشف",
        cancelled: "ملغي",
      }
    : {
        draft: "Draft",
        planning: "Planning",
        active: "Active",
        completed: "Completed",
        archived: "Archived",
        cancelled: "Cancelled",
      };

  const loadProjects =
    useCallback(async (page = pagination.current_page): Promise<void> => {
      if (!token) {
        return;
      }

      setIsLoading(true);
      setError(null);

      try {
        const response = await fetchProjects(token, {
          page,
          perPage: 20,
          search: searchQuery,
          status:
            statusFilter === "all"
              ? undefined
              : statusFilter,
        });

        setProjects(response.data.data);
        setPagination({
          current_page: response.data.current_page,
          last_page: response.data.last_page,
          total: response.data.total,
        });
      } catch (caughtError) {
        setError(
          caughtError instanceof Error
            ? caughtError.message
            : labels.loadError
        );
      } finally {
        setIsLoading(false);
      }
    }, [
      token,
      pagination.current_page,
      searchQuery,
      statusFilter,
      labels.loadError,
    ]);

  useEffect(() => {
    if (!token) {
      return;
    }

    let isCancelled = false;

    fetchTenantUsers(token, {
        page: 1,
        perPage: 100,
        status: "active",
      })
      .then((usersResponse) => {
        if (!isCancelled) {
          setProjectManagers(
            usersResponse.data.users.data.map(
              (membership) => membership.user
            )
          );
        }
      })
      .catch(() => {
        if (!isCancelled) {
          setProjectManagers([]);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [token]);

  useEffect(() => {
    if (!token) {
      return;
    }

    let isCancelled = false;

    fetchProjects(token, {
      page: pagination.current_page,
      perPage: 20,
      search: searchQuery,
      status:
        statusFilter === "all"
          ? undefined
          : statusFilter,
    })
      .then((response) => {
        if (!isCancelled) {
          setProjects(response.data.data);
          setPagination((current) => ({
            ...current,
            current_page: response.data.current_page,
            last_page: response.data.last_page,
            total: response.data.total,
          }));
          setError(null);
        }
      })
      .catch((caughtError: unknown) => {
        if (!isCancelled) {
          setError(
            caughtError instanceof Error
              ? caughtError.message
              : labels.loadError
          );
        }
      })
      .finally(() => {
        if (!isCancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [
    token,
    pagination.current_page,
    searchQuery,
    statusFilter,
    labels.loadError,
  ]);

  const openCreateModal = (): void => {
    setFormProject(null);
    setFormOpen(true);
  };

  const openEditModal = (
    project: Project
  ): void => {
    setFormProject(project);
    setFormOpen(true);
  };

  const handleFormSubmit = async (
    payload: ProjectFormPayload
  ): Promise<void> => {
    if (!token) {
      return;
    }

    setSubmitting(true);

    try {
      if (formProject) {
        await updateProject(
          token,
          formProject.id,
          payload
        );

        await loadProjects();
      } else {
        await createProject(
          token,
          payload
        );

        await loadProjects(1);
      }

      setFormOpen(false);
      setFormProject(null);
    } finally {
      setSubmitting(false);
    }
  };

  const handleArchiveAction = async (): Promise<void> => {
    if (!token || !projectForArchiveAction) {
      return;
    }

    setProcessingArchiveAction(true);

    try {
      if (archiveAction === "archive") {
        await archiveProject(token, projectForArchiveAction.id);
      } else {
        await restoreProject(token, projectForArchiveAction.id);
      }

      await loadProjects(
        projects.length === 1 && pagination.current_page > 1
          ? pagination.current_page - 1
          : pagination.current_page
      );

      setProjectForArchiveAction(null);
    } finally {
      setProcessingArchiveAction(false);
    }
  };

  return (
    <AppShell>
      <div className="mx-auto max-w-[1600px] space-y-5">
        <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)] sm:p-6">
          <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-center">
            <div>
              <div className="flex items-center gap-3">
                <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
                  <Building2 size={21} />
                </span>

                <div>
                  <h2 className="text-xl font-bold text-[var(--text-primary)]">
                    {labels.title}
                  </h2>

                  <p className="mt-1 text-xs text-[var(--text-secondary)]">
                    {labels.description}
                  </p>
                </div>
              </div>
            </div>

            <Button
              type="button"
              onClick={openCreateModal}
              className="gap-2 !bg-[var(--brand-gold)] !text-white hover:!bg-[var(--brand-gold-strong)]"
            >
              <Plus size={18} />
              {labels.newProject}
            </Button>
          </div>

          <div className="mt-6 grid gap-3 md:grid-cols-[1fr_220px_auto]">
            <div className="relative">
              <Search
                size={17}
                className="pointer-events-none absolute start-4 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
              />

              <input
                type="search"
                value={searchQuery}
                onChange={(event) =>
                  {
                    setSearchQuery(event.target.value);
                    setPagination((current) => ({
                      ...current,
                      current_page: 1,
                    }));
                  }
                }
                placeholder={labels.search}
                className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] ps-11 pe-4 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-muted)] focus:border-[var(--brand-gold)]"
              />
            </div>

            <select
              value={statusFilter}
              onChange={(event) =>
                {
                  setStatusFilter(
                    event.target.value as
                      | ProjectStatus
                      | "all"
                  );
                  setPagination((current) => ({
                    ...current,
                    current_page: 1,
                  }));
                }
              }
              className="h-11 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
            >
              <option value="all">
                {labels.allStatuses}
              </option>

              {Object.entries(statusLabels).map(
                ([value, label]) => (
                  <option
                    key={value}
                    value={value}
                  >
                    {label}
                  </option>
                )
              )}
            </select>

            <Button
              type="button"
              variant="secondary"
              onClick={() => void loadProjects()}
              disabled={isLoading}
              className="gap-2 !border-[var(--border)] !bg-[var(--surface)] !text-[var(--text-primary)] hover:!bg-[var(--surface-muted)]"
            >
              <RefreshCw
                size={17}
                className={
                  isLoading ? "animate-spin" : ""
                }
              />
              {labels.refresh}
            </Button>
          </div>
        </section>

        <section className="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--surface)] shadow-[var(--shadow-sm)]">
          <div className="flex items-center justify-between border-b border-[var(--border)] px-5 py-4">
            <p className="text-sm font-bold text-[var(--text-primary)]">
              {labels.total}: {pagination.total}
            </p>
          </div>

          {error ? (
            <div className="m-5 rounded-xl border border-[var(--danger)]/25 bg-[var(--danger-soft)] px-4 py-3 text-sm font-semibold text-[var(--danger)]">
              {error}
            </div>
          ) : null}

          {isLoading ? (
            <div className="flex min-h-72 items-center justify-center">
              <div className="text-center">
                <div className="mx-auto h-9 w-9 animate-spin rounded-full border-2 border-[var(--border)] border-t-[var(--brand-gold)]" />
                <p className="mt-4 text-sm text-[var(--text-secondary)]">
                  {labels.loading}
                </p>
              </div>
            </div>
          ) : projects.length === 0 ? (
            <div className="flex min-h-80 items-center justify-center px-6 text-center">
              <div className="max-w-md">
                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
                  <Building2 size={28} />
                </div>

                <h3 className="mt-5 text-base font-bold text-[var(--text-primary)]">
                  {labels.emptyTitle}
                </h3>

                <p className="mt-2 text-sm leading-7 text-[var(--text-secondary)]">
                  {labels.emptyDescription}
                </p>

                <Button
                  type="button"
                  onClick={openCreateModal}
                  className="mt-5 gap-2 !bg-[var(--brand-gold)] !text-white"
                >
                  <Plus size={17} />
                  {labels.newProject}
                </Button>
              </div>
            </div>
          ) : (
            <>
              <div className="hidden overflow-x-auto lg:block">
                <table className="w-full min-w-[1100px]">
                  <thead className="bg-[var(--surface-soft)]">
                    <tr className="text-start text-[11px] font-bold text-[var(--text-muted)]">
                      <th className="px-5 py-3 text-start">
                        {labels.number}
                      </th>
                      <th className="px-5 py-3 text-start">
                        {labels.project}
                      </th>
                      <th className="px-5 py-3 text-start">
                        {labels.type}
                      </th>
                      <th className="px-5 py-3 text-start">
                        {labels.location}
                      </th>
                      <th className="px-5 py-3 text-start">
                        {labels.budget}
                      </th>
                      <th className="px-5 py-3 text-start">
                        {labels.status}
                      </th>
                      <th className="px-5 py-3 text-start">
                        {labels.dates}
                      </th>
                      <th className="px-5 py-3 text-end">
                        {labels.actions}
                      </th>
                    </tr>
                  </thead>

                  <tbody className="divide-y divide-[var(--border)]">
                    {projects.map(
                      (project) => (
                        <ProjectRow
                          key={project.id}
                          project={project}
                          labels={labels}
                          typeLabel={
                            typeLabels[
                              project.project_type
                            ]
                          }
                          statusLabel={
                            statusLabels[
                              project.status
                            ]
                          }
                          onEdit={
                            project.archived_at
                              ? undefined
                              : () => openEditModal(project)
                          }
                          onArchiveAction={() => {
                            setProjectForArchiveAction(project);
                            setArchiveAction(
                              project.archived_at
                                ? "restore"
                                : "archive"
                            );
                          }}
                        />
                      )
                    )}
                  </tbody>
                </table>
              </div>

              <div className="space-y-3 p-4 lg:hidden">
                {projects.map((project) => (
                  <ProjectMobileCard
                    key={project.id}
                    project={project}
                    isArabic={isArabic}
                    typeLabel={
                      typeLabels[project.project_type]
                    }
                    statusLabel={
                      statusLabels[project.status]
                    }
                    onEdit={
                      project.archived_at
                        ? undefined
                        : () => openEditModal(project)
                    }
                    onArchiveAction={() => {
                      setProjectForArchiveAction(project);
                      setArchiveAction(
                        project.archived_at
                          ? "restore"
                          : "archive"
                      );
                    }}
                  />
                ))}
              </div>
            </>
          )}

          {pagination.last_page > 1 ? (
            <div className="flex items-center justify-between gap-3 border-t border-[var(--border)] px-5 py-4">
              <Button
                type="button"
                variant="secondary"
                disabled={
                  isLoading || pagination.current_page === 1
                }
                onClick={() =>
                  setPagination((current) => ({
                    ...current,
                    current_page: current.current_page - 1,
                  }))
                }
              >
                {labels.previous}
              </Button>

              <p className="text-sm font-semibold text-[var(--text-secondary)]">
                {labels.page} {pagination.current_page} {labels.of}{" "}
                {pagination.last_page}
              </p>

              <Button
                type="button"
                variant="secondary"
                disabled={
                  isLoading ||
                  pagination.current_page === pagination.last_page
                }
                onClick={() =>
                  setPagination((current) => ({
                    ...current,
                    current_page: current.current_page + 1,
                  }))
                }
              >
                {labels.next}
              </Button>
            </div>
          ) : null}
        </section>
      </div>

      {isFormOpen ? (
        <ProjectFormModal
          key={formProject?.id ?? "new-project"}
          isOpen
          project={formProject}
          projectManagers={projectManagers}
          isSubmitting={isSubmitting}
          onClose={() => {
            if (!isSubmitting) {
              setFormOpen(false);
              setFormProject(null);
            }
          }}
          onSubmit={handleFormSubmit}
        />
      ) : null}

      <ArchiveProjectDialog
        action={archiveAction}
        project={projectForArchiveAction}
        isProcessing={isProcessingArchiveAction}
        onCancel={() => {
          if (!isProcessingArchiveAction) {
            setProjectForArchiveAction(null);
          }
        }}
        onConfirm={handleArchiveAction}
      />
    </AppShell>
  );
}

type ProjectRowProps = {
  project: Project;
  labels: {
    noBudget: string;
    edit: string;
    archive: string;
    restore: string;
  };
  typeLabel: string;
  statusLabel: string;
  onEdit?: () => void;
  onArchiveAction: () => void;
};

function ProjectRow({
  project,
  labels,
  typeLabel,
  statusLabel,
  onEdit,
  onArchiveAction,
}: ProjectRowProps) {
  return (
    <tr className="transition-colors hover:bg-[var(--surface-soft)]">
      <td className="px-5 py-4">
        <span className="font-mono text-xs font-bold text-[var(--brand-gold-strong)]">
          {project.project_number}
        </span>
      </td>

      <td className="px-5 py-4">
        <p className="max-w-64 truncate text-sm font-bold text-[var(--text-primary)]">
          {project.name}
        </p>

        {project.description ? (
          <p className="mt-1 max-w-64 truncate text-[11px] text-[var(--text-muted)]">
            {project.description}
          </p>
        ) : null}

        {project.project_manager ? (
          <p className="mt-1 max-w-64 truncate text-[11px] text-[var(--text-muted)]">
            {project.project_manager.name}
          </p>
        ) : null}
      </td>

      <td className="px-5 py-4 text-sm text-[var(--text-secondary)]">
        {typeLabel}
      </td>

      <td className="px-5 py-4">
        <div className="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
          <MapPin
            size={15}
            className="text-[var(--text-muted)]"
          />
          <span>
            {project.city}
            {project.district
              ? `، ${project.district}`
              : ""}
          </span>
        </div>
      </td>

      <td className="px-5 py-4 text-sm font-semibold text-[var(--text-primary)]">
        {project.estimated_budget
          ? `${new Intl.NumberFormat(
              "en-US",
              {
                useGrouping: true,
                maximumFractionDigits: 0,
              }
            ).format(
              Number(project.estimated_budget)
            )} SAR`
          : labels.noBudget}
      </td>

      <td className="px-5 py-4">
        <StatusBadge
          status={project.status}
          label={statusLabel}
        />
      </td>

      <td className="px-5 py-4">
        <div className="flex items-center gap-2 text-xs text-[var(--text-secondary)]">
          <CalendarDays size={15} />

          <span>
            {project.planned_start_date
              ? project.planned_start_date.slice(
                  0,
                  10
                )
              : "—"}
          </span>
        </div>
      </td>

      <td className="px-5 py-4">
        <div className="flex justify-end gap-2">
          {onEdit ? (
            <button
              type="button"
              onClick={onEdit}
              title={labels.edit}
              className="flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]"
            >
              <Edit3 size={16} />
            </button>
          ) : null}

          <button
            type="button"
            onClick={onArchiveAction}
            title={
              project.archived_at
                ? labels.restore
                : labels.archive
            }
            className="flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--border)] text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]"
          >
            {project.archived_at ? (
              <RotateCcw size={16} />
            ) : (
              <Archive size={16} />
            )}
          </button>
        </div>
      </td>
    </tr>
  );
}

type ProjectMobileCardProps = {
  project: Project;
  isArabic: boolean;
  typeLabel: string;
  statusLabel: string;
  onEdit?: () => void;
  onArchiveAction: () => void;
};

function ProjectMobileCard({
  project,
  isArabic,
  typeLabel,
  statusLabel,
  onEdit,
  onArchiveAction,
}: ProjectMobileCardProps) {
  return (
    <article className="rounded-2xl border border-[var(--border)] bg-[var(--surface-soft)] p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-mono text-[11px] font-bold text-[var(--brand-gold-strong)]">
            {project.project_number}
          </p>

          <h3 className="mt-2 truncate text-sm font-bold text-[var(--text-primary)]">
            {project.name}
          </h3>
        </div>

        <StatusBadge
          status={project.status}
          label={statusLabel}
        />
      </div>

      <div className="mt-4 grid grid-cols-2 gap-3 text-xs text-[var(--text-secondary)]">
        <div>
          <p className="text-[var(--text-muted)]">
            {isArabic ? "النوع" : "Type"}
          </p>
          <p className="mt-1 font-semibold">
            {typeLabel}
          </p>
        </div>

        <div>
          <p className="text-[var(--text-muted)]">
            {isArabic ? "المدينة" : "City"}
          </p>
          <p className="mt-1 font-semibold">
            {project.city}
          </p>
        </div>

        <div>
          <p className="text-[var(--text-muted)]">
            {isArabic ? "مدير المشروع" : "Project manager"}
          </p>
          <p className="mt-1 truncate font-semibold">
            {project.project_manager?.name ?? "—"}
          </p>
        </div>
      </div>

      <div className="mt-4 flex justify-end gap-2 border-t border-[var(--border)] pt-4">
        {onEdit ? (
          <button
            type="button"
            onClick={onEdit}
            className="flex h-9 items-center gap-2 rounded-xl border border-[var(--border)] px-3 text-xs font-bold text-[var(--text-secondary)]"
          >
            <Edit3 size={15} />
            {isArabic ? "تعديل" : "Edit"}
          </button>
        ) : null}

        <button
          type="button"
          onClick={onArchiveAction}
          className="flex h-9 items-center gap-2 rounded-xl border border-[var(--border)] px-3 text-xs font-bold text-[var(--text-secondary)]"
        >
          {project.archived_at ? (
            <RotateCcw size={15} />
          ) : (
            <Archive size={15} />
          )}
          {isArabic
            ? project.archived_at
              ? "استعادة"
              : "أرشفة"
            : project.archived_at
              ? "Restore"
              : "Archive"}
        </button>
      </div>
    </article>
  );
}

function StatusBadge({
  status,
  label,
}: {
  status: ProjectStatus;
  label: string;
}) {
  const toneClasses: Record<
    ProjectStatus,
    string
  > = {
    draft:
      "bg-[var(--surface-muted)] text-[var(--text-secondary)]",
    planning:
      "bg-[var(--info-soft)] text-[var(--info)]",
    active:
      "bg-[var(--success-soft)] text-[var(--success)]",
    completed:
      "bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]",
    archived:
      "bg-[var(--surface-muted)] text-[var(--text-muted)]",
    cancelled:
      "bg-[var(--danger-soft)] text-[var(--danger)]",
  };

  return (
    <span
      className={`inline-flex rounded-full px-3 py-1 text-[11px] font-bold ${toneClasses[status]}`}
    >
      {label}
    </span>
  );
}
