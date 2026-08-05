import type { TranslationKey } from "@/i18n/types";
import type {
  LeadActivityType,
  LeadArchiveReason,
  LeadLostReason,
  LeadSource,
  LeadStage,
  OpenLeadStage,
} from "@/types/lead";

export const leadStages: LeadStage[] = [
  "new",
  "qualified",
  "viewing",
  "negotiation",
  "won",
  "lost",
];

export const openLeadStages: OpenLeadStage[] = [
  "new",
  "qualified",
  "viewing",
  "negotiation",
];

export const leadSources: LeadSource[] = [
  "whatsapp",
  "phone_call",
  "website",
  "social_media",
  "referral",
  "walk_in",
  "advertising",
  "exhibition",
  "other",
];

export const leadLostReasons: LeadLostReason[] = [
  "price_too_high",
  "no_suitable_unit",
  "financing_unavailable",
  "competitor",
  "not_ready",
  "unresponsive",
  "duplicate",
  "not_qualified",
  "other",
];

export const leadArchiveReasons: LeadArchiveReason[] = [
  "duplicate",
  "invalid_record",
  "created_by_mistake",
  "other",
];

export const stageRanks: Record<OpenLeadStage, number> = {
  new: 10,
  qualified: 20,
  viewing: 30,
  negotiation: 40,
};

export function stageKey(stage: LeadStage): TranslationKey {
  return `crm.stage.${stage}` as TranslationKey;
}

export function sourceKey(source: LeadSource): TranslationKey {
  return `crm.source.${source}` as TranslationKey;
}

export function lostReasonKey(reason: LeadLostReason): TranslationKey {
  return `crm.lostReason.${reason}` as TranslationKey;
}

export function archiveReasonKey(reason: LeadArchiveReason): TranslationKey {
  return `crm.archiveReason.${reason}` as TranslationKey;
}

export function activityTypeKey(type: LeadActivityType): TranslationKey {
  const suffix: Record<LeadActivityType, string> = {
    note: "note",
    stage_change: "stageChange",
    assignment: "assignment",
    archive: "archive",
    restore: "restore",
    follow_up_scheduled: "followUpScheduled",
    follow_up_rescheduled: "followUpRescheduled",
    follow_up_completed: "followUpCompleted",
    follow_up_cancelled: "followUpCancelled",
  };

  return `crm.activity.${suffix[type]}` as TranslationKey;
}
