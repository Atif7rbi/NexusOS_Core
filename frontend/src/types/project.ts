export type ProjectType =
  | "residential"
  | "commercial"
  | "mixed_use"
  | "land"
  | "villa"
  | "tower"
  | "compound"
  | "warehouse"
  | "other";

export type ProjectStatus =
  | "draft"
  | "active"
  | "completed"
  | "cancelled";

export type DataOrigin =
  | "user"
  | "import"
  | "system"
  | "demo";

export type ProjectManager = {
  id: number;
  name: string;
  email: string;
};

export type Project = {
  id: string;
  project_number: string;
  project_number_year: number;
  project_sequence_number: number;
  name: string;
  description: string | null;
  project_type: ProjectType;
  status: ProjectStatus;
  country_code: string;
  city: string;
  district: string | null;
  address_line: string | null;
  currency: string;
  estimated_budget: string | null;
  planned_start_date: string | null;
  planned_end_date: string | null;
  actual_start_date: string | null;
  actual_end_date: string | null;
  project_manager_id: number | null;
  project_manager?: ProjectManager | null;
  archived_at: string | null;
  data_origin: DataOrigin;
  external_reference: string | null;
  legacy_reference: string | null;
  created_by: number | null;
  updated_by: number | null;
  created_at: string;
  updated_at: string;
};

export type ProjectFormPayload = {
  sequence_number?: number;
  name: string;
  description?: string | null;
  project_type: ProjectType;
  country_code?: string;
  city: string;
  district?: string | null;
  address_line?: string | null;
  currency?: string;
  estimated_budget?: number | null;
  planned_start_date?: string | null;
  planned_end_date?: string | null;
  actual_start_date?: string | null;
  actual_end_date?: string | null;
  project_manager_id?: number | null;
  external_reference?: string | null;
  legacy_reference?: string | null;
};

export type ProjectPagination = {
  current_page: number;
  data: Project[];
  first_page_url: string;
  from: number | null;
  last_page: number;
  last_page_url: string;
  links: Array<{
    url: string | null;
    label: string;
    active: boolean;
  }>;
  next_page_url: string | null;
  path: string;
  per_page: number;
  prev_page_url: string | null;
  to: number | null;
  total: number;
};

export type ProjectsResponse = {
  data: ProjectPagination;
};

export type ProjectResponse = {
  message?: string;
  data: {
    project: Project;
  };
};
