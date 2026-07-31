# Collections Frontend Specification v1

**Status:** FROZEN
**Project:** NexusOS Pilot
**Repository:** Atif7rbi/ufq-pilot
**Scope:** Collections Frontend only.

This document is the official implementation reference for the Collections Frontend.
All implementation must follow this specification unless a newer frozen version officially supersedes it.

---

## 1. Module Overview

### What this spec covers

The Collection Schedule tab inside `ContractDetailsModal`. The tab already exists as a placeholder (`CollectionScheduleNavigation`). This spec replaces that placeholder with a full implementation.

### What this spec does NOT cover

- Payments, receipts, allocations, accounting
- The standalone `/collections` page (remains placeholder)
- The `CollectionSummary` dashboard widget (remains placeholder)
- Cross-contract collection reports
- Notifications or reminders

### Backend contract (read-only reference)

All four endpoints are live and tested:

```
GET  /contracts/{contract}/collection-schedule[?include_history=true]
POST /contracts/{contract}/collection-schedule/draft
POST /contracts/{contract}/collection-schedule/finalize
POST /contracts/{contract}/collection-schedule/amend
```

Response envelope:

- `GET` → `{ data: { contract, schedule, allowed_actions } }`
- Commands → `{ message, data: { contract, schedule, allowed_actions } }`

---

## 2. New Files and Integration Points

### New files to create

```
frontend/src/types/collection.ts
frontend/src/services/collections.ts
frontend/src/components/collections/CollectionScheduleTab.tsx
frontend/src/components/collections/CollectionLineEditor.tsx
frontend/src/components/collections/CollectionScheduleView.tsx
frontend/src/components/collections/CollectionHistoryPanel.tsx
frontend/src/components/collections/FinalizeDialog.tsx
frontend/src/components/collections/AmendDialog.tsx
```

### Files to modify

```
frontend/src/components/contracts/ContractDetailsModal.tsx
  → replace CollectionScheduleNavigation() with <CollectionScheduleTab contract={contract} />

frontend/src/i18n/ar-SA.ts
  → add all collection.* keys defined in §12

frontend/src/i18n/en-US.ts
  → add all collection.* keys defined in §12

frontend/src/hooks/useResourceInvalidation.ts
  → no change needed (contracts resource covers Collection Schedule refresh)
```

---

## 3. TypeScript Types

**File:** `src/types/collection.ts`

```typescript
// Stored status of each Collection row
export type CollectionStatus = 'draft' | 'scheduled' | 'cancelled';

// Derived state of the full schedule for a contract
export type DerivedScheduleState = 'absent' | 'draft' | 'scheduled' | 'cancelled';

export type CollectionActor = {
  id: number;
  name: string;
};

export type ActiveCollection = {
  id: string;
  sequence: number;
  title: string;
  amount: string;       // decimal string e.g. "150000.00"
  due_date: string;     // "YYYY-MM-DD"
  notes: string | null;
  status: CollectionStatus;
  scheduled_at: string | null;  // ISO-8601 UTC e.g. "2026-07-31T10:00:00Z"
  scheduled_by: CollectionActor | null;
};

export type CancelledCollection = ActiveCollection & {
  cancelled_at: string;                  // ISO-8601 UTC, always present
  cancelled_by: CollectionActor;         // always present
  cancellation_reason: string;           // always present
};

export type AllowedActions = {
  can_save_draft: boolean;
  can_finalize: boolean;
  can_amend: boolean;
};

export type CollectionSchedule = {
  derived_state: DerivedScheduleState;
  active_total: string;                        // decimal string "0.00" when absent
  active_collections: ActiveCollection[];
  cancelled_history?: CancelledCollection[];   // present only when include_history=true
};

export type CollectionContractInfo = {
  id: string;
  status: string;
  currency: string;   // e.g. "SAR"
  total_amount: string;
};

export type CollectionScheduleResource = {
  contract: CollectionContractInfo;
  schedule: CollectionSchedule;
  allowed_actions: AllowedActions;
};

// API response shapes
export type GetCollectionScheduleResponse = {
  data: CollectionScheduleResource;
};

export type CollectionCommandResponse = {
  message: string;
  data: CollectionScheduleResource;
};

// Local editor state for a draft/amend line
export type CollectionLineInput = {
  _key: string;        // local render key only — NEVER sent to API
  id: string | null;   // server ULID for existing draft rows; null for new rows
  sequence: number;
  title: string;
  amount: string;
  due_date: string;
  notes: string;
};

// Payload types for API calls
export type SaveDraftPayload = {
  lines: Array<{
    id?: string;         // omit for new lines; include for existing draft rows
    sequence: number;
    title: string;
    amount: string;
    due_date: string;
    notes: string | null;
  }>;
};

export type FinalizePayload = Record<string, never>;   // empty object {}

export type AmendPayload = {
  expected_active_collection_ids: string[];
  lines: Array<{
    sequence: number;
    title: string;
    amount: string;
    due_date: string;
    notes: string | null;
  }>;
  cancellation_reason: string;
};
```

