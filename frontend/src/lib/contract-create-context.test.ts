import { describe, expect, it } from "vitest";

import {
  buildReservationListReturnTo,
  buildContextualContractUrl,
  clearContractCreateQuery,
  parseContractCreateQuery,
  validateReservationReturnTo,
} from "@/lib/contract-create-context";

const reservationId = "01K00000000000000000000001";

describe("contract create context", () => {
  it("builds a deterministic return URL from effective list state", () => {
    expect(
      buildReservationListReturnTo({
        page: 3,
        search: "  محمد  ",
        statusFilter: "active",
        projectFilter: "project-1",
      })
    ).toBe(
      "/reservations/?page=3&search=%D9%85%D8%AD%D9%85%D8%AF&status=active&project_id=project-1"
    );

    expect(
      buildReservationListReturnTo({
        page: 1,
        search: " ",
        statusFilter: "all",
        projectFilter: "all",
      })
    ).toBe("/reservations/");
  });

  it("builds a static-export URL and preserves reservation list state", () => {
    const returnTo =
      "/reservations/?page=3&status=active&project=project-1";
    const url = buildContextualContractUrl(
      reservationId,
      returnTo
    );
    const parsed = new URL(url, "https://nexusos.test");

    expect(parsed.pathname).toBe("/contracts/");
    expect(parsed.searchParams.get("create")).toBe("1");
    expect(parsed.searchParams.get("reservation")).toBe(
      reservationId
    );
    expect(parsed.searchParams.get("returnTo")).toBe(returnTo);
  });

  it("accepts only a relative reservations URL without a fragment", () => {
    expect(
      validateReservationReturnTo(
        "/reservations/?page=2&status=active"
      )
    ).toBe("/reservations/?page=2&status=active");
    expect(
      validateReservationReturnTo("//evil.example")
    ).toBeNull();
    expect(
      validateReservationReturnTo(
        "https://evil.example/reservations/"
      )
    ).toBeNull();
    expect(
      validateReservationReturnTo(
        "http://evil.example/reservations/"
      )
    ).toBeNull();
    expect(
      validateReservationReturnTo("/contracts/")
    ).toBeNull();
    expect(
      validateReservationReturnTo(
        "/other/../reservations/"
      )
    ).toBeNull();
    expect(
      validateReservationReturnTo("/reservations/#details")
    ).toBeNull();
  });

  it("normalizes unsafe return targets to the reservations list", () => {
    const query = new URLSearchParams({
      create: "1",
      reservation: reservationId,
      returnTo: "//evil.example",
    });
    const result = parseContractCreateQuery(
      `?${query.toString()}`
    );

    expect(result.context).toEqual({
      reservationId,
      returnTo: "/reservations/",
    });
    expect(result.normalizedUrl).not.toBeNull();

    const normalized = new URL(
      result.normalizedUrl ?? "",
      "https://nexusos.test"
    );
    expect(normalized.searchParams.get("returnTo")).toBe(
      "/reservations/"
    );
  });

  it("removes invalid or completed context without losing unrelated state", () => {
    expect(
      parseContractCreateQuery(
        "?page=4&create=1&reservation=invalid"
      )
    ).toEqual({
      context: null,
      normalizedUrl: "/contracts/?page=4",
    });

    expect(
      clearContractCreateQuery(
        `?status=draft&create=1&reservation=${reservationId}&returnTo=%2Freservations%2F`
      )
    ).toBe("/contracts/?status=draft");
  });
});
