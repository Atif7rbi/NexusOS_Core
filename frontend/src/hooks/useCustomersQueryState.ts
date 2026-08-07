"use client";

import { useSearchParams } from "next/navigation";
import {
  useCallback,
  useEffect,
  useState,
} from "react";

import type {
  CustomerCategory,
  CustomerStatus,
  CustomerType,
} from "@/types/customer";

const CUSTOMER_STATUSES: CustomerStatus[] = [
  "lead",
  "customer",
  "inactive",
  "archived",
];

const CUSTOMER_TYPES: CustomerType[] = [
  "individual",
  "company",
];

const CUSTOMER_CATEGORIES: CustomerCategory[] = [
  "investor",
  "buyer",
  "broker",
  "owner",
  "other",
];

export type CustomersQueryState = {
  page: number;
  search: string;
  status: CustomerStatus | "all";
  type: CustomerType | "all";
  category: CustomerCategory | "all";
  customerId: string | null;
  setPage: (page: number) => void;
  setSearch: (search: string) => void;
  setStatus: (
    status: CustomerStatus | "all"
  ) => void;
  setType: (
    type: CustomerType | "all"
  ) => void;
  setCategory: (
    category: CustomerCategory | "all"
  ) => void;
  openCustomer: (customerId: string) => void;
  closeCustomer: () => void;
};

type QueryPatch = {
  page?: number;
  search?: string;
  status?: CustomerStatus | "all";
  type?: CustomerType | "all";
  category?: CustomerCategory | "all";
  customer?: string | null;
};

export function useCustomersQueryState():
  CustomersQueryState {
  const searchParams = useSearchParams();
  const [, setRevision] = useState(0);

  const currentParams =
    typeof window === "undefined"
      ? new URLSearchParams(
          searchParams.toString()
        )
      : new URLSearchParams(
          window.location.search
        );

  const rawPage = Number(
    currentParams.get("page") ?? "1"
  );

  const page =
    Number.isInteger(rawPage) && rawPage > 0
      ? rawPage
      : 1;

  const search =
    currentParams.get("search") ?? "";

  const rawStatus =
    currentParams.get("status");

  const status =
    rawStatus &&
    CUSTOMER_STATUSES.includes(
      rawStatus as CustomerStatus
    )
      ? (rawStatus as CustomerStatus)
      : "all";

  const rawType =
    currentParams.get("type");

  const type =
    rawType &&
    CUSTOMER_TYPES.includes(
      rawType as CustomerType
    )
      ? (rawType as CustomerType)
      : "all";

  const rawCategory =
    currentParams.get("category");

  const category =
    rawCategory &&
    CUSTOMER_CATEGORIES.includes(
      rawCategory as CustomerCategory
    )
      ? (rawCategory as CustomerCategory)
      : "all";

  const customerId =
    currentParams.get("customer");

  const updateQuery = useCallback(
    (patch: QueryPatch): void => {
      const next = new URLSearchParams(
        window.location.search
      );

      if (patch.page !== undefined) {
        if (patch.page <= 1) {
          next.delete("page");
        } else {
          next.set(
            "page",
            String(patch.page)
          );
        }
      }

      if (patch.search !== undefined) {
        const normalized =
          patch.search.trim();

        if (normalized === "") {
          next.delete("search");
        } else {
          next.set(
            "search",
            normalized
          );
        }
      }

      if (patch.status !== undefined) {
        if (patch.status === "all") {
          next.delete("status");
        } else {
          next.set(
            "status",
            patch.status
          );
        }
      }

      if (patch.type !== undefined) {
        if (patch.type === "all") {
          next.delete("type");
        } else {
          next.set(
            "type",
            patch.type
          );
        }
      }

      if (patch.category !== undefined) {
        if (patch.category === "all") {
          next.delete("category");
        } else {
          next.set(
            "category",
            patch.category
          );
        }
      }

      if (patch.customer !== undefined) {
        if (patch.customer === null) {
          next.delete("customer");
        } else {
          next.set(
            "customer",
            patch.customer
          );
        }
      }

      const path =
        next.size > 0
          ? `/customers/?${next.toString()}`
          : "/customers/";

      window.history.pushState(
        null,
        "",
        path
      );

      setRevision(
        (current) => current + 1
      );
    },
    []
  );

  useEffect(() => {
    const handlePopState = (): void => {
      setRevision(
        (current) => current + 1
      );
    };

    window.addEventListener(
      "popstate",
      handlePopState
    );

    return () => {
      window.removeEventListener(
        "popstate",
        handlePopState
      );
    };
  }, []);

  return {
    page,
    search,
    status,
    type,
    category,
    customerId,

    setPage: (nextPage) => {
      updateQuery({
        page: Math.max(1, nextPage),
      });
    },

    setSearch: (nextSearch) => {
      updateQuery({
        search: nextSearch,
        page: 1,
      });
    },

    setStatus: (nextStatus) => {
      updateQuery({
        status: nextStatus,
        page: 1,
      });
    },

    setType: (nextType) => {
      updateQuery({
        type: nextType,
        page: 1,
      });
    },

    setCategory: (nextCategory) => {
      updateQuery({
        category: nextCategory,
        page: 1,
      });
    },

    openCustomer: (nextCustomerId) => {
      updateQuery({
        customer: nextCustomerId,
      });
    },

    closeCustomer: () => {
      updateQuery({
        customer: null,
      });
    },
  };
}
