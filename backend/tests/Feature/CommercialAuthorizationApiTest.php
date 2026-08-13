<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Collections\Actions\FinalizeCollectionScheduleAction;
use App\Modules\Collections\Actions\SaveDraftCollectionScheduleAction;
use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Collections\Models\Collection;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Customers\Models\Customer;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Models\Unit;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

final class CommercialAuthorizationApiTest extends ApiTestCase
{
    private int $unitSequence = 0;

    private int $customerSequence = 0;

    public function test_all_roles_can_view_all_commercial_resources(): void
    {
        $context = $this->context();
        $reservation = $this->reservation($context, $context['project'], $context['sales']->id);
        $contract = $this->contract($context, $context['project'], $context['sales']->id, 'draft');

        foreach ($this->sixRoles($context) as $role => $user) {
            Sanctum::actingAs($user);

            $this->getJson('/api/reservations')->assertOk();
            $this->getJson("/api/reservations/{$reservation->id}")->assertOk();
            $this->getJson('/api/contracts')->assertOk();
            $this->getJson("/api/contracts/{$contract->id}")->assertOk();
            $this->getJson('/api/customers')->assertOk();
            $this->getJson("/api/customers/{$context['customer']->id}")->assertOk();
            $this->getJson('/api/collections')->assertOk();
            $this->getJson("/api/contracts/{$contract->id}/collection-schedule")->assertOk();
        }
    }

    public function test_reservation_create_matrix_is_enforced(): void
    {
        $context = $this->context();
        $cases = [
            'system owner' => [$context['owner'], $context['project'], 201],
            'administrator' => [$context['administrator'], $context['project'], 201],
            'project manager own project' => [$context['project_manager'], $context['project'], 201],
            'project manager other project' => [$context['project_manager'], $context['other_project'], 403],
            'sales' => [$context['sales'], $context['project'], 201],
            'accountant' => [$context['accountant'], $context['project'], 403],
            'employee' => [$context['employee'], $context['project'], 403],
        ];

        foreach ($cases as [$actor, $project, $expectedStatus]) {
            Sanctum::actingAs($actor);
            $unit = $this->unit($context, $project, 'available');
            $customer = $this->customer($context, $context['sales']->id);

            $this->postJson('/api/reservations', [
                'unit_id' => $unit->id,
                'customer_id' => $customer->id,
            ])->assertStatus($expectedStatus);
        }
    }

    public function test_reservation_edit_and_cancel_matrix_is_enforced(): void
    {
        $context = $this->context();
        $cases = [
            'system owner' => [$context['owner'], $context['project'], $context['other_sales']->id, 200],
            'administrator' => [$context['administrator'], $context['project'], $context['other_sales']->id, 200],
            'project manager own project' => [$context['project_manager'], $context['project'], $context['other_sales']->id, 200],
            'project manager other project' => [$context['project_manager'], $context['other_project'], $context['other_sales']->id, 403],
            'sales own reservation' => [$context['sales'], $context['project'], $context['sales']->id, 200],
            'sales other reservation' => [$context['sales'], $context['project'], $context['other_sales']->id, 403],
            'accountant' => [$context['accountant'], $context['project'], $context['other_sales']->id, 403],
            'employee' => [$context['employee'], $context['project'], $context['other_sales']->id, 403],
        ];

        foreach ($cases as [$actor, $project, $creatorId, $expectedStatus]) {
            $editable = $this->reservation($context, $project, $creatorId);
            Sanctum::actingAs($actor);
            $this->patchJson("/api/reservations/{$editable->id}", ['notes' => 'authorization matrix'])
                ->assertStatus($expectedStatus);

            $cancellable = $this->reservation($context, $project, $creatorId);
            Sanctum::actingAs($actor);
            $this->patchJson("/api/reservations/{$cancellable->id}/cancel", [
                'cancellation_reason' => 'authorization matrix',
            ])->assertStatus($expectedStatus);
        }
    }

