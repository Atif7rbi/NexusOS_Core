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
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\DTOs\PostedJournalResult;
use Laravel\Sanctum\Sanctum;

abstract class AccountingApiTestCase extends ApiTestCase
{
    /** @return array{Tenant,User} */
    protected function accountingActor(string $role = User::ROLE_ADMINISTRATOR, array $tenantAttributes = [], string $membershipStatus = TenantUser::STATUS_ACTIVE): array
    {
        $tenant = Tenant::factory()->create($tenantAttributes + ['currency' => 'SAR']);
        $actor = User::factory()->create(['role' => $role, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'status' => $membershipStatus]);

        return [$tenant, $actor];
    }

    protected function acting(User $actor): void
    {
        Sanctum::actingAs($actor);
    }

    protected function activate(Tenant $tenant, User $actor): string
    {
        return app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
    }

    protected function period(Tenant $tenant, User $actor, string $start = '2026-01-01', string $end = '2026-12-31'): string
    {
        return app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, $start, $end);
    }

    protected function account(Tenant $tenant, User $actor, string $code, string $type, string $classification, ?string $parent = null): string
    {
        return app(ManageAccountAction::class)->create((string) $tenant->id, $actor, [
            'code' => $code,
            'name' => 'Account '.$code,
            'description' => null,
            'kind' => 'posting',
            'account_type' => $type,
            'classification' => $classification,
            'parent_id' => $parent,
        ]);
    }

    /** @return array{string,string} */
    protected function accounts(Tenant $tenant, User $actor, string $prefix): array
    {
        return [
            $this->account($tenant, $actor, $prefix.'1', 'asset', 'current_asset'),
            $this->account($tenant, $actor, $prefix.'3', 'equity', 'equity'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    protected function linePayload(string $debit, string $credit, string $amount = '100.00'): array
    {
        return [
            ['account_id' => $debit, 'debit' => $amount, 'credit' => '0', 'memo' => 'Debit'],
            ['account_id' => $credit, 'debit' => '0', 'credit' => $amount, 'memo' => 'Credit'],
        ];
    }

    /** @return list<JournalLineData> */
    protected function lineData(string $debit, string $credit, string $amount = '100.00'): array
    {
        return [new JournalLineData($debit, $amount, '0', 'Debit'), new JournalLineData($credit, '0', $amount, 'Credit')];
    }

    protected function ready(string $prefix = 'R'): array
    {
        [$tenant, $actor] = $this->accountingActor();
        $this->activate($tenant, $actor);
        $period = $this->period($tenant, $actor);
        [$debit, $credit] = $this->accounts($tenant, $actor, $prefix);

        return [$tenant, $actor, $period, $debit, $credit];
    }

    protected function posted(Tenant $tenant, User $actor, string $debit, string $credit, string $date = '2026-03-01'): PostedJournalResult
    {
        $id = app(ManageManualJournalAction::class)->create((string) $tenant->id, $actor, $date, 'Posted fixture', $this->lineData($debit, $credit));

        return app(ManageManualJournalAction::class)->post((string) $tenant->id, $id, $actor);
    }
}