---

## 4. API Service

**File:** `src/services/collections.ts`

Follow the exact pattern of `src/services/contracts.ts`: use `requestJson` from `@/lib/http`, pass `token` from `useAuth()`, throw via `parseApiError`.

```typescript
// Functions to implement:

fetchCollectionSchedule(
  token: string,
  contractId: string,
  includeHistory?: boolean
): Promise<CollectionScheduleResource>
// GET /contracts/{contractId}/collection-schedule[?include_history=true]
// Returns response.data

saveDraftCollectionSchedule(
  token: string,
  contractId: string,
  payload: SaveDraftPayload
): Promise<CollectionScheduleResource>
// POST /contracts/{contractId}/collection-schedule/draft
// Returns response.data

finalizeCollectionSchedule(
  token: string,
  contractId: string
): Promise<CollectionScheduleResource>
// POST /contracts/{contractId}/collection-schedule/finalize
// Body: {}
// Returns response.data

amendCollectionSchedule(
  token: string,
  contractId: string,
  payload: AmendPayload
): Promise<CollectionScheduleResource>
// POST /contracts/{contractId}/collection-schedule/amend
// Returns response.data
```

**Error handling:** All functions call `parseApiError(response)` on `!response.ok` and throw `ApiRequestError`. The existing `parseApiError` reads `payload.message` which maps to the frozen `{ message, error: { code, message } }` envelope — the `message` field is always populated, so this works directly.

---

## 5. Screen Layout

The tab already exists inside `ContractDetailsModal`. The tab switcher, modal header, and modal footer are unchanged. Only the tab body changes.

**Integration point in `ContractDetailsModal`:**

```tsx
// Replace:
) : (
  <CollectionScheduleNavigation />
)}

// With:
) : (
  <CollectionScheduleTab contract={contract} />
)}
```

`CollectionScheduleTab` receives `contract: Contract` (existing type from `@/types/contract`) and manages all Collection Schedule state internally. The modal knows nothing about Collection state.

---

## 6. CollectionScheduleTab — State Machine

The tab loads the schedule on mount. State transitions follow `derived_state` from the API response.

### Local state

```typescript
type TabState =
  | { phase: 'loading' }
  | { phase: 'error'; message: string }
  | { phase: 'read'; resource: CollectionScheduleResource }
  | { phase: 'draft-edit'; resource: CollectionScheduleResource; lines: CollectionLineInput[]; dirty: boolean }
  | { phase: 'amend-edit'; resource: CollectionScheduleResource; lines: CollectionLineInput[]; generationToken: string[]; cancellationReason: string; dirty: boolean }
  | { phase: 'finalize-confirm'; resource: CollectionScheduleResource }
  | { phase: 'amend-confirm'; resource: CollectionScheduleResource; lines: CollectionLineInput[]; generationToken: string[]; cancellationReason: string }
  | { phase: 'command-pending'; resource: CollectionScheduleResource }
```

### On mount

1. Set phase `loading`.
2. Call `fetchCollectionSchedule(token, contract.id)`.
3. On success → set phase `read` with resource.
4. On error → set phase `error` with message.

### Phase transitions

| Current phase | Event | Next phase |
|---|---|---|
| `read` (derived_state=absent or draft, can_save_draft=true) | click "إنشاء جدول" or "تعديل المسودة" | `draft-edit`, lines populated from active_collections |
| `draft-edit` | click "حفظ المسودة" (validation passes, not pending) | `command-pending` → on success `read` |
| `draft-edit` | click "إلغاء" with dirty=false | `read` |
| `draft-edit` | click "إلغاء" with dirty=true | show discard dialog → on confirm `read` |
| `read` (derived_state=draft, can_finalize=true, totals match) | click "اعتماد جدول التحصيل" | `finalize-confirm` |
| `finalize-confirm` | click "تأكيد" | `command-pending` → on success `read` |
| `finalize-confirm` | click "إلغاء" | `read` |
| `read` (derived_state=scheduled, can_amend=true) | click "تعديل جدول التحصيل" | `amend-edit`, lines cloned from active (IDs stripped), generationToken captured |
| `amend-edit` | click "متابعة للتأكيد" (validation passes) | `amend-confirm` |
| `amend-confirm` | click "تأكيد التعديل" | `command-pending` → on success `read` |
| `amend-confirm` | click "رجوع" | `amend-edit` (state preserved) |
| `amend-edit` | click "إلغاء" with dirty=false | `read` |
| `amend-edit` | click "إلغاء" with dirty=true | show discard dialog → on confirm `read` |
| `read` (can_save_draft=true, derived_state=absent) | lines=[] save | `command-pending` → on success `read` (derived_state stays absent) |
| any phase | `command-pending` error 409/422/503 | `read` + error banner OR `draft-edit`/`amend-edit` + error banner (see §10) |
| `read` | click "تحميل السجل التاريخي" | fetches include_history=true, shows history panel |

