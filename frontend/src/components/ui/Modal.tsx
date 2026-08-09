import type { ReactNode } from "react";

import { X } from "lucide-react";

type ModalProps = {
  isOpen: boolean;
  onClose: () => void;
  closeLabel: string;
  children: ReactNode;
  maxWidthClassName?: string;
  zIndexClassName?: string;
  className?: string;
};

type ModalHeaderProps = {
  icon?: ReactNode;
  title: string;
  description?: string;
  closeLabel: string;
  onClose: () => void;
};

type ModalFooterProps = {
  children: ReactNode;
  className?: string;
};

export function Modal({
  isOpen,
  onClose,
  closeLabel,
  children,
  maxWidthClassName = "max-w-2xl",
  zIndexClassName = "z-[70]",
  className = "",
}: ModalProps) {
  if (!isOpen) {
    return null;
  }

  return (
    <div
      className={[
        "fixed inset-0 flex items-center justify-center p-3 sm:p-6",
        zIndexClassName,
      ].join(" ")}
    >
      <button
        type="button"
        onClick={onClose}
        aria-label={closeLabel}
        className="absolute inset-0 bg-[var(--backdrop)] backdrop-blur-[2px]"
      />

      <div
        role="dialog"
        aria-modal="true"
        className={[
          "relative w-full overflow-hidden rounded-[26px]",
          "border border-[var(--border)] bg-[var(--surface)]",
          "shadow-[var(--shadow-lg)]",
          maxWidthClassName,
          className,
        ].join(" ")}
      >
        {children}
      </div>
    </div>
  );
}

export function ModalHeader({
  icon,
  title,
  description,
  closeLabel,
  onClose,
}: ModalHeaderProps) {
  return (
    <header className="flex items-start justify-between gap-4 border-b border-[var(--border)] px-5 py-5 sm:px-7">
      <div className="flex min-w-0 items-start gap-4">
        {icon ? <span>{icon}</span> : null}

        <div>
          <h2 className="text-lg font-bold text-[var(--text-primary)]">
            {title}
          </h2>
          {description ? (
            <p className="mt-1 text-xs text-[var(--text-secondary)]">
              {description}
            </p>
          ) : null}
        </div>
      </div>

      <button
        type="button"
        onClick={onClose}
        aria-label={closeLabel}
        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-[var(--text-muted)] hover:bg-[var(--surface-muted)]"
      >
        <X size={19} />
      </button>
    </header>
  );
}

export function ModalFooter({
  children,
  className = "",
}: ModalFooterProps) {
  return (
    <footer
      className={[
        "flex justify-end gap-3 border-t border-[var(--border)]",
        "bg-[var(--surface)] px-5 py-4 sm:px-7",
        className,
      ].join(" ")}
    >
      {children}
    </footer>
  );
}
