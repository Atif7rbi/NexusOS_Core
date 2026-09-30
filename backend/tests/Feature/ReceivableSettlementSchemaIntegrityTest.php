<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Settlement\Actions\ReverseReceivableSettlement;
use App\Modules\Settlement\Actions\SettlePaymentAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesReceivableSettlementFixtures;
use Tests\TestCase;

final class ReceivableSettlementSchemaIntegrityTest extends TestCase
{
    use CreatesReceivableSettlementFixtures;
    use RefreshDatabase;

    public function test_direct_sql_cannot_mutate_or_delete_settlement_truth(): void
    {
        [$fixture, $settlement] = $this->postedSettlement();
        $variants = [
            ['amount' => '999.00'],
            ['currency' => 'USD'],
            ['accounting_date' => '2026-08-20'],
            ['payment_id' => (string) Str::ulid()],
            ['receivable_id' => (string) Str::ulid()],
            ['receipt_payment_association_id' => (string) Str::ulid()],
            ['receipt_id' => (string) Str::ulid()],
            ['bank_receipt_cash_posting_id' => (string) Str::ulid()],
            ['receivable_ar_recognition_id' => (string) Str::ulid()],
            ['clearing_account_id' => $fixture['accounts']['ar']],
            ['ar_control_account_id' => $fixture['accounts']['clearing']],
            ['journal_entry_id' => $fixture['cashPosting']['journal_entry_id']],
        ];
        foreach ($variants as $changes) {
            $this->assertSqlRejected(fn () => DB::table('receivable_settlements')
                ->where('id', $settlement->id)->update($changes));
        }
        $this->assertSqlRejected(fn () => DB::table('receivable_settlements')
            ->where('id', $settlement->id)->delete(), ['55000']);
        self::assertSame('1000.00', DB::table('receivable_settlements')->where('id', $settlement->id)->value('amount'));
    }

    public function test_direct_sql_cannot_duplicate_allocation_or_fabricate_lifecycle(): void
    {
        [, $settlement] = $this->postedSettlement();
        $duplicate = (array) $settlement;
        $duplicate['id'] = (string) Str::ulid();
        $duplicate['settlement_operation_id'] = (string) Str::ulid();
        $this->assertSqlRejected(
            fn () => DB::table('receivable_settlements')->insert($duplicate),
            ['23505'],
        );
        $this->assertSqlRejected(fn () => DB::table('receivable_settlements')
            ->where('id', $settlement->id)->update(['status' => 'reversed']));
    }

    public function test_direct_sql_parent_terminal_transitions_are_blocked_while_posted(): void
    {
        [$fixture] = $this->postedSettlement();
        $now = now();
        $this->assertSqlRejected(fn () => DB::table('payment_allocations')
            ->where('id', $fixture['allocationId'])->update([
                'status' => 'cancelled', 'cancelled_at' => $now,
                'cancelled_by' => $fixture['context']['actor']->id,
                'cancellation_reason' => 'Direct correction', 'updated_at' => $now,
            ]));
        $this->assertSqlRejected(fn () => DB::table('receipt_payment_associations')
            ->where('id', $fixture['associationId'])->update([
                'status' => 'cancelled',
                'cancellation_operation_id' => (string) Str::ulid(),
                'cancellation_reason' => 'Direct correction',
                'cancelled_by' => $fixture['context']['actor']->id,
                'cancelled_at' => $now, 'updated_at' => $now,
            ]));
        $this->assertSqlRejected(fn () => DB::table('bank_receipt_cash_postings')
            ->where('id', $fixture['cashPosting']['posting_id'])->update([
                'status' => 'reversed',
                'reversal_operation_id' => (string) Str::ulid(),
                'reversal_journal_entry_id' => $fixture['cashPosting']['journal_entry_id'],
                'reversal_date' => '2026-08-23',
                'reversal_reason' => 'Direct correction',
                'reversed_by' => $fixture['context']['actor']->id,
                'reversed_at' => $now, 'updated_at' => $now,
            ]));
        $this->assertSqlRejected(fn () => DB::table('receivable_ar_recognitions')
            ->where('id', $fixture['recognitionId'])->update([
                'status' => 'reversed',
                'reversal_operation_id' => (string) Str::ulid(),
                'reversal_journal_entry_id' => $fixture['cashPosting']['journal_entry_id'],
                'reversed_by' => $fixture['context']['actor']->id,
                'reversed_at' => $now,
            ]));
    }

    public function test_generic_journal_reversal_cannot_reverse_settlement_owned_journal(): void
    {
        [$fixture, $settlement] = $this->postedSettlement();
        $this->expectException(AccountingValidationFailed::class);
        app(ReverseJournalAction::class)->execute(
            $fixture['context']['tenant_id'],
            $settlement->journal_entry_id,
            $fixture['context']['actor'],
            '2026-08-23',
            'Generic reversal forbidden',
        );
    }

    public function test_direct_sql_orphan_settlement_source_journal_cannot_commit(): void
    {
        $fixture = $this->settlementContext();
        $orphanSettlementId = (string) Str::ulid();

        $this->assertSqlRejected(function () use ($fixture, $orphanSettlementId): void {
            $this->createDirectPostedJournal(
                $fixture,
                'business',
                'receivable_settlement',
                $orphanSettlementId,
                '2026-08-22',
                [
                    [$fixture['accounts']['clearing'], '1000.00', '0.00'],
                    [$fixture['accounts']['ar'], '0.00', '1000.00'],
                ],
            );
        }, ['23503']);

        self::assertFalse(DB::table('journal_entries')
            ->where('tenant_id', $fixture['context']['tenant_id'])
            ->where('source_type', 'receivable_settlement')
            ->where('source_id', $orphanSettlementId)
            ->exists());
    }

