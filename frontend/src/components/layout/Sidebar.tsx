"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  PanelLeftClose,
  PanelLeftOpen,
  PanelRightClose,
  PanelRightOpen,
  X,
} from "lucide-react";
import { useState } from "react";

import { NexusBrand } from "@/components/brand/NexusBrand";
import { getNavigationGroupsForRole } from "@/config/navigation";
import { useTranslation } from "@/hooks/useTranslation";
import { useAppShell } from "@/providers/AppShellProvider";
import { useAuth } from "@/providers/AuthProvider";

const GROUP_STORAGE_KEY = "nexusos_sidebar_groups_v1";

type GroupState = Record<string, boolean>;

function normalizePath(path: string): string {
  if (path === "/") {
    return "/";
  }

  return path.endsWith("/")
    ? path.slice(0, -1)
    : path;
}

function isCurrentPath(
  pathname: string,
  href: string
): boolean {
  return normalizePath(pathname) === normalizePath(href);
}

function getInitialGroupState(): GroupState {
  if (typeof window === "undefined") {
    return {};
  }

  try {
    const storedValue = window.localStorage.getItem(
      GROUP_STORAGE_KEY
    );

    if (!storedValue) {
      return {};
    }

    return JSON.parse(storedValue) as GroupState;
  } catch {
    return {};
  }
}

type SidebarContentProps = {
  mobile?: boolean;
};

