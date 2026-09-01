<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\VerifiedBankReceiptCashFacts;
use App\Modules\ReceiptEvidence\Support\VerifiedBankReceiptCashTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupersedeBankReceiptCashClearingPolicy
{
    public function __construct(private readonly VerifiedBankReceiptCashTransaction $tx, private readonly ReceivablesAuthorization $receivables, private readonly AccountingAuthorization $accounting) {}

    public function execute(string $tenantId, string $policyId, User $actor, array $input): string
    {
        $facts = ['policy_operation_id' => ReceiptEvidenceFacts::operation($input, 'policy_operation_id'), 'clearing_account_id' => VerifiedBankReceiptCashFacts::accountId($input, 'clearing_account_id'), 'supersession_reason' => ReceiptEvidenceFacts::reason($input, 'supersession_reason')];
        $this->receivables->authorize($tenantId, $actor);
        $this->accounting->authorize($tenantId, $actor, 'manage_chart');
        try {
            return $this->tx->run(fn (): string => $this->supersede($tenantId, $policyId, $actor, $facts));
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->resolve($tenantId, $policyId, $actor, $facts);
        }
    }

    private function supersede(string $tenantId, string $policyId, User $actor, array $facts): string
    {
        $this->receivables->authorizeTransactional($tenantId, $actor);
        $this->accounting->authorizeTransactional($tenantId, $actor, 'manage_chart');
        $old = DB::table('bank_receipt_cash_clearing_policies')->where('tenant_id', $tenantId)->where('id', $policyId)->lockForUpdate()->first();
        if ($old === null) {
            throw (new ModelNotFoundException)->setModel('BankReceiptCashClearingPolicy');
        }
        if ($old->status === 'superseded') {
            return $this->replayOrConflict($tenantId, $old, $policyId, $facts);
        }
        $operation = DB::table('bank_receipt_cash_clearing_policies')->where('tenant_id', $tenantId)->where('policy_operation_id', $facts['policy_operation_id'])->lockForUpdate()->first();
        if ($operation !== null) {
            return $this->matchSuccessor($operation, $policyId, $facts);
        }
        $this->lockAccountingAccounts(
            $tenantId,
            [(string) $old->clearing_account_id, $facts['clearing_account_id']],
        );
        $now = now();
        $id = (string) Str::ulid();
        DB::table('bank_receipt_cash_clearing_policies')->where('tenant_id', $tenantId)->where('id', $policyId)->update(['status' => 'superseded', 'supersession_operation_id' => $facts['policy_operation_id'], 'superseded_by' => $actor->id, 'superseded_at' => $now, 'supersession_reason' => $facts['supersession_reason'], 'updated_at' => $now]);
        DB::table('bank_receipt_cash_clearing_policies')->insert(['id' => $id, 'tenant_id' => $tenantId, 'policy_operation_id' => $facts['policy_operation_id'], 'clearing_account_id' => $facts['clearing_account_id'], 'replaces_policy_id' => $old->id, 'status' => 'effective', 'configured_by' => $actor->id, 'configured_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    private function resolve(string $tenantId, string $policyId, User $actor, array $facts): string
    {
        return $this->tx->run(function () use ($tenantId, $policyId, $actor, $facts): string {
            $this->receivables->authorizeTransactional($tenantId, $actor);
            $this->accounting->authorizeTransactional($tenantId, $actor, 'manage_chart');
            $successor = DB::table('bank_receipt_cash_clearing_policies')->where('tenant_id', $tenantId)->where('policy_operation_id', $facts['policy_operation_id'])->lockForUpdate()->first();
            if ($successor === null) {
                throw new ReceiptEvidenceConflict('Cash clearing policy supersession outcome is unavailable after uniqueness race.');
            }

            return $this->matchSuccessor($successor, $policyId, $facts);
        });
    }

    private function replayOrConflict(string $tenantId, object $old, string $policyId, array $facts): string
    {
        if ($old->supersession_operation_id !== $facts['policy_operation_id'] || $old->supersession_reason !== $facts['supersession_reason']) {
            throw new ReceiptEvidenceConflict('Cash clearing policy is already superseded by a different terminal operation.');
        }
        $successor = DB::table('bank_receipt_cash_clearing_policies')->where('tenant_id', $tenantId)->where('replaces_policy_id', $policyId)->lockForUpdate()->first();
        if ($successor === null) {
            throw new ReceiptEvidenceConflict('Cash clearing policy supersession history is inconsistent.');
        }

        return $this->matchSuccessor($successor, $policyId, $facts);
    }

    private function matchSuccessor(object $successor, string $policyId, array $facts): string
    {
        if ($successor->replaces_policy_id !== $policyId || $successor->policy_operation_id !== $facts['policy_operation_id'] || $successor->clearing_account_id !== $facts['clearing_account_id']) {
            throw new ReceiptEvidenceConflict('Cash clearing policy supersession operation identity was reused with different facts.');
        }

        return (string) $successor->id;
    }

    /** @param array<int,string> $accountIds */
    private function lockAccountingAccounts(
        string $tenantId,
        array $accountIds,
    ): void {
        $ids = array_values(array_unique($accountIds));
        sort($ids, SORT_STRING);

        $locked = DB::table('accounts')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($locked->count() !== count($ids)) {
            throw new ReceiptEvidenceConflict(
                'Required Accounting account was not found.',
            );
        }
    }
}
