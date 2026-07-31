"use client";

import {
  AlertTriangle,
  FileSignature,
} from "lucide-react";
import { useState } from "react";

import { CollectionScheduleTab } from "@/components/collections/CollectionScheduleTab";
import {
  contractStatusLabels,
  formatContractAmount,
  formatContractDate,
  shortContractId,
} from "@/components/contracts/contract-presenters";
import { Button } from "@/components/ui/Button";
import { ConfirmationDialog } from "@/components/ui/ConfirmationDialog";
import {
  Modal,
  ModalFooter,
  ModalHeader,
} from "@/components/ui/Modal";
import type {
  Contract,
} from "@/types/contract";
import type { Reservation } from "@/types/reservation";

type ContractDetailsModalProps = {
  contract: Contract | null;
  reservation: Reservation | null;
  isLoading: boolean;
  error: string | null;
  onClose: () => void;
};

export function ContractDetailsModal({
  contract,
  reservation,
  isLoading,
  error,
  onClose,
}: ContractDetailsModalProps) {
  const [activeTab, setActiveTab] = useState<"details" | "collections">(
    "details"
  );
  const [isCollectionDirty, setIsCollectionDirty] =
    useState(false);
  const [pendingNavigation, setPendingNavigation] =
    useState<"details" | "close" | null>(null);
  const isOpen = Boolean(contract || isLoading || error);

  const requestClose = (): void => {
    if (isCollectionDirty) {
      setPendingNavigation("close");
      return;
    }

    onClose();
  };

  const requestTab = (
    nextTab: "details" | "collections"
  ): void => {
    if (
      activeTab === "collections" &&
      nextTab !== activeTab &&
      isCollectionDirty
    ) {
      setPendingNavigation(nextTab);
      return;
    }

    setActiveTab(nextTab);
  };

  const confirmNavigation = (): void => {
    const destination = pendingNavigation;

    setPendingNavigation(null);
    setIsCollectionDirty(false);

    if (destination === "close") {
      onClose();
      return;
    }

    if (destination === "details") {
      setActiveTab("details");
    }
  };

  return (
    <>
      <Modal
        isOpen={isOpen}
        onClose={requestClose}
        closeLabel="إغلاق"
        maxWidthClassName="max-w-3xl"
        className="flex max-h-[94vh] flex-col"
      >
        <ModalHeader
          icon={
            <span className="flex h-11 w-11 items-center justify-center rounded-2xl bg-[var(--brand-gold-soft)] text-[var(--brand-gold-strong)]">
              <FileSignature size={21} />
            </span>
          }
          title="تفاصيل العقد"
          description={
            contract
              ? `العقد ${shortContractId(contract.id)}`
              : undefined
          }
          closeLabel="إغلاق"
          onClose={requestClose}
        />

        <nav
          aria-label="أقسام العقد"
          className="flex gap-2 border-b border-[var(--border)] px-5 pt-4 sm:px-7"
        >
          <TabButton
            isActive={activeTab === "details"}
            onClick={() => requestTab("details")}
          >
            تفاصيل العقد
          </TabButton>
          <TabButton
            isActive={activeTab === "collections"}
            onClick={() => requestTab("collections")}
          >
            جدول التحصيل
          </TabButton>
        </nav>

        <div className="min-h-0 flex-1 overflow-y-auto p-5 sm:p-7">
          {isLoading ? (
            <p className="py-12 text-center text-sm text-[var(--text-secondary)]">
              جارٍ تحميل تفاصيل العقد...
            </p>
          ) : error || !contract ? (
            <p className="py-12 text-center text-sm font-semibold text-[var(--danger)]">
              {error ?? "تعذر تحميل تفاصيل العقد."}
            </p>
          ) : activeTab === "details" ? (
            <ContractDetails
              contract={contract}
              reservation={reservation}
            />
          ) : (
            <CollectionScheduleTab
              key={contract.id}
              contract={contract}
              onDirtyChange={setIsCollectionDirty}
            />
          )}
        </div>

        <ModalFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={requestClose}
          >
            إغلاق
          </Button>
        </ModalFooter>
      </Modal>

      <ConfirmationDialog
        isOpen={pendingNavigation !== null}
        title="تجاهل التعديلات؟"
        description="ستفقد جميع التعديلات غير المحفوظة على جدول التحصيل."
        icon={
          <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--warning-soft)] text-[var(--warning)]">
            <AlertTriangle size={21} />
          </span>
        }
        isProcessing={false}
        closeLabel="إغلاق"
        cancelLabel="متابعة التحرير"
        confirmLabel="تجاهل التعديلات"
        processingLabel="تجاهل التعديلات"
        confirmVariant="danger"
        onCancel={() => setPendingNavigation(null)}
        onConfirm={confirmNavigation}
      />
    </>
  );
}

function ContractDetails({
  contract,
  reservation,
}: {
  contract: Contract;
  reservation: Reservation | null;
}) {
  const project = reservation?.unit?.project;
  const details = [
    {
      label: "المشروع",
      value: project
        ? `${project.project_number} — ${project.name}`
        : "—",
    },
    {
      label: "الوحدة",
      value: reservation?.unit?.unit_number ?? "—",
    },
    {
      label: "العميل",
      value: reservation?.customer?.name ?? "—",
    },
    {
      label: "الحجز",
      value: reservation
        ? shortContractId(reservation.id)
        : shortContractId(contract.reservation_id),
    },
    {
      label: "قيمة العقد",
      value: formatContractAmount(contract.total_amount),
    },
    {
      label: "الحالة",
      value: contractStatusLabels[contract.status],
    },
    {
      label: "تاريخ الإنشاء",
      value: formatContractDate(contract.created_at, true),
    },
    {
      label: "آخر تحديث",
      value: formatContractDate(contract.updated_at, true),
    },
  ];

  return (
    <>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-xl font-bold text-[var(--text-primary)]">
            {reservation?.customer?.name ?? "عقد بيع"}
          </p>
          <p className="mt-1 text-sm text-[var(--text-secondary)]">
            الوحدة {reservation?.unit?.unit_number ?? "—"}
          </p>
        </div>
        <span className="rounded-full bg-[var(--surface-muted)] px-3 py-1 text-xs font-bold text-[var(--text-secondary)]">
          {contractStatusLabels[contract.status]}
        </span>
      </div>

      <dl className="grid gap-4 sm:grid-cols-2">
        {details.map((detail) => (
          <div
            key={detail.label}
            className="rounded-xl bg-[var(--surface-soft)] p-4"
          >
            <dt className="text-xs font-semibold text-[var(--text-secondary)]">
              {detail.label}
            </dt>
            <dd className="mt-1 break-words text-sm font-bold text-[var(--text-primary)]">
              {detail.value}
            </dd>
          </div>
        ))}
      </dl>
    </>
  );
}

function TabButton({
  isActive,
  onClick,
  children,
}: {
  isActive: boolean;
  onClick: () => void;
  children: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={[
        "border-b-2 px-3 py-3 text-sm font-bold transition-colors",
        isActive
          ? "border-[var(--brand-gold)] text-[var(--brand-gold-strong)]"
          : "border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)]",
      ].join(" ")}
    >
      {children}
    </button>
  );
}
