"use client";

import {
  Edit3,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  UserRound,
  UsersRound,
} from "lucide-react";
import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";

import { AppShell } from "@/components/layout/AppShell";
import { DeleteUserDialog } from "@/components/users/DeleteUserDialog";
import { UserFormModal } from "@/components/users/UserFormModal";
import { UsersOverview } from "@/components/users/UsersOverview";
import { useTranslation } from "@/hooks/useTranslation";
import { canAdministrateTenant } from "@/lib/tenant-administrator";
import { useAuth } from "@/providers/AuthProvider";
import {
  createTenantUser,
  fetchTenantUsers,
  removeTenantUser,
  updateTenantUser,
} from "@/services/users";
import type {
  CreateTenantUserPayload,
  TenantUser,
  TenantUsersSummary,
  UpdateTenantUserPayload,
  UserRole,
} from "@/types/tenant-user";

const emptySummary: TenantUsersSummary = {
  total: 0,
  active: 0,
  paused: 0,
  limit: 3,
};

export default function UsersPage() {
  const { isArabic } = useTranslation();
  const { token, user } = useAuth();

  const canManageUsers = canAdministrateTenant(user?.role);

  const [users, setUsers] = useState<TenantUser[]>(
    []
  );

  const [summary, setSummary] =
    useState<TenantUsersSummary>(emptySummary);

  const [isLoading, setIsLoading] =
    useState(true);

  const [error, setError] =
    useState<string | null>(null);

  const [successMessage, setSuccessMessage] =
    useState<string | null>(null);

  const [searchQuery, setSearchQuery] =
    useState("");

  const [statusFilter, setStatusFilter] =
    useState("all");

  const [roleFilter, setRoleFilter] =
    useState<UserRole | "all">("all");

  const [isFormOpen, setFormOpen] =
    useState(false);

  const [editingUser, setEditingUser] =
    useState<TenantUser | null>(null);

  const [isSubmitting, setSubmitting] =
    useState(false);

  const [userToDelete, setUserToDelete] =
    useState<TenantUser | null>(null);

  const [isDeleting, setDeleting] =
    useState(false);

  const labels = useMemo(
    () =>
      isArabic
        ? {
            title: "المستخدمون",
            description:
              "إدارة أعضاء الشركة وأدوارهم وحالاتهم",
            add: "إضافة مستخدم",
            search:
              "البحث بالاسم أو البريد أو الجوال",
            allStatuses: "كل الحالات",
            allRoles: "كل الأدوار",
            refresh: "تحديث",
            loading: "جارٍ تحميل المستخدمين...",
            emptyTitle: "لا يوجد مستخدمون",
            emptyDescription:
              "ابدأ بإضافة أول مستخدم إلى الشركة.",
            loadError: "تعذر تحميل المستخدمين.",
            user: "المستخدم",
            phone: "الجوال",
            role: "الدور",
            status: "الحالة",
            lastLogin: "آخر دخول",
            actions: "الإجراءات",
            neverLoggedIn: "لم يسجل الدخول",
            edit: "تعديل",
            remove: "إزالة",
            createSuccess:
              "تمت إضافة المستخدم بنجاح.",
            updateSuccess:
              "تم تحديث المستخدم بنجاح.",
            deleteSuccess:
              "تمت إزالة عضوية المستخدم.",
            readOnlyTitle:
              "صلاحية عرض فقط",
            readOnlyDescription:
              "يمكنك مشاهدة المستخدمين وأدوارهم وحالاتهم، لكن ليس لديك صلاحية الإضافة أو التعديل أو الحذف.",
            ownAccountHint:
              "تعديل حسابك الشخصي سيكون من صفحة حسابي.",
          }
        : {
            title: "Users",
            description:
              "Manage company members, roles and status",
            add: "Add user",
            search:
              "Search by name, email or phone",
            allStatuses: "All statuses",
            allRoles: "All roles",
            refresh: "Refresh",
            loading: "Loading users...",
            emptyTitle: "No users",
            emptyDescription:
              "Add the first company user.",
            loadError: "Unable to load users.",
            user: "User",
            phone: "Phone",
            role: "Role",
            status: "Status",
            lastLogin: "Last login",
            actions: "Actions",
            neverLoggedIn: "Never logged in",
            edit: "Edit",
            remove: "Remove",
            createSuccess:
              "User added successfully.",
            updateSuccess:
              "User updated successfully.",
            deleteSuccess:
              "User membership removed.",
            readOnlyTitle:
              "View-only access",
            readOnlyDescription:
              "You can view users, roles and statuses, but you cannot add, edit or remove users.",
            ownAccountHint:
              "Your personal account will be managed from My Account.",
          },
    [isArabic]
  );

  const roleLabels: Record<UserRole, string> =
    isArabic
      ? {
          administrator: "مدير النظام",
          project_manager: "مدير مشاريع",
          sales: "مسوق عقاري",
          accountant: "محاسب",
          employee: "موظف",
        }
      : {
          administrator: "Administrator",
          project_manager: "Project manager",
          sales: "Real Estate Marketer",
          accountant: "Accountant",
          employee: "Employee",
        };

  const statusLabels: Record<string, string> =
    isArabic
      ? {
          invited: "مدعو",
          active: "نشط",
          paused: "متوقف مؤقتًا",
          suspended: "معلق",
          removed: "تمت إزالته",
        }
      : {
          invited: "Invited",
          active: "Active",
          paused: "Paused",
          suspended: "Suspended",
          removed: "Removed",
        };

  const statusClasses: Record<string, string> = {
    invited:
      "bg-[var(--info-soft)] text-[var(--info)]",
    active:
      "bg-[var(--success-soft)] text-[var(--success)]",
    paused:
      "bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]",
    suspended:
      "bg-[var(--danger-soft)] text-[var(--danger)]",
    removed:
      "bg-[var(--surface-muted)] text-[var(--text-muted)]",
  };

  const loadUsers =
    useCallback(async (): Promise<void> => {
      if (!token) {
        return;
      }

      setIsLoading(true);
      setError(null);

      try {
        const response = await fetchTenantUsers(
          token,
          {
            page: 1,
            perPage: 100,
          }
        );

        setUsers(response.data.users.data);
        setSummary(response.data.summary);
      } catch (caughtError) {
        setError(
          caughtError instanceof Error
            ? caughtError.message
            : labels.loadError
        );
      } finally {
        setIsLoading(false);
      }
    }, [token, labels.loadError]);

  useEffect(() => {
    queueMicrotask(() => {
      void loadUsers();
    });
  }, [loadUsers]);

  useEffect(() => {
    if (!successMessage) {
      return;
    }

    const timeoutId = window.setTimeout(
      () => setSuccessMessage(null),
      3500
    );

    return () => window.clearTimeout(timeoutId);
  }, [successMessage]);

  const filteredUsers = useMemo(() => {
    const normalizedSearch =
      searchQuery.trim().toLocaleLowerCase();

    return users.filter((membership) => {
      const matchesStatus =
        statusFilter === "all" ||
        membership.status === statusFilter;

      const matchesRole =
        roleFilter === "all" ||
        membership.user.role === roleFilter;

      const searchable = [
        membership.user.name,
        membership.user.email,
        membership.user.phone ?? "",
      ]
        .join(" ")
        .toLocaleLowerCase();

      const matchesSearch =
        !normalizedSearch ||
        searchable.includes(normalizedSearch);

      return (
        matchesStatus &&
        matchesRole &&
        matchesSearch
      );
    });
  }, [
    users,
    searchQuery,
    statusFilter,
    roleFilter,
  ]);

  const openCreateModal = (): void => {
    if (!canManageUsers) {
      return;
    }

    setEditingUser(null);
    setFormOpen(true);
  };

  const openEditModal = (
    membership: TenantUser
  ): void => {
    if (
      !canManageUsers ||
      membership.user_id === user?.id
    ) {
      return;
    }

    setEditingUser(membership);
    setFormOpen(true);
  };

  const closeFormModal = (): void => {
    if (isSubmitting) {
      return;
    }

    setFormOpen(false);
    setEditingUser(null);
  };

  const handleSubmit = async (
    payload:
      | CreateTenantUserPayload
      | UpdateTenantUserPayload
  ): Promise<void> => {
    if (!token) {
      return;
    }

    setSubmitting(true);

    try {
      if (editingUser) {
        await updateTenantUser(
          token,
          editingUser.id,
          payload as UpdateTenantUserPayload
        );

        setSuccessMessage(
          labels.updateSuccess
        );
      } else {
        await createTenantUser(
          token,
          payload as CreateTenantUserPayload
        );

        setSuccessMessage(
          labels.createSuccess
        );
      }

      setFormOpen(false);
      setEditingUser(null);
      await loadUsers();
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (): Promise<void> => {
    if (!token || !userToDelete) {
      return;
    }

    setDeleting(true);
    setError(null);

    try {
      await removeTenantUser(
        token,
        userToDelete.id
      );

      setUserToDelete(null);
      setSuccessMessage(labels.deleteSuccess);
      await loadUsers();
    } catch (caughtError) {
      setError(
        caughtError instanceof Error
          ? caughtError.message
          : labels.loadError
      );
    } finally {
      setDeleting(false);
    }
  };

  const formatDate = (
    value: string | null
  ): string => {
    if (!value) {
      return labels.neverLoggedIn;
    }

    return new Intl.DateTimeFormat(
      isArabic ? "ar-SA" : "en-US",
      {
        dateStyle: "medium",
        timeStyle: "short",
      }
    ).format(new Date(value));
  };

  return (
    <AppShell>
      <div className="space-y-6">
        <header className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
          <div>
            <h1 className="text-2xl font-bold text-[var(--text-primary)] sm:text-3xl">
              {labels.title}
            </h1>

            <p className="mt-2 text-sm text-[var(--text-secondary)]">
              {labels.description}
            </p>
          </div>

          <button
            type="button"
            onClick={openCreateModal}
            disabled={
              isLoading ||
              !canManageUsers ||
              summary.total >= summary.limit
            }
            className="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[var(--action-primary)] px-5 text-sm font-bold text-[var(--action-primary-foreground)] transition-all hover:bg-[var(--action-primary-hover)] focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)] disabled:cursor-not-allowed disabled:bg-[var(--surface-muted)] disabled:text-[var(--text-muted)] disabled:opacity-60"
          >
            <Plus size={18} />
            {labels.add}
          </button>
        </header>

        <UsersOverview
          summary={summary}
          isArabic={isArabic}
          isLoading={isLoading}
        />

        {!canManageUsers ? (
          <div className="rounded-2xl border border-[var(--info)]/25 bg-[var(--info-soft)] px-5 py-4">
            <p className="text-sm font-bold text-[var(--info)]">
              {labels.readOnlyTitle}
            </p>

            <p className="mt-1 text-sm leading-7 text-[var(--text-secondary)]">
              {labels.readOnlyDescription}
            </p>
          </div>
        ) : null}

        {successMessage ? (
          <div className="rounded-xl border border-[var(--success)]/25 bg-[var(--success-soft)] px-4 py-3 text-sm font-semibold text-[var(--success)]">
            {successMessage}
          </div>
        ) : null}

        {error ? (
          <div className="rounded-xl border border-[var(--danger)]/25 bg-[var(--danger-soft)] px-4 py-3 text-sm font-semibold text-[var(--danger)]">
            {error}
          </div>
        ) : null}

        <section className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)]">
          <div className="grid gap-3 lg:grid-cols-[1fr_190px_190px_auto]">
            <label className="relative">
              <Search
                size={17}
                className="absolute start-4 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
              />

              <input
                type="search"
                value={searchQuery}
                onChange={(event) =>
                  setSearchQuery(event.target.value)
                }
                placeholder={labels.search}
                className="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] ps-11 pe-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
              />
            </label>

            <select
              value={statusFilter}
              onChange={(event) =>
                setStatusFilter(event.target.value)
              }
              className="h-11 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
            >
              <option value="all">
                {labels.allStatuses}
              </option>
              <option value="active">
                {statusLabels.active}
              </option>
              <option value="paused">
                {statusLabels.paused}
              </option>
              <option value="suspended">
                {statusLabels.suspended}
              </option>
            </select>

            <select
              value={roleFilter}
              onChange={(event) =>
                setRoleFilter(
                  event.target.value as
                    | UserRole
                    | "all"
                )
              }
              className="h-11 rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] px-4 text-sm text-[var(--text-primary)] outline-none focus:border-[var(--brand-gold)]"
            >
              <option value="all">
                {labels.allRoles}
              </option>

              {Object.entries(roleLabels).map(
                ([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                )
              )}
            </select>

            <button
              type="button"
              onClick={() => void loadUsers()}
              disabled={isLoading}
              className="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-[var(--border)] px-4 text-sm font-bold text-[var(--text-secondary)] transition-colors hover:bg-[var(--surface-muted)]"
            >
              <RefreshCw
                size={17}
                className={
                  isLoading ? "animate-spin" : ""
                }
              />
              {labels.refresh}
            </button>
          </div>

          {isLoading ? (
            <div className="flex min-h-80 items-center justify-center">
              <div className="text-center">
                <div className="mx-auto h-10 w-10 animate-spin rounded-full border-2 border-[var(--border)] border-t-[var(--brand-gold)]" />
                <p className="mt-4 text-sm text-[var(--text-secondary)]">
                  {labels.loading}
                </p>
              </div>
            </div>
          ) : filteredUsers.length === 0 ? (
            <div className="flex min-h-80 items-center justify-center text-center">
              <div className="max-w-sm">
                <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
                  <UsersRound size={25} />
                </span>

                <h2 className="mt-4 text-base font-bold text-[var(--text-primary)]">
                  {labels.emptyTitle}
                </h2>

                <p className="mt-2 text-sm leading-7 text-[var(--text-secondary)]">
                  {labels.emptyDescription}
                </p>
              </div>
            </div>
          ) : (
            <>
              <div className="mt-5 hidden overflow-x-auto lg:block">
                <table className="w-full min-w-[900px] border-separate border-spacing-0">
                  <thead>
                    <tr className="text-start text-xs font-bold text-[var(--text-muted)]">
                      {[
                        labels.user,
                        labels.phone,
                        labels.role,
                        labels.status,
                        labels.lastLogin,
                        labels.actions,
                      ].map((label) => (
                        <th
                          key={label}
                          className="border-b border-[var(--border)] px-4 py-3 text-start"
                        >
                          {label}
                        </th>
                      ))}
                    </tr>
                  </thead>

                  <tbody>
                    {filteredUsers.map(
                      (membership) => (
                        <tr
                          key={membership.id}
                          className="transition-colors hover:bg-[var(--surface-soft)]"
                        >
                          <td className="border-b border-[var(--border)] px-4 py-4">
                            <div className="flex items-center gap-3">
                              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
                                <UserRound size={18} />
                              </span>

                              <div className="min-w-0">
                                <p className="truncate text-sm font-bold text-[var(--text-primary)]">
                                  {membership.user.name}
                                </p>

                                <p
                                  className="mt-1 truncate text-xs text-[var(--text-secondary)]"
                                  dir="ltr"
                                >
                                  {membership.user.email}
                                </p>
                              </div>
                            </div>
                          </td>

                          <td
                            className="border-b border-[var(--border)] px-4 py-4 text-sm text-[var(--text-secondary)]"
                            dir="ltr"
                          >
                            {membership.user.phone ?? "—"}
                          </td>

                          <td className="border-b border-[var(--border)] px-4 py-4 text-sm font-semibold text-[var(--text-primary)]">
                            {
                              roleLabels[
                                membership.user.role
                              ]
                            }
                          </td>

                          <td className="border-b border-[var(--border)] px-4 py-4">
                            <span
                              className={[
                                "inline-flex rounded-full px-3 py-1 text-xs font-bold",
                                statusClasses[
                                  membership.status
                                ],
                              ].join(" ")}
                            >
                              {
                                statusLabels[
                                  membership.status
                                ]
                              }
                            </span>
                          </td>

                          <td className="border-b border-[var(--border)] px-4 py-4 text-xs text-[var(--text-secondary)]">
                            {formatDate(
                              membership.user
                                .last_login_at
                            )}
                          </td>

                          <td className="border-b border-[var(--border)] px-4 py-4">
                            <div className="flex items-center gap-2">
                              <button
                                type="button"
                                onClick={() =>
                                  openEditModal(
                                    membership
                                  )
                                }
                                disabled={
                                  !canManageUsers ||
                                  membership.user_id ===
                                    user?.id
                                }
                                className="flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-secondary)] transition-colors hover:border-[var(--brand-gold)] hover:text-[var(--brand-gold-strong)] disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:border-[var(--border)] disabled:hover:text-[var(--text-secondary)]"
                                title={
                                  membership.user_id ===
                                  user?.id
                                    ? labels.ownAccountHint
                                    : labels.edit
                                }
                              >
                                <Edit3 size={16} />
                              </button>

                              <button
                                type="button"
                                onClick={() =>
                                  setUserToDelete(
                                    membership
                                  )
                                }
                                disabled={
                                  !canManageUsers ||
                                  membership.user_id ===
                                    user?.id
                                }
                                className="flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--border)] text-[var(--text-secondary)] transition-colors hover:border-[var(--danger)] hover:text-[var(--danger)] disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:border-[var(--border)] disabled:hover:text-[var(--text-secondary)]"
                                title={
                                  membership.user_id ===
                                  user?.id
                                    ? labels.ownAccountHint
                                    : labels.remove
                                }
                              >
                                <Trash2 size={16} />
                              </button>
                            </div>
                          </td>
                        </tr>
                      )
                    )}
                  </tbody>
                </table>
              </div>

              <div className="mt-5 grid gap-4 lg:hidden">
                {filteredUsers.map((membership) => (
                  <article
                    key={membership.id}
                    className="rounded-2xl border border-[var(--border)] bg-[var(--surface-soft)] p-4"
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex min-w-0 items-center gap-3">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
                          <UserRound size={18} />
                        </span>

                        <div className="min-w-0">
                          <p className="truncate text-sm font-bold text-[var(--text-primary)]">
                            {membership.user.name}
                          </p>

                          <p
                            className="mt-1 truncate text-xs text-[var(--text-secondary)]"
                            dir="ltr"
                          >
                            {membership.user.email}
                          </p>
                        </div>
                      </div>

                      <span
                        className={[
                          "shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold",
                          statusClasses[
                            membership.status
                          ],
                        ].join(" ")}
                      >
                        {
                          statusLabels[
                            membership.status
                          ]
                        }
                      </span>
                    </div>

                    <div className="mt-4 grid grid-cols-2 gap-3 text-xs">
                      <div>
                        <p className="text-[var(--text-muted)]">
                          {labels.role}
                        </p>
                        <p className="mt-1 font-bold text-[var(--text-primary)]">
                          {
                            roleLabels[
                              membership.user.role
                            ]
                          }
                        </p>
                      </div>

                      <div>
                        <p className="text-[var(--text-muted)]">
                          {labels.phone}
                        </p>
                        <p
                          className="mt-1 font-bold text-[var(--text-primary)]"
                          dir="ltr"
                        >
                          {membership.user.phone ??
                            "—"}
                        </p>
                      </div>
                    </div>

                    <div className="mt-4 flex gap-2 border-t border-[var(--border)] pt-4">
                      <button
                        type="button"
                        onClick={() =>
                          openEditModal(membership)
                        }
                        disabled={
                          !canManageUsers ||
                          membership.user_id === user?.id
                        }
                        className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl border border-[var(--border)] px-3 py-2 text-xs font-bold text-[var(--text-secondary)] disabled:cursor-not-allowed disabled:opacity-35"
                      >
                        <Edit3 size={15} />
                        {labels.edit}
                      </button>

                      <button
                        type="button"
                        onClick={() =>
                          setUserToDelete(membership)
                        }
                        disabled={
                          !canManageUsers ||
                          membership.user_id === user?.id
                        }
                        className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl border border-[var(--danger)]/30 px-3 py-2 text-xs font-bold text-[var(--danger)] disabled:cursor-not-allowed disabled:opacity-35"
                      >
                        <Trash2 size={15} />
                        {labels.remove}
                      </button>
                    </div>
                  </article>
                ))}
              </div>
            </>
          )}
        </section>
      </div>

      <UserFormModal
        key={`${editingUser?.id ?? "new-user"}-${isFormOpen ? "open" : "closed"}`}
        isOpen={isFormOpen}
        membership={editingUser}
        isSubmitting={isSubmitting}
        onClose={closeFormModal}
        onSubmit={handleSubmit}
      />

      <DeleteUserDialog
        membership={userToDelete}
        isDeleting={isDeleting}
        onClose={() => {
          if (!isDeleting) {
            setUserToDelete(null);
          }
        }}
        onConfirm={handleDelete}
      />
    </AppShell>
  );
}
