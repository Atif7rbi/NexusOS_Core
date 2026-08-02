import { describe, expect, it } from "vitest";

import { arSA } from "@/i18n/ar-SA";
import { enUS } from "@/i18n/en-US";

describe("CRM translations", () => {
  it("contains matching Arabic and English CRM keys with non-empty text", () => {
    const arabicKeys = Object.keys(arSA).filter((key) =>
      key.startsWith("crm.") || key.startsWith("pages.crm.")
    );
    const englishKeys = Object.keys(enUS).filter((key) =>
      key.startsWith("crm.") || key.startsWith("pages.crm.")
    );

    expect(arabicKeys.length).toBeGreaterThan(100);
    expect(new Set(arabicKeys)).toEqual(new Set(englishKeys));
    arabicKeys.forEach((key) => {
      expect(arSA[key as keyof typeof arSA].trim()).not.toBe("");
      expect(enUS[key as keyof typeof enUS].trim()).not.toBe("");
    });
  });

  it("renders meaningful labels in both languages", () => {
    expect(arSA["pages.crm.title"]).toMatch(/[\u0600-\u06FF]/);
    expect(enUS["pages.crm.title"]).toMatch(/[A-Za-z]/);
    expect(arSA["crm.actions.claim"]).not.toBe(enUS["crm.actions.claim"]);
  });
});
