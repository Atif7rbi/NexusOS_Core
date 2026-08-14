<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesDomainIntegrityFixtures;

final class ProjectCustomerDependencyIntegrityTest extends ApiTestCase
{
    use CreatesDomainIntegrityFixtures;

    public function test_project_complete_waits_for_active_reservations(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'reserved',
        );
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            $unit->id,
            $customer->id,
            $actor->id,
        );

        $this->patchJson("/api/projects/{$project->id}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project']);
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => 'active',
        ]);

        $this->patchJson("/api/reservations/{$reservation->id}/cancel")
            ->assertOk();
        $this->patchJson("/api/projects/{$project->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.project.status', 'completed');
    }

    public function test_project_revert_waits_for_live_contract_and_succeeds_after_cancellation(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'sold',
        );
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            $unit->id,
            $customer->id,
            $actor->id,
            'converted',
        );
        $contract = $this->createIntegrityContract(
            $tenantId,
            $reservation->id,
            $actor->id,
            'active',
        );

        $this->patchJson("/api/projects/{$project->id}/revert-to-draft")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project']);

        $this->patchJson("/api/contracts/{$contract->id}/cancel")
            ->assertOk();
        $this->patchJson("/api/projects/{$project->id}/revert-to-draft")
            ->assertOk()
            ->assertJsonPath('data.project.status', 'draft');
    }

    public function test_project_cancel_remains_blocked_by_completed_sold_commitments(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'sold',
        );
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            $unit->id,
            $customer->id,
            $actor->id,
            'converted',
        );
        $this->createIntegrityContract(
            $tenantId,
            $reservation->id,
            $actor->id,
            'completed',
        );

        $this->patchJson("/api/projects/{$project->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project']);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => 'active',
        ]);
    }

    public function test_archiving_resolved_project_and_customer_preserves_journey_without_parent_cascade(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'sold',
        );
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            $unit->id,
            $customer->id,
            $actor->id,
            'converted',
        );
        $contract = $this->createIntegrityContract(
            $tenantId,
            $reservation->id,
            $actor->id,
            'active',
        );

        $this->patchJson("/api/customers/{$customer->id}/archive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer']);

        $this->patchJson("/api/contracts/{$contract->id}/cancel")
            ->assertOk();
        $this->patchJson("/api/projects/{$project->id}/archive")
            ->assertOk();
        $this->patchJson("/api/customers/{$customer->id}/archive")
            ->assertOk();

        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'status' => 'available',
            'archived_at' => null,
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => 'converted',
        ]);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'cancelled',
        ]);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.status', 'archived')
            ->assertJsonPath('data.business_context.summary.reservations_total', 1)
            ->assertJsonPath('data.business_context.summary.contracts_total', 1);
    }
}