---

## 7. UI States

### 7.1 Loading

Displayed while fetching the schedule on mount or after a command completes.

- Full-tab skeleton: a centered spinner or pulse skeleton rows.
- No action buttons visible.
- Use existing loading pattern from `ListLoadingState` if applicable, otherwise a `<p>` with `common.loading` i18n key centered vertically.

### 7.2 Error (load failure)

Displayed when the initial GET fails.

- Use `<FormErrorBanner>` with the error message.
- A "إعادة المحاولة" button that re-triggers the fetch.
- No action buttons.

### 7.3 Absent

`derived_state === 'absent'`

- Use `<EmptyState>` component with:
  - icon: `ListOrdered` from lucide-react
  - title: `collection.absent.title`
  - description: `collection.absent.description`
  - action: `<Button variant="primary">إنشاء جدول التحصيل</Button>` — **only when `allowed_actions.can_save_draft === true`**. When false, no action rendered.

### 7.4 Draft (read mode)

`derived_state === 'draft'`, phase = `read`

**Header row** (flex, space-between):

- Right: title "مسودة جدول التحصيل" + badge `<Badge variant="draft">مسودة</Badge>`
- Left: action buttons (visibility controlled by `allowed_actions`)
  - `can_save_draft === true` → Button "تعديل المسودة" (secondary)
  - `can_finalize === true` AND `active_total === contract.total_amount` → Button "اعتماد جدول التحصيل" (primary)
  - `can_finalize === true` AND `active_total !== contract.total_amount` → Button "اعتماد جدول التحصيل" disabled, tooltip/hint: "إجمالي المسودة لا يساوي قيمة العقد"

**Summary row:**

- "إجمالي المسودة: {formatAmount(active_total)} {currency}"
- "قيمة العقد: {formatAmount(contract.total_amount)} {currency}"
- If they differ: a warning notice between the two values.

**Collections table** (read-only):

| # | البند | المبلغ | تاريخ الاستحقاق | ملاحظات |
|---|---|---|---|---|
| sequence | title | amount formatted | due_date formatted | notes or "—" |

### 7.5 Scheduled (read mode)

`derived_state === 'scheduled'`, phase = `read`

**Header row:**

- Right: title "جدول التحصيل المعتمد" + badge `<Badge variant="scheduled">معتمد</Badge>`
- Left: `can_amend === true` → Button "تعديل جدول التحصيل" (secondary)

**Summary row:**

- "إجمالي جدول التحصيل: {formatAmount(active_total)} {currency}"

**Collections table** (read-only, adds scheduled_at and scheduled_by columns):

| # | البند | المبلغ | تاريخ الاستحقاق | تاريخ الاعتماد | اعتمد بواسطة |
|---|---|---|---|---|---|
| sequence | title | formatted | formatted | formatted | actor.name or "—" |

**History section** (below table):

- Collapsed by default.
- Button "عرض السجل التاريخي" — fetches `include_history=true` on first click, then toggles panel visibility.
- See §7.7.

### 7.6 Cancelled

`derived_state === 'cancelled'`

- Read-only, no action buttons.
- Badge `<Badge variant="cancelled">ملغى</Badge>`.
- Message: "جدول التحصيل المرتبط بهذا العقد ملغى. يمكن عرض السجل التاريخي أدناه."
- History section visible and fetched automatically on first render (one GET with include_history=true).
- active_collections will be empty — no table rendered.

### 7.7 History Panel

Rendered when `include_history=true` has been fetched successfully and user has expanded it.

**Title:** "السجل التاريخي للبنود الملغاة"

**If `cancelled_history` is empty:** `<EmptyState>` with title "لا يوجد سجل تاريخي" and no action.

**If not empty:** Table ordered as returned by the API (`cancelled_at DESC, sequence ASC`):

| # | البند | المبلغ | تاريخ الاستحقاق | ألغي بواسطة | سبب الإلغاء | تاريخ الإلغاء |
|---|---|---|---|---|---|---|
| sequence | title | formatted | formatted | actor.name | cancellation_reason | formatted |

History is always read-only.

### 7.8 Draft Editor

`phase === 'draft-edit'`

See §8.1 (Actions → Save Draft) for full editor specification.

### 7.9 Amend Editor

`phase === 'amend-edit'`

See §8.3 (Actions → Amend) for full editor specification.

---

## 8. Actions

### 8.1 Save Draft

**Entry:** Click "إنشاء جدول التحصيل" (from absent) or "تعديل المسودة" (from draft read).

**Initial lines:**

- From `absent`: one empty line with `sequence=1`, all fields blank.
- From `draft`: clone `active_collections` into `CollectionLineInput[]`, preserving `id` for each existing row.

