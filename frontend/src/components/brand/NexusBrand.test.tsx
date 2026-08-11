import { render, screen } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

import { NexusBrand } from "@/components/brand/NexusBrand";

describe("NexusBrand typography contract", () => {
  it("keeps the wordmark and one-line subtitle outside scalable application typography", () => {
    render(<NexusBrand inverse />);

    const wordmark = screen.getByText("Nexus", { exact: false });
    const subtitle = screen.getByText("Business Operating System");

    expect(wordmark.className).toContain("nexus-brand-wordmark");
    expect(wordmark.className).not.toContain("type-");
    expect(subtitle.className).toContain("nexus-brand-subtitle");
    expect(subtitle.className).not.toContain("type-");
  });

  it("defines fixed brand type and a one-line subtitle outside text-size tokens", () => {
    const css = readFileSync(
      resolve(process.cwd(), "src/app/globals.css"),
      "utf8"
    );

    expect(css).toMatch(/\.nexus-brand-wordmark\s*\{[\s\S]*font-size:\s*20px !important;/);
    expect(css).toMatch(/\.nexus-brand-subtitle\s*\{[\s\S]*font-size:\s*12px !important;/);
    expect(css).toMatch(/\.nexus-brand-subtitle\s*\{[\s\S]*white-space:\s*nowrap;/);
    expect(css).toMatch(/\.nexus-brand-wordmark--large\s*\{[\s\S]*font-size:\s*36px !important;/);
    expect(css).toMatch(/\.nexus-brand-subtitle--large\s*\{[\s\S]*font-size:\s*14px !important;/);
  });

  it("keeps a distinct fixed large brand variant", () => {
    render(<NexusBrand large inverse />);

    expect(screen.getByText("Nexus", { exact: false }).className).toContain(
      "nexus-brand-wordmark--large"
    );
    expect(screen.getByText("Business Operating System").className).toContain(
      "nexus-brand-subtitle--large"
    );
  });
});