function SidebarContent({
  mobile = false,
}: SidebarContentProps) {
  const pathname = usePathname();
  const { isArabic, t } = useTranslation();
  const { user } = useAuth();
  const visibleNavigationGroups =
    getNavigationGroupsForRole(user?.role ?? null);

  const {
    isSidebarCollapsed,
    toggleSidebar,
    closeMobileSidebar,
  } = useAppShell();

  const [expandedGroups, setExpandedGroups] =
    useState<GroupState>(getInitialGroupState);

  const collapsed =
    !mobile && isSidebarCollapsed;

  const ActiveChevron = isArabic
    ? ChevronLeft
    : ChevronRight;

  const ClosedChevron = isArabic
    ? ChevronLeft
    : ChevronRight;

  const CollapseIcon = isArabic
    ? PanelRightClose
    : PanelLeftClose;

  const ExpandIcon = isArabic
    ? PanelRightOpen
    : PanelLeftOpen;

  const toggleGroup = (groupKey: string): void => {
    setExpandedGroups((current) => {
      const nextState = {
        ...current,
        [groupKey]:
          current[groupKey] === false,
      };

      window.localStorage.setItem(
        GROUP_STORAGE_KEY,
        JSON.stringify(nextState)
      );

      return nextState;
    });
  };

  return (
    <>
      <div className="flex h-[82px] shrink-0 items-center justify-between border-b border-[var(--border)] px-4">
        <NexusBrand compact={collapsed} />

        {mobile ? (
          <button
            type="button"
            onClick={closeMobileSidebar}
            className="flex h-9 w-9 items-center justify-center rounded-xl text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]"
            aria-label={t("navigation.closeSidebar")}
          >
            <X size={19} />
          </button>
        ) : null}
      </div>

      <nav className="sidebar-scroll flex-1 overflow-y-auto px-3 py-5">
        <div className="space-y-4">
          {visibleNavigationGroups.map((group, groupIndex) => {
            const groupKey = group.labelKey;
            const isHomeGroup = groupIndex === 0;

            const isExpanded =
              isHomeGroup ||
              expandedGroups[groupKey] !== false;

            return (
              <section key={groupKey}>
                {!collapsed ? (
                  isHomeGroup ? (
                    <p className="mb-2 px-3 text-[10px] font-bold text-[var(--sidebar-heading)]">
                      {t(group.labelKey)}
                    </p>
                  ) : (
                    <button
                      type="button"
                      onClick={() =>
                        toggleGroup(groupKey)
                      }
                      className="mb-2 flex h-8 w-full items-center justify-between rounded-lg px-3 text-[10px] font-bold text-[var(--sidebar-heading)] transition-colors hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]"
                      aria-expanded={isExpanded}
                    >
                      <span>{t(group.labelKey)}</span>

                      {isExpanded ? (
                        <ChevronDown
                          size={14}
                          className="transition-transform duration-200"
                        />
                      ) : (
                        <ClosedChevron
                          size={14}
                          className="transition-transform duration-200"
                        />
                      )}
                    </button>
                  )
                ) : null}

                <div
                  className={[
                    "grid transition-[grid-template-rows,opacity] duration-200",
                    isExpanded || collapsed
                      ? "grid-rows-[1fr] opacity-100"
                      : "grid-rows-[0fr] opacity-0",
                  ].join(" ")}
                >
                  <div className="min-h-0 overflow-hidden">
                    <div className="space-y-1">
                      {group.items.map((item) => {
                        const active = isCurrentPath(
                          pathname,
                          item.href
                        );

                        const Icon = item.icon;
                        const label = t(item.labelKey);

                        return (
                          <Link
                            key={item.href}
                            href={item.href}
                            title={
                              collapsed
                                ? label
                                : undefined
                            }
                            onClick={
                              mobile
                                ? closeMobileSidebar
                                : undefined
                            }
                            className={[
                              "motion-ui group relative flex min-h-10 items-center rounded-xl",
                              collapsed
                                ? "justify-center px-2"
                                : "gap-3 px-3",
                              "text-[13px] font-semibold",
                              active
                                ? "bg-[var(--sidebar-active-bg)] text-[var(--sidebar-active-text)]"
                                : "text-[var(--sidebar-text)] hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]",
                            ].join(" ")}
                          >
                            {active ? (
                              <span
                                className={[
                                  "absolute h-6 w-[3px] bg-[var(--brand-gold)]",
                                  isArabic
                                    ? "-right-3 rounded-l-full"
                                    : "-left-3 rounded-r-full",
                                ].join(" ")}
                              />
                            ) : null}

                            <Icon
                              size={18}
                              strokeWidth={
                                active ? 2 : 1.7
                              }
                              className={
                                active
                                  ? "shrink-0 text-[var(--brand-gold-strong)]"
                                  : "shrink-0 text-[var(--text-muted)] group-hover:text-[var(--text-primary)]"
                              }
                            />

                            {!collapsed ? (
                              <>
                                <span className="min-w-0 flex-1 truncate">
                                  {label}
                                </span>

                                {active ? (
                                  <ActiveChevron
                                    size={14}
                                    className="text-[var(--brand-gold)]"
                                  />
                                ) : null}
                              </>
                            ) : null}
                          </Link>
                        );
                      })}
                    </div>
                  </div>
                </div>
              </section>
            );
          })}
        </div>
      </nav>

      {!mobile ? (
        <div className="shrink-0 border-t border-[var(--border)] px-3 py-4">
          <button
            type="button"
            onClick={toggleSidebar}
            title={
              collapsed
                ? t("navigation.expandSidebar")
                : t("navigation.collapseSidebar")
            }
            className={[
              "motion-ui flex h-10 w-full items-center rounded-xl",
              collapsed
                ? "justify-center"
                : "gap-3 px-3",
              "text-xs font-semibold text-[var(--text-secondary)]",
              "hover:bg-[var(--surface-muted)] hover:text-[var(--text-primary)]",
            ].join(" ")}
          >
            {collapsed ? (
              <ExpandIcon size={18} />
            ) : (
              <>
                <CollapseIcon size={18} />
                <span>
                  {t("navigation.collapseSidebar")}
                </span>
              </>
            )}
          </button>
        </div>
      ) : null}
    </>
  );
}

export function Sidebar() {
  const { isArabic } = useTranslation();

  const {
    isSidebarCollapsed,
    isMobileSidebarOpen,
    closeMobileSidebar,
  } = useAppShell();

  return (
    <>
      <aside
        className={[
          "hidden h-screen shrink-0 flex-col",
          "border-[var(--border)] bg-[var(--sidebar-bg)]",
          "transition-[width] duration-200",
          "lg:sticky lg:top-0 lg:flex",
          isArabic ? "border-l" : "border-r",
          isSidebarCollapsed
            ? "w-[82px]"
            : "w-[252px]",
        ].join(" ")}
      >
        <SidebarContent />
      </aside>

      {isMobileSidebarOpen ? (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            type="button"
            aria-label="Close sidebar"
            onClick={closeMobileSidebar}
            className="absolute inset-0 bg-slate-950/45 backdrop-blur-[2px]"
          />

          <aside
            className={[
              "absolute inset-y-0 flex w-[286px] flex-col",
              "border-[var(--border)] bg-[var(--sidebar-bg)]",
              "shadow-[var(--shadow-lg)]",
              isArabic
                ? "right-0 border-l"
                : "left-0 border-r",
            ].join(" ")}
          >
            <SidebarContent mobile />
          </aside>
        </div>
      ) : null}
    </>
  );
}
