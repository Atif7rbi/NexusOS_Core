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
});
