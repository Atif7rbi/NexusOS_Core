# Contract Consideration — Phase 3B-2

Phase 3B-2 wires the two frozen v1 sources into the Phase 3B-1 Contract
Consideration foundation without changing its storage model or economic grammar.
The canonical storage types remain Position, Transition, Lot and TransitionLot.
There is no separate Consumption aggregate and there are no mutable balance
counters.

## Atomic source coordination

For an adopted Contract, creation of an effective supported source and creation
of its exact Contract Consideration Transition graph occur in the same PostgreSQL
transaction. The supported sources are exactly:

- `CONTRACTUAL_BILLING_ENTITLEMENT`
- `UNIT_HANDOVER_ACCEPTANCE`

The existing source-specific authorization and lock corridors remain
authoritative. Contract Consideration locks append after the complete source
corridor; no competing lock protocol is introduced.

Contracts without a ContractConsiderationPosition retain their existing source
behavior. For an adopted Contract, source activation requires the explicit
`contract_consideration_transition_operation_id`. This is operation identity
only. The caller cannot supply economic movement truth.

The coordinator derives source type/id, contract, economic date, amount,
currency, semantic precedence, predecessor selection, successor positions and
audit actor/time from the locked authoritative source and immutable graph
history.

## Frozen grammar

Billing consumes only:

- `UNPERFORMED_UNBILLED -> BILLED_UNEARNED`
- `EARNED_UNBILLED -> BILLED_EARNED`

Unit Handover consumes the full adopted Contract consideration and moves:

- `UNPERFORMED_UNBILLED -> EARNED_UNBILLED`
- `BILLED_UNEARNED -> BILLED_EARNED`

Predecessor lots are consumed oldest-first under canonical ordering. Billing may
consume partial available capacity. Unit Handover remains full-performance truth
and cannot partially consume an eligible predecessor lot.

## Replay and recovery

A supported source coordinates once under tenant-safe source identity. Replay
requires the same Transition operation identity and an already coherent graph.
Missing historical graph truth is never inferred or backfilled on replay.

Unique-race recovery re-enters the authoritative source corridor before accepting
a winner so that adopted source replay also proves the exact Contract
Consideration operation identity and graph.

## Reversal

Source reversal remains authoritative. Contract Consideration reversal appends
to the existing source correction corridor in the same transaction and copies
exact source-derived reversal provenance: reversal operation, source/correction
operation, reason, reference, actor and timestamp.

Billing source correction reverses affected Consideration Transitions in reverse
canonical order so successor-first history is preserved. Unit Handover reversal
is rejected while a later effective dependent Transition exists; downstream
history must be reversed first.

## Boundary

Phase 3B-2 does not post Journals, recognize Revenue, establish or recognize GL
AR, create Settlement truth, process Payments, or implement Reporting.
Performance, Economic Entitlement, Receivable, GL recognition and Settlement
remain separate accounting boundaries.
