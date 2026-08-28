# Payments and Allocations v1

Payments v1 records an explicit customer receipt. Recording a Payment does not post Accounting, change Collection state, or create an Allocation. A Payment is immutable business evidence with the lifecycle `received -> cancelled`.

Allocations v1 are explicit links from one received Payment to one recognized Receivable. Their lifecycle is `effective -> cancelled`; correction is cancel-then-replace. The source facts remain immutable and no Payment, Receivable, or Allocation is deletable.

`RecordPayment` takes caller-owned `payment_operation_id`; `AllocatePayment` takes caller-owned `allocation_operation_id`. Both identifiers are tenant-unique and stable outside the retry boundary. Replays with the same immutable facts return the original row; a reuse with different facts conflicts.

The only authoritative allocation order is actor/authorization, Payment lock, Receivable lock, then Allocation write. The PostgreSQL allocation trigger takes the same Payment then Receivable locks and validates tenant, customer, currency, effective parent state, and both remaining capacities. This makes direct SQL and independent processes subject to the same protections.

Cancelling a Payment or Receivable first locks that parent, then performs a plain MVCC effective-allocation read. That read deliberately has no `FOR UPDATE` or `FOR SHARE`: the already-held parent lock is the concurrency barrier. An effective Allocation must be explicitly cancelled before either parent can be cancelled.

No paid, outstanding, balance, accounting status, journal, payment allocation accounting, invoice, FX, or revenue policy is persisted by this phase. Reporting derives totals from effective Allocation rows. The credit-side accounting policy remains deferred.
