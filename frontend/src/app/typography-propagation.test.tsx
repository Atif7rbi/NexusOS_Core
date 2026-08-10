import { readFileSync } from "node:fs";
import { join } from "node:path";

import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Input";
import { DataTable } from "@/components/ui/crud/DataTable";

const globalsCss = readFileSync(
  join(process.cwd(), "src/app/globals.css"),
  "utf8"
);

describe("global typography propagation", () => {
  it("maps Tailwind operational size utilities to the selected semantic tokens", () => {
    expect(globalsCss).toContain("--text-xs: var(--type-helper)");
    expect(globalsCss).toContain("--text-sm: var(--type-body)");
    expect(globalsCss).toContain("--text-base: var(--type-body)");
    expect(globalsCss).toContain(".type-table :is(th, td)");
  });

  it("keeps shared buttons, inputs, and tables on shared typography primitives", () => {
    render(
      <>
        <Button>حفظ</Button>
        <Input label="اسم المشروع" name="project_name" />
        <DataTable>
          <tbody>
            <tr><td>صف تشغيلي</td></tr>
          </tbody>
        </DataTable>
      </>
    );

    expect(screen.getByRole("button", { name: "حفظ" }).className).toContain(
      "type-button"
    );
    expect(screen.getByRole("textbox").className).toContain("type-input");
    expect(screen.getByRole("table").className).toContain("type-table");
  });
});
