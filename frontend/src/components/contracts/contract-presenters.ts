import type {
  Contract,
  ContractLifecycleAction,
  ContractStatus,
} from "@/types/contract";
import {
  formatDate,
  formatDateTime,
} from "@/lib/date-format";
import { formatMoney } from "@/lib/number-format";

export const contractStatusLabels: Record<ContractStatus, string> = {
  draft: "مسودة",
  active: "نشط",
  completed: "مكتمل",
  cancelled: "ملغي",
};

export const contractStatusClasses: Record<ContractStatus, string> = {
  draft: "bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]",
  active: "bg-[var(--success-soft)] text-[var(--success)]",
  completed: "bg-[var(--info-soft)] text-[var(--info)]",
  cancelled: "bg-[var(--danger-soft)] text-[var(--danger)]",
};

export type LifecycleSelection = {
  contract: Contract;
  action: ContractLifecycleAction;
};

export const lifecycleCopy: Record<
  ContractLifecycleAction,
  {
    title: string;
    description: string;
    confirmLabel: string;
    processingLabel: string;
    successMessage: string;
  }
> = {
  activate: {
    title: "تفعيل العقد",
    description:
      "سيتم تفعيل العقد وتحويل الحجز إلى عقد وبيع الوحدة المرتبطة به.",
    confirmLabel: "تفعيل العقد",
    processingLabel: "جارٍ التفعيل...",
    successMessage: "تم تفعيل العقد بنجاح.",
  },
  complete: {
    title: "إكمال العقد",
    description:
      "سيتم نقل العقد النشط إلى الحالة المكتملة. تأكد من اكتمال الإجراءات المطلوبة.",
    confirmLabel: "إكمال العقد",
    processingLabel: "جارٍ الإكمال...",
    successMessage: "تم إكمال العقد بنجاح.",
  },
  cancel: {
    title: "إلغاء العقد",
    description:
      "سيتم إلغاء العقد. لا يمكن التراجع عن هذا الإجراء من واجهة العقود.",
    confirmLabel: "إلغاء العقد",
    processingLabel: "جارٍ الإلغاء...",
    successMessage: "تم إلغاء العقد بنجاح.",
  },
};

export function shortContractId(value: string): string {
  return value.length > 12 ? `${value.slice(0, 6)}…${value.slice(-4)}` : value;
}

export function formatContractAmount(value: string): string {
  return formatMoney(value);
}

export function formatContractDate(
  value: string,
  includeTime = false
): string {
  return includeTime ? formatDateTime(value) : formatDate(value);
}
