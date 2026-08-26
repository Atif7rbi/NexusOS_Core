# Receivable Accounting Integration Infrastructure v1

Status: FROZEN infrastructure contract for Phase 5B. This phase selects no Contract/Collection recognition event and no Revenue or Deferred Revenue policy.

## Recognition identity and replay

`recognition_operation_id` is a caller-supplied ULID created outside the retry boundary and unique per Tenant. A caller replay must reuse the same operation ID. Same canonical recognition facts return the same Receivable; different customer, optional typed provenance, currency, exact amount, due date, or normalized recognition timestamp conflict. PostgreSQL uniqueness is the race authority.

The forward migration maps each pre-Phase-5B Receivable's existing immutable `id` to `recognition_operation_id` as a one-time legacy compatibility identity. New writes have no fallback and require an externally stable operation ULID.

Atomic transaction retry and caller replay after an unknown commit outcome are distinct. Atomicity does not create caller retry identity.

## Integration boundary

The originating Business Module owns and retries one outer PostgreSQL transaction. It first locks its own source, then invokes the shared infrastructure with caller-owned ordered Accounting lines. Receivables owns obligation truth; Accounting owns posting integrity. The source identity is:

```text
origin      = business
source_type = receivable_recognition
source_id   = receivable.id
```

The integrated path rejects non-SAR before Accounting; Accounting remains an independent SAR-only backstop. Receivables stores no Journal FK, Accounting status, Accounting effect, or account IDs.

## Recovery and cancellation

Unknown-commit recovery begins with `(tenant_id, recognition_operation_id)`, then resolves the source-identified Journal and compares canonical Accounting facts. Both absent is retryable; both present and matching is committed. Receivable-only, orphan-Journal, or mismatching facts fail closed. Partial committed state is never repaired asynchronously.

`CancelReceivableAction` remains an Accounting-agnostic internal primitive. The public cancellation controller uses `ReceivableCancellationOrchestrator`: it locks the Receivable, resolves its Accounting source effect, performs exact Accounting reversal when present, then cancels the Receivable in the same outer transaction. Reversal failure rolls back cancellation. No public route invokes the primitive directly.

## Deferred business policy

Phase 5B does not decide why a Receivable is recognized, does not connect Contract activation or Collection dates to recognition, and does not choose Revenue versus Deferred Revenue. Payments, allocations, Invoice, FX, account mappings, aging, UI, deployment, and production migrations remain out of scope.
