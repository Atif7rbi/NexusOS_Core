"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
} from "react";

import {
  initialDashboardResource,
  loadDashboardResource,
} from "@/hooks/dashboard-resource";
import { canAccessCrm } from "@/lib/crm-authorization";
import { hasModuleEntitlement } from "@/lib/entitlements";
import { useAuth } from "@/providers/AuthProvider";
import { fetchCollectionsIndex } from "@/services/collections";
import { fetchContracts } from "@/services/contracts";
import { fetchCustomers } from "@/services/customers";
import { fetchLeads } from "@/services/leads";
import { fetchProjects } from "@/services/projects";
import { fetchReservations } from "@/services/reservations";
import { fetchUnits } from "@/services/units";
import type {
  DashboardCollections,
  DashboardData,
  DashboardFollowUps,
  DashboardProjects,
  DashboardResource,
} from "@/types/dashboard";

export function useDashboard(): DashboardData {
  const { token, user, effectiveModules } = useAuth();
  const requestSequence = useRef(0);
  const availableModules = {
    projects: hasModuleEntitlement(effectiveModules, "projects"),
    units: hasModuleEntitlement(effectiveModules, "units"),
    customers: hasModuleEntitlement(effectiveModules, "customers"),
    crm: hasModuleEntitlement(effectiveModules, "crm"),
    reservations: hasModuleEntitlement(effectiveModules, "reservations"),
    contracts: hasModuleEntitlement(effectiveModules, "contracts"),
    collections: hasModuleEntitlement(effectiveModules, "collections"),
  };
  const hasCrmAccess =
    availableModules.crm && canAccessCrm(user?.role);

  const [projects, setProjects] =
    useState<DashboardResource<DashboardProjects>>(
      initialDashboardResource
    );
  const [customers, setCustomers] =
    useState<DashboardResource<number>>(initialDashboardResource);
  const [units, setUnits] =
    useState<DashboardResource<number>>(initialDashboardResource);
  const [reservations, setReservations] =
    useState<DashboardResource<number>>(initialDashboardResource);
  const [contracts, setContracts] =
    useState<DashboardResource<number>>(initialDashboardResource);
  const [followUps, setFollowUps] =
    useState<DashboardResource<DashboardFollowUps>>(
      initialDashboardResource
    );
  const [collections, setCollections] =
    useState<DashboardResource<DashboardCollections>>(
      initialDashboardResource
    );

  const loadDashboard = useCallback(async (): Promise<void> => {
    if (!token) {
      return;
    }

    const requestId = ++requestSequence.current;
    const isCurrent = (): boolean =>
      requestSequence.current === requestId;
    const tasks: Promise<void>[] = [];
    const unavailable = {
      data: null,
      isLoading: false,
      error: null,
    };

    if (availableModules.projects) {
      tasks.push(loadDashboardResource(
        async () => {
          const response = await fetchProjects(token, {
            perPage: 5,
            status: "active",
          });

          return {
            items: response.data.data,
            activeTotal: response.data.total,
          };
        },
        setProjects,
        isCurrent
      ));
    } else {
      setProjects(unavailable);
    }

    if (availableModules.customers) {
      tasks.push(loadDashboardResource(
        async () =>
          (await fetchCustomers(token, { per_page: 1 }))
            .data.summary.total,
        setCustomers,
        isCurrent
      ));
    } else {
      setCustomers(unavailable);
    }

    if (availableModules.units) {
      tasks.push(loadDashboardResource(
        async () =>
          (
            await fetchUnits(token, {
              per_page: 1,
              status: "available",
              archived: false,
            })
          ).data.units.total,
        setUnits,
        isCurrent
      ));
    } else {
      setUnits(unavailable);
    }

    if (availableModules.reservations) {
      tasks.push(loadDashboardResource(
        async () =>
          (await fetchReservations(token, { per_page: 1 }))
            .data.summary.active,
        setReservations,
        isCurrent
      ));
    } else {
      setReservations(unavailable);
    }

    if (availableModules.contracts) {
      tasks.push(loadDashboardResource(
        async () =>
          (await fetchContracts(token, { per_page: 1 }))
            .data.summary.total,
        setContracts,
        isCurrent
      ));
    } else {
      setContracts(unavailable);
    }

    if (availableModules.collections) {
      tasks.push(loadDashboardResource(
        async () => {
          const response = await fetchCollectionsIndex(token, {
            per_page: 5,
            schedule_state: "scheduled",
            sort: "next_scheduled_collection",
          });

          return {
            items: response.data.items,
            summary: response.data.summary,
          };
        },
        setCollections,
        isCurrent
      ));
    } else {
      setCollections(unavailable);
    }

    if (hasCrmAccess) {
      tasks.push(
        loadDashboardResource(
          async () => {
            const [overdue, today] = await Promise.all([
              fetchLeads(token, {
                follow_up_state: "overdue",
                per_page: 5,
              }),
              fetchLeads(token, {
                follow_up_state: "today",
                per_page: 5,
              }),
            ]);

            return {
              summary: overdue.data.summary,
              overdue: overdue.data.leads,
              today: today.data.leads,
            };
          },
          setFollowUps,
          isCurrent
        )
      );
    } else {
      setFollowUps({
        data: null,
        isLoading: false,
        error: null,
      });
    }

    await Promise.all(tasks);
  }, [
    availableModules.collections,
    availableModules.contracts,
    availableModules.customers,
    availableModules.projects,
    availableModules.reservations,
    availableModules.units,
    hasCrmAccess,
    token,
  ]);

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      void loadDashboard();
    }, 0);

    return () => {
      window.clearTimeout(timeoutId);
      requestSequence.current += 1;
    };
  }, [loadDashboard]);

  return {
    projects,
    customers,
    units,
    reservations,
    contracts,
    followUps,
    collections,
    hasCrmAccess,
    availableModules,
    refresh: loadDashboard,
  };
}
