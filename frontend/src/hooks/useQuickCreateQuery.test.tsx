import {
  act,
  renderHook,
  waitFor,
} from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { useQuickCreateQuery } from "@/hooks/useQuickCreateQuery";

const navigation = vi.hoisted(() => ({
  replace: vi.fn((url: string) => {
    window.history.replaceState(null, "", url);
  }),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: navigation.replace }),
  useSearchParams: () =>
    new URLSearchParams(window.location.search),
}));

describe("useQuickCreateQuery", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(null, "", "/projects/");
  });

  it("opens from create=1 and clears only that parameter", async () => {
    window.history.replaceState(
      null,
      "",
      "/projects/?status=active&create=1&page=2"
    );

    const { result, rerender } = renderHook(() => useQuickCreateQuery());

    await waitFor(() => {
      expect(result.current.isRequested).toBe(true);
    });

    act(() => result.current.clear());
    rerender();

    expect(result.current.isRequested).toBe(false);
    expect(window.location.pathname).toBe("/projects/");
    expect(window.location.search).toBe("?status=active&page=2");
    expect(navigation.replace).toHaveBeenCalledWith(
      "/projects/?status=active&page=2",
      { scroll: false }
    );
  });

  it("tracks query navigation in both directions without stale state", async () => {
    window.history.replaceState(null, "", "/projects/?create=1");

    const { result, rerender } = renderHook(() =>
      useQuickCreateQuery()
    );

    await waitFor(() => {
      expect(result.current.isRequested).toBe(true);
    });

    window.history.replaceState(
      null,
      "",
      "/projects/?status=active"
    );
    rerender();

    await waitFor(() => {
      expect(result.current.isRequested).toBe(false);
    });

    window.history.replaceState(
      null,
      "",
      "/projects/?status=active&create=1"
    );
    rerender();

    await waitFor(() => {
      expect(result.current.isRequested).toBe(true);
    });
  });
});
