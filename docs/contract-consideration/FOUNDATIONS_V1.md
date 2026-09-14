# Contract Consideration — Phase 3B-1

The durable adoption aggregate is carried by `ContractConsiderationPosition`.
Absence means unadopted; presence contains an explicit immutable ADOPTED
operation, basis, scope, capacity and audit evidence. Adoption and its one
GENESIS lot commit together. There is no independent mutable adoption flag.

The four storage types are Position, Transition, Lot and TransitionLot.
TransitionLot is the historical consumption edge, not an additional balance.
`lot_id` names the predecessor and `successor_lot_id` preserves the exact
output allocation required by the frozen graph replay contract.

Public foundation operations adopt and recover adoption. They accept source
identities and operation identity only; capacity, currency, basis and scope
are derived under authorization → Contract → Position locks. Current amounts
are derived from immutable lots and effective consumption, never counters.

Transition reversal facts are source-derived. The Transition reversal operation
matches the source row reversal operation; its source-operation identity matches
the Unit Handover reversal operation or the Contractual Billing source-correction
operation, as applicable. Reason, reference, actor and timestamp must also match
the authoritative source exactly at PostgreSQL final state.

Canonical insertion order is monotonic across all recorded Transition history.
Reversing a Transition restores effective capacity but never opens a corridor for
inserting a new Transition behind that historical canonical order. Successor-first
reversal still considers the latest effective Transition and remains unchanged.

Source activation orchestrators are not changed in this milestone. An adopted
Contract is fail-closed: an effective supported source requires its exact
coherent transition graph at commit. Until later source integration milestones,
ordinary activation on such a Contract consequently cannot commit alone.
Legacy Contracts without adoption retain their existing source behavior.

No Revenue, Journal, AR, Settlement, Payment or Reporting implementation belongs
to this module. Source-derived construction and source-aware correction entry
points will be wired in later milestones; there is no public generic movement API.