**`_key` field:** Generate `crypto.randomUUID()` for each line (local render key for React). Never send to API.

**Editor layout — per line:**

Each line is a row containing:

- Drag handle icon (reorder). Reorder updates sequences deterministically as `1..N` for the visible order.
- `sequence` field: integer, read-only display (auto-managed by order).
- `title` field: text input, required.
- `amount` field: text input, required, positive decimal.
- `due_date` field: date input (`YYYY-MM-DD`), required.
- `notes` field: text input, optional.
- Delete button: removes the line from local state only. No API call.

**Sequence management:**

- On add: `sequence = max(existing sequences) + 1`.
- On delete: sequences of remaining lines are NOT auto-renumbered (gaps allowed — server permits this).
- On explicit reorder: rewrite all sequences `1..N` in visible order.

**Add line button:** Below the lines list. Always visible in editor.

**Save Draft button state:**

| Condition | State |
|---|---|
| `phase !== 'draft-edit'` | not rendered |
| pending | disabled + "جارٍ الحفظ..." |
| dirty=false AND lines unchanged from server | disabled |
| any local validation error | disabled |
| lines empty AND phase entered from draft (not absent) | enabled (sends `lines: []`, clears draft) |
| lines empty AND phase entered from absent | disabled (no meaningful action) |
| lines present AND all valid | enabled |

**Local validation (blocks save button):**

- Each `title` after trim must not be empty.
- Each `title` length ≤ 150 characters.
- Each `amount` must be a positive decimal with at most 2 decimal places.
- Each `due_date` must be a valid calendar date in `YYYY-MM-DD`.
- `sequence` values must be unique across lines.
- Due dates must be non-decreasing in sequence order.
- **Do NOT validate that `sum(amounts) === contract.total_amount`** — draft may be partial.

**On save:**

1. Set pending.
2. Build `SaveDraftPayload`:
   - For each line: include `id` if `line.id !== null`; omit `id` if `line.id === null`.
   - Send `notes: null` if `line.notes` is empty string.
3. Call `saveDraftCollectionSchedule(token, contractId, payload)`.
4. On success: replace resource with response, rebuild lines from `active_collections`, set `dirty=false`, transition to `read`.
5. On error: see §10.

**Cancel with unsaved changes:**

- `dirty=false` → transition to `read` immediately.
- `dirty=true` → show discard confirmation dialog:
  - Title: "تجاهل التعديلات؟"
  - Body: "ستفقد جميع التعديلات غير المحفوظة على جدول التحصيل."
  - Buttons: "متابعة التحرير" (cancel) / "تجاهل التعديلات" (confirm → transition to `read`).

### 8.2 Finalize

**Entry conditions (all must be true):**

- `phase === 'read'`
- `allowed_actions.can_finalize === true`
- `active_total === contract.total_amount` (compare as decimals: `Number(a).toFixed(2) === Number(b).toFixed(2)`)
- `derived_state === 'draft'`

**Button:** "اعتماد جدول التحصيل" — primary variant.

**If `can_finalize === true` but totals don't match:** Button is visible but disabled. Display hint below or near button: "إجمالي جدول التحصيل ({formatted}) لا يساوي قيمة العقد ({formatted}). عدّل الأسطر أولًا."

**On click:** Transition to `finalize-confirm`.

**Finalize Confirmation Dialog** (uses `<ConfirmationDialog>`):

- `title`: "اعتماد جدول التحصيل"
- `description`: "سيُعتمد جدول التحصيل ويُحوَّل من مسودة إلى مجدول. لا يمكن إعادته إلى مسودة — أي تعديل مالي لاحق يتم من خلال تعديل جدول التحصيل."
- `icon`: CheckCircle2 in brand-gold colors (matching existing modal icon pattern)
- `children`: summary card showing:
  - "عدد بنود التحصيل: {active_collections.length}"
  - "إجمالي جدول التحصيل: {formatAmount(active_total)} {currency}"
  - "قيمة العقد: {formatAmount(contract.total_amount)} {currency}"
- `confirmLabel`: "اعتماد الجدول"
- `processingLabel`: "جارٍ الاعتماد..."
- `cancelLabel`: "إلغاء"
- `closeLabel`: "إغلاق"
- `confirmVariant`: `"primary"`
- `isProcessing`: true while pending
- `error`: error message if command failed

**On confirm:**

1. Set `isProcessing = true`.
2. Call `finalizeCollectionSchedule(token, contractId)` — body is `{}`.
3. On success: close dialog, replace resource, transition to `read` (derived_state=scheduled).
4. On error: keep dialog open, show error in `FormErrorBanner` inside dialog, re-enable confirm button. See §10.

**On cancel or close:** Transition to `read`.

### 8.3 Amend

**Entry conditions:**

