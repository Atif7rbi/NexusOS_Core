# Collections Frontend Specification v1

**Status:** FROZEN
**Project:** NexusOS Pilot
**Repository:** Atif7rbi/ufq-pilot
**Depends on:** Collections Backend API Specification v1

---

## 1. Purpose

This document defines the frontend implementation for the `/collections` page. It assumes `GET /api/collections` is available and returns the contract defined in the Backend API Specification.

---

## 2. Navigation

The navigation entry already exists in `src/config/navigation.ts` under the **المالية** group (`href: "/collections/"`, icon `HandCoins`). No change required.

The route `/collections/` exists as `src/app/collections/page.tsx` (currently a placeholder). This specification replaces it.

**Permissions:** Accessible to any authenticated user with active tenant membership. No role gate at the page level.

---

## 3. Types and Services

### 3.1 Extend existing types

Do not create `src/types/collections-index.ts`. Add the following to the existing `src/types/collection.ts`, importing `ContractStatus` from `src/types/contract.ts` and reusing the existing `DerivedScheduleState` type already defined in that file.

```typescript
// src/types/collection.ts — additions

import type { ContractStatus } from '@/types/contract';

// Re-export for use within the module
export type { ContractStatus };

export type CollectionsIndexItem = {
  contract_id: string;
  contract_status: ContractStatus;
  contract_total_amount: string;    // decimal string e.g. "1000000.00"
  currency: string;                 // e.g. "SAR"
  customer_name: string | null;
  unit_number: string | null;
  project_name: string | null;
  schedule_state: DerivedScheduleState;  // reuses existing type — see note below
  schedule_active_total: string;    // decimal string; "0.00" when absent/cancelled
};

// Note: The Collections index never exposes the internal integrity-violation
// state. Contracts whose derived schedule state resolves to that internal
// state are excluded from the endpoint entirely, as defined by the Backend
// API Specification. Accordingly, frontend code never receives or handles
// that internal state. The DerivedScheduleState type as used here covers
// only: 'absent' | 'draft' | 'scheduled' | 'cancelled'.

export type CollectionsIndexPagination = {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;     // filter-scoped
};

export type CollectionsIndexSummary = {
  total_contracts: number;    // tenant-wide
  scheduled_count: number;    // tenant-wide
  draft_count: number;        // tenant-wide
  absent_count: number;       // tenant-wide
  cancelled_count: number;    // tenant-wide, present in API but no card in v1
};

export type CollectionsIndexResponse = {
  data: {
    items: CollectionsIndexItem[];
    pagination: CollectionsIndexPagination;
    summary: CollectionsIndexSummary;
  };
};

export type CollectionsIndexQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: ContractStatus | '';
  schedule_state?: DerivedScheduleState | '';
};
```

### 3.2 Extend existing service

Do not create `src/services/collections-index.ts`. Add the following to the existing `src/services/collections.ts`, reusing `createQueryString` and `requestJson` from `@/lib/http`.

```typescript
// src/services/collections.ts — addition

export async function fetchCollectionsIndex(
  token: string,
  query: CollectionsIndexQuery
): Promise<CollectionsIndexResponse>
// GET /collections with query params
// Omit empty-string values from the query string
// Throws ApiRequestError on non-OK responses
```

The service path is `/collections` (the configured API base URL already includes the `/api` prefix).

---

## 4. Page Layout

Follows the structural pattern of `src/app/contracts/page.tsx`. All layout components (`AppShell`, `CrudPageLayout`, `CrudPageHeader`, `CrudSection`, `SummaryCard`, `ListLoadingState`, `ListEmptyState`, `ListErrorState`, `Pagination`) are available in the existing codebase.

```
<AppShell>
  <CrudPageLayout>
    <CrudPageHeader
      title={t("pages.collections.title")}
      description={t("pages.collections.description")}
    />

    [Summary cards — 4 cards from data.summary]

    <CrudSection>
      [Toolbar — search + contract status filter
               + schedule state filter
               + refresh
               + reset filters (when any filter is active)]
      [Main table]
      <Pagination … />
    </CrudSection>
  </CrudPageLayout>
</AppShell>
```

**No create button.** This is a read-only monitoring page.

### Loading state

`<ListLoadingState>` with label `t("collections.loading")` and appropriate ARIA live region. Shown on initial load and while fetching after filter, search, or page changes.

### Empty states

Two distinct states:

**A — No contracts in tenant (no active filters):**
- Title: `t("collections.empty.title")`
- Description: `t("collections.empty.description")`
- No action.

**B — Filters or search active, no matching results:**
- Title: `t("collections.emptyFiltered.title")`
- Description: `t("collections.emptyFiltered.description")`
- Action button: `t("collections.resetFilters")` — clears all filters and search, resets to page 1, triggers a new fetch.

The page determines which empty state to show by checking whether any filter or search is currently active.

### Error state

`<ListErrorState>` with the API error message and a retry button. Error region uses appropriate ARIA live semantics.

---

## 5. Summary Cards

Four `<SummaryCard>` components in a responsive grid (4 columns desktop, 2 tablet, 1 mobile). Values come directly from `data.summary` — never computed on the client.

Summary cards are **tenant-wide**. They do not change when the user applies search, status, or schedule state filters. They reflect the full tenant at all times.

| Card | Value | i18n key | Icon | Tone |
|---|---|---|---|---|
| إجمالي العقود | `summary.total_contracts` | `collections.cards.totalContracts` | `FileSignature` | `gold` |
| جداول معتمدة | `summary.scheduled_count` | `collections.cards.scheduled` | `CircleCheckBig` | `success` |
| مسودات قيد التحرير | `summary.draft_count` | `collections.cards.draft` | `FilePen` | `info` |
| بدون جدول تحصيل | `summary.absent_count` | `collections.cards.absent` | `FileX` | `gold` |

`summary.cancelled_count` is present in the API response but does not have a card in v1.

---

## 6. Toolbar

One row containing:

| Control | Type | Behavior |
|---|---|---|
| Search | Text input | Debounced 400ms → updates `search` → resets to page 1. Accessible label: `t("collections.search.label")` |
| Contract status | `<Select>` | Options: "الكل" (empty string), "مسودة", "نشط", "مكتمل", "ملغى" → updates `status` → resets to page 1. Accessible label: `t("collections.filter.status")` |
| Schedule state | `<Select>` | Options: "الكل" (empty string), "بدون جدول", "مسودة", "معتمد", "ملغى" → updates `schedule_state` → resets to page 1. Accessible label: `t("collections.filter.scheduleState")` |
| Refresh | Icon button | Re-fetches with current params. Does not reset query. No duplicate concurrent request launched. Accessible name: `t("collections.refresh")` |
| Reset Filters | Text/link button | Visible only when at least one of `search`, `status`, or `schedule_state` is active. Clears all three, resets page to 1, triggers one new fetch. Accessible name: `t("collections.resetFilters")` |

---

## 7. Main Table

### Columns

| Column (AR) | Source field | Notes |
|---|---|---|
| العميل | `item.customer_name` | "—" if null |
| المشروع | `item.project_name` | "—" if null |
| الوحدة | `item.unit_number` | "—" if null |
| قيمة العقد | `item.contract_total_amount` + `item.currency` | `Intl.NumberFormat` |
| حالة العقد | `item.contract_status` | Translated badge using `collections.contractStatus.*` keys |
| حالة الجدول | `item.schedule_state` | Colored badge — see §7.1 |
| إجمالي الجدول | `item.schedule_active_total` + `item.currency` | "—" when `schedule_state === 'absent'` |
| الإجراءات | Row action buttons | See §8 |

Table headers use appropriate `scope` attributes. Row actions are keyboard-accessible.

### Schedule state badges (§7.1)

| `schedule_state` | i18n key | Visual |
|---|---|---|
| `absent` | `collections.scheduleState.absent` | Neutral/muted |
| `draft` | `collections.scheduleState.draft` | Info/blue |
| `scheduled` | `collections.scheduleState.scheduled` | Success/green |
| `cancelled` | `collections.scheduleState.cancelled` | Danger/red |

Each badge includes `aria-label` with the full state description e.g. `aria-label="حالة جدول التحصيل: معتمد"`.

### Sorting

No sort controls in v1. Order is server-defined (newest contract first).

### Pagination

Uses the existing `<Pagination>` component. `per_page` fixed at 20.

---

## 8. Row Actions

The Collections index table is read-only. It contains no create, edit, finalize, amend, delete, or payment commands.

Row actions open the existing `ContractDetailsModal`. After opening, the existing authorized Collection Schedule commands within the modal remain available per their own specification — this does not affect the read-only nature of the index.

**`ContractDetailsModal` modification required:** Add an `initialTab` prop:

```typescript
initialTab?: 'details' | 'collections'
// default: 'details'
```