    public function test_contract_draft_operational_matrix_is_enforced_for_every_command(): void
    {
        $context = $this->context();
        $cases = [
            'system owner' => [$context['owner'], $context['project'], $context['other_sales']->id, true],
            'administrator' => [$context['administrator'], $context['project'], $context['other_sales']->id, true],
            'project manager own project' => [$context['project_manager'], $context['project'], $context['other_sales']->id, true],
            'project manager other project' => [$context['project_manager'], $context['other_project'], $context['other_sales']->id, false],
            'sales own journey' => [$context['sales'], $context['project'], $context['sales']->id, true],
            'sales other journey' => [$context['sales'], $context['project'], $context['other_sales']->id, false],
            'accountant' => [$context['accountant'], $context['project'], $context['other_sales']->id, false],
            'employee' => [$context['employee'], $context['project'], $context['other_sales']->id, false],
        ];

        foreach ($cases as [$actor, $project, $creatorId, $allowed]) {
            Sanctum::actingAs($actor);
            $reservation = $this->reservation($context, $project, $creatorId);
            $this->postJson('/api/contracts', [
                'reservation_id' => $reservation->id,
                'total_amount' => '1000.00',
            ])->assertStatus($allowed ? 201 : 403);

            $draft = $this->contract($context, $project, $creatorId, 'draft');
            Sanctum::actingAs($actor);
            $this->patchJson("/api/contracts/{$draft->id}", ['total_amount' => '1100.00'])
                ->assertStatus($allowed ? 200 : 403);

            $draft = $this->contract($context, $project, $creatorId, 'draft');
            Sanctum::actingAs($actor);
            $this->patchJson("/api/contracts/{$draft->id}/activate")
                ->assertStatus($allowed ? 200 : 403);

            $draft = $this->contract($context, $project, $creatorId, 'draft');
            Sanctum::actingAs($actor);
            $this->patchJson("/api/contracts/{$draft->id}/cancel")
                ->assertStatus($allowed ? 200 : 403);
        }
    }

    public function test_contract_terminal_matrix_is_owner_and_administrator_only(): void
    {
        $context = $this->context();
        $cases = [
            'system owner' => [$context['owner'], true],
            'administrator' => [$context['administrator'], true],
            'project manager' => [$context['project_manager'], false],
            'sales' => [$context['sales'], false],
            'accountant' => [$context['accountant'], false],
            'employee' => [$context['employee'], false],
        ];

        foreach ($cases as [$actor, $allowed]) {
            $active = $this->contract($context, $context['project'], $actor->id, 'active');
            Sanctum::actingAs($actor);
            $this->patchJson("/api/contracts/{$active->id}/complete")
                ->assertStatus($allowed ? 200 : 403);

            $active = $this->contract($context, $context['project'], $actor->id, 'active');
            Sanctum::actingAs($actor);
            $this->patchJson("/api/contracts/{$active->id}/cancel")
                ->assertStatus($allowed ? 200 : 403);
        }
    }

    public function test_customer_create_matrix_is_enforced(): void
    {
        $context = $this->context();
        $cases = [
            'system owner' => [$context['owner'], 201],
            'administrator' => [$context['administrator'], 201],
            'sales' => [$context['sales'], 201],
            'project manager' => [$context['project_manager'], 201],
            'accountant' => [$context['accountant'], 403],
            'employee' => [$context['employee'], 403],
        ];

        foreach ($cases as [$actor, $expectedStatus]) {
            Sanctum::actingAs($actor);
            $this->postJson('/api/customers', $this->customerPayload())
                ->assertStatus($expectedStatus);
        }
    }

    public function test_customer_edit_archive_and_restore_matrices_are_enforced(): void
    {
        $context = $this->context();
        $mutationCases = [
            'system owner' => [$context['owner'], $context['other_sales']->id, 200],
            'administrator' => [$context['administrator'], $context['other_sales']->id, 200],
            'sales own customer' => [$context['sales'], $context['sales']->id, 200],
            'sales other customer' => [$context['sales'], $context['other_sales']->id, 403],
            'project manager' => [$context['project_manager'], $context['other_sales']->id, 403],
            'accountant' => [$context['accountant'], $context['other_sales']->id, 403],
            'employee' => [$context['employee'], $context['other_sales']->id, 403],
        ];

        foreach ($mutationCases as [$actor, $creatorId, $expectedStatus]) {
            $customer = $this->customer($context, $creatorId);
            Sanctum::actingAs($actor);
            $this->patchJson("/api/customers/{$customer->id}", ['notes' => 'authorization matrix'])
                ->assertStatus($expectedStatus);

            $customer = $this->customer($context, $creatorId);
            Sanctum::actingAs($actor);
            $this->patchJson("/api/customers/{$customer->id}/archive")
                ->assertStatus($expectedStatus);
        }

        $restoreCases = [
            'system owner' => [$context['owner'], 200],
            'administrator' => [$context['administrator'], 200],
            'sales own customer' => [$context['sales'], 403],
            'project manager' => [$context['project_manager'], 403],
            'accountant' => [$context['accountant'], 403],
            'employee' => [$context['employee'], 403],
        ];

        foreach ($restoreCases as [$actor, $expectedStatus]) {
            $customer = $this->archivedCustomer($context, $actor->id);
            Sanctum::actingAs($actor);
            $this->patchJson("/api/customers/{$customer->id}/restore")
                ->assertStatus($expectedStatus);
        }
    }

