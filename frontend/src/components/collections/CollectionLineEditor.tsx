"use client";

import {
  ArrowDown,
  ArrowUp,
  GripVertical,
  Plus,
  Trash2,
} from "lucide-react";

import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { useTranslation } from "@/hooks/useTranslation";
import type { CollectionLineInput } from "@/types/collection";

export type CollectionLineErrors = Record<
  string,
  Partial<
    Record<
      "title" | "amount" | "due_date" | "sequence",
      string
    >
  >
>;

type CollectionLineEditorProps = {
  lines: CollectionLineInput[];
  errors: CollectionLineErrors;
  disabled?: boolean;
  onChange: (
    key: string,
    field: "title" | "amount" | "due_date" | "notes",
    value: string
  ) => void;
  onAdd: () => void;
  onDelete: (key: string) => void;
  onMove: (index: number, direction: -1 | 1) => void;
};

export function CollectionLineEditor({
  lines,
  errors,
  disabled = false,
  onChange,
  onAdd,
  onDelete,
  onMove,
}: CollectionLineEditorProps) {
  const { t } = useTranslation();

  return (
    <div className="space-y-4">
      {lines.map((line, index) => {
        const lineErrors = errors[line._key] ?? {};

        return (
          <div
            key={line._key}
            className="rounded-xl border border-[var(--border)] bg-[var(--surface-soft)] p-4"
          >
            <div className="mb-4 flex items-center justify-between gap-3">
              <div className="flex items-center gap-2 text-sm font-bold text-[var(--text-primary)]">
                <GripVertical
                  size={18}
                  className="text-[var(--text-muted)]"
                />
                <span>
                  {t("collection.line.sequence")}{" "}
                  {line.sequence}
                </span>
              </div>

              <div className="flex items-center gap-1">
                <button
                  type="button"
                  aria-label={t("collection.line.moveUp")}
                  title={t("collection.line.moveUp")}
                  disabled={disabled || index === 0}
                  onClick={() => onMove(index, -1)}
                  className="rounded-lg p-2 text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] disabled:pointer-events-none disabled:opacity-40"
                >
                  <ArrowUp size={17} />
                </button>
                <button
                  type="button"
                  aria-label={t("collection.line.moveDown")}
                  title={t("collection.line.moveDown")}
                  disabled={
                    disabled || index === lines.length - 1
                  }
                  onClick={() => onMove(index, 1)}
                  className="rounded-lg p-2 text-[var(--text-secondary)] hover:bg-[var(--surface-muted)] disabled:pointer-events-none disabled:opacity-40"
                >
                  <ArrowDown size={17} />
                </button>
                <button
                  type="button"
                  aria-label={t("collection.line.delete")}
                  title={t("collection.line.delete")}
                  disabled={disabled}
                  onClick={() => onDelete(line._key)}
                  className="rounded-lg p-2 text-[var(--danger)] hover:bg-[var(--danger-soft)] disabled:pointer-events-none disabled:opacity-40"
                >
                  <Trash2 size={17} />
                </button>
              </div>
            </div>
            {lineErrors.sequence ? (
              <p className="mb-4 text-xs font-semibold text-[var(--danger)]">
                {lineErrors.sequence}
              </p>
            ) : null}

            <div className="grid gap-4 sm:grid-cols-2">
              <Input
                label={t("collection.line.title")}
                value={line.title}
                error={lineErrors.title}
                disabled={disabled}
                maxLength={150}
                onChange={(event) =>
                  onChange(
                    line._key,
                    "title",
                    event.target.value
                  )
                }
              />
              <Input
                label={t("collection.line.amount")}
                value={line.amount}
                error={lineErrors.amount}
                disabled={disabled}
                inputMode="decimal"
                dir="ltr"
                onChange={(event) =>
                  onChange(
                    line._key,
                    "amount",
                    event.target.value
                  )
                }
              />
              <Input
                label={t("collection.line.dueDate")}
                type="date"
                value={line.due_date}
                error={lineErrors.due_date}
                disabled={disabled}
                dir="ltr"
                onChange={(event) =>
                  onChange(
                    line._key,
                    "due_date",
                    event.target.value
                  )
                }
              />
              <Input
                label={t("collection.line.notes")}
                value={line.notes}
                disabled={disabled}
                onChange={(event) =>
                  onChange(
                    line._key,
                    "notes",
                    event.target.value
                  )
                }
              />
            </div>
          </div>
        );
      })}

      <Button
        type="button"
        variant="secondary"
        size="sm"
        disabled={disabled}
        leadingIcon={<Plus size={16} />}
        onClick={onAdd}
      >
        {t("collection.line.addLine")}
      </Button>
    </div>
  );
}

export function createEmptyCollectionLine(
  sequence: number
): CollectionLineInput {
  return {
    _key: crypto.randomUUID(),
    id: null,
    sequence,
    title: "",
    amount: "",
    due_date: "",
    notes: "",
  };
}

export function cloneCollectionLines(
  lines: Array<{
    id: string;
    sequence: number;
    title: string;
    amount: string;
    due_date: string;
    notes: string | null;
  }>,
  preserveIds: boolean
): CollectionLineInput[] {
  return lines.map((line) => ({
    _key: crypto.randomUUID(),
    id: preserveIds ? line.id : null,
    sequence: line.sequence,
    title: line.title,
    amount: line.amount,
    due_date: line.due_date,
    notes: line.notes ?? "",
  }));
}

export function collectionLinesEqual(
  left: CollectionLineInput[],
  right: CollectionLineInput[]
): boolean {
  if (left.length !== right.length) {
    return false;
  }

  return left.every((line, index) => {
    const other = right[index];

    return (
      other !== undefined &&
      line.id === other.id &&
      line.sequence === other.sequence &&
      line.title === other.title &&
      line.amount === other.amount &&
      line.due_date === other.due_date &&
      line.notes === other.notes
    );
  });
}

export function isValidCalendarDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return false;
  }

  const [year, month, day] = value
    .split("-")
    .map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));

  return (
    date.getUTCFullYear() === year &&
    date.getUTCMonth() === month - 1 &&
    date.getUTCDate() === day
  );
}
