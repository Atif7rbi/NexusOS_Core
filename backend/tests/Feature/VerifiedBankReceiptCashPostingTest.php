<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ReceiptEvidence\Actions\ConfigureBankReceiptCashClearingPolicy;
use App\Modules\ReceiptEvidence\Actions\ConfigureReceivingAccountCashMapping;
use App\Modules\ReceiptEvidence\Actions\InvalidateBankReceipt;
use App\Modules\ReceiptEvidence\Actions\PostVerifiedBankReceiptCash;
use App\Modules\ReceiptEvidence\Actions\ReversePostedBankReceiptCashAndInvalidate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesVerifiedBankReceiptCashFixtures;
use Tests\TestCase;

final class VerifiedBankReceiptCashPostingTest extends TestCase
{
    use CreatesVerifiedBankReceiptCashFixtures;
    use RefreshDatabase;

    public function test_explicit_posting_snapshots_mapping_and_policy_and_replays(): void
    {
        [$tenant, $actor, $cash, $clearing, $receiving, $receipt] = $this->cashPostingContext();
        $mapping = app(ConfigureReceivingAccountCashMapping::class)->execute((string) $tenant->id, $receiving, $actor, ['mapping_operation_id' => (string) Str::ulid(), 'cash_account_id' => $cash]);
        $policy = app(ConfigureBankReceiptCashClearingPolicy::class)->execute((string) $tenant->id, $actor, ['policy_operation_id' => (string) Str::ulid(), 'clearing_account_id' => $clearing]);
        $operation = (string) Str::ulid();
        $result = app(PostVerifiedBankReceiptCash::class)->execute((string) $tenant->id, $receipt, $actor, ['posting_operation_id' => $operation]);
        $replay = app(PostVerifiedBankReceiptCash::class)->execute((string) $tenant->id, $receipt, $actor, ['posting_operation_id' => $operation]);

        self::assertSame($result['posting_id'], $replay['posting_id']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($mapping, DB::table('bank_receipt_cash_postings')->where('id', $result['posting_id'])->value('cash_mapping_id'));
        self::assertSame($policy, DB::table('bank_receipt_cash_postings')->where('id', $result['posting_id'])->value('cash_policy_id'));
        self::assertSame('2026-08-01', DB::table('journal_entries')->where('id', $result['journal_entry_id'])->value('entry_date'));
        self::assertSame(2, DB::table('journal_lines')->where('journal_entry_id', $result['journal_entry_id'])->count());
    }

    public function test_posted_cash_blocks_ordinary_invalidation_and_compound_reversal_invalidates(): void
    {
        [$tenant, $actor, $cash, $clearing, $receiving, $receipt] = $this->cashPostingContext();
        app(ConfigureReceivingAccountCashMapping::class)->execute((string) $tenant->id, $receiving, $actor, ['mapping_operation_id' => (string) Str::ulid(), 'cash_account_id' => $cash]);
        app(ConfigureBankReceiptCashClearingPolicy::class)->execute((string) $tenant->id, $actor, ['policy_operation_id' => (string) Str::ulid(), 'clearing_account_id' => $clearing]);
        $posting = app(PostVerifiedBankReceiptCash::class)->execute((string) $tenant->id, $receipt, $actor, ['posting_operation_id' => (string) Str::ulid()]);

        $this->expectException(QueryException::class);
        app(InvalidateBankReceipt::class)->execute((string) $tenant->id, $receipt, $actor, ['invalidation_operation_id' => (string) Str::ulid(), 'invalidation_reason' => 'Must reverse first']);
    }

    public function test_compound_reversal_and_invalidation_replays_exactly(): void
    {
        [$tenant, $actor, $cash, $clearing, $receiving, $receipt] = $this->cashPostingContext();
        app(ConfigureReceivingAccountCashMapping::class)->execute((string) $tenant->id, $receiving, $actor, ['mapping_operation_id' => (string) Str::ulid(), 'cash_account_id' => $cash]);
        app(ConfigureBankReceiptCashClearingPolicy::class)->execute((string) $tenant->id, $actor, ['policy_operation_id' => (string) Str::ulid(), 'clearing_account_id' => $clearing]);
        $posting = app(PostVerifiedBankReceiptCash::class)->execute((string) $tenant->id, $receipt, $actor, ['posting_operation_id' => (string) Str::ulid()]);
        $facts = ['reversal_operation_id' => (string) Str::ulid(), 'reversal_date' => '2026-08-02', 'reversal_reason' => 'Evidence corrected', 'invalidation_operation_id' => (string) Str::ulid(), 'invalidation_reason' => 'Evidence corrected'];
        $result = app(ReversePostedBankReceiptCashAndInvalidate::class)->execute((string) $tenant->id, $receipt, $posting['posting_id'], $actor, $facts);
        $replay = app(ReversePostedBankReceiptCashAndInvalidate::class)->execute((string) $tenant->id, $receipt, $posting['posting_id'], $actor, $facts);

        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($result['reversal_journal_entry_id'], $replay['reversal_journal_entry_id']);
        self::assertSame('reversed', DB::table('bank_receipt_cash_postings')->where('id', $posting['posting_id'])->value('status'));
        self::assertSame('invalidated', DB::table('bank_receipt_evidence')->where('id', $receipt)->value('status'));
    }
}
