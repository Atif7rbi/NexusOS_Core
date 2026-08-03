export type LeadStage =
  | "new"
  | "qualified"
  | "viewing"
  | "negotiation"
  | "won"
  | "lost";

export type OpenLeadStage = Exclude<LeadStage, "won" | "lost">;

export type LeadSource =
  | "whatsapp"
  | "phone_call"
  | "website"
  | "social_media"
  | "referral"
  | "walk_in"
  | "advertising"
  | "exhibition"
  | "other";

export type LeadLostReason =
  | "price_too_high"
  | "no_suitable_unit"
  | "financing_unavailable"
  | "competitor"
  | "not_ready"
  | "unresponsive"
  | "duplicate"
  | "not_qualified"
  | "other";

export type LeadArchiveReason =
  | "duplicate"
  | "invalid_record"
  | "created_by_mistake"
  | "other";

export type LeadActivityType =
  | "note"
  | "stage_change"
  | "assignment"
  | "archive"
  | "restore";

export type LeadConversionMode =
  | "created"
  | "linked_existing"
  | "linked_and_promoted";

export type LeadAllowedActions = {
  can_edit: boolean;
  can_claim: boolean;
  can_assign: boolean;
  can_move_stage: boolean;
  can_move_backward: boolean;
  can_move_to_lost: boolean;
  can_reopen: boolean;
  can_add_note: boolean;
  can_archive: boolean;
  can_restore: boolean;
  can_convert: boolean;
};

export type LeadAssignee = {
  id: number;
  name: string;
  role: string;
  account_status: string;
  membership_status: string | null;
  is_eligible: boolean;
};

export type LeadProject = {
  id: string;
  name: string;
  archived_at: string | null;
};

export type LeadUnit = {
  id: string;
  project_id: string;
  unit_number: string;
  status: string;
  archived_at: string | null;
};

export type LeadCustomer = {
  id: string;
  name: string;
  status: string;
  archived_at: string | null;
};

export type Lead = {
  id: string;
  tenant_id: string;
  name: string;
  phone: string;
  email: string | null;
  source: LeadSource;
  source_detail: string | null;
  project_id: string | null;
  unit_id: string | null;
  stage: LeadStage;
  assigned_to: LeadAssignee | null;
  next_follow_up_at: string | null;
  lost_reason: LeadLostReason | null;
  lost_reason_detail: string | null;
  lost_at: string | null;
  lost_by: number | null;
  customer_id: string | null;
  converted_at: string | null;
  converted_by: number | null;
  conversion_mode: LeadConversionMode | null;
  archived_at: string | null;
  archived_by: number | null;
  archive_reason: LeadArchiveReason | null;
  archive_reason_detail: string | null;
  restored_by: number | null;
  restored_at: string | null;
  created_by: number;
  updated_by: number | null;
  created_at: string;
  updated_at: string;
  project: LeadProject | null;
  unit: LeadUnit | null;
  customer: LeadCustomer | null;
  allowed_actions: LeadAllowedActions;
};

export type LeadSummary = {
  active: number;
  unassigned: number;
  overdue: number;
  converted_this_month: number;
};

export type LeadPagination = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};

export type LeadsIndexResponse = {
  data: {
    leads: Lead[];
    summary: LeadSummary;
    pagination: LeadPagination;
  };
};

export type LeadResponse = {
  message?: string;
  data: {
    lead: Lead;
  };
};

export type LeadActivityPayload = Record<
  string,
  string | number | boolean | null
>;

export type LeadActivity = {
  id: string;
  lead_id: string;
  type: LeadActivityType;
  body: string | null;
  payload: LeadActivityPayload | null;
  occurred_at: string;
  created_by: {
    id: number;
    name: string;
  } | null;
  created_at: string;
};

export type LeadActivitiesResponse = {
  data: {
    activities: LeadActivity[];
    pagination: LeadPagination;
  };
};

export type LeadActivityResponse = {
  message?: string;
  data: {
    activity: LeadActivity;
  };
};

export type LeadsIndexQuery = {
  search?: string;
  stage?: LeadStage | "";
  source?: LeadSource | "";
  assigned_to?: number | null;
  project_id?: string;
  overdue?: boolean;
  archived?: boolean;
  page?: number;
  per_page?: number;
};

export type CreateLeadPayload = {
  name: string;
  phone: string;
  email?: string | null;
  source: LeadSource;
  source_detail?: string | null;
  project_id?: string | null;
  unit_id?: string | null;
  assigned_to?: number | null;
  next_follow_up_at?: string | null;
  acknowledge_duplicate?: boolean;
};

export type UpdateLeadPayload = Partial<
  Omit<CreateLeadPayload, "assigned_to" | "acknowledge_duplicate">
>;

export type LeadDuplicateMatch = {
  id: string;
  name: string;
  stage: LeadStage;
  assigned_to: {
    id: number;
    name: string;
  } | null;
  archived: boolean;
  updated_at: string | null;
};

export type ConvertLeadCreateNewPayload = {
  conversion_intent: "create_new";
  type: "individual" | "company";
  category: "investor" | "buyer" | "broker" | "owner" | "other";
};

export type ConvertLeadLinkExistingPayload = {
  conversion_intent: "link_existing";
  existing_customer_id: string;
};

export type ConvertLeadPayload =
  | ConvertLeadCreateNewPayload
  | ConvertLeadLinkExistingPayload;

export type LeadConversionConflict = {
  id: string;
  status: string;
};
