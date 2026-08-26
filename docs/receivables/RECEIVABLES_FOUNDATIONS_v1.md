# Receivables Domain Foundations v1

Status: FROZEN implementation contract for Phase 5A.

## Boundary

A Receivable is an independently recognized customer financial obligation. Recognition is explicit and never follows automatically from Contract activation, Collection scheduling or due dates, Reservation state, or any other business lifecycle.

The following distinctions are authoritative:

- Collection ≠ Receivable.
- Receivable ≠ Payment.
- Contract ≠ Receivable.
- Invoice ≠ Receivable.
- Collection Schedule ≠ Payment; scheduled amount is not collected amount.
- Contract and Reservation state are not Accounting transactions.

Receivables owns obligation truth only. Phase 5A does not create an Accounting Journal and does not call the Accounting posting boundary.

## Aggregate and lifecycle

`receivables` stores the required customer, optional typed Contract and Collection references, source currency snapshot, exact `NUMERIC(19,2)` recognized amount, mandatory immutable `due_date`, recognition evidence, cancellation evidence, and operational timestamps.

The only persisted states are `recognized` and `cancelled`. Recognition creates the final immutable obligation directly in `recognized`. Cancellation is the sole transition and requires timestamp, same-tenant actor, and non-blank reason. Cancellation is terminal. Correction is cancel plus an independently recognized replacement; v1 has no replacement or reversal self-FKs.

PostgreSQL enforces positive money, the closed status vocabulary, lifecycle-field consistency, composite tenant FKs, immutable recognized truth, terminal cancellation, and no deletion.

## Settlement, invoices, and sources

There is no stored paid, unpaid, partially settled, overdue, outstanding, or balance state. Payment, PaymentAllocation, Receipt, Invoice, tax/ZATCA data, aging, reminders, and settlement projections are outside Phase 5A. `recognized_amount` must not be exposed as a permanent outstanding-balance contract.

There is no generic `source_type/source_id` pair. Current relationships use nullable typed FKs. Invoice identity and generic source identity are deferred. No `invoice_id`, accounting account mapping, FX conversion, or Accounting posting exists.

## Currency and dates

The Receivable preserves valid Core business currency (`SAR` or `USD`) as recognition-time truth. Accounting v1 remains SAR-only; preservation of USD does not imply FX or posting support. `due_date` is a caller-supplied `YYYY-MM-DD` business date and is distinct from the required RFC3339 `recognized_at` operational event timestamp.

## Authorization and integration boundary

Mutations require an active User, active Tenant, active same-tenant membership, and the existing administrator/accountant role convention. Authorization is rechecked under row locks inside the PostgreSQL transaction. Read queries are tenant-scoped.

Future recognized-obligation Accounting integration must be owned by one business-specific outer transaction:

```text
recognize Receivable
+
post business transaction
→ both commit or both roll back
```

Atomicity guarantees intra-transaction consistency between Receivable recognition and required Accounting posting. Caller-level retry safety of the overall orchestration command is a separate concern and is not implied by transaction atomicity.

Before production Receivable-to-Accounting integration, the originating business command must freeze stable business-operation identity, safe caller retries, and unknown-commit-outcome recovery. Phase 5A deliberately does not invent a generic HTTP Idempotency-Key system.

## Phase 5A HTTP boundary

The minimum authenticated boundary is list, show, explicit recognition, and cancellation. It exposes authoritative Receivable facts only. There is no edit, delete, restore, settlement, Invoice, Accounting-posting, reporting, or UI endpoint.