    public function test_direct_sql_cannot_reverse_recorded_settlement_reversal_journal(): void
    {
        [$fixture, $settlement] = $this->postedSettlement();
        app(ReverseReceivableSettlement::class)->execute(
            $fixture['context']['tenant_id'],
            $settlement->id,
            $fixture['context']['actor'],
            [
                'reversal_operation_id' => (string) Str::ulid(),
                'reversal_date' => '2026-08-23',
                'reversal_reason' => 'Authoritative Settlement correction',
            ],
        );
        $settlement = DB::table('receivable_settlements')
            ->where('id', $settlement->id)
            ->firstOrFail();
        $target = DB::table('journal_entries')
            ->where('id', $settlement->reversal_journal_entry_id)
            ->firstOrFail();
        $targetLines = DB::table('journal_lines')
            ->where('journal_entry_id', $target->id)
            ->orderBy('line_number')
            ->get();

        $this->assertSqlRejected(function () use ($fixture, $target, $targetLines): void {
            $this->createDirectPostedJournal(
                $fixture,
                'reversal',
                'journal_entry',
                $target->id,
                '2026-08-24',
                $targetLines->map(fn (object $line): array => [
                    $line->account_id,
                    (string) $line->credit,
                    (string) $line->debit,
                ])->all(),
                $target->id,
                'Forbidden reversal of Settlement reversal',
            );
        }, ['23514']);

        self::assertFalse(DB::table('journal_entries')
            ->where('tenant_id', $fixture['context']['tenant_id'])
            ->where('reverses_journal_entry_id', $target->id)
            ->exists());
    }

    public function test_database_registered_exact_source_audit_and_trigger_contract(): void
    {
        [, $settlement] = $this->postedSettlement();
        self::assertDatabaseHas('accounting_source_types', [
            'origin' => 'business', 'key' => 'receivable_settlement',
            'owner_module' => 'settlement',
        ]);
        self::assertDatabaseHas('accounting_audits', [
            'event' => 'receivable_settlement.posted',
            'subject_type' => 'receivable_settlement',
            'subject_id' => $settlement->id,
        ]);
        foreach ([
            'receivable_settlements_final_state',
            'receivable_settlement_journals_final_state',
            'payment_allocations_settlement_guard',
            'receipt_associations_settlement_guard',
            'cash_postings_settlement_guard',
            'receivable_ar_settlement_guard',
        ] as $trigger) {
            self::assertNotNull(DB::selectOne(
                'SELECT oid FROM pg_catalog.pg_trigger WHERE tgname=? AND NOT tgisinternal',
                [$trigger],
            ), "Missing trigger {$trigger}");
        }
    }

    private function postedSettlement(): array
    {
        $fixture = $this->settlementContext();
        $result = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );

        return [$fixture, DB::table('receivable_settlements')->where('id', $result['settlement_id'])->firstOrFail()];
    }

    private function createDirectPostedJournal(
        array $fixture,
        string $origin,
        string $sourceType,
        string $sourceId,
        string $entryDate,
        array $lines,
        ?string $reversesJournalEntryId = null,
        ?string $reversalReason = null,
    ): string {
        $journalId = (string) Str::ulid();
        $tenantId = $fixture['context']['tenant_id'];
        $actorId = $fixture['context']['actor']->id;
        $at = now();
        DB::table('journal_entries')->insert([
            'id' => $journalId,
            'tenant_id' => $tenantId,
            'entry_date' => $entryDate,
            'description' => 'Direct SQL Settlement ownership fixture',
            'status' => 'draft',
            'origin' => $origin,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $at,
            'updated_at' => $at,
            'reverses_journal_entry_id' => $reversesJournalEntryId,
            'reversal_reason' => $reversalReason,
        ]);
        foreach ($lines as $index => [$accountId, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $journalId,
                'line_number' => $index + 1,
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => 'Direct SQL Settlement ownership fixture line',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }

        $period = DB::table('accounting_periods')
            ->where('tenant_id', $tenantId)
            ->whereDate('start_date', '<=', $entryDate)
            ->whereDate('end_date', '>=', $entryDate)
            ->firstOrFail();
        $year = (int) substr($entryDate, 0, 4);
        $sequence = ((int) DB::table('journal_entries')
            ->where('tenant_id', $tenantId)
            ->where('journal_number_year', $year)
            ->max('journal_sequence_number')) + 1;
        DB::table('journal_entries')
            ->where('tenant_id', $tenantId)
            ->where('id', $journalId)
            ->update([
                'status' => 'posted',
                'accounting_period_id' => $period->id,
                'journal_number' => 'JRN-'.$year.'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                'journal_number_year' => $year,
                'journal_sequence_number' => $sequence,
                'posted_by' => $actorId,
                'posted_at' => $at,
                'updated_by' => $actorId,
                'updated_at' => $at,
            ]);

        return $journalId;
    }

    private function assertSqlRejected(callable $operation, array $states = ['23503', '23514', '23505', '55000', '42501']): void
    {
        DB::beginTransaction();
        try {
            $operation();
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            self::fail('PostgreSQL accepted inconsistent Settlement truth.');
        } catch (QueryException $exception) {
            self::assertContains((string) ($exception->errorInfo[0] ?? ''), $states, $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
