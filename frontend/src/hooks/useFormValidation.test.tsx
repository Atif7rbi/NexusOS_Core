import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { useFormValidation } from "@/hooks/useFormValidation";
import { ApiRequestError } from "@/lib/api-error";

function ValidationHarness({ hidden = false }: { hidden?: boolean }) {
  const {
    formRef,
    fieldErrors,
    formError,
    setValidationError,
  } = useFormValidation();

  const field = hidden ? "server_only" : "name";

  return (
    <form ref={formRef}>
      <label htmlFor="name">الاسم</label>
      <input id="name" name="name" />
      <span>{fieldErrors[field]?.[0] ?? "no-field-error"}</span>
      <span>{formError ?? "no-form-error"}</span>
      <button
        type="button"
        onClick={() =>
          setValidationError(
            new ApiRequestError(
              "Validation failed",
              { [field]: ["رسالة التحقق"] },
              422,
              "validation_failed"
            ),
            "تعذر حفظ النموذج."
          )
        }
      >
        fail
      </button>
    </form>
  );
}

describe("useFormValidation", () => {
  it("shows a field error without a redundant general banner", () => {
    render(<ValidationHarness />);

    fireEvent.click(screen.getByRole("button", { name: "fail" }));

    expect(screen.getByText("رسالة التحقق")).toBeTruthy();
    expect(screen.getByText("no-form-error")).toBeTruthy();
  });

  it("promotes hidden field errors to the general banner", () => {
    render(<ValidationHarness hidden />);

    fireEvent.click(screen.getByRole("button", { name: "fail" }));

    expect(screen.getAllByText("رسالة التحقق")).toHaveLength(2);
  });
});
