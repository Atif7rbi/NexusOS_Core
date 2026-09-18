# Accounting Recognition Foundations v1

Status: IMPLEMENTATION SLICE 1 — foundation only.

Authoritative architecture remains the previously approved/frozen GL AR Checkpoint 3 and PerformanceAccountingRecognition architecture. This document records the implementation boundary; it does not redesign that architecture.

## Scope

This slice introduces only the durable foundations required by later accounting-recognition owners:

- versioned ReceivableArPolicy;
- versioned AR counterpart policy;
- versioned PerformanceAccountingPolicy;
- durable PerformanceAccountingAdoption;
- AccountingPositionOrigin;
- AccountingPositionConsumption;
- immutable Origin-to-Journal-line allocations;
- immutable Consumption-to-Journal-line allocations;
- PostgreSQL final-state integrity and runtime privilege boundaries.

This slice does not create ReceivableArRecognition or PerformanceAccountingRecognition, and does not post AR, Revenue, Contract Asset, or Contract Liability Journals.

## Frozen semantic account roles

The policy families explicitly designate only the frozen roles:

~~~text
ACCOUNTS_RECEIVABLE_CONTROL
CONTRACT_ASSET_CONTROL
CONTRACT_LIABILITY_CONTROL
REVENUE
~~~

Broad Chart-of-Accounts classifications do not infer these roles.

Policy-selected accounts must be active posting Accounts of the compatible broad Accounting type when a policy version is created.

## Policy history

Each policy family is Tenant-scoped, versioned and historically reproducible.

A policy version starts active and open-ended, is immutable except for its one terminal active-to-superseded control transition, is never deleted, has a contiguous version number and an exact non-overlapping effective-date window, and leaves exactly one active version when history exists.

A successor begins strictly after the current active version and closes its predecessor through the day immediately before the successor effective_from.

Future Recognition actions resolve the policy version applicable to the fixed accounting date. They must not use request time or the current active mapping as historical inference.

## Performance Accounting adoption

PerformanceAccountingAdoption is a durable, irreversible clean-protocol eligibility fact.

Canonical v1 values are:

~~~text
accounting_scope_version = PERFORMANCE_ACCOUNTING_V1
adoption_basis           = CLEAN_PROTOCOL_READY
status                   = ADOPTED
~~~

The caller supplies only Contract identity and stable performance_accounting_adoption_operation_id.

Capacity, currency, Contract Consideration root identity and eligibility are derived under locks.

Clean adoption requires an exact adopted Contract Consideration root, exact SAR Contract capacity, no prior effective Unit Handover performance, no prior Performance Accounting protocol history requiring synthetic reconstruction, and every currently effective BILLED_UNEARNED economic lot (if any) to have sufficient exact protocol-backed CONTRACT_LIABILITY Accounting Position Origin capacity tied to that same economic provenance.

Legacy Receivables, generic Journals or GL balances never satisfy protocol provenance. No historical backfill or synthetic Accounting Position is created by adoption.

## Accounting Position provenance

The shared provenance family is:

~~~text
AccountingPositionOrigin
AccountingPositionConsumption
AccountingPositionOriginJournalLineAllocation
AccountingPositionConsumptionJournalLineAllocation
~~~

Only these accounting positions exist in this foundation:

~~~text
CONTRACT_ASSET
CONTRACT_LIABILITY
~~~

An Origin is immutable accounting capacity tied to Tenant and Contract, future owning Recognition identity, exact Posted Journal, exact Account, exact economic source, exact Contract Consideration Transition, exact output Lot/economic leg, and exact amount, SAR currency and accounting date.

A Consumption is an immutable capacity-consumption edge tied to exact Origin, future consuming Recognition identity, exact consuming Posted Journal, exact consuming Contract Consideration Transition/output Lot, and exact amount/economic leg.

Effective consumption may never exceed Origin capacity. An Origin cannot become reversed while an effective consumer remains.

## Journal-line allocation integrity

Journal cardinality and economic-leg cardinality are intentionally separate. Origin/Consumption allocation edges make the relationship explicit.

Created positions:
- Contract Asset Origin allocation uses its exact Contract Asset debit line.
- Contract Liability Origin allocation uses its exact Contract Liability credit line.

Consumed positions:
- Contract Asset Consumption allocation uses the exact historical Contract Asset credit line.
- Contract Liability Consumption allocation uses the exact historical Contract Liability debit line.

Every Origin and Consumption is fully allocated. Every controlled Journal line participating in this provenance family is fully explained by its exact allocation graph. Account identity, Contract, currency and economic-leg identity must match exactly.

## Source-derived causal grammar

~~~text
PerformanceAccountingRecognition
+ UNIT_HANDOVER_ACCEPTANCE
+ EARNED_UNBILLED output
-> CONTRACT_ASSET Origin
~~~

~~~text
ReceivableArRecognition
+ CONTRACTUAL_BILLING_ENTITLEMENT
+ BILLED_UNEARNED output
-> CONTRACT_LIABILITY Origin
~~~

~~~text
CONTRACT_ASSET Origin
-> later ReceivableArRecognition
-> Billing BILLED_EARNED output
~~~

~~~text
CONTRACT_LIABILITY Origin
-> later PerformanceAccountingRecognition
-> Handover BILLED_EARNED output
~~~

No GL balance matching or unrelated same-amount provenance is accepted.

## Deliberate staged runtime boundary

Slice 1 does not yet contain the two Recognition owner aggregates.

Therefore the runtime database role has configuration rights on the three policy families, insert/read rights on Performance Accounting adoption, and SELECT only on Accounting Position Origins, Consumptions and their allocation edges.

The application runtime cannot manufacture provenance in Slice 1. Migration-owner direct-SQL tests construct provenance solely to prove PostgreSQL invariants.

Slice 2/3 must introduce the authoritative Recognition owner tables and exact owner-to-Journal/source final-state contracts before any runtime provenance DML is enabled.

This is a staging boundary, not a permanent generic provenance-write API.

## Existing generic Receivable Accounting integration

ReceivableAccountingIntegration remains legacy/internal infrastructure.

Its caller-supplied ordered Journal lines are not the policy owner for entitlement-backed GL AR and do not satisfy this protocol durable Recognition provenance.

No new path in Slice 1 calls it to establish AR.

## Explicit exclusions

This slice does not implement PerformanceAccountingRecognition runtime posting, ReceivableArRecognition runtime posting, Revenue recognition, GL AR establishment, Contract Asset/Contract Liability Journal orchestration, source/accounting correction orchestration, Settlement, Payment accounting redesign, Invoice accounting, FX, historical accounting adoption/backfill, partial or milestone performance, reporting or deployment.

Those remain later explicitly authorized implementation work.