    public function test_collection_draft_matrix_uses_explicit_reservation_ownership(): void
    {
        $context = $this->context();
        $cases = [
            'system owner' => [$context['owner'], $context['other_sales']->id, 200],
            'administrator' => [$context['administrator'], $context['other_sales']->id, 200],
            'accountant' => [$context['accountant'], $context['other_sales']->id, 200],
            'sales own journey' => [$context['sales'], $context['sales']->id, 200],
            'sales other journey' => [$context['sales'], $context['other_sales']->id, 403],
            'project manager' => [$context['project_manager'], $context['other_sales']->id, 403],
            'employee' => [$context['employee'], $context['other_sales']->id, 403],
        ];

        foreach ($cases as [$actor, $reservationCreatorId, $expectedStatus]) {
            $contract = $this->contract($context, $context['project'], $reservationCreatorId, 'draft');
            Sanctum::actingAs($actor);
            $this->postJson("/api/contracts/{$contract->id}/collection-schedule/draft", $this->collectionPayload())
                ->assertStatus($expectedStatus);
        }
    }

    public function test_collection_finalize_and_amend_matrices_are_enforced(): void
    {
        $context = $this->context();
        $cases = [
            'system owner' => [$context['owner'], 200],
            'administrator' => [$context['administrator'], 200],
            'accountant' => [$context['accountant'], 200],
            'sales' => [$context['sales'], 403],
            'project manager' => [$context['project_manager'], 403],
            'employee' => [$context['employee'], 403],
        ];

        foreach ($cases as [$actor, $expectedStatus]) {
            $contract = $this->contract($context, $context['project'], $context['sales']->id, 'draft');
            $this->saveDraftDirectly($context, $contract);
            Sanctum::actingAs($actor);
            $this->postJson("/api/contracts/{$contract->id}/collection-schedule/finalize")
                ->assertStatus($expectedStatus);

            $contract = $this->contract($context, $context['project'], $context['sales']->id, 'draft');
            $this->saveDraftDirectly($context, $contract);
            app(FinalizeCollectionScheduleAction::class)->execute(
                (string) $context['tenant']->id,
                (string) $contract->id,
                $context['administrator']->id,
            );
            $activeIds = Collection::query()
                ->where('tenant_id', $context['tenant']->id)
                ->where('contract_id', $contract->id)
                ->where('status', 'scheduled')
                ->pluck('id')
                ->all();

            Sanctum::actingAs($actor);
            $this->postJson("/api/contracts/{$contract->id}/collection-schedule/amend", [
                ...$this->collectionPayload(),
                'expected_active_collection_ids' => $activeIds,
                'cancellation_reason' => 'authorization matrix',
            ])->assertStatus($expectedStatus);
        }
    }

    public function test_cross_tenant_commercial_records_are_hidden_before_authorization(): void
    {
        $tenantA = $this->context();
        $tenantB = $this->context();
        $reservation = $this->reservation($tenantB, $tenantB['project'], $tenantB['sales']->id);
        $contract = $this->contract($tenantB, $tenantB['project'], $tenantB['sales']->id, 'draft');

        Sanctum::actingAs($tenantA['administrator']);
        $this->getJson("/api/reservations/{$reservation->id}")->assertNotFound();
        $this->patchJson("/api/reservations/{$reservation->id}", ['notes' => 'hidden'])->assertNotFound();
        $this->getJson("/api/contracts/{$contract->id}")->assertNotFound();
        $this->patchJson("/api/contracts/{$contract->id}", ['total_amount' => '1001.00'])->assertNotFound();
        $this->getJson("/api/customers/{$tenantB['customer']->id}")->assertNotFound();
        $this->patchJson("/api/customers/{$tenantB['customer']->id}", ['notes' => 'hidden'])->assertNotFound();
        $this->getJson("/api/contracts/{$contract->id}/collection-schedule")->assertNotFound();
        $this->postJson("/api/contracts/{$contract->id}/collection-schedule/draft", $this->collectionPayload())
            ->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $users = [];

        foreach ([
            'owner' => User::ROLE_SYSTEM_OWNER,
            'administrator' => User::ROLE_ADMINISTRATOR,
            'project_manager' => User::ROLE_PROJECT_MANAGER,
            'other_project_manager' => User::ROLE_PROJECT_MANAGER,
            'sales' => User::ROLE_SALES,
            'other_sales' => User::ROLE_SALES,
            'accountant' => User::ROLE_ACCOUNTANT,
            'employee' => User::ROLE_EMPLOYEE,
        ] as $key => $role) {
            $users[$key] = User::factory()->create([
                'role' => $role,
                'status' => User::STATUS_ACTIVE,
            ]);
            TenantUser::factory()->forTenant($tenant)->forUser($users[$key])->active()->create();
        }

        return [
            ...$users,
            'tenant' => $tenant,
            'project' => $this->project($tenant, $users['project_manager'], 'AUTH-A'),
            'other_project' => $this->project($tenant, $users['other_project_manager'], 'AUTH-B'),
            'customer' => $this->customerForTenant($tenant, $users['sales']->id),
        ];
    }

