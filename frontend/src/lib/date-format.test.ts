import { describe, expect, it } from "vitest";

import {
  formatDate,
  formatDateTime,
} from "@/lib/date-format";

describe("formatDate", () => {
  it("renders an ISO date-only value without a timezone shift", () => {
    expect(formatDate("2026-09-01")).toBe("01/09/2026");
  });
});

describe("formatDateTime", () => {
  it("renders datetimes in Asia/Riyadh", () => {
    expect(
      formatDateTime("2026-08-05T06:30:00.000Z")
    ).toBe("05/08/2026, 09:30");
  });
});
