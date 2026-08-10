import {
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from "vitest";

import {
  TypographyProvider,
  useTypography,
} from "@/providers/TypographyProvider";

const authState = vi.hoisted(() => ({
  user: null as { id: number } | null,
  isLoading: false,
}));

vi.mock("@/providers/AuthProvider", () => ({
  useAuth: () => authState,
}));

function TypographyProbe() {
  const {
    textSize,
    fontFamily,
    setTextSize,
    setFontFamily,
  } = useTypography();

  return (
    <div>
      <output>{`${textSize}:${fontFamily}`}</output>
      <button type="button" onClick={() => setTextSize("large")}>
        large
      </button>
      <button type="button" onClick={() => setFontFamily("tajawal")}>
        tajawal
      </button>
    </div>
  );
}

function renderProvider(userId: number | null) {
  authState.user = userId === null ? null : { id: userId };
  return render(
    <TypographyProvider>
      <TypographyProbe />
    </TypographyProvider>
  );
}

function rerenderProvider(
  view: ReturnType<typeof render>,
  userId: number | null
): void {
  authState.user = userId === null ? null : { id: userId };
  view.rerender(
    <TypographyProvider>
      <TypographyProbe />
    </TypographyProvider>
  );
}

describe("TypographyProvider", () => {
  beforeEach(() => {
    authState.user = null;
    authState.isLoading = false;
  });

  afterEach(() => {
    window.localStorage.clear();
    delete document.documentElement.dataset.textSize;
    delete document.documentElement.dataset.font;
  });

  it("persists text size and font separately for each authenticated user", async () => {
    const view = renderProvider(11);

    fireEvent.click(screen.getByRole("button", { name: "large" }));
    fireEvent.click(screen.getByRole("button", { name: "tajawal" }));

    await waitFor(() => {
      expect(document.documentElement.dataset.textSize).toBe("large");
    });
    expect(document.documentElement.dataset.font).toBe("tajawal");
    expect(window.localStorage.getItem("ufq_text_size:user:11")).toBe("large");
    expect(window.localStorage.getItem("ufq_font:user:11")).toBe("tajawal");

    rerenderProvider(view, null);
    await waitFor(() => {
      expect(screen.getByText("normal:ibm-plex-sans-arabic")).toBeTruthy();
    });

    rerenderProvider(view, 22);
    expect(screen.getByText("normal:ibm-plex-sans-arabic")).toBeTruthy();
    expect(window.localStorage.getItem("ufq_text_size:user:22")).toBeNull();
    expect(window.localStorage.getItem("ufq_font:user:22")).toBeNull();

    rerenderProvider(view, 11);
    await waitFor(() => {
      expect(screen.getByText("large:tajawal")).toBeTruthy();
    });
  });

  it("removes malformed user storage and safely applies defaults", async () => {
    window.localStorage.setItem("ufq_text_size:user:7", "huge");
    window.localStorage.setItem("ufq_font:user:7", "unsupported");
    renderProvider(7);

    await waitFor(() => {
      expect(document.documentElement.dataset.textSize).toBe("normal");
    });
    expect(document.documentElement.dataset.font).toBe("ibm-plex-sans-arabic");
    expect(window.localStorage.getItem("ufq_text_size:user:7")).toBeNull();
    expect(window.localStorage.getItem("ufq_font:user:7")).toBeNull();
  });
});