    /** @return array<string, User> */
    private function sixRoles(array $context): array
    {
        return [
            'system_owner' => $context['owner'],
            'administrator' => $context['administrator'],
            'project_manager' => $context['project_manager'],
            'sales' => $context['sales'],
            'accountant' => $context['accountant'],
            'employee' => $context['employee'],
        ];
    }

    private function project(Tenant $tenant, User $manager, string $prefix): Project
    {
        $year = 2026;
        $sequence = (int) Project::query()
            ->where('project_number_year', $year)
            ->max('project_sequence_number') + 1;

        return Project::query()->create([
            'tenant_id' => $tenant->id,
            'project_number' => "{$prefix}-{$sequence}",
            'project_number_year' => $year,
            'project_sequence_number' => $sequence,
            'name' => "{$prefix}-{$sequence}",
            'project_type' => 'residential',
            'status' => ProjectStatus::Active->value,
            'country_code' => 'SA',
            'city' => 'الرياض',
            'currency' => 'SAR',
            'data_origin' => 'user',
            'project_manager_id' => $manager->id,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
    }

    private function unit(array $context, Project $project, string $status): Unit
    {
        $this->unitSequence++;

        return Unit::query()->create([
            'tenant_id' => $context['tenant']->id,
            'project_id' => $project->id,
            'unit_number' => "AUTH-UNIT-{$this->unitSequence}",
            'unit_type' => 'apartment',
            'status' => $status,
            'selling_price' => '1000.00',
            'created_by' => $context['administrator']->id,
            'updated_by' => $context['administrator']->id,
        ]);
    }

    private function customer(array $context, int $creatorId): Customer
    {
        return $this->customerForTenant($context['tenant'], $creatorId);
    }

    private function customerForTenant(Tenant $tenant, int $creatorId): Customer
    {
        $payload = $this->customerPayload();

        return Customer::query()->create([
            'tenant_id' => $tenant->id,
            'type' => $payload['type'],
            'category' => $payload['category'],
            'status' => 'customer',
            'name' => $payload['name'],
            'phone' => $payload['phone'],
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
        ]);
    }

    /** @return array<string, string> */
    private function customerPayload(): array
    {
        $this->customerSequence++;

        return [
            'type' => 'individual',
            'category' => 'buyer',
            'name' => "عميل صلاحيات {$this->customerSequence}",
            'phone' => '059'.str_pad((string) $this->customerSequence, 7, '0', STR_PAD_LEFT),
        ];
    }

    private function archivedCustomer(array $context, int $creatorId): Customer
    {
        $customer = $this->customer($context, $creatorId);
        $customer->forceFill([
            'status' => 'archived',
            'archived_at' => now(),
            'archived_by' => $context['administrator']->id,
        ])->save();

        return $customer;
    }

    private function reservation(
        array $context,
        Project $project,
        int $creatorId,
        string $status = 'active',
    ): Reservation {
        $unitStatus = $status === 'converted' ? 'sold' : 'reserved';

        return Reservation::query()->create([
            'tenant_id' => $context['tenant']->id,
            'unit_id' => $this->unit($context, $project, $unitStatus)->id,
            'customer_id' => $this->customer($context, $creatorId)->id,
            'status' => $status,
            'reserved_at' => now(),
            'expires_at' => now()->addHours(48),
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
        ]);
    }

    private function contract(
        array $context,
        Project $project,
        int $reservationCreatorId,
        string $status,
    ): Contract {
        $reservation = $this->reservation(
            $context,
            $project,
            $reservationCreatorId,
            $status === 'active' ? 'converted' : 'active',
        );
        $contract = new Contract([
            'tenant_id' => $context['tenant']->id,
            'reservation_id' => $reservation->id,
            'status' => $status,
            'total_amount' => '1000.00',
            'activated_at' => $status === 'active' ? now() : null,
            'created_by' => $reservationCreatorId,
            'updated_by' => $reservationCreatorId,
        ]);
        $contract->currency = 'SAR';
        $contract->save();

        return $contract;
    }

    /** @return array{lines: array<int, array<string, int|string>>} */
    private function collectionPayload(): array
    {
        return ['lines' => [[
            'sequence' => 1,
            'title' => 'قسط كامل',
            'amount' => '1000.00',
            'due_date' => '2027-09-01',
        ]]];
    }

    private function saveDraftDirectly(array $context, Contract $contract): void
    {
        app(SaveDraftCollectionScheduleAction::class)->execute(
            (string) $context['tenant']->id,
            (string) $contract->id,
            $context['administrator']->id,
            [new CollectionLineData(
                id: null,
                sequence: 1,
                title: 'قسط كامل',
                amount: '1000.00',
                dueDate: CarbonImmutable::parse('2027-09-01'),
            )],
        );
    }
}
