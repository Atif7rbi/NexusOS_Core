import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Button } from "@/components/ui/Button";

describe("Button", () => {
  it.each([
    ["primary", "--action-primary", "--action-primary-foreground"],
    ["accent", "--action-accent", "--action-accent-foreground"],
    ["danger", "--action-danger", "--action-danger-foreground"],
  ] as const)(
    "uses the semantic %s action contract",
    (variant, backgroundToken, foregroundToken) => {
      render(<Button variant={variant}>Action</Button>);

      const className = screen.getByRole("button").className;

      expect(className).toContain(backgroundToken);
      expect(className).toContain(foregroundToken);
      expect(className).not.toContain("text-white");
    }
  );

  it("applies the shared disabled contract", () => {
    render(<Button disabled>Disabled action</Button>);

    const button = screen.getByRole("button");

    expect(
      (button as HTMLButtonElement).disabled
    ).toBe(true);
    expect(button.className).toContain("disabled:opacity-60");
    expect(button.className).toContain("--surface-muted");
    expect(button.className).toContain("--text-muted");
  });
});