- `phase === 'read'`
- `allowed_actions.can_amend === true`
- `derived_state === 'scheduled'`

**Button:** "تعديل جدول التحصيل" — secondary variant.

**On click:** Transition to `amend-edit`:

- Capture `generationToken = active_collections.map(c => c.id)` at this exact moment.
- Clone lines from `active_collections` into `CollectionLineInput[]` — **all `id` fields set to `null`** (Amend is cancel-and-replace; replacement lines have no server IDs).
- Set `cancellationReason = ''`.
- Set `dirty = false`.

**Amend Editor:**

Same line editor as Draft (§8.1), with these differences:

- No `id` field management — all lines treated as new.
- **No `lines: []` submission** — Amend requires `min 1` line. If all lines are deleted, "متابعة للتأكيد" remains disabled.
- **`cancellationReason` field** is part of the editor (not the confirmation dialog):
  - Label: "سبب استبدال جدول التحصيل"
  - Required text input, 1–500 characters after trim.
  - Shown below the lines list, above action buttons.
- **Local validation for Amend also checks:** `sum(amounts) === contract.total_amount` — unlike Draft, Amend enforces total match. If totals don't match: "متابعة للتأكيد" disabled with notice "إجمالي الجدول البديل ({formatted}) يجب أن يساوي قيمة العقد ({formatted})."

**"متابعة للتأكيد" button enabled when:**

- `dirty = true` OR lines differ from the initial clone
- All local validation passes (including total match and cancellation reason)
- Not pending

**On click "متابعة للتأكيد":** Transition to `amend-confirm` — create immutable snapshot of current lines and cancellation reason.

**Amend Confirmation Dialog** (uses `<ConfirmationDialog>`):

- `title`: "تأكيد استبدال جدول التحصيل"
- `description`: "سيتم إلغاء جدول التحصيل المعتمد الحالي بالكامل وإنشاء جدول بديل يحل محله. ستُحفظ بنود الجدول الحالي كسجل تاريخي. هذا إجراء مالي لا يمكن التراجع عنه تلقائيًا."
- `icon`: RefreshCw or ArrowLeftRight in brand-gold
- `children`: comparison summary:
  - **الجدول الحالي (الذي بُني عليه التعديل):** عدد البنود، الإجمالي، العملة
  - **الجدول البديل المقترح:** عدد البنود، الإجمالي، العملة
  - "سبب الاستبدال: {cancellationReason}"
- `confirmLabel`: "تنفيذ التعديل"
- `processingLabel`: "جارٍ التعديل..."
- `cancelLabel`: "رجوع"
- `closeLabel`: "إغلاق"
- `confirmVariant`: `"primary"`
- `isProcessing`: true while pending
- `error`: error message if command failed

**On confirm:**

1. Set `isProcessing = true`.
2. Build `AmendPayload`:
   - `expected_active_collection_ids`: `generationToken` (captured at entry to amend-edit)
   - `lines`: from snapshot (no `id` fields)
   - `cancellation_reason`: trimmed cancellation reason
3. Call `amendCollectionSchedule(token, contractId, payload)`.
4. On success: close dialog, replace resource, transition to `read` (derived_state=scheduled).
5. On error: keep dialog open, show error. See §10.

**On "رجوع":** Return to `amend-edit` with all state preserved (lines, cancellation reason, generation token).

**Cancel with unsaved changes (from amend-edit):**

Same discard dialog as Draft (§8.1) — "ستفقد جميع التعديلات المحلية على الجدول البديل وسبب الاستبدال."

### 8.4 History

**Button:** "عرض السجل التاريخي" — ghost/link variant, shown below the active schedule table in `scheduled` state.

**Behavior:**

- First click: fetch `GET .../collection-schedule?include_history=true`. Show loading state in the history panel region.
- On success: store `cancelled_history` in local state, show panel.
- Second click: toggle visibility (use cached data — do NOT refetch on toggle).
- After a successful Amend: invalidate the history cache (set to null). Next open will refetch.
- Button label toggles: "عرض السجل التاريخي" / "إخفاء السجل التاريخي".

**History panel must NOT be shown during:**

- Draft editing mode
- Amend editing mode
- Any confirmation dialog phase
- Command pending phase

---

## 9. Error Handling

### Error code → user message mapping

Map `error.code` from the API response to Arabic messages. Read `payload.error.code` from the JSON body when `response.ok === false`.

