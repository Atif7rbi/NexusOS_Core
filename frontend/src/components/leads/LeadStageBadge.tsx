import { Badge } from "@/components/ui/Badge";
import { stageKey } from "@/components/leads/lead-options";
import { useTranslation } from "@/hooks/useTranslation";
import type { LeadStage } from "@/types/lead";

type LeadStageBadgeProps = {
  stage: LeadStage;
};

export function LeadStageBadge({ stage }: LeadStageBadgeProps) {
  const { t } = useTranslation();
  const variant =
    stage === "won"
      ? "success"
      : stage === "lost"
        ? "danger"
        : stage === "negotiation"
          ? "warning"
          : stage === "viewing"
            ? "info"
            : stage === "qualified"
              ? "primary"
              : "neutral";

  return <Badge variant={variant}>{t(stageKey(stage))}</Badge>;
}
