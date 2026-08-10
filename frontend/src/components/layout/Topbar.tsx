"use client";

import {
  ChevronDown,
  Menu,
} from "lucide-react";
import { usePathname, useRouter } from "next/navigation";
import {
  useEffect,
  useRef,
  useState,
  type FocusEvent,
  type KeyboardEvent,
} from "react";

import { CompanyBrand } from "@/components/brand/CompanyBrand";
import { LanguageToggle } from "@/components/language/LanguageToggle";
import { QuickCreateMenu } from "@/components/layout/QuickCreateMenu";
import { ThemeToggle } from "@/components/theme/ThemeToggle";
import { getPageTitle } from "@/config/page-titles";
import { useTranslation } from "@/hooks/useTranslation";
import { useAppShell } from "@/providers/AppShellProvider";
import { useAuth } from "@/providers/AuthProvider";
import { useSystemSettings } from "@/providers/SystemSettingsProvider";

const roleTranslationKeys = {
  system_owner: "roles.systemOwner",
  administrator: "roles.administrator",
  project_manager: "roles.projectManager",
  sales: "roles.sales",
  accountant: "roles.accountant",
  employee: "roles.employee",
} as const;

export function getGreetingForTimeZone(
  date: Date,
  timeZone: string,
  isArabic: boolean
): string {
  const hour = Number(
    new Intl.DateTimeFormat("en-US", {
      hour: "2-digit",
      hourCycle: "h23",
      timeZone,
    }).format(date)
  );

  const isMorning = hour >= 5 && hour < 12;

  if (isArabic) {
    return isMorning ? "صباح الخير" : "مساء الخير";
  }

  return isMorning ? "Good morning" : "Good evening";
}

