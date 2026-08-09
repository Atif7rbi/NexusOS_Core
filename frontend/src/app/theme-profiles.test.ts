import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

const css = readFileSync(
  resolve(process.cwd(), "src/app/globals.css"),
  "utf8"
);

function profileTokens(profile: string): string {
  const pattern = new RegExp(
    `html\\[data-theme="${profile}"\\]\\s*\\{([\\s\\S]*?)\\n\\}`
  );
  const match = css.match(pattern);

  if (!match) {
    throw new Error(`Missing CSS profile: ${profile}`);
  }

  return match[1];
}

function tokenValue(tokens: string, token: string): string {
  const value = tokens.match(
    new RegExp(`--${token}:\\s*(#[0-9a-f]{6});`, "i")
  )?.[1];

  if (!value) {
    throw new Error(`Missing color token: ${token}`);
  }

  return value;
}

function relativeLuminance(hex: string): number {
  const channels = hex
    .slice(1)
    .match(/.{2}/g)
    ?.map((channel) => Number.parseInt(channel, 16) / 255);

  if (!channels) {
    throw new Error(`Invalid color: ${hex}`);
  }

  const [red, green, blue] = channels.map((channel) =>
    channel <= 0.04045
      ? channel / 12.92
      : ((channel + 0.055) / 1.055) ** 2.4
  );

  return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

function contrastRatio(foreground: string, background: string): number {
  const lighter = Math.max(
    relativeLuminance(foreground),
    relativeLuminance(background)
  );
  const darker = Math.min(
    relativeLuminance(foreground),
    relativeLuminance(background)
  );

  return (lighter + 0.05) / (darker + 0.05);
}

describe("appearance profile token contract", () => {
  it.each(["light-1", "light-2"])(
    "%s uses a blue primary action with white foreground",
    (profile) => {
      const tokens = profileTokens(profile);
      expect(tokens).toMatch(/--action-primary:\s*#[12][0-9a-f]{5};/i);
      expect(tokens).toContain("--action-primary-foreground: #ffffff;");
    }
  );

  it.each(["dark-1", "dark-2"])(
    "%s uses an amber primary action with a dark foreground",
    (profile) => {
      const tokens = profileTokens(profile);
      expect(tokens).toMatch(/--action-primary:\s*#(?:e7a52b|f59e0b);/i);
      expect(tokens).toMatch(
        /--action-primary-foreground:\s*#(?:181322|1c1204);/i
      );
    }
  );

  it.each(["light-1", "light-2", "dark-1", "dark-2"])(
    "%s defines a complete branded sidebar surface",
    (profile) => {
      const tokens = profileTokens(profile);
      expect(tokens).toContain("--sidebar-bg:");
      expect(tokens).toContain("--sidebar-hover-bg:");
      expect(tokens).toContain("--sidebar-active-bg:");
      expect(tokens).toContain("--sidebar-active-indicator:");
      expect(tokens).toContain("--sidebar-icon:");
    }
  );

  it.each(["light-1", "light-2"])(
    "%s keeps light sidebar navigation, headings, hover, and active text readable",
    (profile) => {
      const tokens = profileTokens(profile);

      expect(
        contrastRatio(
          tokenValue(tokens, "sidebar-text"),
          tokenValue(tokens, "sidebar-bg")
        )
      ).toBeGreaterThanOrEqual(4.5);
      expect(
        contrastRatio(
          tokenValue(tokens, "sidebar-heading"),
          tokenValue(tokens, "sidebar-bg")
        )
      ).toBeGreaterThanOrEqual(4.5);
      expect(
        contrastRatio(
          tokenValue(tokens, "sidebar-hover-text"),
          tokenValue(tokens, "sidebar-hover-bg")
        )
      ).toBeGreaterThanOrEqual(4.5);
      expect(
        contrastRatio(
          tokenValue(tokens, "sidebar-active-text"),
          tokenValue(tokens, "sidebar-active-bg")
        )
      ).toBeGreaterThanOrEqual(4.5);
    }
  );
});
