<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Customers\Models\Customer;
use App\Modules\ReceivableAccounting\Exceptions\ReceivableAccountingIntegrityFault;
use App\Modules\ReceivableAccounting\Services\ReceivableAccountingIntegration;
use App\Modules\ReceivableAccounting\Services\ReceivableAccountingRecoveryResolver;
use App\Modules\ReceivableAccounting\Services\ReceivableCancellationOrchestrator;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ReceivableAccountingIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_test_harness_commits_receivable_and_source_identified_journal_atomically(): void
    {
        [$tenant, $actor, $customer, $lines] = $this->ready();
        $input = $this->recognition((string) $customer->id);

        $result = DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
            (string) $tenant->id, $actor, $input, '2026-09-01', 'Test-only recognition policy', $lines,
        ));

        self::assertSame($result['receivable_id'], DB::table('journal_entries')->where('id', $result['journal_entry_id'])->value('source_id'));
        self::assertSame('receivable_recognition', DB::table('journal_entries')->where('id', $result['journal_entry_id'])->value('source_type'));
        self::assertSame('posted', DB::table('journal_entries')->where('id', $result['journal_entry_id'])->value('status'));

        $replay = DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
            (string) $tenant->id, $actor, $input, '2026-09-01', 'Test-only recognition policy', $lines,
        ));
        self::assertSame($result['receivable_id'], $replay['receivable_id']);
        self::assertSame($result['journal_entry_id'], $replay['journal_entry_id']);
        self::assertTrue($replay['replayed']);
    }

    public function test_non_sar_and_posting_failure_roll_back_the_entire_outer_transaction(): void
    {
        [$tenant, $actor, $customer, $lines] = $this->ready();
        $usd = $this->recognition((string) $customer->id, ['currency' => 'USD']);
        try {
            DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
                (string) $tenant->id, $actor, $usd, '2026-09-01', 'Rejected USD', $lines,
            ));
            self::fail('Non-SAR integrated recognition was accepted.');
        } catch (ReceivablesValidationFailed) {
            self::assertDatabaseMissing('receivables', ['recognition_operation_id' => $usd['recognition_operation_id']]);
        }
        $this->expectAccountingValidation(fn () => new BusinessPostingRequest(
            (string) $tenant->id, $actor->id, 'receivable_recognition', (string) Str::ulid(), 'USD',
            '2026-09-01', 'Accounting defensive boundary', $lines,
        ));

        $invalid = $this->recognition((string) $customer->id);
        try {
            DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
                (string) $tenant->id, $actor, $invalid, '2027-09-01', 'Closed period', $lines,
            ));
            self::fail('Posting outside an open period was accepted.');
        } catch (AccountingValidationFailed) {
            self::assertDatabaseMissing('receivables', ['recognition_operation_id' => $invalid['recognition_operation_id']]);
            self::assertDatabaseMissing('journal_entries', ['source_type' => 'receivable_recognition']);
        }
    }

    public function test_unknown_commit_recovery_is_retryable_committed_or_fail_closed(): void
    {
        [$tenant, $actor, $customer, $lines] = $this->ready();
        $resolver = app(ReceivableAccountingRecoveryResolver::class);
        $absent = $this->recognition((string) $customer->id);
        self::assertSame('retryable', $resolver->resolve((string) $tenant->id, $absent['recognition_operation_id'], $actor->id, '2026-09-01', 'Recovery', $lines)['status']);

        $committed = $this->recognition((string) $customer->id);
        DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
            (string) $tenant->id, $actor, $committed, '2026-09-01', 'Recovery', $lines,
        ));
        self::assertSame('committed', $resolver->resolve((string) $tenant->id, $committed['recognition_operation_id'], $actor->id, '2026-09-01', 'Recovery', $lines)['status']);
        try {
            $resolver->resolve((string) $tenant->id, $committed['recognition_operation_id'], $actor->id, '2026-09-01', 'Different facts', $lines);
            self::fail('Mismatching canonical Accounting facts were accepted.');
        } catch (AccountingConflict) {
            self::assertTrue(true);
        }

        $partial = $this->recognition((string) $customer->id);
        app(RecognizeReceivableAction::class)->execute((string) $tenant->id, $actor, $partial);
        $this->expectException(ReceivableAccountingIntegrityFault::class);
        $resolver->resolve((string) $tenant->id, $partial['recognition_operation_id'], $actor->id, '2026-09-01', 'Recovery', $lines);
    }

    public function test_orphan_accounting_source_fails_closed(): void
    {
        [$tenant, $actor, $customer, $lines] = $this->ready();
        DB::transaction(fn () => app(BusinessPostingServiceInterface::class)->post(new BusinessPostingRequest(
            (string) $tenant->id, $actor->id, 'receivable_recognition', (string) Str::ulid(), 'SAR',
            '2026-09-01', 'Orphan fixture', $lines,
        )));

        $this->expectException(ReceivableAccountingIntegrityFault::class);
        app(ReceivableAccountingRecoveryResolver::class)->resolve(
            (string) $tenant->id, (string) Str::ulid(), $actor->id, '2026-09-01', 'Recovery', $lines,
        );
    }

    public function test_accounting_aware_cancellation_reverses_atomically_and_plain_receivable_still_cancels(): void
    {
        [$tenant, $actor, $customer, $lines] = $this->ready();
        $input = $this->recognition((string) $customer->id);
        $posted = DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
            (string) $tenant->id, $actor, $input, '2026-09-01', 'Cancellation', $lines,
        ));
        Sanctum::actingAs($actor);
        $this->postJson('/api/receivables/'.$posted['receivable_id'].'/cancel', [
            'cancelled_at' => '2026-09-02T10:00:00+00:00', 'cancellation_reason' => 'Missing reversal date',
        ])->assertUnprocessable();
        self::assertSame('recognized', DB::table('receivables')->where('id', $posted['receivable_id'])->value('status'));
        app(ReceivableCancellationOrchestrator::class)->execute((string) $tenant->id, $posted['receivable_id'], $actor, '2026-09-02T10:00:00+00:00', 'Cancel effect', '2026-09-02');
        self::assertSame('cancelled', DB::table('receivables')->where('id', $posted['receivable_id'])->value('status'));
        self::assertTrue(DB::table('journal_entries')->where('reverses_journal_entry_id', $posted['journal_entry_id'])->exists());

        $plain = app(RecognizeReceivableAction::class)->execute((string) $tenant->id, $actor, $this->recognition((string) $customer->id));
        app(ReceivableCancellationOrchestrator::class)->execute((string) $tenant->id, $plain, $actor, '2026-09-02T10:00:00+00:00', 'Plain cancellation', null);
        self::assertSame('cancelled', DB::table('receivables')->where('id', $plain)->value('status'));
    }

    public function test_reversal_failure_leaves_receivable_recognized(): void
    {
        [$tenant, $actor, $customer, $lines] = $this->ready();
        $posted = DB::transaction(fn (): array => app(ReceivableAccountingIntegration::class)->recognizeAndPost(
            (string) $tenant->id, $actor, $this->recognition((string) $customer->id), '2026-09-01', 'Rollback', $lines,
        ));
        try {
            app(ReceivableCancellationOrchestrator::class)->execute((string) $tenant->id, $posted['receivable_id'], $actor, '2026-09-02T10:00:00+00:00', 'Invalid reversal', '2026-08-31');
            self::fail('Backdated reversal was accepted.');
        } catch (AccountingValidationFailed) {
            self::assertSame('recognized', DB::table('receivables')->where('id', $posted['receivable_id'])->value('status'));
            self::assertFalse(DB::table('journal_entries')->where('reverses_journal_entry_id', $posted['journal_entry_id'])->exists());
        }
    }

    public function test_receivables_primitives_have_no_accounting_dependency_and_public_controller_uses_orchestration(): void
    {
        foreach (['Actions/RecognizeReceivableAction.php', 'Actions/CancelReceivableAction.php'] as $file) {
            self::assertStringNotContainsString('Modules\\Accounting', (string) file_get_contents(app_path('Modules/Receivables/'.$file)));
        }
        $controller = (string) file_get_contents(app_path('Modules/Receivables/Controllers/ReceivablesController.php'));
        self::assertStringContainsString('ReceivableCancellationOrchestrator', $controller);
        self::assertStringNotContainsString('CancelReceivableAction', $controller);
    }

    private function ready(): array
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR', 'status' => Tenant::STATUS_ACTIVE]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->forTenant($tenant)->forUser($actor)->active()->create();
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id, 'type' => 'individual', 'category' => 'buyer', 'status' => 'customer',
            'name' => 'Integrated debtor', 'phone' => '054'.random_int(1000000, 9999999), 'created_by' => $actor->id,
        ]);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $asset = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->account('1150', 'asset', 'current_asset'));
        $revenue = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->account('4150', 'revenue', 'operating_revenue'));

        return [$tenant, $actor, $customer, [new JournalLineData($asset, '100.00', '0'), new JournalLineData($revenue, '0', '100.00')]];
    }

    private function recognition(string $customerId, array $overrides = []): array
    {
        return array_merge([
            'recognition_operation_id' => (string) Str::ulid(), 'customer_id' => $customerId,
            'contract_id' => null, 'collection_id' => null, 'currency' => 'SAR', 'recognized_amount' => '100.00',
            'due_date' => '2026-09-30', 'recognized_at' => '2026-08-26T10:00:00+00:00',
        ], $overrides);
    }

    private function account(string $code, string $type, string $classification): array
    {
        return ['code' => $code, 'name' => 'Account '.$code, 'description' => null, 'kind' => 'posting', 'account_type' => $type, 'classification' => $classification, 'parent_id' => null];
    }

    private function expectAccountingValidation(callable $callback): void
    {
        try {
            $callback();
            self::fail('Accounting accepted non-SAR business posting.');
        } catch (AccountingValidationFailed) {
            self::assertTrue(true);
        }
    }
}
