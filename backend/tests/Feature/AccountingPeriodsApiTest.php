<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;

final class AccountingPeriodsApiTest extends AccountingApiTestCase
{
    public function test_period_api_supports_create_list_show_boundaries_close_and_admin_reopen(): void
    {
        [$tenant, $admin] = $this->accountingActor();
        $this->activate($tenant, $admin);
        $this->acting($admin);

        $id = $this->postJson('/api/accounting/periods', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'])
            ->assertCreated()->assertJsonPath('data.period.status', 'open')->json('data.period.id');
        $this->getJson('/api/accounting/periods?status=open')->assertOk()->assertJsonPath('data.periods.data.0.id', $id);
        $this->getJson("/api/accounting/periods/{$id}")->assertOk()->assertJsonPath('data.period.start_date', '2026-01-01');
        $this->patchJson("/api/accounting/periods/{$id}", ['start_date' => '2026-01-01', 'end_date' => '2026-12-30'])
            ->assertOk()->assertJsonPath('data.period.end_date', '2026-12-30');
        $this->postJson("/api/accounting/periods/{$id}/close")->assertOk()->assertJsonPath('data.period.status', 'closed');
        $this->postJson("/api/accounting/periods/{$id}/reopen", ['reason' => 'Correction window'])
            ->assertOk()->assertJsonPath('data.period.status', 'open')->assertJsonPath('data.period.reopen_reason', 'Correction window');
    }

    public function test_period_overlap_and_missing_reopen_reason_are_validation_failures(): void
    {
        [$tenant, $admin] = $this->accountingActor();
        $this->activate($tenant, $admin);
        $period = $this->period($tenant, $admin, '2026-01-01', '2026-06-30');
        $this->acting($admin);

        $response = $this->postJson('/api/accounting/periods', ['start_date' => '2026-06-30', 'end_date' => '2026-12-31'])->assertUnprocessable();
        self::assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->postJson("/api/accounting/periods/{$period}/close")->assertOk();
        $this->postJson("/api/accounting/periods/{$period}/reopen", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_accountant_can_close_but_cannot_reopen_and_cross_tenant_period_is_not_found(): void
    {
        [$tenant, $admin] = $this->accountingActor();
        $this->activate($tenant, $admin);
        $period = $this->period($tenant, $admin);
        $accountant = $this->member($tenant, User::ROLE_ACCOUNTANT);
        $this->acting($accountant);
        $this->postJson("/api/accounting/periods/{$period}/close")->assertOk();
        $this->postJson("/api/accounting/periods/{$period}/reopen", ['reason' => 'Not allowed'])->assertForbidden();

        [$otherTenant, $otherAdmin] = $this->accountingActor();
        $this->activate($otherTenant, $otherAdmin);
        $this->acting($otherAdmin);
        $this->getJson("/api/accounting/periods/{$period}")->assertNotFound();
        $this->postJson("/api/accounting/periods/{$period}/close")->assertNotFound();
    }

    private function member(object $tenant, string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => User::STATUS_ACTIVE]);
        \App\Models\TenantUser::factory()->active()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return $user;
    }
}
