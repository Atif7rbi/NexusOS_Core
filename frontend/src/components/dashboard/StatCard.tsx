import Link from "next/link";
import {
  ArrowUpLeft,
  ArrowUpRight,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

type StatCardProps = {
  label: string;
  value: string;
  description: string;
  icon: LucideIcon;
  tone: "gold" | "blue" | "green" | "violet" | "red";
  href?: string;
};

const toneClasses = {
  gold: {
    icon: "bg-[var(--warning-soft)] text-[var(--warning)]",
    accent: "bg-[var(--warning)]",
  },
  blue: {
    icon: "bg-[var(--info-soft)] text-[var(--info)]",
    accent: "bg-[var(--info)]",
  },
  green: {
    icon: "bg-[var(--success-soft)] text-[var(--success)]",
    accent: "bg-[var(--success)]",
  },
  violet: {
    icon:
      "bg-[var(--brand-primary-soft)] text-[var(--brand-primary)]",
    accent: "bg-[var(--brand-primary)]",
  },
  red: {
    icon: "bg-[var(--danger-soft)] text-[var(--danger)]",
    accent: "bg-[var(--danger)]",
  },
};

export function StatCard({
  label,
  value,
  description,
  icon: Icon,
  tone,
  href,
}: StatCardProps) {
  const DirectionArrow = ArrowUpLeft;

  const content = (
    <article
      className={[
        "motion-ui group relative h-full overflow-hidden",
        "rounded-[var(--radius-lg)]",
        "border border-[var(--border)]",
        "bg-[var(--surface)]",
        "p-5 shadow-[var(--shadow-sm)]",
        href
          ? [
              "cursor-pointer",
              "hover:-translate-y-1",
              "hover:border-[var(--border-strong)]",
              "hover:shadow-[var(--shadow-md)]",
            ].join(" ")
          : "",
      ].join(" ")}
    >
      <span
        className={[
          "absolute inset-x-0 top-0 h-[3px]",
          toneClasses[tone].accent,
        ].join(" ")}
      />

      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold text-[var(--text-secondary)]">
            {label}
          </p>

          <p
            className={[
              "mt-3 text-3xl font-bold",
              "tracking-tight text-[var(--text-primary)]",
            ].join(" ")}
            dir="ltr"
          >
            {value}
          </p>
        </div>

        <div
          className={[
            "flex h-11 w-11 shrink-0 items-center justify-center",
            "rounded-[var(--radius-md)]",
            toneClasses[tone].icon,
          ].join(" ")}
        >
          <Icon size={21} strokeWidth={1.9} />
        </div>
      </div>

      <div className="mt-5 flex items-end justify-between gap-3">
        <p className="text-xs leading-5 text-[var(--text-muted)]">
          {description}
        </p>

        {href ? (
          <span
            className={[
              "flex h-8 w-8 shrink-0 items-center justify-center",
              "rounded-full bg-[var(--surface-soft)]",
              "text-[var(--text-muted)]",
              "transition-all duration-200",
              "group-hover:bg-[var(--brand-primary-soft)]",
              "group-hover:text-[var(--brand-primary)]",
            ].join(" ")}
          >
            <span className="rtl:hidden">
              <ArrowUpRight size={15} />
            </span>

            <span className="hidden rtl:inline-flex">
              <DirectionArrow size={15} />
            </span>
          </span>
        ) : null}
      </div>
    </article>
  );

  if (!href) {
    return content;
  }

  return (
    <Link href={href} className="block h-full">
      {content}
    </Link>
  );
}
