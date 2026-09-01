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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConfigureBankReceiptCashClearingPolicy
{
    public function __construct(private readonly VerifiedBankReceiptCashTransaction $tx, private readonly ReceivablesAuthorization $receivables, private readonly AccountingAuthorization $accounting) {}

    public function execute(string $tenantId, User $actor, array $input): string
    {
        $facts = ['policy_operation_id' => ReceiptEvidenceFacts::operation($input, 'policy_operation_id'), 'clearing_account_id' => VerifiedBankReceiptCashFacts::accountId($input, 'clearing_account_id')];
        $this->receivables->authorize($tenantId, $actor);
        $this->accounting->authorize($tenantId, $actor, 'manage_chart');
        try {
            return $this->tx->run(fn (): string => $this->create($tenantId, $actor, $facts));
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->resolve($tenantId, $actor, $facts);
        }
    }

    private function create(string $tenantId, User $actor, array $facts): string
    {
        $this->receivables->authorizeTransactional($tenantId, $actor);
        $this->accounting->authorizeTransactional($tenantId, $actor, 'manage_chart');
        $existing = DB::table('bank_receipt_cash_clearing_policies')->where('tenant_id', $tenantId)->where('policy_operation_id', $facts['policy_operation_id'])->lockForUpdate()->first();
        if ($existing !== null) {
            return $this->match($existing, $facts);
        }
        if (DB::table('bank_receipt_cash_clearing_policies')->where('tenant_id', $tenantId)->where('status', 'effective')->lockForUpdate()->exists()) {
            throw new ReceiptEvidenceConflict('Tenant already has an effective cash clearing policy.');
        }
        $this->lockAccountingAccounts($tenantId, [$facts['clearing_account_id']]);
        $now = now();
        $id = (string) Str::ulid();
        DB::table('bank_receipt_cash_clearing_policies')->insert([...$facts, 'id' => $id, 'tenant_id' => $tenantId, 'status' => 'effective', 'configured_by' => $actor->id, 'configured_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    private function resolve(string $tenantId, User $actor, array $facts): string
    {
        return $this->tx->run(function () use ($tenantId, $actor, $facts): string {
            $this->receivables->authorizeTransactional($tenantId, $actor);
            $this->accounting->authorizeTransactional($tenantId, $actor, 'manage_chart');
            $row = DB::table('bank_receipt_cash_clearing_policies')->where('tenant_id', $tenantId)->where('policy_operation_id', $facts['policy_operation_id'])->lockForUpdate()->first();
            if ($row === null) {
                throw new ReceiptEvidenceConflict('Cash clearing policy outcome is unavailable after uniqueness race.');
            }

            return $this->match($row, $facts);
        });
    }

    private function match(object $row, array $facts): string
    {
        if ($row->clearing_account_id !== $facts['clearing_account_id'] || $row->replaces_policy_id !== null) {
            throw new ReceiptEvidenceConflict('Cash clearing policy operation identity was reused with different facts.');
        }

        return (string) $row->id;
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