| `error.code` | Arabic message |
|---|---|
| `contract_not_eligible_for_draft_edit` | "لا يمكن تعديل مسودة جدول التحصيل في الحالة الحالية للعقد." |
| `schedule_not_in_draft_state` | "جدول التحصيل ليس في حالة مسودة." |
| `contract_not_eligible_for_finalization` | "لا يمكن اعتماد جدول التحصيل في الحالة الحالية للعقد." |
| `contract_not_eligible_for_amendment` | "لا يمكن تعديل جدول التحصيل في الحالة الحالية للعقد." |
| `no_active_schedule_to_amend` | "لا يوجد جدول تحصيل نشط للتعديل عليه." |
| `collection_schedule_changed_since_loaded` | "تغيّر جدول التحصيل منذ آخر تحميل. يتم تحديث البيانات..." |
| `collection_schedule_integrity_violation` | "تعذر عرض جدول التحصيل بسبب حالة غير متسقة في البيانات. يرجى التواصل مع الدعم." |
| `invalid_expected_active_collection_ids` | "تعذر تنفيذ التعديل بسبب خطأ تقني. أعد تحميل الصفحة." |
| `schedule_total_mismatch` | "إجمالي جدول التحصيل لا يساوي قيمة العقد." |
| `duplicate_collection_sequence` | "يوجد تكرار في أرقام التسلسل. راجع الأسطر وأعد المحاولة." |
| `non_decreasing_due_date_violation` | "تواريخ الاستحقاق يجب أن تكون متصاعدة حسب التسلسل." |
| `blank_collection_title` | "عنوان أحد البنود فارغ." |
| `invalid_collection_amount` | "مبلغ أحد البنود غير صالح." |
| `excess_decimal_precision` | "يجب ألا يتجاوز المبلغ منزلتين عشريتين." |
| `invalid_cancellation_reason` | "سبب الإلغاء غير صالح أو طويل جدًا." |
| `role_not_authorized` | "ليست لديك صلاحية تنفيذ هذا الإجراء." |
| `service_unavailable` | "الخدمة غير متاحة مؤقتًا. حاول مرة أخرى." |
| `invalid_query_parameter` | "طلب غير صالح. أعد تحميل الصفحة." |
| *(any other code or network error)* | fallback to `error.message` from API, or "تعذر إكمال العملية." |

### Error display rules

**Errors inside confirmation dialogs** (Finalize, Amend):

- Show in `<FormErrorBanner>` inside the dialog (the `error` prop of `<ConfirmationDialog>`).
- Keep dialog open (do NOT auto-close on error).
- Re-enable the confirm button.

**For `collection_schedule_changed_since_loaded` (409):**

- Close the dialog.
- Show the message in a `<FormErrorBanner>` above the schedule tab content.
- Automatically trigger one GET refresh. On success: replace resource and clear the banner. On failure: keep banner, show retry button.

**For `collection_schedule_integrity_violation` (409):**

- Close any open dialog.
- Show the integrity message prominently in the tab.
- Disable all action buttons.
- No automatic retry.

**Errors during draft/amend editor** (network failure, 503, etc.):

- Show in `<FormErrorBanner>` above the editor's action buttons.
- Preserve all local editor state (lines, unsaved changes).
- Re-enable the save/continue button.

**Role/auth errors (403):**

- Show the "ليست لديك صلاحية" message.
- Trigger one GET refresh to get updated `allowed_actions`.

---

## 10. `allowed_actions` Usage

`allowed_actions` is a **hint from the server only**. The backend re-validates on every command. The frontend must:

1. **Read `allowed_actions` from the API response** — never derive it locally from `contract.status`, `derived_state`, or user role.
2. **Hide (not disable)** action buttons when the corresponding flag is `false`, with **one exception**: `can_finalize=true` but totals don't match → button is visible but disabled (see §8.2).
3. **After every successful command**, update `allowed_actions` from the command response.
4. **Never cache `allowed_actions`** across sessions or across tab navigations.

**Specific rules:**

| `allowed_actions` flag | When false | When true |
|---|---|---|
| `can_save_draft` | Hide "إنشاء جدول" and "تعديل المسودة" buttons | Show them |
| `can_finalize` | Hide "اعتماد جدول التحصيل" | Show (enabled if totals match; disabled with hint if not) |
| `can_amend` | Hide "تعديل جدول التحصيل" | Show |

---

## 11. UX Rules

**R1 — No automatic financial retries.** After a failed Amend or Finalize, the user must explicitly press the confirm button again. No automatic resubmission.

**R2 — Generation token capture is one-time.** `generationToken` is captured from `active_collections[*].id` exactly when the user clicks "تعديل جدول التحصيل". It is never updated silently. If `collection_schedule_changed_since_loaded` is returned, the token becomes invalid and a new Amend flow must start from scratch.

**R3 — No Save Draft from absent with empty lines.** Sending `lines: []` from the absent state produces no meaningful state change. Keep the save button disabled in this case.

**R4 — Dirty state protection.** When `dirty=true`, show the discard dialog before any navigation that would destroy local state (tab switch, modal close, phase change triggered externally). Limit "externally triggered" to user actions — do NOT block server-triggered phase changes (e.g., after a successful command).

**R5 — Notes normalization.** When building API payload, if `notes` is an empty string, send `null`.

