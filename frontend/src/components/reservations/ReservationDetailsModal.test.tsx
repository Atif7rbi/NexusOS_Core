import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { ReservationDetailsModal } from "@/components/reservations/ReservationDetailsModal";
import type {
  Reservation,
  ReservationStatus,
} from "@/types/reservation";

const reservation = (status: ReservationStatus): Reservation => ({
  id: "01K00000000000000000000001",
  tenant_id: "tenant-1",
  unit_id: "unit-1",
  customer_id: "customer-1",
  status,
  reserved_at: "2026-08-14T08:00:00.000Z",
  expires_at: "2026-08-20T08:00:00.000Z",
  notes: null,
  cancellation_reason: null,
  cancelled_at: null,
  cancelled_by: null,
  created_by: 7,
  updated_by: 7,
  created_at: "",
  updated_at: "",
});

describe("ReservationDetailsModal contract action", () => {
  it("links an authorized active reservation to contextual contract creation", () => {
    const href =
      "/contracts/?create=1&reservation=01K00000000000000000000001";

    render(
      <ReservationDetailsModal
        reservation={reservation("active")}
        isLoading={false}
        error={null}
        createContractHref={href}
        onClose={() => undefined}
      />
    );

    expect(
      screen
        .getByRole("link", { name: "إنشاء عقد" })
        .getAttribute("href")
    ).toBe(href);
  });

  it.each<ReservationStatus>([
    "converted",
    "cancelled",
    "expired",
  ])("hides the action for a %s reservation", (status) => {
    render(
      <ReservationDetailsModal
        reservation={reservation(status)}
        isLoading={false}
        error={null}
        createContractHref="/contracts/?create=1"
        onClose={() => undefined}
      />
    );

    expect(
      screen.queryByRole("link", { name: "إنشاء عقد" })
    ).toBeNull();
  });

  it("hides the action when authorization supplies no contextual URL", () => {
    render(
      <ReservationDetailsModal
        reservation={reservation("active")}
        isLoading={false}
        error={null}
        createContractHref={null}
        onClose={() => undefined}
      />
    );

    expect(
      screen.queryByRole("link", { name: "إنشاء عقد" })
    ).toBeNull();
  });
});
