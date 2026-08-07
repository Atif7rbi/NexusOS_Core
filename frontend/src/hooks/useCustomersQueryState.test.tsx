import {
  act,
  renderHook,
} from "@testing-library/react";
import {
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from "vitest";

import { useCustomersQueryState } from "@/hooks/useCustomersQueryState";

vi.mock("next/navigation", () => ({
  useSearchParams: () =>
    new URLSearchParams(
      window.location.search
    ),
}));

describe("useCustomersQueryState", () => {
  beforeEach(() => {
    window.history.replaceState(
      null,
      "",
      "/customers/"
    );
  });

  it("reads valid query state and normalizes invalid values", () => {
    window.history.replaceState(
      null,
      "",
      [
        "/customers/?page=3",
        "search=محمد",
        "status=customer",
        "type=individual",
        "category=buyer",
        "customer=customer-1",
      ].join("&")
    );

    const { result } = renderHook(
      () => useCustomersQueryState()
    );

    expect(result.current).toMatchObject({
      page: 3,
      search: "محمد",
      status: "customer",
      type: "individual",
      category: "buyer",
      customerId: "customer-1",
    });

    window.history.replaceState(
      null,
      "",
      "/customers/?page=-2&status=invalid&type=invalid&category=invalid"
    );

    const invalid = renderHook(
      () => useCustomersQueryState()
    );

    expect(invalid.result.current).toMatchObject({
      page: 1,
      status: "all",
      type: "all",
      category: "all",
    });
  });

  it("updates filters in the URL and resets page", () => {
    window.history.replaceState(
      null,
      "",
      "/customers/?page=4&status=customer"
    );

    const { result } = renderHook(
      () => useCustomersQueryState()
    );

    act(() => {
      result.current.setType(
        "individual"
      );
    });

    expect(window.location.pathname).toBe(
      "/customers/"
    );

    expect(
      new URLSearchParams(
        window.location.search
      ).toString()
    ).toBe(
      "status=customer&type=individual"
    );

    expect(result.current.page).toBe(1);
    expect(result.current.type).toBe(
      "individual"
    );
  });

  it("opens and closes a record without losing list state", () => {
    window.history.replaceState(
      null,
      "",
      "/customers/?page=3&status=customer&search=محمد"
    );

    const { result } = renderHook(
      () => useCustomersQueryState()
    );

    act(() => {
      result.current.openCustomer(
        "customer-1"
      );
    });

    expect(
      window.location.search
    ).toBe(
      "?page=3&status=customer&search=%D9%85%D8%AD%D9%85%D8%AF&customer=customer-1"
    );

    expect(
      result.current.customerId
    ).toBe("customer-1");

    act(() => {
      result.current.closeCustomer();
    });

    expect(
      window.location.search
    ).toBe(
      "?page=3&status=customer&search=%D9%85%D8%AD%D9%85%D8%AF"
    );

    expect(
      result.current.customerId
    ).toBeNull();
    expect(result.current.page).toBe(3);
    expect(result.current.status).toBe(
      "customer"
    );
    expect(result.current.search).toBe(
      "محمد"
    );
  });

  it("removes default values from the URL", () => {
    window.history.replaceState(
      null,
      "",
      "/customers/?page=3&status=customer&type=company&category=investor"
    );

    const { result } = renderHook(
      () => useCustomersQueryState()
    );

    act(() => {
      result.current.setPage(1);
      result.current.setStatus("all");
      result.current.setType("all");
      result.current.setCategory("all");
    });

    expect(window.location.pathname).toBe(
      "/customers/"
    );

    expect(window.location.search).toBe(
      ""
    );
  });
});