The modal resets to the requested `initialTab` whenever it is opened for a new action or a different contract.

### Actions per row

| Label (AR) | i18n key | Behavior |
|---|---|---|
| فتح تفاصيل العقد | `collections.actions.openContract` | Opens `ContractDetailsModal` with `initialTab="details"` |
| جدول التحصيل | `collections.actions.openSchedule` | Opens `ContractDetailsModal` with `initialTab="collections"` |

### Data loading for row actions

Both row actions reuse the existing contract details flow.

When a row action is triggered:

1. Load the selected contract using the existing `fetchContract(token, item.contract_id)` service.
2. Obtain the reservation identifier from the returned contract resource according to the existing Contracts API contract.
3. Load the reservation using the existing `fetchReservation(...)` service.
4. Open the existing `ContractDetailsModal`, passing the loaded contract and reservation.
5. When `initialTab="collections"`, the existing `CollectionScheduleTab` performs its normal targeted request to `GET /contracts/{contract}/collection-schedule` as part of its normal mount behavior.

These targeted requests occur only after explicit user interaction and are not part of the Collections index loading.

### Mobile accessibility

On small screens both row actions must remain accessible. They may be presented as separate buttons or inside an accessible actions menu. A single action button is not sufficient.

---

## 9. Request Behavior

### Single fetch per index query

One `GET /collections` call is made per index query state. No per-row requests are made during index loading.

### Race condition protection

When search, filters, or pagination change in rapid succession, a slower older response must never overwrite the result of a newer query. The engineer may use `AbortController`, a request sequence counter, or an equivalent mechanism. This is a behavioral requirement; the implementation approach is not prescribed.

### Refresh behavior

The Refresh button re-fetches with the current query (same page, same filters, same search). It does not reset the query. If a fetch is already in progress, a second concurrent refresh must not be launched.

### Explicit row actions

Row actions (§8) are exempt from the single-fetch rule. They may load the selected contract, reservation, and schedule through targeted requests after explicit user interaction.

---

## 10. Local State

```typescript
type PageState = {
  query: CollectionsIndexQuery;
  data: CollectionsIndexResponse | null;
  isLoading: boolean;
  error: string | null;
};
```

- **On mount:** fetch with defaults. `isLoading = true` before fetch, reset on response.
- **On query change:** fetch with updated query. Filter/search changes reset `query.page` to 1.
- **On reset filters:** clear `query.search`, `query.status`, `query.schedule_state`; reset `query.page` to 1; fetch.
- **On refresh:** fetch with current `query` unchanged. Block concurrent refresh.

---

## 11. Known Required File Touchpoints

The following files are known to require changes. The engineer may create or modify additional files as needed for clean implementation. Component decomposition within the collections directory is suggested, not mandatory.

- `src/app/collections/page.tsx` — replace placeholder
- `src/types/collection.ts` — add index types (§3.1)
- `src/services/collections.ts` — add `fetchCollectionsIndex` (§3.2)
- `src/components/contracts/ContractDetailsModal.tsx` — add `initialTab` prop (§8)
- `src/i18n/ar-SA.ts` — add keys from §12
- `src/i18n/en-US.ts` — add keys from §12
- `src/i18n/types.ts` — add all new keys to `TranslationKey`
- New Collections index presentation components as needed

---

## 12. i18n Keys

Every key below must be added to `TranslationKey` in `src/i18n/types.ts`. The existing keys `pages.collections.title` and `pages.collections.description` are reused; update `pages.collections.description` to the value below.

### Arabic (`ar-SA.ts`)

