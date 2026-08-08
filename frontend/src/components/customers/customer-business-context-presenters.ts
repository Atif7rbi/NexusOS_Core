export const businessContextStatusTone: Record<string, string> = {
  active: "bg-[var(--success-soft)] text-[var(--success)]",
  converted: "bg-[var(--info-soft)] text-[var(--info)]",
  completed: "bg-[var(--info-soft)] text-[var(--info)]",
  scheduled: "bg-[var(--success-soft)] text-[var(--success)]",
  draft: "bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]",
  expired: "bg-[var(--warning-soft)] text-[var(--warning)]",
  cancelled: "bg-[var(--danger-soft)] text-[var(--danger)]",
  absent: "bg-[var(--surface-muted)] text-[var(--text-secondary)]",
};

export function shortCustomerContextId(value: string): string {
  return value.length > 12
    ? `${value.slice(0, 6)}…${value.slice(-4)}`
    : value;
}

export function getCustomerBusinessContextCopy(isArabic: boolean) {
  return isArabic
    ? {
        title: "الرحلة التجارية",
        description:
          "الحجوزات والمشاريع والوحدات والعقود وجداول التحصيل المرتبطة بالعميل.",
        reservations: "الحجوزات",
        reservation: "الحجز",
        contracts: "العقود",
        contract: "العقد",
        project: "المشروع",
        unit: "الوحدة",
        reservedAt: "تاريخ الحجز",
        expiresAt: "تاريخ الانتهاء",
        scheduleState: "حالة جدول التحصيل",
        scheduledTotal: "إجمالي الجدول المعتمد",
        scheduledLines: "بنود الجدول المعتمد",
        nextScheduled: "أول بند مجدول",
        unavailable: "غير متوفر",
        noneScheduled: "لا يوجد بند مجدول",
        noContracts: "لا توجد عقود مرتبطة بهذا الحجز.",
        emptyTitle: "لا توجد رحلة تجارية بعد",
        emptyDescription: "لا توجد حجوزات مرتبطة بهذا العميل.",
        status: {
          active: "نشط",
          converted: "محول إلى عقد",
          cancelled: "ملغي",
          expired: "منتهي",
          draft: "مسودة",
          completed: "مكتمل",
          absent: "بدون جدول",
          scheduled: "معتمد",
          available: "متاحة",
          reserved: "محجوزة",
          sold: "مباعة",
        },
        unitType: {
          apartment: "شقة",
          villa: "فيلا",
          office: "مكتب",
          shop: "محل",
          land: "أرض",
          other: "أخرى",
        },
      }
    : {
        title: "Business journey",
        description:
          "Reservations, projects, units, contracts and collection schedules linked to this customer.",
        reservations: "Reservations",
        reservation: "Reservation",
        contracts: "Contracts",
        contract: "Contract",
        project: "Project",
        unit: "Unit",
        reservedAt: "Reserved at",
        expiresAt: "Expires at",
        scheduleState: "Collection schedule state",
        scheduledTotal: "Scheduled total",
        scheduledLines: "Scheduled lines",
        nextScheduled: "First scheduled line",
        unavailable: "Not available",
        noneScheduled: "No scheduled line",
        noContracts: "No contracts are linked to this reservation.",
        emptyTitle: "No business journey yet",
        emptyDescription: "No reservations are linked to this customer.",
        status: {
          active: "Active",
          converted: "Converted",
          cancelled: "Cancelled",
          expired: "Expired",
          draft: "Draft",
          completed: "Completed",
          absent: "No schedule",
          scheduled: "Scheduled",
          available: "Available",
          reserved: "Reserved",
          sold: "Sold",
        },
        unitType: {
          apartment: "Apartment",
          villa: "Villa",
          office: "Office",
          shop: "Shop",
          land: "Land",
          other: "Other",
        },
      };
}
