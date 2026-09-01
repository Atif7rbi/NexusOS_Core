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

final class SupersedeReceivingAccountCashMapping
{
    public function __construct(private readonly VerifiedBankReceiptCashTransaction $tx, private readonly ReceivablesAuthorization $receivables, private readonly AccountingAuthorization $accounting) {}

    public function execute(string $tenantId, string $mappingId, User $actor, array $input): string
    {
        $facts = ['mapping_operation_id' => ReceiptEvidenceFacts::operation($input, 'mapping_operation_id'), 'cash_account_id' => VerifiedBankReceiptCashFacts::accountId($input, 'cash_account_id'), 'supersession_reason' => ReceiptEvidenceFacts::reason($input, 'supersession_reason')];
        $this->receivables->authorize($tenantId, $actor);
        $this->accounting->authorize($tenantId, $actor, 'manage_chart');
        try {
            return $this->tx->run(fn (): string => $this->supersede($tenantId, $mappingId, $actor, $facts));
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->resolve($tenantId, $mappingId, $actor, $facts);
        }
    }

    private function supersede(string $tenantId, string $mappingId, User $actor, array $facts): string
    {
        $this->receivables->authorizeTransactional($tenantId, $actor);
        $this->accounting->authorizeTransactional($tenantId, $actor, 'manage_chart');
        $mappingHint = DB::table('approved_receiving_account_cash_mappings')
            ->where('tenant_id', $tenantId)
            ->where('id', $mappingId)
            ->first();

        if ($mappingHint === null) {
            throw (new ModelNotFoundException)->setModel(
                'ApprovedReceivingAccountCashMapping',
            );
        }

        DB::table('approved_receiving_accounts')
            ->where('tenant_id', $tenantId)
            ->where('id', $mappingHint->receiving_account_id)
            ->lockForUpdate()
            ->first()
            ?? throw (new ModelNotFoundException)->setModel(
                'ApprovedReceivingAccount',
            );

        $old = DB::table('approved_receiving_account_cash_mappings')
            ->where('tenant_id', $tenantId)
            ->where('id', $mappingId)
            ->lockForUpdate()
            ->first();

        if ($old === null) {
            throw (new ModelNotFoundException)->setModel(
                'ApprovedReceivingAccountCashMapping',
            );
        }
        if ($old->status === 'superseded') {
            return $this->replayOrConflict($tenantId, $old, $mappingId, $facts);
        }
        $operation = DB::table('approved_receiving_account_cash_mappings')->where('tenant_id', $tenantId)->where('mapping_operation_id', $facts['mapping_operation_id'])->lockForUpdate()->first();
        if ($operation !== null) {
            return $this->matchSuccessor($operation, $mappingId, $facts);
        }
        $this->lockAccountingAccounts(
            $tenantId,
            [(string) $old->cash_account_id, $facts['cash_account_id']],
        );
        $now = now();
        $id = (string) Str::ulid();
        DB::table('approved_receiving_account_cash_mappings')->where('tenant_id', $tenantId)->where('id', $mappingId)->update(['status' => 'superseded', 'supersession_operation_id' => $facts['mapping_operation_id'], 'superseded_by' => $actor->id, 'superseded_at' => $now, 'supersession_reason' => $facts['supersession_reason'], 'updated_at' => $now]);
        DB::table('approved_receiving_account_cash_mappings')->insert(['id' => $id, 'tenant_id' => $tenantId, 'mapping_operation_id' => $facts['mapping_operation_id'], 'receiving_account_id' => $old->receiving_account_id, 'cash_account_id' => $facts['cash_account_id'], 'replaces_mapping_id' => $old->id, 'status' => 'effective', 'configured_by' => $actor->id, 'configured_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    private function resolve(string $tenantId, string $mappingId, User $actor, array $facts): string
    {
        return $this->tx->run(function () use ($tenantId, $mappingId, $actor, $facts): string {
            $this->receivables->authorizeTransactional($tenantId, $actor);
            $this->accounting->authorizeTransactional($tenantId, $actor, 'manage_chart');
            $successor = DB::table('approved_receiving_account_cash_mappings')->where('tenant_id', $tenantId)->where('mapping_operation_id', $facts['mapping_operation_id'])->lockForUpdate()->first();
            if ($successor === null) {
                throw new ReceiptEvidenceConflict('Cash mapping supersession outcome is unavailable after uniqueness race.');
            }

            return $this->matchSuccessor($successor, $mappingId, $facts);
        });
    }

    private function replayOrConflict(string $tenantId, object $old, string $mappingId, array $facts): string
    {
        if ($old->supersession_operation_id !== $facts['mapping_operation_id'] || $old->supersession_reason !== $facts['supersession_reason']) {
            throw new ReceiptEvidenceConflict('Cash mapping is already superseded by a different terminal operation.');
        }
        $successor = DB::table('approved_receiving_account_cash_mappings')->where('tenant_id', $tenantId)->where('replaces_mapping_id', $mappingId)->lockForUpdate()->first();
        if ($successor === null) {
            throw new ReceiptEvidenceConflict('Cash mapping supersession history is inconsistent.');
        }

        return $this->matchSuccessor($successor, $mappingId, $facts);
    }

    private function matchSuccessor(object $successor, string $mappingId, array $facts): string
    {
        if ($successor->replaces_mapping_id !== $mappingId || $successor->mapping_operation_id !== $facts['mapping_operation_id'] || $successor->cash_account_id !== $facts['cash_account_id']) {
            throw new ReceiptEvidenceConflict('Cash mapping supersession operation identity was reused with different facts.');
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
