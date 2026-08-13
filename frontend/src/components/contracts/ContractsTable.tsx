import {
  Ban,
  CheckCircle2,
  CircleCheckBig,
  Edit3,
  Eye,
} from "lucide-react";
import type { ReactNode } from "react";

import {
  contractStatusClasses,
  contractStatusLabels,
  formatContractAmount,
  formatContractDate,
  shortContractId,
} from "@/components/contracts/contract-presenters";
import { DataTable } from "@/components/ui/crud/DataTable";
import {
  canCancelContract,
  canCompleteContract,
  canEditOrActivateContract,
} from "@/lib/commercial-authorization";
import type { AuthUser } from "@/types/auth";
import type {
  Contract,
  ContractLifecycleAction,
} from "@/types/contract";

type ContractsTableProps = {
  contracts: Contract[];
  currentUser: AuthUser | null;
  onDetails: (contract: Contract) => void;
  onEdit: (contract: Contract) => void;
  onLifecycle: (
    contract: Contract,
    action: ContractLifecycleAction
  ) => void;
};

export function ContractsTable({
  contracts,
  currentUser,
  onDetails,
  onEdit,
  onLifecycle,
}: ContractsTableProps) {
  return (
    <DataTable minWidth="1040px">
      <thead className="border-b border-[var(--border)] text-xs text-[var(--text-secondary)]">
        <tr>
          <th className="px-3 py-3">العقد</th>
          <th className="px-3 py-3">الوحدة</th>
          <th className="px-3 py-3">العميل</th>
          <th className="px-3 py-3">القيمة</th>
          <th className="px-3 py-3">الحالة</th>
          <th className="px-3 py-3">تاريخ الإنشاء</th>
          <th className="px-3 py-3">الإجراءات</th>
        </tr>
      </thead>
      <tbody>
        {contracts.map((contract) => (
          <tr
            key={contract.id}
            className="border-b border-[var(--border)] last:border-0"
          >
            <td className="px-3 py-4 font-mono text-xs font-bold text-[var(--text-primary)]">
              {shortContractId(contract.id)}
            </td>
            <td className="px-3 py-4 text-sm font-semibold text-[var(--text-primary)]">
              {contract.reservation?.unit?.unit_number ?? "—"}
            </td>
            <td className="px-3 py-4 text-sm text-[var(--text-secondary)]">
              {contract.reservation?.customer?.name ?? "—"}
            </td>
            <td className="px-3 py-4 text-sm font-bold text-[var(--text-primary)]">
              {formatContractAmount(contract.total_amount)}
            </td>
            <td className="px-3 py-4">
              <span
                className={`rounded-full px-3 py-1 text-xs font-bold ${contractStatusClasses[contract.status]}`}
              >
                {contractStatusLabels[contract.status]}
              </span>
            </td>
            <td className="px-3 py-4 text-sm text-[var(--text-secondary)]">
              {formatContractDate(contract.created_at)}
            </td>
            <td className="px-3 py-4">
              <div className="flex items-center gap-1">
                <ActionButton
                  label="عرض التفاصيل"
                  tone="info"
                  onClick={() => onDetails(contract)}
                >
                  <Eye size={17} />
                </ActionButton>

                {canEditOrActivateContract(currentUser, contract) ? (
                  <>
                    <ActionButton
                      label="تعديل العقد"
                      tone="gold"
                      onClick={() => onEdit(contract)}
                    >
                      <Edit3 size={17} />
                    </ActionButton>
                    <ActionButton
                      label="تفعيل العقد"
                      tone="success"
                      onClick={() => onLifecycle(contract, "activate")}
                    >
                      <CircleCheckBig size={17} />
                    </ActionButton>
                  </>
                ) : null}

                {canCompleteContract(currentUser, contract) ? (
                  <ActionButton
                    label="إكمال العقد"
                    tone="success"
                    onClick={() => onLifecycle(contract, "complete")}
                  >
                    <CheckCircle2 size={17} />
                  </ActionButton>
                ) : null}

                {canCancelContract(currentUser, contract) ? (
                  <ActionButton
                    label="إلغاء العقد"
                    tone="danger"
                    onClick={() => onLifecycle(contract, "cancel")}
                  >
                    <Ban size={17} />
                  </ActionButton>
                ) : null}
              </div>
            </td>
          </tr>
        ))}
      </tbody>
    </DataTable>
  );
}

function ActionButton({
  label,
  tone,
  onClick,
  children,
}: {
  label: string;
  tone: "gold" | "success" | "info" | "danger";
  onClick: () => void;
  children: ReactNode;
}) {
  const toneClasses = {
    gold: "text-[var(--brand-gold-strong)] hover:bg-[var(--brand-gold-soft)]",
    success: "text-[var(--success)] hover:bg-[var(--success-soft)]",
    info: "text-[var(--info)] hover:bg-[var(--info-soft)]",
    danger: "text-[var(--danger)] hover:bg-[var(--danger-soft)]",
  };

  return (
    <button
      type="button"
      onClick={onClick}
      className={`rounded-lg p-2 ${toneClasses[tone]}`}
      aria-label={label}
      title={label}
    >
      {children}
    </button>
  );
}
