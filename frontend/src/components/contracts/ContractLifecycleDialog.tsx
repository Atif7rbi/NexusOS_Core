import {
  Ban,
  CircleCheckBig,
} from "lucide-react";

import {
  formatContractAmount,
  lifecycleCopy,
  shortContractId,
  type LifecycleSelection,
} from "@/components/contracts/contract-presenters";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";

type ContractLifecycleDialogProps = {
  selection: LifecycleSelection | null;
  error: string | null;
  isProcessing: boolean;
  onCancel: () => void;
  onConfirm: () => void;
};

export function ContractLifecycleDialog({
  selection,
  error,
  isProcessing,
  onCancel,
  onConfirm,
}: ContractLifecycleDialogProps) {
  const copy = selection ? lifecycleCopy[selection.action] : null;
  const isDanger = selection?.action === "cancel";

  return (
    <ConfirmationDialog
      isOpen={selection !== null}
      title={copy?.title ?? ""}
      description={copy?.description ?? ""}
      icon={
        <span
          className={[
            "flex h-12 w-12 items-center justify-center rounded-2xl",
            isDanger
              ? "bg-[var(--danger-soft)] text-[var(--danger)]"
              : "bg-[var(--success-soft)] text-[var(--success)]",
          ].join(" ")}
        >
          {isDanger ? <Ban size={21} /> : <CircleCheckBig size={21} />}
        </span>
      }
      error={error}
      isProcessing={isProcessing}
      closeLabel="إغلاق"
      cancelLabel="الرجوع"
      confirmLabel={copy?.confirmLabel ?? ""}
      processingLabel={copy?.processingLabel ?? "جارٍ التنفيذ..."}
      confirmVariant={isDanger ? "danger" : "primary"}
      onCancel={onCancel}
      onConfirm={onConfirm}
    >
      {selection ? (
        <div className="rounded-xl bg-[var(--surface-soft)] px-4 py-3 text-sm text-[var(--text-secondary)]">
          <span className="font-semibold text-[var(--text-primary)]">
            العقد:
          </span>{" "}
          {shortContractId(selection.contract.id)}
          <br />
          <span className="font-semibold text-[var(--text-primary)]">
            القيمة:
          </span>{" "}
          {formatContractAmount(selection.contract.total_amount)}
        </div>
      ) : null}
    </ConfirmationDialog>
  );
}
