"use client";

import { ChevronDown } from "lucide-react";
import {
  useId,
  useState,
  type ReactNode,
} from "react";

type DashboardCollapsibleSectionProps = {
  title: string;
  description?: string;
  summary?: ReactNode;
  action?: ReactNode;
  children: ReactNode;
  contentClassName?: string;
};

export function DashboardCollapsibleSection({
  title,
  description,
  summary,
  action,
  children,
  contentClassName = "mt-6",
}: DashboardCollapsibleSectionProps) {
  const [isExpanded, setExpanded] = useState(true);
  const sectionId = useId();
  const headingId = `${sectionId}-heading`;
  const contentId = `${sectionId}-content`;

  return (
    <section
      aria-labelledby={headingId}
      className="rounded-[var(--radius-lg)] border border-[var(--border)] bg-[var(--surface)] p-6 shadow-[var(--shadow-sm)]"
    >
      <div className="flex items-start justify-between gap-4">
        <button
          type="button"
          aria-expanded={isExpanded}
          aria-controls={contentId}
          onClick={() => setExpanded((current) => !current)}
          className="motion-ui group flex min-w-0 flex-1 items-start gap-3 rounded-[var(--radius-sm)] text-start focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
        >
          <ChevronDown
            size={20}
            aria-hidden="true"
            className={[
              "mt-0.5 shrink-0 text-[var(--text-muted)] transition-transform",
              isExpanded ? "rotate-180" : "rotate-0",
            ].join(" ")}
          />

          <span className="min-w-0">
            <span
              id={headingId}
              role="heading"
              aria-level={2}
              className="block text-lg font-bold text-[var(--text-primary)] group-hover:text-[var(--action-primary)]"
            >
              {title}
            </span>
            {description ? (
              <span className="mt-1 block text-sm font-normal text-[var(--text-secondary)]">
                {description}
              </span>
            ) : null}
          </span>
        </button>

        {action ? <div className="shrink-0">{action}</div> : null}
      </div>

      {summary ? (
        <div className="mt-4 flex flex-wrap gap-2">
          {summary}
        </div>
      ) : null}

      {isExpanded ? (
        <div id={contentId} className={contentClassName}>
          {children}
        </div>
      ) : null}
    </section>
  );
}
