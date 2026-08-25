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
use App\Modules\Accounting\DTOs\JournalLineData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountingApplicationConcurrencyTest extends TestCase
{
    public function test_membership_deactivation_cannot_race_transactional_authorization_and_financial_commit(): void
    {
        [$tenant, $actor] = $this->ready();
        [$asset, $equity] = $this->accounts($tenant, $actor, 'AUTH');
        $draft = app(ManageManualJournalAction::class)->create($tenant, $actor, '2026-03-01', 'Authorization race', $this->balancedLines($asset, $equity));

        $results = $this->workers([
            ['action' => 'deactivate_membership', 'tenant_id' => $tenant, 'actor_id' => $actor->id, 'hold_ms' => 600],
            ['action' => 'manual_post', 'tenant_id' => $tenant, 'actor_id' => $actor->id, 'journal_id' => $draft, 'pre_delay_ms' => 120],
        ]);

        self::assertTrue($results[0]['ok']);
        self::assertFalse($results[1]['ok']);
        self::assertSame('draft', DB::table('journal_entries')->where('id', $draft)->value('status'));
        self::assertFalse(DB::table('business_number_sequences')->where('tenant_id', $tenant)->where('prefix', 'JRN')->exists());
    }

    public function test_supported_settings_first_application_paths_complete_without_reverse_lock_deadlock(): void
    {
        [$tenant, $first] = $this->ready();
        $second = $this->member($tenant);
        $third = $this->member($tenant);
        [$asset, $equity] = $this->accounts($tenant, $first, 'LOCK');
        $unused = $this->account($tenant, $first, 'LOCK9', 'expense', 'operating_expense');
        $period = (string) DB::table('accounting_periods')->where('tenant_id', $tenant)->value('id');
        $operation = app(ManageOpeningBalanceAction::class)->create($tenant, $third, '2026-01-01', $this->balancedLines($asset, $equity));

        $results = $this->workers([
            ['action' => 'account_archive', 'tenant_id' => $tenant, 'actor_id' => $first->id, 'account_id' => $unused],
            ['action' => 'period_boundaries', 'tenant_id' => $tenant, 'actor_id' => $second->id, 'period_id' => $period, 'start_date' => '2026-01-01', 'end_date' => '2026-12-30'],
            ['action' => 'opening_update', 'tenant_id' => $tenant, 'actor_id' => $third->id, 'operation_id' => $operation, 'entry_date' => '2026-02-01', 'lines' => $this->linePayload($asset, $equity)],
        ]);

        self::assertSame([true, true, true], array_column($results, 'ok'), json_encode($results));
        self::assertSame('archived', DB::table('accounts')->where('id', $unused)->value('status'));
        self::assertSame('2026-12-30', (string) DB::table('accounting_periods')->where('id', $period)->value('end_date'));
        self::assertSame('2026-02-01', (string) DB::table('opening_balance_operations')->where('id', $operation)->value('accounting_date'));
    }

    public function test_opening_draft_edit_delete_and_post_race_leaves_one_valid_terminal_truth(): void
    {
        [$tenant, $first] = $this->ready();
        $second = $this->member($tenant);
        $third = $this->member($tenant);
        [$asset, $equity] = $this->accounts($tenant, $first, 'DRAFT');
        $operation = app(ManageOpeningBalanceAction::class)->create($tenant, $first, '2026-01-01', $this->balancedLines($asset, $equity));
        $journal = (string) DB::table('opening_balance_operations')->where('id', $operation)->value('journal_entry_id');

        $results = $this->workers([
            ['action' => 'opening_update', 'tenant_id' => $tenant, 'actor_id' => $first->id, 'operation_id' => $operation, 'entry_date' => '2026-02-01', 'lines' => $this->linePayload($asset, $equity)],
            ['action' => 'opening_delete', 'tenant_id' => $tenant, 'actor_id' => $second->id, 'operation_id' => $operation],
            ['action' => 'opening_post', 'tenant_id' => $tenant, 'actor_id' => $third->id, 'operation_id' => $operation],
        ]);

        self::assertGreaterThanOrEqual(1, count(array_filter($results, fn (array $result): bool => $result['ok'])), json_encode($results));
        $operationRow = DB::table('opening_balance_operations')->where('id', $operation)->first();
        if ($operationRow === null) {
            self::assertFalse(DB::table('journal_entries')->where('id', $journal)->exists());
        } else {
            self::assertSame('posted', $operationRow->status);
            self::assertSame('effective', $operationRow->effect_state);
            self::assertSame('posted', DB::table('journal_entries')->where('id', $journal)->value('status'));
        }
    }

    public function test_opening_aware_reversal_and_structural_command_follow_settings_first_order(): void
    {
        [$tenant, $first] = $this->ready();
        $second = $this->member($tenant);
        [$asset, $equity] = $this->accounts($tenant, $first, 'REV');
        $unused = $this->account($tenant, $first, 'REV9', 'expense', 'operating_expense');
        $operation = app(ManageOpeningBalanceAction::class)->create($tenant, $first, '2026-01-01', $this->balancedLines($asset, $equity));
        $root = app(ManageOpeningBalanceAction::class)->post($tenant, $operation, $first)->journalEntryId;

        $results = $this->workers([
            ['action' => 'opening_reverse', 'tenant_id' => $tenant, 'actor_id' => $first->id, 'journal_id' => $root, 'entry_date' => '2026-03-01'],
            ['action' => 'account_archive', 'tenant_id' => $tenant, 'actor_id' => $second->id, 'account_id' => $unused],
        ]);

        self::assertSame([true, true], array_column($results, 'ok'), json_encode($results));
        self::assertSame('neutralized', DB::table('opening_balance_operations')->where('id', $operation)->value('effect_state'));
        self::assertSame('archived', DB::table('accounts')->where('id', $unused)->value('status'));
    }

    public function test_same_business_source_and_facts_resolve_one_posted_journal_for_both_callers(): void
    {
        [$tenant, $first] = $this->ready();
        $second = $this->member($tenant);
        [$asset, $equity] = $this->accounts($tenant, $first, 'SAME');
        $this->sourceType('phase2_same_source');
        $source = (string) Str::ulid();
        $payload = $this->businessPayload($tenant, 'phase2_same_source', $source, $asset, $equity, 'Canonical posting');

        $results = $this->workers([
            $payload + ['actor_id' => $first->id],
            $payload + ['actor_id' => $second->id],
        ]);

        self::assertSame([true, true], array_column($results, 'ok'), json_encode($results));
        self::assertSame($results[0]['journal_id'], $results[1]['journal_id']);
        self::assertSame(1, DB::table('journal_entries')->where('tenant_id', $tenant)->where('source_id', $source)->where('status', 'posted')->count());
    }

    public function test_same_business_source_with_different_facts_gives_loser_a_conflict(): void
    {
        [$tenant, $first] = $this->ready();
        $second = $this->member($tenant);
        [$asset, $equity] = $this->accounts($tenant, $first, 'DIFF');
        $this->sourceType('phase2_different_source');
        $source = (string) Str::ulid();
        $firstPayload = $this->businessPayload($tenant, 'phase2_different_source', $source, $asset, $equity, 'First canonical posting');
        $secondPayload = $this->businessPayload($tenant, 'phase2_different_source', $source, $asset, $equity, 'Different posting');

        $results = $this->workers([
            $firstPayload + ['actor_id' => $first->id],
            $secondPayload + ['actor_id' => $second->id],
        ]);

        self::assertSame(1, count(array_filter($results, fn (array $result): bool => $result['ok'])));
        $loser = $results[0]['ok'] ? $results[1] : $results[0];
        self::assertSame('App\\Modules\\Accounting\\Exceptions\\AccountingConflict', $loser['class']);
        self::assertSame(1, DB::table('journal_entries')->where('tenant_id', $tenant)->where('source_id', $source)->where('status', 'posted')->count());
    }

    /** @return array{string,User} */
    private function ready(): array
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR']);
        $actor = $this->member((string) $tenant->id);
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');

        return [(string) $tenant->id, $actor];
    }

    private function member(string $tenantId): User
    {
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->active()->create(['tenant_id' => $tenantId, 'user_id' => $actor->id]);

        return $actor;
    }

    /** @return array{string,string} */
    private function accounts(string $tenantId, User $actor, string $prefix): array
    {
        return [
            $this->account($tenantId, $actor, $prefix.'1', 'asset', 'current_asset'),
            $this->account($tenantId, $actor, $prefix.'3', 'equity', 'equity'),
        ];
    }

    private function account(string $tenantId, User $actor, string $code, string $type, string $classification): string
    {
        return app(ManageAccountAction::class)->create($tenantId, $actor, [
            'code' => $code,
            'name' => 'Account '.$code,
            'description' => null,
            'kind' => 'posting',
            'account_type' => $type,
            'classification' => $classification,
            'parent_id' => null,
        ]);
    }

    /** @return array<int,JournalLineData> */
    private function balancedLines(string $asset, string $equity): array
    {
        return [new JournalLineData($asset, '25', '0'), new JournalLineData($equity, '0', '25')];
    }

    /** @return array<int,array<string,string>> */
    private function linePayload(string $asset, string $equity): array
    {
        return [
            ['account_id' => $asset, 'debit' => '25', 'credit' => '0'],
            ['account_id' => $equity, 'debit' => '0', 'credit' => '25'],
        ];
    }

    private function sourceType(string $key): void
    {
        DB::table('accounting_source_types')->insert(['origin' => 'business', 'key' => $key, 'owner_module' => 'tests', 'description' => 'Application concurrency fixture']);
    }

    /** @return array<string,mixed> */
    private function businessPayload(string $tenant, string $sourceType, string $sourceId, string $asset, string $equity, string $description): array
    {
        return [
            'action' => 'business_post',
            'tenant_id' => $tenant,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'entry_date' => '2026-03-01',
            'description' => $description,
            'lines' => $this->linePayload($asset, $equity),
        ];
    }

    /** @param array<int,array<string,mixed>> $payloads @return array<int,array<string,mixed>> */
    private function workers(array $payloads): array
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());
        $database = array_intersect_key($connection, array_flip(['host', 'port', 'database', 'username', 'password']));
        $running = [];

        foreach ($payloads as $payload) {
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/accounting_application_worker.php'), base64_encode(json_encode($payload + ['database' => $database], JSON_THROW_ON_ERROR))],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $running[] = [$process, $pipes];
        }

        $results = [];
        foreach ($running as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            $results[] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }
}
