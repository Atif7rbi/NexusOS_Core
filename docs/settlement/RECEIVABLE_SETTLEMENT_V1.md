# Receivable Settlement v1

## Boundary

`ReceivableSettlement` is an explicit accounting event. It connects one effective
`PaymentAllocation` to the exact posted Cash and Accounts Receivable facts that
already exist. It does not change Payment, Allocation, Receipt, Receivable, or
Recognition into accounting substitutes and does not add paid/outstanding states
to Receivables.

The caller supplies only `payment_allocation_id` and a stable tenant-scoped
`settlement_operation_id`. All remaining facts are derived under locks.

## Required provenance

A posted Settlement retains the exact identities of:

- Payment and Receivable from the effective Allocation;
- effective Receipt-to-Payment association and its effective Receipt;
- posted `BankReceiptCashPosting` for that Receipt;
- posted `ReceivableArRecognition` for that Receivable;
- historical Clearing and AR account snapshots;
- the owned business Journal.

All currency values in v1 are SAR. The Settlement amount equals the complete
Allocation amount. One historical Settlement is allowed per Allocation.

## Journal grammar and date

The owned Journal uses source type `receivable_settlement`:

```text
Dr BankReceiptCashPosting.clearing_account_id
Cr ReceivableArRecognition.ar_control_account_id
```

Both lines equal `PaymentAllocation.amount`. The accounting date is the later of
the Cash Posting date and the AR Recognition date and must be contained in exactly
one open Accounting Period.

The exact historical account IDs are mandatory. Consistent with
`SETTLEMENT-ARCH-001`, both accounts must be active when the new business Journal
is posted. An archived account causes fail-closed behavior; explicit restoration
through `ManageAccountAction::restore` is required. No policy substitution or
Settlement-specific PostingEngine bypass exists. Exact reversal Journals remain
permitted after those accounts are archived.

## Lifecycle and idempotency

```text
posted -> reversed
```

Canonical Settlement truth is immutable and cannot be deleted. Posting and
reversal operation IDs are durable tenant-unique identities. Exact retries return
the committed result; reuse with different facts conflicts.

Reversal accepts only the Settlement identity, a stable reversal operation ID,
date, and reason. It creates an exact Accounting-owned reversal of the original
Journal and never rewrites it.

## Lifecycle coordination

While a Settlement is posted it blocks:

- PaymentAllocation cancellation;
- supporting ReceiptPaymentAssociation cancellation;
- supporting Cash Posting reversal and Receipt invalidation;
- supporting Receivable AR source correction/reversal.

The correction order is explicit: reverse the Settlement first, then correct the
upstream evidence. No automatic cascade is introduced.

## Lock corridor

The deterministic corridor is:

```text
transactional authorization
-> Payment
-> Receivable
-> PaymentAllocation
-> ReceiptPaymentAssociation
-> BankReceiptCashPosting
-> ReceivableArRecognition
-> ReceivableSettlement
-> Journal / Accounting Period / Accounts
```

Receipt effectiveness remains stable through the locked effective association and
the existing Receipt lifecycle guards. Identity hints are never authoritative;
all canonical facts are revalidated inside the locked transaction.

## PostgreSQL integrity

The database enforces tenant provenance, operation uniqueness, one Settlement per
Allocation, immutable history, exact Journal grammar, final reversal coupling,
parent lifecycle guards, AR capacity, Cash Clearing capacity, no deletion, and
runtime-role least privilege. Deferred final-state triggers couple Settlement and
Journal commits. Direct SQL cannot create a contradictory final state.

## Exclusions

This slice does not add automatic allocation, partial Settlement of one Allocation,
FX, refunds, write-offs, discounts, bank reconciliation, Receivable balance
columns, accounting read models, reporting, UI work, merge, or deployment.
