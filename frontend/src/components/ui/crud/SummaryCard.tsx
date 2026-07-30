import type { ComponentType } from "react";

import { formatInteger } from "@/lib/number-format";

type SummaryCardTone = "gold" | "success" | "info";

type SummaryCardProps = {
  title: string;
  value: number | string;
  icon: ComponentType<{ size?: number }>;
  tone?: SummaryCardTone;
};

const toneClasses: Record<SummaryCardTone, string> = {
  gold: "bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]",
  success: "bg-[var(--success-soft)] text-[var(--success)]",
  info: "bg-[var(--info-soft)] text-[var(--info)]",
};

export function SummaryCard({
  title,
  value,
  icon: Icon,
  tone = "gold",
}: SummaryCardProps) {
  return (
    <article className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-5 shadow-[var(--shadow-sm)]">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-xs font-semibold text-[var(--text-secondary)]">
            {title}
          </p>
          <p className="mt-3 text-3xl font-bold text-[var(--text-primary)]">
            {typeof value === "number" ? formatInteger(value) : value}
          </p>
        </div>

        <span
          className={[
            "flex h-11 w-11 items-center justify-center rounded-2xl",
            toneClasses[tone],
          ].join(" ")}
        >
          <Icon size={20} />
        </span>
      </div>
    </article>
  );
}
