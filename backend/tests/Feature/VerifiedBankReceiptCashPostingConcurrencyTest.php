<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\ReceiptEvidence\Actions\ConfigureBankReceiptCashClearingPolicy;
use App\Modules\ReceiptEvidence\Actions\ConfigureReceivingAccountCashMapping;
use App\Modules\ReceiptEvidence\Actions\PostVerifiedBankReceiptCash;
use App\Modules\ReceiptEvidence\Actions\VerifyBankReceipt;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Support\VerifiedBankReceiptCashRecoveryResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesVerifiedBankReceiptCashFixtures;
use Tests\TestCase;

final class VerifiedBankReceiptCashPostingConcurrencyTest extends TestCase
{
    use CreatesVerifiedBankReceiptCashFixtures;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_c1_same_posting_operation_same_facts_replays(): void
    {
        $c = $this->configured();
        $operation = (string) Str::ulid();

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, $operation),
            $this->postPayload($c, $operation),
        ]);

        self::assertSame(2, $this->successes($results), json_encode($results));

        $ids = array_map(
            fn (array $result): string => $result['result']['posting_id'],
            $results,
        );

        self::assertSame($ids[0], $ids[1]);
        self::assertSame(
            1,
            DB::table('bank_receipt_cash_postings')
                ->where('tenant_id', $c['tenant'])
                ->where('posting_operation_id', $operation)
                ->count(),
        );
    }

    public function test_c2_same_posting_operation_different_facts_conflicts(): void
    {
        $c = $this->configured();
        $otherReceipt = $this->receipt(
            $c['tenant'],
            $c['actor'],
            $c['receiving'],
            'c2-other',
        );
        $operation = (string) Str::ulid();

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, $operation),
            $this->postPayload(
                [...$c, 'receipt' => $otherReceipt],
                $operation,
            ),
        ]);

        $this->assertOneReceiptConflict($results);
        self::assertSame(
            1,
            DB::table('bank_receipt_cash_postings')
                ->where('tenant_id', $c['tenant'])
                ->where('posting_operation_id', $operation)
                ->count(),
        );
    }

    public function test_c3_different_operations_same_receipt_create_one_posting(): void
    {
        $c = $this->configured();

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, (string) Str::ulid()),
            $this->postPayload($c, (string) Str::ulid()),
        ]);

        $this->assertOneReceiptConflict($results);

        self::assertSame(
            1,
            DB::table('bank_receipt_cash_postings')
                ->where('tenant_id', $c['tenant'])
                ->where('receipt_id', $c['receipt'])
                ->count(),
        );
    }

    public function test_c4_post_vs_direct_invalidation_never_commits_forbidden_state(): void
    {
        $c = $this->configured();

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, (string) Str::ulid()),
            [
                'action' => 'direct_invalidate_receipt',
                'tenant_id' => $c['tenant'],
                'actor_id' => $c['actor']->id,
                'receipt_id' => $c['receipt'],
                'invalidation_operation_id' => (string) Str::ulid(),
                'invalidation_reason' => 'C4 direct race',
            ],
        ]);

        self::assertSame(1, $this->successes($results), json_encode($results));

        $status = DB::table('bank_receipt_evidence')
            ->where('id', $c['receipt'])
            ->value('status');

        $posted = DB::table('bank_receipt_cash_postings')
            ->where('tenant_id', $c['tenant'])
            ->where('receipt_id', $c['receipt'])
            ->where('status', 'posted')
            ->exists();

        self::assertFalse($status === 'invalidated' && $posted);
        self::assertTrue(
            ($status === 'invalidated' && ! $posted)
            || ($status === 'effective' && $posted),
        );
    }

    public function test_c5_compound_reversal_vs_posting_retry_has_valid_terminal_truth(): void
    {
        $c = $this->configured();
        $operation = (string) Str::ulid();

        $posting = app(PostVerifiedBankReceiptCash::class)->execute(
            $c['tenant'],
            $c['receipt'],
            $c['actor'],
            ['posting_operation_id' => $operation],
        );

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, $operation),
            $this->reversePayload($c, $posting['posting_id']),
        ]);

        self::assertGreaterThanOrEqual(
            1,
            $this->successes($results),
            json_encode($results),
        );

        self::assertSame(
            'reversed',
            DB::table('bank_receipt_cash_postings')
                ->where('id', $posting['posting_id'])
                ->value('status'),
        );
        self::assertSame(
            'invalidated',
            DB::table('bank_receipt_evidence')
                ->where('id', $c['receipt'])
                ->value('status'),
        );
        self::assertSame(
            1,
            DB::table('journal_entries')
                ->where('tenant_id', $c['tenant'])
                ->where('origin', 'business')
                ->where('source_type', 'bank_receipt_cash_posting')
                ->where('source_id', $posting['posting_id'])
                ->count(),
        );
    }

    public function test_c6_same_reversal_operation_same_facts_replays(): void
    {
        $c = $this->configuredAndPosted();
        $facts = $this->reversalFacts();

        $results = $this->race($c['tenant'], [
            $this->reversePayload($c, $c['posting_id'], $facts),
            $this->reversePayload($c, $c['posting_id'], $facts),
        ]);

        self::assertSame(2, $this->successes($results), json_encode($results));

        $ids = array_map(
            fn (array $result): string => $result['result']['reversal_journal_entry_id'],
            $results,
        );

        self::assertSame($ids[0], $ids[1]);
    }

    public function test_c7_same_reversal_operation_different_facts_conflicts(): void
    {
        $c = $this->configuredAndPosted();
        $operation = (string) Str::ulid();

        $first = $this->reversalFacts([
            'reversal_operation_id' => $operation,
            'reversal_reason' => 'C7 first',
        ]);
        $second = $this->reversalFacts([
            'reversal_operation_id' => $operation,
            'reversal_reason' => 'C7 different',
        ]);

        $results = $this->race($c['tenant'], [
            $this->reversePayload($c, $c['posting_id'], $first),
            $this->reversePayload($c, $c['posting_id'], $second),
        ]);

        $this->assertOneReceiptConflict($results);
    }

    public function test_c8_mapping_supersession_same_operation_same_facts_replays(): void
    {
        $c = $this->configured();
        $replacement = $this->account(
            $c['tenant'],
            $c['actor'],
            '1200',
            'asset',
            'current_asset',
        );

        $facts = [
            'mapping_operation_id' => (string) Str::ulid(),
            'cash_account_id' => $replacement,
            'supersession_reason' => 'C8',
        ];

        $results = $this->race($c['tenant'], [
            $this->mappingSupersessionPayload($c, $facts),
            $this->mappingSupersessionPayload($c, $facts),
        ]);

        self::assertSame(2, $this->successes($results), json_encode($results));

        $ids = array_map(
            fn (array $result): string => $result['mapping_id'],
            $results,
        );

        self::assertSame($ids[0], $ids[1]);
    }

    public function test_c9_mapping_same_operation_different_facts_returns_domain_conflict(): void
    {
        $c = $this->configured();

        $firstAccount = $this->account(
            $c['tenant'],
            $c['actor'],
            '1200',
            'asset',
            'current_asset',
        );
        $secondAccount = $this->account(
            $c['tenant'],
            $c['actor'],
            '1300',
            'asset',
            'current_asset',
        );

        $operation = (string) Str::ulid();

        $results = $this->race($c['tenant'], [
            $this->mappingSupersessionPayload($c, [
                'mapping_operation_id' => $operation,
                'cash_account_id' => $firstAccount,
                'supersession_reason' => 'C9',
            ]),
            $this->mappingSupersessionPayload($c, [
                'mapping_operation_id' => $operation,
                'cash_account_id' => $secondAccount,
                'supersession_reason' => 'C9',
            ]),
        ]);

        $this->assertOneReceiptConflict($results);
    }

    public function test_c10_two_mapping_supersessions_same_predecessor_have_one_successor(): void
    {
        $c = $this->configured();

        $firstAccount = $this->account(
            $c['tenant'],
            $c['actor'],
            '1200',
            'asset',
            'current_asset',
        );
        $secondAccount = $this->account(
            $c['tenant'],
            $c['actor'],
            '1300',
            'asset',
            'current_asset',
        );

        $results = $this->race($c['tenant'], [
            $this->mappingSupersessionPayload($c, [
                'mapping_operation_id' => (string) Str::ulid(),
                'cash_account_id' => $firstAccount,
                'supersession_reason' => 'C10 first',
            ]),
            $this->mappingSupersessionPayload($c, [
                'mapping_operation_id' => (string) Str::ulid(),
                'cash_account_id' => $secondAccount,
                'supersession_reason' => 'C10 second',
            ]),
        ]);

        $this->assertOneReceiptConflict($results);

        self::assertSame(
            1,
            DB::table('approved_receiving_account_cash_mappings')
                ->where('tenant_id', $c['tenant'])
                ->where('replaces_mapping_id', $c['mapping'])
                ->where('status', 'effective')
                ->count(),
        );
    }

    public function test_c11_mapping_supersession_vs_first_post_snapshots_one_committed_mapping(): void
    {
        $c = $this->configured();
        $replacement = $this->account(
            $c['tenant'],
            $c['actor'],
            '1200',
            'asset',
            'current_asset',
        );

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, (string) Str::ulid()),
            $this->mappingSupersessionPayload($c, [
                'mapping_operation_id' => (string) Str::ulid(),
                'cash_account_id' => $replacement,
                'supersession_reason' => 'C11',
            ]),
        ]);

        self::assertSame(2, $this->successes($results), json_encode($results));

        $posting = DB::table('bank_receipt_cash_postings')
            ->where('tenant_id', $c['tenant'])
            ->where('receipt_id', $c['receipt'])
            ->first();

        self::assertNotNull($posting);

        $mapping = DB::table('approved_receiving_account_cash_mappings')
            ->where('tenant_id', $c['tenant'])
            ->where('id', $posting->cash_mapping_id)
            ->first();

        self::assertNotNull($mapping);
        self::assertSame($mapping->cash_account_id, $posting->cash_account_id);
    }

    public function test_c12_policy_supersession_same_operation_same_facts_replays(): void
    {
        $c = $this->configured();
        $replacement = $this->account(
            $c['tenant'],
            $c['actor'],
            '2200',
            'liability',
            'current_liability',
        );

        $facts = [
            'policy_operation_id' => (string) Str::ulid(),
            'clearing_account_id' => $replacement,
            'supersession_reason' => 'C12',
        ];

        $results = $this->race($c['tenant'], [
            $this->policySupersessionPayload($c, $facts),
            $this->policySupersessionPayload($c, $facts),
        ]);

        self::assertSame(2, $this->successes($results), json_encode($results));

        $ids = array_map(
            fn (array $result): string => $result['policy_id'],
            $results,
        );

        self::assertSame($ids[0], $ids[1]);
    }

    public function test_c13_policy_same_operation_different_facts_conflicts(): void
    {
        $c = $this->configured();

        $firstAccount = $this->account(
            $c['tenant'],
            $c['actor'],
            '2200',
            'liability',
            'current_liability',
        );
        $secondAccount = $this->account(
            $c['tenant'],
            $c['actor'],
            '2300',
            'liability',
            'current_liability',
        );

        $operation = (string) Str::ulid();

        $results = $this->race($c['tenant'], [
            $this->policySupersessionPayload($c, [
                'policy_operation_id' => $operation,
                'clearing_account_id' => $firstAccount,
                'supersession_reason' => 'C13',
            ]),
            $this->policySupersessionPayload($c, [
                'policy_operation_id' => $operation,
                'clearing_account_id' => $secondAccount,
                'supersession_reason' => 'C13',
            ]),
        ]);

        $this->assertOneReceiptConflict($results);
    }

    public function test_c14_two_policy_supersessions_same_predecessor_have_one_effective_successor(): void
    {
        $c = $this->configured();

        $firstAccount = $this->account(
            $c['tenant'],
            $c['actor'],
            '2200',
            'liability',
            'current_liability',
        );
        $secondAccount = $this->account(
            $c['tenant'],
            $c['actor'],
            '2300',
            'liability',
            'current_liability',
        );

        $results = $this->race($c['tenant'], [
            $this->policySupersessionPayload($c, [
                'policy_operation_id' => (string) Str::ulid(),
                'clearing_account_id' => $firstAccount,
                'supersession_reason' => 'C14 first',
            ]),
            $this->policySupersessionPayload($c, [
                'policy_operation_id' => (string) Str::ulid(),
                'clearing_account_id' => $secondAccount,
                'supersession_reason' => 'C14 second',
            ]),
        ]);

        $this->assertOneReceiptConflict($results);

        self::assertSame(
            1,
            DB::table('bank_receipt_cash_clearing_policies')
                ->where('tenant_id', $c['tenant'])
                ->where('replaces_policy_id', $c['policy'])
                ->where('status', 'effective')
                ->count(),
        );
    }

    public function test_c15_policy_supersession_vs_post_snapshots_one_committed_policy(): void
    {
        $c = $this->configured();

        $replacement = $this->account(
            $c['tenant'],
            $c['actor'],
            '2200',
            'liability',
            'current_liability',
        );

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, (string) Str::ulid()),
            $this->policySupersessionPayload($c, [
                'policy_operation_id' => (string) Str::ulid(),
                'clearing_account_id' => $replacement,
                'supersession_reason' => 'C15',
            ]),
        ]);

        self::assertSame(2, $this->successes($results), json_encode($results));

        $posting = DB::table('bank_receipt_cash_postings')
            ->where('tenant_id', $c['tenant'])
            ->where('receipt_id', $c['receipt'])
            ->first();

        self::assertNotNull($posting);

        $policy = DB::table('bank_receipt_cash_clearing_policies')
            ->where('tenant_id', $c['tenant'])
            ->where('id', $posting->cash_policy_id)
            ->first();

        self::assertNotNull($policy);
        self::assertSame(
            $policy->clearing_account_id,
            $posting->clearing_account_id,
        );
    }

    public function test_c16_period_close_vs_post_never_posts_into_already_closed_period(): void
    {
        $c = $this->configured();

        $periodId = (string) DB::table('accounting_periods')
            ->where('tenant_id', $c['tenant'])
            ->where('start_date', '<=', '2026-08-01')
            ->where('end_date', '>=', '2026-08-01')
            ->value('id');

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, (string) Str::ulid()),
            [
                'action' => 'close_period',
                'tenant_id' => $c['tenant'],
                'actor_id' => $c['actor']->id,
                'period_id' => $periodId,
            ],
        ]);

        self::assertGreaterThanOrEqual(
            1,
            $this->successes($results),
            json_encode($results),
        );

        self::assertSame(
            'closed',
            DB::table('accounting_periods')
                ->where('id', $periodId)
                ->value('status'),
        );

        self::assertSame(
            0,
            DB::table('journal_entries')
                ->where('tenant_id', $c['tenant'])
                ->where('source_type', 'bank_receipt_cash_posting')
                ->where('status', 'draft')
                ->count(),
        );

        self::assertLessThanOrEqual(
            1,
            DB::table('bank_receipt_cash_postings')
                ->where('tenant_id', $c['tenant'])
                ->where('receipt_id', $c['receipt'])
                ->count(),
        );
    }

    public function test_c17_account_archive_vs_post_never_uses_an_invalid_account_at_post_time(): void
    {
        $c = $this->configured();

        $results = $this->race($c['tenant'], [
            $this->postPayload($c, (string) Str::ulid()),
            [
                'action' => 'archive_account',
                'tenant_id' => $c['tenant'],
                'actor_id' => $c['actor']->id,
                'account_id' => $c['cash'],
            ],
        ]);

        self::assertGreaterThanOrEqual(
            1,
            $this->successes($results),
            json_encode($results),
        );

        self::assertSame(
            'archived',
            DB::table('accounts')->where('id', $c['cash'])->value('status'),
        );

        self::assertSame(
            0,
            DB::table('journal_entries')
                ->where('tenant_id', $c['tenant'])
                ->where('source_type', 'bank_receipt_cash_posting')
                ->where('status', 'draft')
                ->count(),
        );

        $posting = DB::table('bank_receipt_cash_postings')
            ->where('tenant_id', $c['tenant'])
            ->where('receipt_id', $c['receipt'])
            ->first();

        if ($posting !== null) {
            self::assertSame($c['cash'], $posting->cash_account_id);
            self::assertSame(
                'posted',
                DB::table('journal_entries')
                    ->where('id', $posting->journal_entry_id)
                    ->value('status'),
            );
        }
    }

    public function test_c18_cross_tenant_receipt_mapping_policy_and_accounts_are_rejected(): void
    {
        $a = $this->configured();
        $b = $this->configured();

        $postingId = (string) Str::ulid();

        $journal = $this->businessJournal(
            $a,
            $postingId,
            '2026-08-01',
            [
                new JournalLineData($a['cash'], '25.00', '0'),
                new JournalLineData($a['clearing'], '0', '25.00'),
            ],
        );

        $crossReceipt = $this->runWorker([
            'action' => 'direct_insert_posting',
            'row' => $this->postingRow(
                $a,
                $postingId,
                $journal,
                ['receipt_id' => $b['receipt']],
            ),
        ]);

        self::assertFalse($crossReceipt['ok'], json_encode($crossReceipt));

        $postingId = (string) Str::ulid();

        $journal = $this->businessJournal(
            $a,
            $postingId,
            '2026-08-01',
            [
                new JournalLineData($a['cash'], '25.00', '0'),
                new JournalLineData($a['clearing'], '0', '25.00'),
            ],
        );

        $crossDependencies = $this->runWorker([
            'action' => 'direct_insert_posting',
            'row' => $this->postingRow($a, $postingId, $journal, [
                'cash_mapping_id' => $b['mapping'],
                'cash_policy_id' => $b['policy'],
                'cash_account_id' => $b['cash'],
                'clearing_account_id' => $b['clearing'],
            ]),
        ]);

        self::assertFalse(
            $crossDependencies['ok'],
            json_encode($crossDependencies),
        );
    }

    public function test_c19_direct_sql_dual_effective_mapping_and_policy_are_rejected(): void
    {
        $c = $this->configured();

        $mapping = $this->runWorker([
            'action' => 'direct_insert_mapping',
            'id' => (string) Str::ulid(),
            'tenant_id' => $c['tenant'],
            'actor_id' => $c['actor']->id,
            'mapping_operation_id' => (string) Str::ulid(),
            'receiving_account_id' => $c['receiving'],
            'cash_account_id' => $c['cash'],
        ]);

        self::assertFalse($mapping['ok'], json_encode($mapping));
        self::assertSame('23505', $mapping['sqlstate']);

        $policy = $this->runWorker([
            'action' => 'direct_insert_policy',
            'id' => (string) Str::ulid(),
            'tenant_id' => $c['tenant'],
            'actor_id' => $c['actor']->id,
            'policy_operation_id' => (string) Str::ulid(),
            'clearing_account_id' => $c['clearing'],
        ]);

        self::assertFalse($policy['ok'], json_encode($policy));
        self::assertSame('23505', $policy['sqlstate']);
    }

    public function test_c20_direct_sql_broken_supersession_chains_are_rejected(): void
    {
        $c = $this->configured();

        $replacementCash = $this->account(
            $c['tenant'],
            $c['actor'],
            '1200',
            'asset',
            'current_asset',
        );

        $mapping = $this->runWorker([
            'action' => 'direct_broken_mapping_chain',
            'tenant_id' => $c['tenant'],
            'actor_id' => $c['actor']->id,
            'mapping_id' => $c['mapping'],
            'successor_id' => (string) Str::ulid(),
            'supersession_operation_id' => (string) Str::ulid(),
            'different_operation_id' => (string) Str::ulid(),
            'receiving_account_id' => $c['receiving'],
            'cash_account_id' => $replacementCash,
        ]);

        self::assertFalse($mapping['ok'], json_encode($mapping));
        self::assertSame('23514', $mapping['sqlstate']);

        $replacementClearing = $this->account(
            $c['tenant'],
            $c['actor'],
            '2200',
            'liability',
            'current_liability',
        );

        $policy = $this->runWorker([
            'action' => 'direct_broken_policy_chain',
            'tenant_id' => $c['tenant'],
            'actor_id' => $c['actor']->id,
            'policy_id' => $c['policy'],
            'successor_id' => (string) Str::ulid(),
            'supersession_operation_id' => (string) Str::ulid(),
            'different_operation_id' => (string) Str::ulid(),
            'clearing_account_id' => $replacementClearing,
        ]);

        self::assertFalse($policy['ok'], json_encode($policy));
        self::assertSame('23514', $policy['sqlstate']);
    }

    public function test_c21_direct_sql_wrong_journal_facts_are_rejected(): void
    {
        $this->assertBadJournalPostingRejected('source');
        $this->assertBadJournalPostingRejected('date');
        $this->assertBadJournalPostingRejected('amount');
        $this->assertBadJournalPostingRejected('shape');
        $this->assertBadJournalPostingRejected('accounts');
    }

    public function test_c22_runtime_role_cannot_execute_protected_phase8f_function(): void
    {
        $c = $this->configured();

        $result = $this->runWorker([
            'action' => 'runtime_execute',
            'tenant_id' => $c['tenant'],
            'runtime_role' => (string) (
                env('ACCOUNTING_RUNTIME_DB_ROLE') ?: 'nexusos_runtime'
            ),
        ]);

        self::assertFalse($result['ok'], json_encode($result));
        self::assertSame('42501', $result['sqlstate'], json_encode($result));
    }

    public function test_c23_unknown_outcome_recovery_is_retryable_committed_or_fail_closed(): void
    {
        $c = $this->configured();
        $resolver = app(VerifiedBankReceiptCashRecoveryResolver::class);

        $absentOperation = (string) Str::ulid();

        $retryable = $resolver->resolve(
            $c['tenant'],
            $absentOperation,
            $c['receipt'],
            (int) $c['actor']->id,
        );

        self::assertSame('retryable', $retryable['status']);
        self::assertNull($retryable['posting_id']);
        self::assertNull($retryable['journal_entry_id']);

        $operation = (string) Str::ulid();

        $posted = app(PostVerifiedBankReceiptCash::class)->execute(
            $c['tenant'],
            $c['receipt'],
            $c['actor'],
            ['posting_operation_id' => $operation],
        );

        $committed = $resolver->resolve(
            $c['tenant'],
            $operation,
            $c['receipt'],
            (int) $c['actor']->id,
        );

        self::assertSame('committed', $committed['status']);
        self::assertSame($posted['posting_id'], $committed['posting_id']);
        self::assertSame(
            $posted['journal_entry_id'],
            $committed['journal_entry_id'],
        );

        try {
            $resolver->resolve(
                $c['tenant'],
                $operation,
                (string) Str::ulid(),
                (int) $c['actor']->id,
            );
            self::fail('Expected mismatched recovery facts to fail closed.');
        } catch (ReceiptEvidenceConflict) {
            self::assertTrue(true);
        }

        $orphan = $this->configured();
        $sourceId = (string) Str::ulid();

        $this->businessJournal(
            $orphan,
            $sourceId,
            '2026-08-01',
            [
                new JournalLineData($orphan['cash'], '25.00', '0'),
                new JournalLineData($orphan['clearing'], '0', '25.00'),
            ],
        );

        try {
            $resolver->resolve(
                $orphan['tenant'],
                (string) Str::ulid(),
                $orphan['receipt'],
                (int) $orphan['actor']->id,
            );
            self::fail('Expected orphan Accounting truth to fail closed.');
        } catch (ReceiptEvidenceConflict) {
            self::assertTrue(true);
        }

        self::assertSame(
            1,
            DB::table('bank_receipt_cash_postings')
                ->where('tenant_id', $c['tenant'])
                ->where('posting_operation_id', $operation)
                ->count(),
        );
    }

    private function configured(): array
    {
        [$tenant, $actor, $cash, $clearing, $receiving, $receipt] =
            $this->cashPostingContext();

        $tenantId = (string) $tenant->id;

        $mapping = app(ConfigureReceivingAccountCashMapping::class)->execute(
            $tenantId,
            $receiving,
            $actor,
            [
                'mapping_operation_id' => (string) Str::ulid(),
                'cash_account_id' => $cash,
            ],
        );

        $policy = app(ConfigureBankReceiptCashClearingPolicy::class)->execute(
            $tenantId,
            $actor,
            [
                'policy_operation_id' => (string) Str::ulid(),
                'clearing_account_id' => $clearing,
            ],
        );

        return [
            'tenant' => $tenantId,
            'actor' => $actor,
            'cash' => $cash,
            'clearing' => $clearing,
            'receiving' => $receiving,
            'receipt' => $receipt,
            'mapping' => $mapping,
            'policy' => $policy,
        ];
    }

    private function configuredAndPosted(): array
    {
        $c = $this->configured();

        $posting = app(PostVerifiedBankReceiptCash::class)->execute(
            $c['tenant'],
            $c['receipt'],
            $c['actor'],
            ['posting_operation_id' => (string) Str::ulid()],
        );

        return [
            ...$c,
            'posting_id' => $posting['posting_id'],
            'journal_entry_id' => $posting['journal_entry_id'],
        ];
    }

    private function receipt(
        string $tenant,
        User $actor,
        string $receiving,
        string $source,
    ): string {
        return app(VerifyBankReceipt::class)->execute(
            $tenant,
            $actor,
            [
                'receipt_operation_id' => (string) Str::ulid(),
                'receiving_account_id' => $receiving,
                'source_identity_kind' => 'bank_transaction_id',
                'source_identity_version' => 1,
                'source_identity' => $source.'-'.Str::lower(
                    (string) Str::ulid(),
                ),
                'amount' => '25.00',
                'currency' => 'SAR',
                'control_date' => '2026-08-01',
                'evidence_reference' => 'concurrency',
            ],
        );
    }

    private function account(
        string $tenant,
        User $actor,
        string $code,
        string $type,
        string $classification,
    ): string {
        return app(ManageAccountAction::class)->create(
            $tenant,
            $actor,
            $this->cashAccount($code, $type, $classification),
        );
    }

    private function postPayload(array $c, string $operation): array
    {
        return [
            'action' => 'post',
            'tenant_id' => $c['tenant'],
            'actor_id' => $c['actor']->id,
            'receipt_id' => $c['receipt'],
            'facts' => ['posting_operation_id' => $operation],
        ];
    }

    private function reversePayload(
        array $c,
        string $postingId,
        ?array $facts = null,
    ): array {
        return [
            'action' => 'reverse',
            'tenant_id' => $c['tenant'],
            'actor_id' => $c['actor']->id,
            'receipt_id' => $c['receipt'],
            'posting_id' => $postingId,
            'facts' => $facts ?? $this->reversalFacts(),
        ];
    }

    private function reversalFacts(array $overrides = []): array
    {
        return [
            ...[
                'reversal_operation_id' => (string) Str::ulid(),
                'reversal_date' => '2026-08-02',
                'reversal_reason' => 'Concurrent Phase 8F correction',
                'invalidation_operation_id' => (string) Str::ulid(),
                'invalidation_reason' => 'Concurrent Phase 8F correction',
            ],
            ...$overrides,
        ];
    }

    private function mappingSupersessionPayload(
        array $c,
        array $facts,
    ): array {
        return [
            'action' => 'supersede_mapping',
            'tenant_id' => $c['tenant'],
            'actor_id' => $c['actor']->id,
            'mapping_id' => $c['mapping'],
            'facts' => $facts,
        ];
    }

    private function policySupersessionPayload(
        array $c,
        array $facts,
    ): array {
        return [
            'action' => 'supersede_policy',
            'tenant_id' => $c['tenant'],
            'actor_id' => $c['actor']->id,
            'policy_id' => $c['policy'],
            'facts' => $facts,
        ];
    }

    private function businessJournal(
        array $c,
        string $sourceId,
        string $entryDate,
        array $lines,
        ?string $requestSourceId = null,
    ): string {
        $request = new BusinessPostingRequest(
            $c['tenant'],
            (int) $c['actor']->id,
            'bank_receipt_cash_posting',
            $requestSourceId ?? $sourceId,
            'SAR',
            $entryDate,
            'Verified bank receipt cash posting',
            $lines,
        );

        $result = DB::transaction(
            fn () => app(BusinessPostingServiceInterface::class)->post(
                $request,
            ),
        );

        return (string) $result->journalEntryId;
    }

    private function postingRow(
        array $c,
        string $postingId,
        string $journalId,
        array $overrides = [],
    ): array {
        $now = now()->toDateTimeString();

        return [
            ...[
                'id' => $postingId,
                'tenant_id' => $c['tenant'],
                'posting_operation_id' => (string) Str::ulid(),
                'receipt_id' => $c['receipt'],
                'cash_mapping_id' => $c['mapping'],
                'cash_policy_id' => $c['policy'],
                'receiving_account_id' => $c['receiving'],
                'amount' => '25.00',
                'currency' => 'SAR',
                'accounting_date' => '2026-08-01',
                'cash_account_id' => $c['cash'],
                'clearing_account_id' => $c['clearing'],
                'status' => 'posted',
                'journal_entry_id' => $journalId,
                'posted_by' => $c['actor']->id,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ...$overrides,
        ];
    }

    private function assertBadJournalPostingRejected(string $kind): void
    {
        $c = $this->configured();
        $postingId = (string) Str::ulid();

        $entryDate = $kind === 'date'
            ? '2026-08-02'
            : '2026-08-01';

        $requestSource = $kind === 'source'
            ? (string) Str::ulid()
            : $postingId;

        $lines = [
            new JournalLineData($c['cash'], '25.00', '0'),
            new JournalLineData($c['clearing'], '0', '25.00'),
        ];

        if ($kind === 'amount') {
            $lines = [
                new JournalLineData($c['cash'], '24.00', '0'),
                new JournalLineData($c['clearing'], '0', '24.00'),
            ];
        }

        if ($kind === 'shape') {
            $otherClearing = $this->account(
                $c['tenant'],
                $c['actor'],
                '2200',
                'liability',
                'current_liability',
            );

            $lines = [
                new JournalLineData($c['cash'], '25.00', '0'),
                new JournalLineData($c['clearing'], '0', '20.00'),
                new JournalLineData($otherClearing, '0', '5.00'),
            ];
        }

        if ($kind === 'accounts') {
            $otherCash = $this->account(
                $c['tenant'],
                $c['actor'],
                '1200',
                'asset',
                'current_asset',
            );
            $otherClearing = $this->account(
                $c['tenant'],
                $c['actor'],
                '2200',
                'liability',
                'current_liability',
            );

            $lines = [
                new JournalLineData($otherCash, '25.00', '0'),
                new JournalLineData($otherClearing, '0', '25.00'),
            ];
        }

        $journal = $this->businessJournal(
            $c,
            $postingId,
            $entryDate,
            $lines,
            $requestSource,
        );

        $result = $this->runWorker([
            'action' => 'direct_insert_posting',
            'row' => $this->postingRow(
                $c,
                $postingId,
                $journal,
            ),
        ]);

        self::assertFalse($result['ok'], $kind.': '.json_encode($result));
        self::assertSame(
            '23514',
            $result['sqlstate'],
            $kind.': '.json_encode($result),
        );
    }

    private function successes(array $results): int
    {
        return count(
            array_filter(
                $results,
                fn (array $result): bool => $result['ok'],
            ),
        );
    }

    private function assertOneReceiptConflict(array $results): void
    {
        self::assertSame(
            1,
            $this->successes($results),
            json_encode($results),
        );

        $failed = array_values(
            array_filter(
                $results,
                fn (array $result): bool => ! $result['ok'],
            ),
        );

        self::assertCount(1, $failed);
        self::assertSame(
            ReceiptEvidenceConflict::class,
            $failed[0]['class'],
            json_encode($results),
        );
    }

    private function file(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);

        if ($file === false) {
            throw new \RuntimeException('Unable to create temporary file.');
        }

        unlink($file);
        $this->temporaryFiles[] = $file;

        return $file;
    }

    private function wait(array $files): void
    {
        $deadline = microtime(true) + 15;

        while (
            array_filter(
                $files,
                fn (string $file): bool => ! is_file($file),
            )
        ) {
            if (microtime(true) > $deadline) {
                self::fail(
                    'Timed out waiting for Phase 8F concurrency barrier.',
                );
            }

            usleep(1000);
        }
    }

    private function start(array $payload): array
    {
        $connection = config(
            'database.connections.'.DB::getDefaultConnection(),
        );

        $database = array_intersect_key(
            $connection,
            array_flip([
                'host',
                'port',
                'database',
                'username',
                'password',
            ]),
        );

        $process = proc_open(
            [
                PHP_BINARY,
                base_path(
                    'tests/Support/'
                    .'verified_bank_receipt_cash_posting_worker.php',
                ),
                base64_encode(
                    json_encode(
                        $payload + ['database' => $database],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($process);
        fclose($pipes[0]);

        return [$process, $pipes[1], $pipes[2]];
    }

    private function finish(array $worker): array
    {
        [$process, $stdoutPipe, $stderrPipe] = $worker;

        $stdout = stream_get_contents($stdoutPipe);
        $stderr = stream_get_contents($stderrPipe);

        fclose($stdoutPipe);
        fclose($stderrPipe);

        self::assertSame(0, proc_close($process), $stderr);

        return json_decode(
            $stdout,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function runWorker(array $payload): array
    {
        return $this->finish($this->start($payload));
    }

    private function waitLocks(array $pids): void
    {
        $deadline = microtime(true) + 15;

        while (true) {
            $waiting = DB::table('pg_stat_activity')
                ->whereIn('pid', $pids)
                ->where('wait_event_type', 'Lock')
                ->count();

            if ($waiting === count($pids)) {
                return;
            }

            if (microtime(true) > $deadline) {
                self::fail(
                    'Timed out waiting for Phase 8F PostgreSQL locks.',
                );
            }

            usleep(1000);
        }
    }

    private function race(string $tenant, array $payloads): array
    {
        $ready = $this->file('phase8f-holder-ready-');
        $release = $this->file('phase8f-holder-release-');
        $start = $this->file('phase8f-start-');

        $holder = $this->start([
            'action' => 'hold_tenant',
            'tenant_id' => $tenant,
            'ready_file' => $ready,
            'release_file' => $release,
            'barrier_timeout_ms' => 15000,
        ]);

        $this->wait([$ready]);

        $workers = [];
        $pidFiles = [];

        foreach ($payloads as $index => $payload) {
            $pidFile = $this->file(
                'phase8f-worker-'.$index.'-',
            );

            $pidFiles[] = $pidFile;

            $workers[] = $this->start(
                $payload + [
                    'start_barrier' => $start,
                    'barrier_timeout_ms' => 15000,
                    'pid_file' => $pidFile,
                ],
            );
        }

        $this->wait($pidFiles);
        touch($start);

        $pids = array_map(
            fn (string $file): int => (int) trim((string) file_get_contents($file)),
            $pidFiles,
        );

        $this->waitLocks($pids);

        touch($release);

        $results = array_map(
            fn (array $worker): array => $this->finish($worker),
            $workers,
        );

        $this->finish($holder);

        return $results;
    }
}
