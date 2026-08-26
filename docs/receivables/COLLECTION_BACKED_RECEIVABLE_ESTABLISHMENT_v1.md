# Collection-Backed Receivable Establishment v1

Status: FROZEN implementation contract for Phase 5C.

`EstablishCollectionReceivable` is an explicit authorized business command. It establishes one scheduled contractual Collection as an effective Receivable; Contract activation, Collection finalization, and due-date passage never establish one automatically.

## Scope and canonical truth

Phase 5C is Collection-backed only: `collection_id` is required and Contract-level-only establishment is excluded. The command accepts only the Collection identity, caller-owned `recognition_operation_id`, and recognition evidence. Under transactional authorization and the established Contract -> Collection source lock order, it derives `contract_id`, Reservation Customer provenance, `recognized_amount`, `due_date`, and immutable Contract `currency`.

PostgreSQL extends `enforce_receivable_history` for every non-null `collection_id`: tenant, Contract, Customer provenance, scheduled status, amount, due date, and currency must all match the authoritative Collection/Contract chain. A partial unique index permits at most one `recognized` Receivable for a tenant/Collection, while retaining historical cancelled corrections.

Migration preflight preserves the same distinction: provenance and immutable snapshots are validated for all Collection-backed historical rows, while the source Collection must still be `scheduled` only for an effective `recognized` Receivable. A cancelled historical Receivable may therefore retain a source Collection that was legitimately cancelled by a later correction.

The operation identity remains immutable and tenant-unique. The same identity with the same canonical facts replays; the same identity with differing facts conflicts. It is caller-owned outside retry boundaries.

## Causal correction order and concurrency

An effective (`recognized`) Receivable blocks both Collection amendment and Collection cancellation. The required order is: cancel the Receivable explicitly, amend or cancel the Collection, create a replacement Collection when needed, then establish a new Receivable explicitly. There is no automatic cancellation, replacement, or carry-forward.

Collection amendment and cancellation already lock Contract then Collection. Their effective-Receivable guard is deliberately a plain MVCC Receivables read; it uses no Receivables lock because the Collection lock is the shared concurrency barrier.

## Accounting boundary

Phase 5C creates no Accounting entry and does not invoke `ReceivableAccountingIntegration`. No journal, accounting status/effect, account mapping, or credit-side policy is introduced. Revenue, Contract Liability, Deferred Revenue, clearing policy, invoices, payments, allocations, partial recognition, FX, and the Collection amount precision migration remain deferred.
