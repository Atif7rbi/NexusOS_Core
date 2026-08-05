import { describe, expect, it } from "vitest";

import { formatDateTime } from "@/lib/date-format";

describe("formatDateTime", () => {
  it("renders datetimes in Asia/Riyadh", () => {
    expect(
      formatDateTime("2026-08-05T06:30:00.000Z")
    ).toBe("05/08/2026, 09:30");
  });
});
