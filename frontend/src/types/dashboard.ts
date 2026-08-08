import type {
  CollectionsIndexItem,
  CollectionsIndexSummary,
} from "@/types/collection";
import type {
  Lead,
  LeadSummary,
} from "@/types/lead";
import type { Project } from "@/types/project";

export type DashboardResource<T> = {
  data: T | null;
  isLoading: boolean;
  error: string | null;
};

export type DashboardProjects = {
  items: Project[];
  activeTotal: number;
};

export type DashboardFollowUps = {
  summary: LeadSummary;
  overdue: Lead[];
  today: Lead[];
};

export type DashboardCollections = {
  items: CollectionsIndexItem[];
  summary: CollectionsIndexSummary;
};

export type DashboardData = {
  projects: DashboardResource<DashboardProjects>;
  customers: DashboardResource<number>;
  units: DashboardResource<number>;
  reservations: DashboardResource<number>;
  contracts: DashboardResource<number>;
  followUps: DashboardResource<DashboardFollowUps>;
  collections: DashboardResource<DashboardCollections>;
  hasCrmAccess: boolean;
  refresh: () => Promise<void>;
};
