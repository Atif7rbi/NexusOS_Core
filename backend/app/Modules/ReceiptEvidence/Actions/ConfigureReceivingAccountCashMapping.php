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

final class ConfigureReceivingAccountCashMapping
{
    public function __construct(private readonly VerifiedBankReceiptCashTransaction $tx, private readonly ReceivablesAuthorization $receivables, private readonly AccountingAuthorization $accounting) {}

    public function execute(string $tenantId, string $receivingAccountId, User $actor, array $input): string
    {
        $facts = ['mapping_operation_id' => ReceiptEvidenceFacts::operation($input, 'mapping_operation_id'), 'cash_account_id' => VerifiedBankReceiptCashFacts::accountId($input, 'cash_account_id')];
        $this->receivables->authorize($tenantId, $actor);
        $this->accounting->authorize($tenantId, $actor, 'manage_chart');
        try {
            return $this->tx->run(fn (): string => $this->create($tenantId, $receivingAccountId, $actor, $facts));
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? '') !== '23505') {
                throw $exception;
            }

            return $this->resolve($tenantId, $receivingAccountId, $actor, $facts);
        }
    }

    private function create(string $tenantId, string $accountId, User $actor, array $facts): string
    {
        $this->receivables->authorizeTransactional($tenantId, $actor);
        $this->accounting->authorizeTransactional($tenantId, $actor, 'manage_chart');
        DB::table('approved_receiving_accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->lockForUpdate()->first()
            ?? throw (new ModelNotFoundException)->setModel('ApprovedReceivingAccount');
        $existing = DB::table('approved_receiving_account_cash_mappings')->where('tenant_id', $tenantId)->where('mapping_operation_id', $facts['mapping_operation_id'])->lockForUpdate()->first();
        if ($existing !== null) {
            return $this->match($existing, $accountId, $facts);
        }
        if (DB::table('approved_receiving_account_cash_mappings')->where('tenant_id', $tenantId)->where('receiving_account_id', $accountId)->where('status', 'effective')->lockForUpdate()->exists()) {
            throw new ReceiptEvidenceConflict('Receiving account already has an effective cash mapping.');
        }
        $this->lockAccountingAccounts($tenantId, [$facts['cash_account_id']]);
        $now = now();
        $id = (string) Str::ulid();
        DB::table('approved_receiving_account_cash_mappings')->insert([...$facts, 'id' => $id, 'tenant_id' => $tenantId, 'receiving_account_id' => $accountId, 'status' => 'effective', 'configured_by' => $actor->id, 'configured_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    private function resolve(string $tenantId, string $accountId, User $actor, array $facts): string
    {
        return $this->tx->run(function () use ($tenantId, $accountId, $actor, $facts): string {
            $this->receivables->authorizeTransactional($tenantId, $actor);
            $this->accounting->authorizeTransactional($tenantId, $actor, 'manage_chart');
            $row = DB::table('approved_receiving_account_cash_mappings')->where('tenant_id', $tenantId)->where('mapping_operation_id', $facts['mapping_operation_id'])->lockForUpdate()->first();
            if ($row === null) {
                throw new ReceiptEvidenceConflict('Cash mapping outcome is unavailable after uniqueness race.');
            }

            return $this->match($row, $accountId, $facts);
        });
    }

    private function match(object $row, string $accountId, array $facts): string
    {
        if ($row->receiving_account_id !== $accountId || $row->cash_account_id !== $facts['cash_account_id'] || $row->replaces_mapping_id !== null) {
            throw new ReceiptEvidenceConflict('Cash mapping operation identity was reused with different facts.');
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
