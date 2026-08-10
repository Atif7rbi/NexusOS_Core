import type { ReactNode } from "react";

type DataTableProps = {
  children: ReactNode;
  minWidth?: string;
  className?: string;
};

export function DataTable({
  children,
  minWidth = "850px",
  className = "",
}: DataTableProps) {
  return (
    <div className="overflow-x-auto">
      <table
        className={[
          "type-table w-full text-right",
          className,
        ].join(" ")}
        style={{ minWidth }}
      >
        {children}
      </table>
    </div>
  );
}
