import { Button } from "@/components/ui/Button";
import { formatInteger } from "@/lib/number-format";

type PaginationProps = {
  page: number;
  lastPage: number;
  isLoading?: boolean;
  previousLabel: string;
  nextLabel: string;
  pageLabel: string;
  ofLabel: string;
  onPrevious: () => void;
  onNext: () => void;
};

export function Pagination({
  page,
  lastPage,
  isLoading = false,
  previousLabel,
  nextLabel,
  pageLabel,
  ofLabel,
  onPrevious,
  onNext,
}: PaginationProps) {
  if (lastPage <= 1) {
    return null;
  }

  return (
    <div className="flex items-center justify-between border-t border-[var(--border)] pt-5">
      <Button
        type="button"
        variant="secondary"
        disabled={isLoading || page === 1}
        onClick={onPrevious}
      >
        {previousLabel}
      </Button>

      <p className="text-sm font-semibold text-[var(--text-secondary)]">
        {pageLabel} {formatInteger(page)} {ofLabel} {formatInteger(lastPage)}
      </p>

      <Button
        type="button"
        variant="secondary"
        disabled={isLoading || page === lastPage}
        onClick={onNext}
      >
        {nextLabel}
      </Button>
    </div>
  );
}
