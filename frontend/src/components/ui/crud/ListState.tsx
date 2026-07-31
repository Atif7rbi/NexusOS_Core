import type { ComponentType, ReactNode } from "react";

type ListLoadingStateProps = {
  label: string;
};

type ListEmptyStateProps = {
  icon: ComponentType<{ size?: number; className?: string }>;
  title: string;
  description: string;
  action?: ReactNode;
};

type ListErrorStateProps = {
  message: string;
  action?: ReactNode;
};

export function ListLoadingState({
  label,
}: ListLoadingStateProps) {
  return (
    <div
      role="status"
      aria-live="polite"
      className="flex min-h-72 items-center justify-center"
    >
      <div className="text-center">
        <div className="mx-auto h-9 w-9 animate-spin rounded-full border-2 border-[var(--border)] border-t-[var(--brand-gold)]" />
        <p className="mt-4 text-sm text-[var(--text-secondary)]">
          {label}
        </p>
      </div>
    </div>
  );
}

export function ListEmptyState({
  icon: Icon,
  title,
  description,
  action,
}: ListEmptyStateProps) {
  return (
    <div className="flex min-h-72 items-center justify-center px-6 text-center">
      <div>
        <Icon
          size={28}
          className="mx-auto text-[var(--brand-gold-strong)]"
        />
        <h3 className="mt-5 text-base font-bold text-[var(--text-primary)]">
          {title}
        </h3>
        <p className="mt-2 text-sm text-[var(--text-secondary)]">
          {description}
        </p>
        {action ? <div className="mt-5">{action}</div> : null}
      </div>
    </div>
  );
}

export function ListErrorState({
  message,
  action,
}: ListErrorStateProps) {
  return (
    <div
      role="alert"
      className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[var(--danger)]/25 bg-[var(--danger-soft)] px-4 py-3 text-sm font-semibold text-[var(--danger)]"
    >
      <p>{message}</p>
      {action}
    </div>
  );
}
