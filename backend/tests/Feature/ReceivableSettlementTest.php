<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\Payments\Actions\CancelPaymentAllocationAction;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\ReceiptEvidence\Actions\CancelReceiptPaymentAssociation;
use App\Modules\ReceiptEvidence\Actions\ReversePostedBankReceiptCashAndInvalidate;
use App\Modules\ReceiptEvidence\Actions\SupersedeBankReceiptCashClearingPolicy;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\Settlement\Actions\ReverseReceivableSettlement;
use App\Modules\Settlement\Actions\SettlePaymentAllocation;
use App\Modules\Settlement\Exceptions\SettlementConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesReceivableSettlementFixtures;
use Tests\TestCase;

final class ReceivableSettlementTest extends TestCase
{
    use CreatesReceivableSettlementFixtures;
    use RefreshDatabase;

    public function test_settlement_posts_exact_clearing_to_ar_journal_and_replays(): void
    {
        $fixture = $this->settlementContext();
        $operation = (string) Str::ulid();
        $facts = [
            'payment_allocation_id' => $fixture['allocationId'],
            'settlement_operation_id' => $operation,
        ];
        $result = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            $facts,
        );
        $replay = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            $facts,
        );

        self::assertSame($result['settlement_id'], $replay['settlement_id']);
        self::assertTrue($replay['idempotent_replay']);
        $settlement = DB::table('receivable_settlements')
            ->where('id', $result['settlement_id'])->firstOrFail();
        self::assertSame('1000.00', $settlement->amount);
        self::assertSame('2026-08-22', (string) $settlement->accounting_date);
        self::assertSame($fixture['accounts']['clearing'], $settlement->clearing_account_id);
        self::assertSame($fixture['accounts']['ar'], $settlement->ar_control_account_id);
        self::assertSame($fixture['recognitionId'], $settlement->receivable_ar_recognition_id);
        self::assertSame($fixture['cashPosting']['posting_id'], $settlement->bank_receipt_cash_posting_id);

        $lines = DB::table('journal_lines')
            ->where('journal_entry_id', $settlement->journal_entry_id)
            ->orderBy('line_number')->get();
        self::assertCount(2, $lines);
        self::assertSame($fixture['accounts']['clearing'], $lines[0]->account_id);
        self::assertSame('1000.00', $lines[0]->debit);
        self::assertSame('0.00', $lines[0]->credit);
        self::assertSame($fixture['accounts']['ar'], $lines[1]->account_id);
        self::assertSame('0.00', $lines[1]->debit);
        self::assertSame('1000.00', $lines[1]->credit);
    }

    public function test_operation_and_allocation_identities_are_durable(): void
    {
        $fixture = $this->settlementContext();
        $operation = (string) Str::ulid();
        app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            [
                'payment_allocation_id' => $fixture['allocationId'],
                'settlement_operation_id' => $operation,
            ],
        );

        try {
            app(SettlePaymentAllocation::class)->execute(
                $fixture['context']['tenant_id'],
                $fixture['context']['actor'],
                [
                    'payment_allocation_id' => (string) Str::ulid(),
                    'settlement_operation_id' => $operation,
                ],
            );
            self::fail('Settlement operation accepted different canonical facts.');
        } catch (SettlementConflict) {
            self::assertTrue(true);
        }
        self::assertSame(1, DB::table('receivable_settlements')->count());
    }

    public function test_reversal_is_exact_idempotent_and_releases_allocation_correction(): void
    {
        $fixture = $this->settlementContext();
        $posted = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            [
                'payment_allocation_id' => $fixture['allocationId'],
                'settlement_operation_id' => (string) Str::ulid(),
            ],
        );
        try {
            app(CancelPaymentAllocationAction::class)->execute(
                $fixture['context']['tenant_id'],
                $fixture['allocationId'],
                $fixture['context']['actor'],
                'Correction',
            );
            self::fail('Posted Settlement did not block Allocation cancellation.');
        } catch (PaymentsConflict) {
            self::assertTrue(true);
        }

        $facts = [
            'reversal_operation_id' => (string) Str::ulid(),
            'reversal_date' => '2026-08-23',
            'reversal_reason' => 'Correct allocation',
        ];
        $reversed = app(ReverseReceivableSettlement::class)->execute(
            $fixture['context']['tenant_id'],
            $posted['settlement_id'],
            $fixture['context']['actor'],
            $facts,
        );
        $replay = app(ReverseReceivableSettlement::class)->execute(
            $fixture['context']['tenant_id'],
            $posted['settlement_id'],
            $fixture['context']['actor'],
            $facts,
        );
        self::assertSame($reversed['reversal_journal_entry_id'], $replay['reversal_journal_entry_id']);
        self::assertTrue($replay['idempotent_replay']);
        app(CancelPaymentAllocationAction::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['allocationId'],
            $fixture['context']['actor'],
            'Correction',
        );
        self::assertSame('cancelled', DB::table('payment_allocations')->where('id', $fixture['allocationId'])->value('status'));
    }

    public function test_posted_settlement_blocks_receipt_and_cash_correction(): void
    {
        $fixture = $this->settlementContext();
        app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );
        try {
            app(CancelReceiptPaymentAssociation::class)->execute(
                $fixture['context']['tenant_id'],
                $fixture['associationId'],
                $fixture['context']['actor'],
                ['cancellation_operation_id' => (string) Str::ulid(), 'cancellation_reason' => 'Correction'],
            );
            self::fail('Posted Settlement did not block association cancellation.');
        } catch (ReceiptEvidenceConflict) {
            self::assertTrue(true);
        }
        $this->expectException(ReceiptEvidenceConflict::class);
        app(ReversePostedBankReceiptCashAndInvalidate::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['receiptId'],
            $fixture['cashPosting']['posting_id'],
            $fixture['context']['actor'],
            [
                'reversal_operation_id' => (string) Str::ulid(),
                'reversal_date' => '2026-08-23',
                'reversal_reason' => 'Correction',
                'invalidation_operation_id' => (string) Str::ulid(),
                'invalidation_reason' => 'Correction',
            ],
        );
    }

    public function test_archived_historical_accounts_fail_closed_and_restoration_retries_same_operation(): void
    {
        foreach (['ar', 'clearing'] as $key) {
            $fixture = $this->settlementContext();
            $operation = (string) Str::ulid();
            app(ManageAccountAction::class)->archive(
                $fixture['context']['tenant_id'],
                $fixture['accounts'][$key],
                $fixture['context']['actor'],
            );
            try {
                app(SettlePaymentAllocation::class)->execute(
                    $fixture['context']['tenant_id'],
                    $fixture['context']['actor'],
                    ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => $operation],
                );
                self::fail('Archived historical account accepted a new Settlement.');
            } catch (\Throwable) {
                self::assertSame(0, DB::table('receivable_settlements')->where('tenant_id', $fixture['context']['tenant_id'])->count());
            }
            app(ManageAccountAction::class)->restore(
                $fixture['context']['tenant_id'],
                $fixture['accounts'][$key],
                $fixture['context']['actor'],
            );
            $posted = app(SettlePaymentAllocation::class)->execute(
                $fixture['context']['tenant_id'],
                $fixture['context']['actor'],
                ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => $operation],
            );
            self::assertSame($fixture['accounts'][$key], DB::table('journal_lines')->where('journal_entry_id', $posted['journal_entry_id'])->where('account_id', $fixture['accounts'][$key])->value('account_id'));
        }
    }

    public function test_policy_changes_do_not_replace_historical_accounts(): void
    {
        $fixture = $this->settlementContext();
        $newAr = $this->settlementAccount($fixture['context'], 'SET-AR-2', 'asset', 'current_asset');
        $newClearing = $this->settlementAccount($fixture['context'], 'SET-CLEAR-2', 'liability', 'current_liability');
        app(ConfigureAccountingRecognitionPolicies::class)->receivableAr(
            $fixture['context']['tenant_id'], $fixture['context']['actor'], '2026-09-01', $newAr,
        );
        app(SupersedeBankReceiptCashClearingPolicy::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['cashPolicyId'],
            $fixture['context']['actor'],
            ['policy_operation_id' => (string) Str::ulid(), 'clearing_account_id' => $newClearing, 'supersession_reason' => 'Policy evolution'],
        );
        $result = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'],
            $fixture['context']['actor'],
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );
        $row = DB::table('receivable_settlements')->where('id', $result['settlement_id'])->firstOrFail();
        self::assertSame($fixture['accounts']['ar'], $row->ar_control_account_id);
        self::assertSame($fixture['accounts']['clearing'], $row->clearing_account_id);
        self::assertNotSame($newAr, $row->ar_control_account_id);
        self::assertNotSame($newClearing, $row->clearing_account_id);
    }

    public function test_archived_accounts_still_allow_exact_settlement_reversal(): void
    {
        $fixture = $this->settlementContext();
        $posted = app(SettlePaymentAllocation::class)->execute(
            $fixture['context']['tenant_id'], $fixture['context']['actor'],
            ['payment_allocation_id' => $fixture['allocationId'], 'settlement_operation_id' => (string) Str::ulid()],
        );
        app(ManageAccountAction::class)->archive($fixture['context']['tenant_id'], $fixture['accounts']['ar'], $fixture['context']['actor']);
        app(ManageAccountAction::class)->archive($fixture['context']['tenant_id'], $fixture['accounts']['clearing'], $fixture['context']['actor']);
        $reversed = app(ReverseReceivableSettlement::class)->execute(
            $fixture['context']['tenant_id'], $posted['settlement_id'], $fixture['context']['actor'],
            ['reversal_operation_id' => (string) Str::ulid(), 'reversal_date' => '2026-08-23', 'reversal_reason' => 'Correction'],
        );
        self::assertSame('posted', DB::table('journal_entries')->where('id', $reversed['reversal_journal_entry_id'])->value('status'));
        self::assertSame('reversed', DB::table('receivable_settlements')->where('id', $posted['settlement_id'])->value('status'));
    }
}