```typescript
// Update existing key
"pages.collections.description": "متابعة حالة جداول التحصيل لجميع العقود",

// Summary cards
"collections.cards.totalContracts": "إجمالي العقود",
"collections.cards.scheduled":      "جداول معتمدة",
"collections.cards.draft":          "مسودات قيد التحرير",
"collections.cards.absent":         "بدون جدول تحصيل",

// Toolbar
"collections.search.label":              "بحث",
"collections.search.placeholder":        "بحث بالعميل أو رقم الوحدة...",
"collections.filter.status":             "حالة العقد",
"collections.filter.allStatuses":        "جميع الحالات",
"collections.filter.scheduleState":      "حالة الجدول",
"collections.filter.allScheduleStates":  "جميع الحالات",
"collections.refresh":                   "تحديث",
"collections.resetFilters":              "إعادة تعيين الفلاتر",

// Loading / error
"collections.loading": "جارٍ تحميل جداول التحصيل...",
"collections.error":   "تعذر تحميل جداول التحصيل.",
"collections.retry":   "إعادة المحاولة",

// Empty states
"collections.empty.title":               "لا توجد عقود مسجلة",
"collections.empty.description":         "لا توجد عقود في هذا الحساب بعد.",
"collections.emptyFiltered.title":       "لا توجد نتائج مطابقة",
"collections.emptyFiltered.description": "لم يتم العثور على عقود مطابقة لمعايير البحث الحالية.",

// Table columns
"collections.table.customer":      "العميل",
"collections.table.project":       "المشروع",
"collections.table.unit":          "الوحدة",
"collections.table.contractTotal": "قيمة العقد",
"collections.table.contractStatus":"حالة العقد",
"collections.table.scheduleState": "حالة الجدول",
"collections.table.scheduleTotal": "إجمالي الجدول",
"collections.table.actions":       "الإجراءات",

// Contract status badge labels (for use in Collections table)
"collections.contractStatus.draft":      "مسودة",
"collections.contractStatus.active":     "نشط",
"collections.contractStatus.completed":  "مكتمل",
"collections.contractStatus.cancelled":  "ملغى",

// Schedule state badge labels
"collections.scheduleState.absent":    "بدون جدول",
"collections.scheduleState.draft":     "مسودة",
"collections.scheduleState.scheduled": "معتمد",
"collections.scheduleState.cancelled": "ملغى",

// Row actions
"collections.actions.openContract": "فتح تفاصيل العقد",
"collections.actions.openSchedule": "جدول التحصيل",
```

### English (`en-US.ts`)

```typescript
// Update existing key
"pages.collections.description": "Monitor Collection Schedule status across all contracts",

// Summary cards
"collections.cards.totalContracts": "Total Contracts",
"collections.cards.scheduled":      "Finalized Schedules",
"collections.cards.draft":          "Drafts in Progress",
"collections.cards.absent":         "No Schedule",

// Toolbar
"collections.search.label":              "Search",
"collections.search.placeholder":        "Search by customer or unit number...",
"collections.filter.status":             "Contract Status",
"collections.filter.allStatuses":        "All Statuses",
"collections.filter.scheduleState":      "Schedule State",
"collections.filter.allScheduleStates":  "All States",
"collections.refresh":                   "Refresh",
"collections.resetFilters":              "Reset Filters",

// Loading / error
"collections.loading": "Loading collection schedules...",
"collections.error":   "Failed to load collection schedules.",
"collections.retry":   "Retry",

// Empty states
"collections.empty.title":               "No Contracts Found",
"collections.empty.description":         "No contracts have been created in this account yet.",
"collections.emptyFiltered.title":       "No Matching Results",
"collections.emptyFiltered.description": "No contracts matched the current search and filter criteria.",

// Table columns
"collections.table.customer":      "Customer",
"collections.table.project":       "Project",
"collections.table.unit":          "Unit",
"collections.table.contractTotal": "Contract Value",
"collections.table.contractStatus":"Contract Status",
"collections.table.scheduleState": "Schedule State",
"collections.table.scheduleTotal": "Schedule Total",
"collections.table.actions":       "Actions",

// Contract status badge labels
"collections.contractStatus.draft":      "Draft",
"collections.contractStatus.active":     "Active",
"collections.contractStatus.completed":  "Completed",
"collections.contractStatus.cancelled":  "Cancelled",

// Schedule state badge labels
"collections.scheduleState.absent":    "No Schedule",
"collections.scheduleState.draft":     "Draft",
"collections.scheduleState.scheduled": "Finalized",
"collections.scheduleState.cancelled": "Cancelled",

// Row actions
"collections.actions.openContract": "Open Contract Details",
"collections.actions.openSchedule": "Collection Schedule",
```

---

## 13. UX Rules

**R1 — Read-only index.** The Collections index table contains no create, edit, finalize, amend, delete, or payment commands. Row actions open the existing modal; any authorized commands available within the modal are governed by their own specifications.

**R2 — Single fetch per index query.** One `GET /collections` call per page load, page change, or filter/search change. No per-row requests during index loading.

**R3 — Summary cards are tenant-wide.** Cards always show tenant-wide totals as returned by `data.summary`. Applying search or filters does not change the summary cards.

**R4 — No auto-retry.** Show a manual retry button on error. No automatic resubmission.

