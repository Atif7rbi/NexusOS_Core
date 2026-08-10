import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Input } from "@/components/ui/Input";

describe("Input", () => {
  it("uses the shared primary form-label semantic class while keeping placeholders muted", () => {
    render(
      <Input
        label="اسم المشروع"
        name="project_name"
        placeholder="أدخل اسم المشروع"
      />
    );

    expect(screen.getByText("اسم المشروع").className).toContain(
      "type-form-label"
    );
    expect(screen.getByPlaceholderText("أدخل اسم المشروع").className).toContain(
      "placeholder:text-[var(--text-muted)]"
    );
  });
});
