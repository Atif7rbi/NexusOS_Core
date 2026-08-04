import { CalendarClock } from "lucide-react";

import type { FollowUpBucket } from "@/types/lead";

type FollowUpQueueItem = {
  value: FollowUpBucket;
  label: string;
};

type FollowUpQueueTabsProps = {
  activeBucket: FollowUpBucket | "";
  items: FollowUpQueueItem[];
  allLabel: string;
  title: string;
  onChange: (bucket: FollowUpBucket | "") => void;
};

export function FollowUpQueueTabs({
  activeBucket,
  items,
  allLabel,
  title,
  onChange,
}: FollowUpQueueTabsProps) {
  return (
    <section
      aria-label={title}
      className="rounded-2xl border border-[var(--border)] bg-[var(--surface)] p-4 shadow-[var(--shadow-sm)]"
    >
      <div className="mb-3 flex items-center gap-2 text-sm font-bold text-[var(--text-primary)]">
        <CalendarClock
          size={17}
          aria-hidden="true"
          className="text-[var(--brand-gold-strong)]"
        />
        <span>{title}</span>
      </div>

      <div className="flex gap-2 overflow-x-auto pb-1" role="tablist">
        <QueueTab
          active={activeBucket === ""}
          label={allLabel}
          onClick={() => onChange("")}
        />
        {items.map((item) => (
          <QueueTab
            key={item.value}
            active={activeBucket === item.value}
            label={item.label}
            onClick={() => onChange(item.value)}
          />
        ))}
      </div>
    </section>
  );
}

function QueueTab({
  active,
  label,
  onClick,
}: {
  active: boolean;
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={[
        "shrink-0 rounded-xl border px-4 py-2 text-sm font-semibold transition",
        active
          ? "border-[var(--brand-gold)] bg-[var(--brand-gold)]/12 text-[var(--text-primary)]"
          : "border-[var(--border)] bg-[var(--surface-soft)] text-[var(--text-secondary)] hover:border-[var(--brand-gold)]/60",
      ].join(" ")}
    >
      {label}
    </button>
  );
}
