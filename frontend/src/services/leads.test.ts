import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  addLeadNote,
  archiveLead,
  assignLead,
  claimLead,
  createLead,
  fetchLead,
  fetchLeadActivities,
  fetchLeads,
  LeadDuplicateError,
  moveLeadStage,
  moveLeadToLost,
  reopenLead,
  restoreLead,
  updateLead,
} from "@/services/leads";
import { leadFixture } from "@/test/lead-fixtures";

const lead = leadFixture();

function response(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

describe("leads API client", () => {
  beforeEach(() => {
    process.env.NEXT_PUBLIC_API_BASE_URL = "https://api.example.test/api";
  });

  it("composes list filters and pagination", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      response({ data: { leads: [], summary: {}, pagination: {} } })
    );

    await fetchLeads("token", {
      search: "Acme",
      stage: "qualified",
      overdue: true,
      page: 2,
    });

    expect(fetchMock.mock.calls[0]?.[0]).toBe(
      "https://api.example.test/api/leads?search=Acme&stage=qualified&overdue=true&page=2"
    );
  });

  it("loads details and archived activities through the approved endpoints", async () => {
    const fetchMock = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValueOnce(response({ data: { lead } }))
      .mockResolvedValueOnce(
        response({ data: { activities: [], pagination: {} } })
      );

    await fetchLead("token", lead.id, true);
    await fetchLeadActivities("token", lead.id, true);

    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      `https://api.example.test/api/leads/${lead.id}?archived=true`,
      `https://api.example.test/api/leads/${lead.id}/activities?per_page=100&archived=true`,
    ]);
  });

  it("submits the canonical create payload without normalized_phone", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      response({ data: { lead } }, 201)
    );

    await createLead("token", {
      name: "Test Lead",
      phone: "0501234567",
      source: "website",
    });

    const request = fetchMock.mock.calls[0]?.[1];
    expect(JSON.parse(String(request?.body))).toEqual({
      name: "Test Lead",
      phone: "0501234567",
      source: "website",
    });
    expect(String(request?.body)).not.toContain("normalized_phone");
  });

  it("exposes only duplicate matches returned by the API", async () => {
    const matches = [
      {
        id: "visible-match",
        name: "Visible match",
        stage: "new",
        assigned_to: null,
        archived: false,
        updated_at: null,
      },
    ];
    vi.spyOn(globalThis, "fetch").mockResolvedValue(
      response(
        {
          error: {
            code: "lead_phone_duplicate_detected",
            message: "Review duplicates",
            matches,
          },
        },
        409
      )
    );

    const caught = await createLead("token", {
      name: "Test Lead",
      phone: "0501234567",
      source: "website",
    }).catch((error: unknown) => error);

    expect(caught).toBeInstanceOf(LeadDuplicateError);
    expect((caught as LeadDuplicateError).matches).toEqual(matches);
  });

  it.each([
    ["update", () => updateLead("token", lead.id, { name: "Updated" }), "", { name: "Updated" }],
    ["claim", () => claimLead("token", lead.id), "/claim", undefined],
    ["assign", () => assignLead("token", lead.id, 9), "/assign", { assigned_to: 9 }],
    ["unassign", () => assignLead("token", lead.id, null), "/assign", { assigned_to: null }],
    ["stage", () => moveLeadStage("token", lead.id, "qualified"), "/stage", { stage: "qualified" }],
    ["lost", () => moveLeadToLost("token", lead.id, "competitor", null), "/lose", { lost_reason: "competitor", lost_reason_detail: null }],
    ["reopen", () => reopenLead("token", lead.id), "/reopen", undefined],
    ["archive", () => archiveLead("token", lead.id, "duplicate", null), "/archive", { archive_reason: "duplicate", archive_reason_detail: null }],
    ["restore", () => restoreLead("token", lead.id), "/restore", undefined],
  ] as const)("sends the %s command with its exact contract", async (_name, invoke, suffix, body) => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      response({ data: { lead } })
    );

    await invoke();

    const [url, request] = fetchMock.mock.calls[0] ?? [];
    expect(url).toBe(`https://api.example.test/api/leads/${lead.id}${suffix}`);
    expect(request?.method).toBe("PATCH");
    expect(request?.body).toBe(body === undefined ? undefined : JSON.stringify(body));
  });

  it("adds a note through the activity endpoint", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      response({ data: { activity: { id: "activity" } } }, 201)
    );

    await addLeadNote("token", lead.id, "Called the customer");

    expect(fetchMock).toHaveBeenCalledWith(
      `https://api.example.test/api/leads/${lead.id}/notes`,
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ body: "Called the customer" }),
      })
    );
  });
});