**R6 — Amount display formatting.** Display amounts using `Intl.NumberFormat` with the contract's `currency`. Example: `1000.00 SAR` → "1,000.00 ريال سعودي" in Arabic locale. Use a consistent `formatAmount(amount: string, currency: string)` helper.

**R7 — Date display formatting.** Display `due_date` (YYYY-MM-DD) as a human-readable Arabic date. Display `scheduled_at`/`cancelled_at` (ISO-8601 UTC) as a localized datetime. Use a consistent `formatCollectionDate` helper.

**R8 — Actor display.** If `scheduled_by` or `cancelled_by` is `null`, display "—". Never show the numeric `id`; show only `actor.name`.

**R9 — History is read-only.** No editing, no selection, no actions inside the history panel.

**R10 — Single command at a time.** While a command is pending (`command-pending` phase), all action buttons (save, finalize, amend, history load) are disabled. Only one command may be in flight at a time.

**R11 — Tab label does not change.** The "جدول التحصيل" tab label in the modal nav is always the same, regardless of derived_state.

**R12 — Fetch on tab open.** Trigger `fetchCollectionSchedule` when the user navigates to the collections tab (not on modal open if the user stays on "details" tab). Cache the result for the lifetime of the modal open session; invalidate and re-fetch after every successful command.

**R13 — Amount comparison uses decimal arithmetic.** When comparing `active_total === contract.total_amount`, use `Number(a).toFixed(2) === Number(b).toFixed(2)` to avoid string format differences. Do not use floating-point equality directly.

---

## 12. i18n Keys

Add to both `ar-SA.ts` and `en-US.ts`. Arabic values listed; translate English accordingly.

```typescript
// Absent state
"collection.absent.title": "لا يوجد جدول تحصيل",
"collection.absent.description": "لم يتم إنشاء جدول تحصيل لهذا العقد بعد.",
"collection.absent.create": "إنشاء جدول التحصيل",

// Draft state
"collection.draft.title": "مسودة جدول التحصيل",
"collection.draft.edit": "تعديل المسودة",
"collection.draft.save": "حفظ المسودة",
"collection.draft.saving": "جارٍ الحفظ...",
"collection.draft.totalWarning": "إجمالي المسودة لا يساوي قيمة العقد",

// Scheduled state
"collection.scheduled.title": "جدول التحصيل المعتمد",
"collection.scheduled.amend": "تعديل جدول التحصيل",

// Cancelled state
"collection.cancelled.notice": "جدول التحصيل المرتبط بهذا العقد ملغى. يمكن عرض السجل التاريخي أدناه.",

// Finalize
"collection.finalize.button": "اعتماد جدول التحصيل",
"collection.finalize.dialog.title": "اعتماد جدول التحصيل",
"collection.finalize.dialog.description": "سيُعتمد جدول التحصيل ويُحوَّل من مسودة إلى مجدول. لا يمكن إعادته إلى مسودة — أي تعديل مالي لاحق يتم من خلال تعديل جدول التحصيل.",
"collection.finalize.dialog.confirm": "اعتماد الجدول",
"collection.finalize.dialog.confirming": "جارٍ الاعتماد...",
"collection.finalize.dialog.cancel": "إلغاء",
"collection.finalize.dialog.close": "إغلاق",
"collection.finalize.totalsMismatch": "إجمالي جدول التحصيل لا يساوي قيمة العقد.",

// Amend
"collection.amend.button": "تعديل جدول التحصيل",
"collection.amend.proceed": "متابعة للتأكيد",
"collection.amend.cancel": "إلغاء",
"collection.amend.back": "رجوع",
"collection.amend.reason.label": "سبب استبدال جدول التحصيل",
"collection.amend.totalsMismatch": "إجمالي الجدول البديل يجب أن يساوي قيمة العقد.",
"collection.amend.dialog.title": "تأكيد استبدال جدول التحصيل",
"collection.amend.dialog.description": "سيتم إلغاء جدول التحصيل المعتمد الحالي بالكامل وإنشاء جدول بديل يحل محله. ستُحفظ بنود الجدول الحالي كسجل تاريخي. هذا إجراء مالي لا يمكن التراجع عنه تلقائيًا.",
"collection.amend.dialog.confirm": "تنفيذ التعديل",
"collection.amend.dialog.confirming": "جارٍ التعديل...",
"collection.amend.dialog.cancel": "رجوع",
"collection.amend.dialog.close": "إغلاق",
"collection.amend.dialog.current": "جدول التحصيل الحالي",
"collection.amend.dialog.proposed": "الجدول البديل المقترح",
"collection.amend.dialog.reason": "سبب الاستبدال",

// Line editor
"collection.line.sequence": "#",
"collection.line.title": "بيان البند",
"collection.line.amount": "المبلغ",
"collection.line.dueDate": "تاريخ الاستحقاق",
"collection.line.notes": "ملاحظات",
"collection.line.delete": "حذف السطر",
"collection.line.addLine": "إضافة سطر",

// Summary
"collection.summary.activeTotal": "إجمالي جدول التحصيل",
"collection.summary.contractTotal": "قيمة العقد",
"collection.summary.lineCount": "عدد البنود",
"collection.summary.scheduledAt": "تاريخ الاعتماد",
"collection.summary.scheduledBy": "اعتمد بواسطة",

// History
"collection.history.show": "عرض السجل التاريخي",
"collection.history.hide": "إخفاء السجل التاريخي",
"collection.history.title": "السجل التاريخي للبنود الملغاة",
"collection.history.empty.title": "لا يوجد سجل تاريخي",
"collection.history.cancelledAt": "تاريخ الإلغاء",
"collection.history.cancelledBy": "ألغي بواسطة",
"collection.history.reason": "سبب الإلغاء",

// Discard dialog
"collection.discard.title": "تجاهل التعديلات؟",
"collection.discard.description": "ستفقد جميع التعديلات غير المحفوظة على جدول التحصيل.",
"collection.discard.confirm": "تجاهل التعديلات",
"collection.discard.cancel": "متابعة التحرير",
"collection.discard.close": "إغلاق",

// Loading / error
"collection.loading": "جارٍ تحميل جدول التحصيل...",
"collection.loadError": "تعذر تحميل جدول التحصيل.",
"collection.retry": "إعادة المحاولة",
"collection.genericError": "تعذر إكمال العملية.",
```

