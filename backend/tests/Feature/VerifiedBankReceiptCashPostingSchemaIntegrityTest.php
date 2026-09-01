<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ReceiptEvidence\Actions\ConfigureBankReceiptCashClearingPolicy;
use App\Modules\ReceiptEvidence\Actions\ConfigureReceivingAccountCashMapping;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesVerifiedBankReceiptCashFixtures;
use Tests\TestCase;

final class VerifiedBankReceiptCashPostingSchemaIntegrityTest extends TestCase
{
    use CreatesVerifiedBankReceiptCashFixtures;
    use RefreshDatabase;

    public function test_database_rejects_dual_effective_mapping_and_policy(): void
    {
        [$tenant, $actor, $cash, $clearing, $receiving] = $this->cashPostingContext();
        app(ConfigureReceivingAccountCashMapping::class)->execute((string) $tenant->id, $receiving, $actor, ['mapping_operation_id' => (string) Str::ulid(), 'cash_account_id' => $cash]);
        app(ConfigureBankReceiptCashClearingPolicy::class)->execute((string) $tenant->id, $actor, ['policy_operation_id' => (string) Str::ulid(), 'clearing_account_id' => $clearing]);

        $this->expectException(QueryException::class);
        DB::table('approved_receiving_account_cash_mappings')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'mapping_operation_id' => (string) Str::ulid(), 'receiving_account_id' => $receiving, 'cash_account_id' => $cash, 'status' => 'effective', 'configured_by' => $actor->id, 'configured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_database_rejects_immutable_cash_posting_mutation(): void
    {
        [$tenant, $actor, $cash, $clearing, $receiving, $receipt] = $this->cashPostingContext();
        $mapping = app(ConfigureReceivingAccountCashMapping::class)->execute((string) $tenant->id, $receiving, $actor, ['mapping_operation_id' => (string) Str::ulid(), 'cash_account_id' => $cash]);
        $policy = app(ConfigureBankReceiptCashClearingPolicy::class)->execute((string) $tenant->id, $actor, ['policy_operation_id' => (string) Str::ulid(), 'clearing_account_id' => $clearing]);
        $this->expectException(QueryException::class);
        DB::table('bank_receipt_cash_postings')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'posting_operation_id' => (string) Str::ulid(), 'receipt_id' => $receipt, 'cash_mapping_id' => $mapping, 'cash_policy_id' => $policy, 'receiving_account_id' => $receiving, 'amount' => '25.00', 'currency' => 'SAR', 'accounting_date' => '2026-08-01', 'cash_account_id' => $cash, 'clearing_account_id' => $clearing, 'status' => 'posted', 'journal_entry_id' => null, 'posted_by' => $actor->id, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