**R5 — Absent schedule total is "—".** When `schedule_state === 'absent'`, the schedule total column shows "—", not a formatted zero.

**R6 — Filter and search reset pagination.** Any change to search or filters resets to page 1.

**R7 — Reset Filters is contextual.** The Reset Filters action appears only when at least one of `search`, `status`, or `schedule_state` is active.

**R8 — Race condition protection.** A slower older response must never overwrite the result of a newer query. The Refresh button must not launch a second concurrent request if one is already in progress.

**R9 — RTL layout.** Fully RTL: tables, toolbars, cards, and pagination respect right-to-left direction.

**R10 — Responsive.** On small screens, the table collapses to a card-per-row layout. Both row actions remain accessible on mobile (as separate buttons or inside an accessible actions menu). Summary cards collapse to 1 column. Toolbar controls stack vertically.

**R11 — Consistent number formatting.** Monetary values use `Intl.NumberFormat` with `item.currency`. Counts use `formatInteger` from `@/lib/number-format`.

**R12 — Accessible table.** Table headers carry appropriate `scope` attributes. Row actions are keyboard-accessible. Schedule state badges carry descriptive `aria-label`. Search and both selects have accessible labels. Refresh and Reset Filters have accessible names. Loading and error regions use appropriate ARIA live semantics.

**R13 — Collections index is independent of Contracts list.** The Collections page does not depend on the Contracts list page. It may reuse `ContractDetailsModal`, contract types, contract presenters, and existing contract and reservation services — this is intentional reuse, not coupling.

---

## 14. Acceptance Criteria

**AC-1 — Page renders for authenticated users.** Any active tenant member can access `/collections/`.

**AC-2 — Single API call per index interaction.** Exactly one `GET /collections` call per page load, page change, or filter/search change. No per-row calls during index loading.

**AC-3 — Summary cards are tenant-wide and filter-independent.** Cards render from `data.summary` and do not change when search, status, or schedule state filters are applied. `pagination.total` does change when filters are applied.

**AC-4 — Search works.** 400ms debounce. Re-fetches with `?search={q}`. Resets to page 1.

**AC-5 — Contract status filter works.** Re-fetches with `?status={s}`. Resets to page 1.

**AC-6 — Schedule state filter works.** Re-fetches with `?schedule_state={ss}`. Resets to page 1.

**AC-7 — Pagination works.** Next/previous page fetches the correct page.

**AC-8 — Filter and search changes reset to page 1.**

**AC-9 — Reset Filters clears all active filters.** Visible when any filter is active. Clears search, both selects, resets to page 1, triggers one new fetch.

**AC-10 — Correct empty state when no filters are active.** Shows "لا توجد عقود مسجلة" with no action button.

**AC-11 — Correct empty state when filters are active.** Shows "لا توجد نتائج مطابقة" with Reset Filters action.

**AC-12 — "فتح تفاصيل العقد" opens `ContractDetailsModal` on the details tab.** The modal opens with `initialTab="details"`.

**AC-13 — "جدول التحصيل" opens `ContractDetailsModal` on the collection schedule tab.** The modal opens with `initialTab="collections"`.

**AC-14 — `ContractDetailsModal` resets to the requested tab on each open.**

**AC-15 — Row actions follow the existing contract details flow.** `fetchContract` is called first; the reservation identifier is obtained from the returned contract resource per the existing Contracts API contract; `fetchReservation` is called next; the modal receives both.

**AC-16 — No editing from the index table.** No create, edit, finalize, amend, delete, or payment affordances exist in the index table itself.

**AC-17 — Absent schedule total displays "—".**

**AC-18 — RTL layout is correct.**

**AC-19 — Both row actions accessible on mobile.** Available as separate buttons or inside an accessible actions menu on small screens.

**AC-20 — Stale responses cannot overwrite newer results.** Rapid filter/search changes never display results from a superseded request.

**AC-21 — Existing types and services are extended, not duplicated.** `CollectionsIndexItem` and related types are in `src/types/collection.ts`. `fetchCollectionsIndex` is in `src/services/collections.ts`. `ContractStatus` and `DerivedScheduleState` are not redefined.

**AC-22 — `TranslationKey` is updated.** All new i18n keys are added to the union in `src/i18n/types.ts`.

---

## 15. Implementation Order

1. Implement and test `GET /api/collections` (Backend API Specification v1).
2. Implement the frontend consumer (this specification).
3. Run backend tests including bounded query count verification.
4. Run frontend lint and production build.
5. Validate the complete flow manually.