---

## 13. Acceptance Criteria

Each criterion must pass before implementation is considered complete.

**AC-1 — Absent state:** Opening the Collection Schedule tab for a contract with no collections shows the empty state. "إنشاء جدول التحصيل" button is visible only if `can_save_draft=true`.

**AC-2 — Draft save:** Entering the editor and saving one or more valid lines transitions the tab to draft read state with the correct `derived_state`, `active_total`, and line data.

**AC-3 — Empty save clears draft:** Saving with `lines: []` from an existing draft returns `derived_state=absent` and the tab shows the absent state.

**AC-4 — Finalize button gated by totals:** The Finalize button is disabled with a visible hint when `active_total !== contract.total_amount`. When totals match and `can_finalize=true`, the button is enabled.

**AC-5 — Finalize flow:** Clicking Finalize → confirmation dialog shows correct summary → clicking "اعتماد الجدول" → transitions to scheduled read state with `scheduled_by.name` visible in the table.

**AC-6 — Amend entry captures generation token:** Entering amend mode captures `active_collections[*].id` at that exact moment. The IDs are sent in `expected_active_collection_ids` on submit.

**AC-7 — Amend total validation:** The "متابعة للتأكيد" button is disabled with a visible notice when `sum(lines.amount) !== contract.total_amount`.

**AC-8 — Amend confirm dialog shows both schedules:** The dialog shows the current schedule's line count and total alongside the proposed schedule's line count and total, and the entered cancellation reason.

**AC-9 — Amend success:** Completing the amend flow transitions to scheduled read state with new line IDs distinct from the previous generation.

**AC-10 — Stale generation conflict:** If `409 collection_schedule_changed_since_loaded` is returned, the dialog closes, the message is shown, and the schedule is automatically refreshed once.

**AC-11 — Role authorization:** A user with `employee` or `project_manager` role sees no "إنشاء" / "اعتماد" / "تعديل" buttons (because `allowed_actions` will have all flags false).

**AC-12 — History panel:** Clicking "عرض السجل التاريخي" in scheduled state fetches `include_history=true` once, displays the table ordered by `cancelled_at DESC`, and toggles on second click without re-fetching.

**AC-13 — After Amend, history cache invalidated:** Clicking "عرض السجل التاريخي" after a successful Amend fetches fresh history (not the cached pre-amend version).

**AC-14 — Dirty protection:** Navigating away from an editor with unsaved changes shows the discard dialog. Closing the modal while dirty also triggers it.

**AC-15 — No automatic retries:** After a failed financial command (Finalize or Amend), the confirm button re-enables. No automatic re-submission occurs.

**AC-16 — Error codes mapped:** All error codes in §9 display their specified Arabic message, not a raw code or generic message.

**AC-17 — `allowed_actions` never re-derived:** The frontend does not compute eligibility from `contract.status`, `derived_state`, or user role. It reads only `allowed_actions` from the API response.

**AC-18 — Amounts formatted:** All monetary values display with `Intl.NumberFormat` using the contract's `currency`, consistent throughout the tab.

**AC-19 — Timestamps in UTC 'Z' format:** All `scheduled_at` and `cancelled_at` values are confirmed to end in `Z` in the raw API response. Display them as localized date-time strings, not raw ISO strings.

**AC-20 — Notes sent as null when empty:** When the `notes` field is blank, the payload sends `null`, not `""`.
