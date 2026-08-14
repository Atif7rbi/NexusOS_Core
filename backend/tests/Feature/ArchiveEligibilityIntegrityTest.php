<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesDomainIntegrityFixtures;

final class ArchiveEligibilityIntegrityTest extends ApiTestCase
{
    use CreatesDomainIntegrityFixtures;

    public function test_project_archive_allows_no_live_dependencies_and_blocks_live_journeys(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $emptyProject = $this->createIntegrityProject(
            $tenantId,
            $actor->id,
            'draft',
        );

        $this->patchJson("/api/projects/{$emptyProject->id}/archive")
            ->assertOk();

        $activeReservationProject = $this->createIntegrityProject(
            $tenantId,
            $actor->id,
        );
        $reservedUnit = $this->createIntegrityUnit(
            $tenantId,
            $activeReservationProject->id,
            $actor->id,
            'reserved',
        );
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $this->createIntegrityReservation(
            $tenantId,
            $reservedUnit->id,
            $customer->id,
            $actor->id,
        );

        $this->patchJson("/api/projects/{$activeReservationProject->id}/archive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project']);

        $activeContractProject = $this->createIntegrityProject(
            $tenantId,
            $actor->id,
        );
        $soldUnit = $this->createIntegrityUnit(
            $tenantId,
            $activeContractProject->id,
            $actor->id,
            'sold',
        );
        $convertedReservation = $this->createIntegrityReservation(
            $tenantId,
            $soldUnit->id,
            $customer->id,
            $actor->id,
            'converted',
        );
        $this->createIntegrityContract(
            $tenantId,
            $convertedReservation->id,
            $actor->id,
            'active',
        );

        $this->patchJson("/api/projects/{$activeContractProject->id}/archive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['project']);

        $this->assertDatabaseHas('projects', [
            'id' => $activeReservationProject->id,
            'archived_at' => null,
        ]);
        $this->assertDatabaseHas('projects', [
            'id' => $activeContractProject->id,
            'archived_at' => null,
        ]);
    }

    public function test_unit_archive_blocks_live_journeys_but_allows_completed_history(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        $availableUnit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
        );
        $this->patchJson("/api/units/{$availableUnit->id}/archive")
            ->assertOk();

        $reservedUnit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'reserved',
        );
        $this->createIntegrityReservation(
            $tenantId,
            $reservedUnit->id,
            $customer->id,
            $actor->id,
        );
        $this->patchJson("/api/units/{$reservedUnit->id}/archive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit']);

        $soldUnit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'sold',
        );
        $convertedReservation = $this->createIntegrityReservation(
            $tenantId,
            $soldUnit->id,
            $customer->id,
            $actor->id,
            'converted',
        );
        $contract = $this->createIntegrityContract(
            $tenantId,
            $convertedReservation->id,
            $actor->id,
            'active',
        );

        $this->patchJson("/api/units/{$soldUnit->id}/archive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit']);

        $contract->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        $this->patchJson("/api/units/{$soldUnit->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.unit.status', 'sold');
    }

    public function test_customer_archive_blocks_live_journeys_and_tenant_scope_precedes_disclosure(): void
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
        $this->createIntegrityReservation(
            $tenantId,
            $unit->id,
            $customer->id,
            $actor->id,
        );

        $this->patchJson("/api/customers/{$customer->id}/archive")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer']);

        $otherActor = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $otherTenantId = $this->integrityTenantId($otherActor);
        $otherCustomer = $this->createIntegrityCustomer(
            $otherTenantId,
            $otherActor->id,
        );

        $this->patchJson("/api/customers/{$otherCustomer->id}/archive")
            ->assertNotFound();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'customer',
            'archived_at' => null,
        ]);
    }
}
