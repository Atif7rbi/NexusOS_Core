import {
  Building2,
  CalendarCheck2,
  FileSignature,
  FolderKanban,
  HandCoins,
  LayoutDashboard,
  Settings,
  ShieldCheck,
  Users,
  UsersRound,
  type LucideIcon,
} from "lucide-react";

import type { TranslationKey } from "@/i18n/types";
import type { UserRole } from "@/types/auth";

export type NavigationItem = {
  labelKey: TranslationKey;
  href: string;
  icon: LucideIcon;
  allowedRoles?: UserRole[];
};

export type NavigationGroup = {
  labelKey: TranslationKey;
  items: NavigationItem[];
};

export const navigationGroups: NavigationGroup[] = [
  {
    labelKey: "navigation.home",
    items: [
      {
        labelKey: "navigation.dashboard",
        href: "/",
        icon: LayoutDashboard,
      },
    ],
  },
  {
    labelKey: "navigation.salesAndCustomers",
    items: [
      {
        labelKey: "navigation.crm",
        href: "/crm/",
        icon: UsersRound,
      },
      {
        labelKey: "navigation.customers",
        href: "/customers/",
        icon: Users,
      },
      {
        labelKey: "navigation.reservations",
        href: "/reservations/",
        icon: CalendarCheck2,
      },
      {
        labelKey: "navigation.contracts",
        href: "/contracts/",
        icon: FileSignature,
      },
    ],
  },
  {
    labelKey: "navigation.realEstateDevelopment",
    items: [
      {
        labelKey: "navigation.projects",
        href: "/projects/",
        icon: FolderKanban,
      },
      {
        labelKey: "navigation.units",
        href: "/units/",
        icon: Building2,
      },
    ],
  },
  {
    labelKey: "navigation.finance",
    items: [
      {
        labelKey: "navigation.collections",
        href: "/collections/",
        icon: HandCoins,
      },
    ],
  },
  {
    labelKey: "navigation.administration",
    items: [
      {
        labelKey: "navigation.usersAndPermissions",
        href: "/users/",
        icon: ShieldCheck,
        allowedRoles: [
          "system_owner",
          "administrator",
        ],
      },
      {
        labelKey: "navigation.settings",
        href: "/settings/",
        icon: Settings,
      },
    ],
  },
];

export function getNavigationGroupsForRole(
  role: UserRole | null
): NavigationGroup[] {
  return navigationGroups
    .map((group) => ({
      ...group,
      items: group.items.filter(
        (item) =>
          !item.allowedRoles ||
          (role !== null &&
            item.allowedRoles.includes(role))
      ),
    }))
    .filter((group) => group.items.length > 0);
}
