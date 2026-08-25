<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ManageOpeningBalanceAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountingApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_chart_period_and_manual_posting_are_atomic_and_audited(): void
    {
        [$tenant,$actor] = $this->actor();
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        $asset = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->account('1000', 'asset', 'current_asset'));
        $equity = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->account('3000', 'equity', 'equity'));
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $draft = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-03-01', 'Capital', [
            new JournalLineData($asset, '100.00', '0'), new JournalLineData($equity, '0', '100.00'),
        ]);
        $posted = app(ManageManualJournalAction::class)->post((string) $tenant->id, $draft, $actor);
        self::assertSame('JRN-2026-001', $posted->journalNumber);
        self::assertSame('posted', DB::table('journal_entries')->where('id', $draft)->value('status'));
        self::assertSame(6, DB::table('accounting_audits')->where('tenant_id', $tenant->id)->count());
    }

    public function test_unbalanced_manual_post_rolls_back_number_and_audit(): void
    {
        [$tenant,$actor] = $this->ready();
        $asset = $this->createAccount($tenant, $actor, '1100', 'asset', 'current_asset');
        $equity = $this->createAccount($tenant, $actor, '3100', 'equity', 'equity');
        $draft = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, '2026-03-01', 'Bad', [new JournalLineData($asset, '10', '0'), new JournalLineData($equity, '0', '9')]);
        try {
            app(ManageManualJournalAction::class)->post((string) $tenant->id, $draft, $actor);
            self::fail('Unbalanced journal posted.');
        } catch (AccountingValidationFailed) {
            self::assertSame('draft', DB::table('journal_entries')->where('id', $draft)->value('status'));
        }
        self::assertFalse(DB::table('business_number_sequences')->where('tenant_id', $tenant->id)->where('prefix', 'JRN')->exists());
    }

    public function test_business_posting_rejects_non_sar_input(): void
    {
        [$tenant,$actor] = $this->ready();
        $asset = $this->createAccount($tenant, $actor, '1200', 'asset', 'current_asset');
        $equity = $this->createAccount($tenant, $actor, '3200', 'equity', 'equity');
        DB::table('accounting_source_types')->insert(['origin' => 'business', 'key' => 'phase2_fixture', 'owner_module' => 'tests', 'description' => 'Phase 2 test fixture']);
        $this->expectException(AccountingValidationFailed::class);
        new BusinessPostingRequest((string) $tenant->id, (int) $actor->id, 'phase2_fixture', (string) Str::ulid(), 'USD', '2026-03-01', 'Fixture', [new JournalLineData($asset, '25', '0'), new JournalLineData($equity, '0', '25')]);
    }

    public function test_business_posting_exact_replay_is_idempotent_and_outer_rollback_is_atomic(): void
    {
        [$tenant,$actor] = $this->ready();
        $asset = $this->createAccount($tenant, $actor, '1250', 'asset', 'current_asset');
        $equity = $this->createAccount($tenant, $actor, '3250', 'equity', 'equity');
        DB::table('accounting_source_types')->insert(['origin' => 'business', 'key' => 'phase2_fixture', 'owner_module' => 'tests', 'description' => 'Phase 2 test fixture']);
        $source = (string) Str::ulid();
        $request = new BusinessPostingRequest((string) $tenant->id, (int) $actor->id, 'phase2_fixture', $source, 'SAR', '2026-03-01', 'Fixture', [new JournalLineData($asset, '25', '0'), new JournalLineData($equity, '0', '25')]);
        $service = app(BusinessPostingServiceInterface::class);
        $first = DB::transaction(fn () => $service->post($request));
        $second = DB::transaction(fn () => $service->post($request));
        self::assertSame($first->journalEntryId, $second->journalEntryId);
        self::assertTrue($second->idempotentReplay);
        self::assertSame(1, DB::table('journal_entries')->where('source_id', $source)->count());
        $rolledBack = (string) Str::ulid();
        try {
            DB::transaction(function () use ($service, $request, $rolledBack): void {
                $service->post(new BusinessPostingRequest($request->tenantId, $request->actorId, $request->sourceType, $rolledBack, 'SAR', $request->entryDate, $request->description, $request->lines));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        self::assertFalse(DB::table('journal_entries')->where('source_id', $rolledBack)->exists());
    }

    public function test_exact_reversal_preserves_lines_and_opening_projection(): void
    {
        [$tenant,$actor] = $this->ready();
        $asset = $this->createAccount($tenant, $actor, '1300', 'asset', 'current_asset');
        $equity = $this->createAccount($tenant, $actor, '3300', 'equity', 'equity');
        $operation = app(ManageOpeningBalanceAction::class)->create((string) $tenant->id, $actor, '2026-01-01', [new JournalLineData($asset, '50', '0', 'opening'), new JournalLineData($equity, '0', '50', 'opening')]);
        $root = app(ManageOpeningBalanceAction::class)->post((string) $tenant->id, $operation, $actor);
        $reversal = app(ReverseJournalAction::class)->execute((string) $tenant->id, $root->journalEntryId, $actor, '2026-02-01', 'Correction');
        $projection = DB::table('opening_balance_operations')->where('id', $operation)->first();
        self::assertSame('neutralized', $projection->effect_state);
        self::assertSame($reversal->journalEntryId, $projection->latest_effect_journal_entry_id);
        $lines = DB::table('journal_lines')->where('journal_entry_id', $reversal->journalEntryId)->orderBy('line_number')->get();
        self::assertSame('0.00', (string) $lines[0]->debit);
        self::assertSame('50.00', (string) $lines[0]->credit);
        self::assertSame('opening', $lines[0]->memo);
    }

    private function actor(): array
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->forTenant($tenant)->forUser($actor)->active()->create();

        return [$tenant, $actor];
    }

    private function ready(): array
    {
        [$tenant,$actor] = $this->actor();
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');

        return [$tenant, $actor];
    }

    private function account(string $code, string $type, string $class): array
    {
        return ['code' => $code, 'name' => 'Account '.$code, 'description' => null, 'kind' => 'posting', 'account_type' => $type, 'classification' => $class, 'parent_id' => null];
    }

    private function createAccount(Tenant $tenant, User $actor, string $code, string $type, string $class): string
    {
        return app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->account($code, $type, $class));
    }
}
