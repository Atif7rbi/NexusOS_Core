import type {
  InputHTMLAttributes,
  ReactNode,
} from "react";
import { useId } from "react";

type InputProps = InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  error?: string | null;
  hint?: string | null;
  leading?: ReactNode;
  trailing?: ReactNode;
};

export function Input({
  label,
  error,
  hint,
  leading,
  trailing,
  className = "",
  id,
  "aria-describedby": ariaDescribedBy,
  ...props
}: InputProps) {
  const generatedId = useId();
  const inputId = id ?? props.name ?? generatedId;
  const hintId = `${inputId}-hint`;
  const errorId = `${inputId}-error`;
  const describedBy = [
    ariaDescribedBy,
    hint && !error ? hintId : null,
    error ? errorId : null,
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <div className="space-y-2">
      <label
        htmlFor={inputId}
        className="block text-sm font-semibold text-[var(--text-secondary)]"
      >
        {label}
      </label>

      <div className="relative">
        {leading && (
          <div className="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-4 text-[var(--text-muted)]">
            {leading}
          </div>
        )}

        <input
          {...props}
          id={inputId}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy || undefined}
          className={[
            "motion-ui h-12 w-full",
            "rounded-[var(--radius-md)]",
            "border border-[var(--border)]",
            "bg-[var(--surface)]",
            "px-4 text-sm",
            "text-[var(--text-primary)]",
            "placeholder:text-[var(--text-muted)]",
            "shadow-[var(--shadow-sm)]",
            "outline-none",
            "focus:border-[var(--brand-primary)]",
            "focus:ring-4 focus:ring-[var(--focus-ring)]",
            "disabled:bg-[var(--surface-muted)]",
            "disabled:text-[var(--text-muted)]",
            error
              ? "border-[var(--danger)] focus:border-[var(--danger)]"
              : "",
            leading ? "ps-11" : "",
            trailing ? "pe-12" : "",
            className,
          ].join(" ")}
        />

        {trailing && (
          <div className="absolute inset-y-0 end-0 flex items-center pe-4 text-[var(--text-secondary)]">
            {trailing}
          </div>
        )}
      </div>

      {hint && !error && (
        <p
          id={hintId}
          className="text-xs text-[var(--text-secondary)]"
        >
          {hint}
        </p>
      )}

      {error && (
        <p
          id={errorId}
          className="text-xs font-semibold text-[var(--danger)]"
        >
          {error}
        </p>
      )}
    </div>
  );
}