export function Topbar() {
  const pathname = usePathname();
  const router = useRouter();

  const { t, isArabic } = useTranslation();
  const { user, logout } = useAuth();
  const settings = useSystemSettings();
  const { openMobileSidebar } = useAppShell();
  const [greeting, setGreeting] = useState("");
  const [isUserMenuOpen, setIsUserMenuOpen] =
    useState(false);
  const userMenuContainerRef =
    useRef<HTMLDivElement>(null);
  const userMenuButtonRef =
    useRef<HTMLButtonElement>(null);
  const logoutButtonRef =
    useRef<HTMLButtonElement>(null);

  const page = getPageTitle(pathname);

  const userInitial =
    user?.name.trim().slice(0, 1).toUpperCase() || "U";

  const userRoleLabel = user
    ? t(
        roleTranslationKeys[
          user.role as keyof typeof roleTranslationKeys
        ] ?? "roles.employee"
      )
    : "";

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      setGreeting(
        getGreetingForTimeZone(
          new Date(),
          settings.timezone,
          isArabic
        )
      );
    }, 0);

    return () => window.clearTimeout(timeoutId);
  }, [isArabic, settings.timezone]);

  useEffect(() => {
    if (!isUserMenuOpen) {
      return;
    }

    const handlePointerDown = (
      event: PointerEvent
    ): void => {
      if (
        !userMenuContainerRef.current?.contains(
          event.target as Node
        )
      ) {
        setIsUserMenuOpen(false);
      }
    };

    const handleEscape = (
      event: globalThis.KeyboardEvent
    ): void => {
      if (event.key !== "Escape") {
        return;
      }

      setIsUserMenuOpen(false);
      userMenuButtonRef.current?.focus();
    };

    document.addEventListener("pointerdown", handlePointerDown);
    document.addEventListener("keydown", handleEscape);

    return () => {
      document.removeEventListener(
        "pointerdown",
        handlePointerDown
      );
      document.removeEventListener("keydown", handleEscape);
    };
  }, [isUserMenuOpen]);

  const handleLogout = async (): Promise<void> => {
    setIsUserMenuOpen(false);
    await logout();
    router.replace("/login/");
  };

  const handleUserMenuKeyDown = (
    event: KeyboardEvent<HTMLButtonElement>
  ): void => {
    if (event.key !== "ArrowDown") {
      return;
    }

    event.preventDefault();
    setIsUserMenuOpen(true);
    window.requestAnimationFrame(() => {
      logoutButtonRef.current?.focus();
    });
  };

  const handleUserMenuBlur = (
    event: FocusEvent<HTMLDivElement>
  ): void => {
    if (
      !event.currentTarget.contains(
        event.relatedTarget as Node | null
      )
    ) {
      setIsUserMenuOpen(false);
    }
  };

  return (
    <header
      className={[
        "sticky top-0 z-30",
        "border-b border-[var(--border)]",
        "bg-[color:var(--surface)]/90",
        "backdrop-blur-xl",
      ].join(" ")}
    >
      <div className="flex min-h-[76px] items-center gap-4 px-4 sm:px-6 lg:px-8">
        <button
          type="button"
          onClick={openMobileSidebar}
          className={[
            "motion-ui inline-flex h-11 w-11 shrink-0 items-center justify-center",
            "rounded-[var(--radius-md)]",
            "border border-[var(--border)]",
            "bg-[var(--surface)]",
            "text-[var(--text-secondary)]",
            "shadow-[var(--shadow-sm)]",
            "hover:border-[var(--border-strong)]",
            "hover:bg-[var(--surface-soft)]",
            "hover:text-[var(--text-primary)]",
            "lg:hidden",
          ].join(" ")}
          aria-label={t("navigation.openSidebar")}
        >
          <Menu size={20} />
        </button>

        <div className="min-w-0 flex-1">
          <h1 className="sr-only">
            {t(page.titleKey)}
          </h1>

          <CompanyBrand
            compact
            inlineText
            responsiveShortName
          />
        </div>

        <div className="ms-auto flex shrink-0 items-center gap-2">
          <QuickCreateMenu />

          <div className="hidden sm:block">
            <LanguageToggle compact />
          </div>

          <ThemeToggle />

          <p
            className="hidden min-w-24 text-center text-xs font-bold text-[var(--text-secondary)] md:block"
            aria-live="polite"
          >
            {greeting}
          </p>

          <div className="mx-1 hidden h-8 w-px bg-[var(--border)] sm:block" />

          <div
            ref={userMenuContainerRef}
            className="relative"
            onMouseEnter={() => setIsUserMenuOpen(true)}
            onMouseLeave={() => setIsUserMenuOpen(false)}
            onBlur={handleUserMenuBlur}
          >
            <button
              ref={userMenuButtonRef}
              type="button"
              onClick={() => setIsUserMenuOpen(true)}
              onKeyDown={handleUserMenuKeyDown}
              className={[
                "motion-ui flex items-center gap-3",
                "rounded-[var(--radius-md)]",
                "border border-transparent",
                "px-2 py-1.5",
                "hover:border-[var(--border)]",
                "hover:bg-[var(--surface-soft)]",
              ].join(" ")}
              aria-label={t("common.userMenu")}
              aria-haspopup="menu"
              aria-expanded={isUserMenuOpen}
              aria-controls="topbar-user-menu"
            >
              <div className="hidden min-w-0 text-start sm:block">
                <p className="max-w-36 truncate text-sm font-bold text-[var(--text-primary)]">
                  {user?.name ?? ""}
                </p>

                <p className="mt-0.5 truncate text-[10px] font-medium text-[var(--text-secondary)]">
                  {userRoleLabel}
                </p>
              </div>

              <div
                className={[
                  "flex h-10 w-10 shrink-0 items-center justify-center",
                  "rounded-[var(--radius-md)]",
                  "bg-[var(--action-primary)]",
                  "text-sm font-bold text-[var(--action-primary-foreground)]",
                  "shadow-[var(--shadow-sm)]",
                ].join(" ")}
              >
                {userInitial}
              </div>

              <ChevronDown
                size={14}
                className="hidden text-[var(--text-muted)] sm:block"
              />
            </button>

            <div
              id="topbar-user-menu"
              role="menu"
              className={[
                "absolute end-0 top-full z-[70] mt-2 w-52",
                "max-w-[calc(100vw-2rem)]",
                "rounded-[var(--radius-md)]",
                "border border-[var(--border)]",
                "bg-[var(--surface)] p-2",
                "shadow-[var(--shadow-lg)]",
                "transition-all duration-150",
                isUserMenuOpen
                  ? "visible translate-y-0 opacity-100"
                  : "invisible translate-y-1 opacity-0",
              ].join(" ")}
            >
              <div className="mb-2 border-b border-[var(--border)] px-3 py-2">
                <p className="truncate text-sm font-bold text-[var(--text-primary)]">
                  {user?.name ?? ""}
                </p>

                <p className="mt-1 text-xs text-[var(--text-secondary)]">
                  {userRoleLabel}
                </p>
              </div>

              <button
                ref={logoutButtonRef}
                type="button"
                onClick={handleLogout}
                role="menuitem"
                className={[
                  "motion-ui w-full rounded-lg px-3 py-2.5",
                  "text-start text-sm font-semibold",
                  "text-[var(--danger)]",
                  "hover:bg-[var(--danger-soft)]",
                ].join(" ")}
              >
                {t("common.logout")}
              </button>
            </div>
          </div>
        </div>
      </div>
    </header>
  );
}
