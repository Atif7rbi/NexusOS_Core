import {
  afterEach,
  describe,
  expect,
  it,
  vi,
} from "vitest";

import { fetchCustomer } from "@/services/customers";

const originalApiBaseUrl =
  process.env.NEXT_PUBLIC_API_BASE_URL;

describe("customers service", () => {
  afterEach(() => {
    vi.restoreAllMocks();

    if (originalApiBaseUrl === undefined) {
      delete process.env.NEXT_PUBLIC_API_BASE_URL;
    } else {
      process.env.NEXT_PUBLIC_API_BASE_URL =
        originalApiBaseUrl;
    }
  });

  it("fetches one customer record", async () => {
    process.env.NEXT_PUBLIC_API_BASE_URL =
      "https://api.example.test";

    const responseCustomer = {
      id: "01K00000000000000000000001",
      type: "individual",
      category: "buyer",
      status: "customer",
      name: "محمد العميل",
      phone: "0500000001",
      email: null,
      national_id: null,
      commercial_registration_number: null,
      city: null,
      address: null,
      notes: null,
      archived_at: null,
      created_at: "2026-08-05T06:30:00.000Z",
      updated_at: "2026-08-05T06:30:00.000Z",
    };

    const fetchMock = vi
      .spyOn(globalThis, "fetch")
      .mockResolvedValue(
        new Response(
          JSON.stringify({
            data: {
              customer: responseCustomer,
              business_context: {
                summary: {
                  reservations_total: 0,
                  contracts_total: 0,
                },
                journeys: [],
              },
            },
          }),
          {
            status: 200,
            headers: {
              "Content-Type": "application/json",
            },
          }
        )
      );

    await expect(
      fetchCustomer(
        "token",
        responseCustomer.id
      )
    ).resolves.toEqual({
      customer: responseCustomer,
      business_context: {
        summary: {
          reservations_total: 0,
          contracts_total: 0,
        },
        journeys: [],
      },
    });

    expect(fetchMock).toHaveBeenCalledWith(
      `https://api.example.test/customers/${responseCustomer.id}`,
      {
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          Authorization: "Bearer token",
        },
        cache: "no-store",
      }
    );
  });

  it("throws the parsed API error", async () => {
    process.env.NEXT_PUBLIC_API_BASE_URL =
      "https://api.example.test";

    vi.spyOn(
      globalThis,
      "fetch"
    ).mockResolvedValue(
      new Response(
        JSON.stringify({
          message: "Customer not found.",
        }),
        {
          status: 404,
          headers: {
            "Content-Type": "application/json",
          },
        }
      )
    );

    await expect(
      fetchCustomer(
        "token",
        "missing-customer"
      )
    ).rejects.toMatchObject({
      message: "Customer not found.",
      status: 404,
    });
  });
});
