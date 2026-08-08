import { describe, expect, it } from "vitest";

import {
  buildContextualReservationUrl,
  clearReservationCreateQuery,
  customerRecordFallback,
  parseReservationCreateQuery,
  validateCustomerReturnTo,
} from "@/lib/reservation-create-context";

const customerId = "01K00000000000000000000001";
const otherCustomerId = "01K00000000000000000000002";

describe("reservation create context", () => {
  it("builds a static-export URL with the complete customer return state", () => {
    const returnTo =
      `/customers/?page=3&status=customer&customer=${customerId}`;
    const url = buildContextualReservationUrl(
      customerId,
      returnTo
    );
    const parsed = new URL(
      url,
      "https://nexusos.test"
    );

    expect(parsed.pathname).toBe("/reservations/");
    expect(parsed.searchParams.get("create")).toBe("1");
    expect(parsed.searchParams.get("customer")).toBe(customerId);
    expect(parsed.searchParams.get("returnTo")).toBe(returnTo);
  });

  it("accepts only the matching relative customer record URL", () => {
    const matching =
      `/customers/?search=محمد&customer=${customerId}`;

    expect(
      validateCustomerReturnTo(matching, customerId)
    ).toBe(
      `/customers/?search=%D9%85%D8%AD%D9%85%D8%AF&customer=${customerId}`
    );
    expect(
      validateCustomerReturnTo(
        "//evil.example",
        customerId
      )
    ).toBeNull();
    expect(
      validateCustomerReturnTo(
        "https://evil.example/customers/",
        customerId
      )
    ).toBeNull();
    expect(
      validateCustomerReturnTo(
        `/customers/?customer=${otherCustomerId}`,
        customerId
      )
    ).toBeNull();
    expect(
      validateCustomerReturnTo(
        `/other/../customers/?customer=${customerId}`,
        customerId
      )
    ).toBeNull();
  });

  it("falls back and normalizes an unsafe return target", () => {
    const query = new URLSearchParams({
      create: "1",
      customer: customerId,
      returnTo: "//evil.example",
    });
    const result = parseReservationCreateQuery(
      `?${query.toString()}`
    );

    expect(result.context).toEqual({
      customerId,
      returnTo: customerRecordFallback(customerId),
    });
    expect(result.normalizedUrl).not.toBeNull();

    const normalized = new URL(
      result.normalizedUrl ?? "",
      "https://nexusos.test"
    );

    expect(normalized.searchParams.get("returnTo")).toBe(
      customerRecordFallback(customerId)
    );
  });

  it("removes an incomplete context without losing unrelated state", () => {
    const result = parseReservationCreateQuery(
      "?page=4&create=1&returnTo=%2Fcustomers%2F"
    );

    expect(result.context).toBeNull();
    expect(result.normalizedUrl).toBe(
      "/reservations/?page=4"
    );
    expect(
      clearReservationCreateQuery(
        `?status=active&create=1&customer=${customerId}`
      )
    ).toBe("/reservations/?status=active");
  });
});
