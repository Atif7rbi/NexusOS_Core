<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Contracts\Actions\ActivateContractAction;
use App\Modules\Reservations\Actions\ExpireReservationAction;
use App\Modules\Reservations\Exceptions\ReservationUnitStateException;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesDomainIntegrityFixtures;

final class ReservationLifecycleIntegrityTest extends ApiTestCase
{
    use CreatesDomainIntegrityFixtures;

    public function test_create_cancel_expire_and_contract_activation_preserve_unit_coupling(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        $cancelUnit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
        );
        $cancelReservationId = $this->postJson('/api/reservations', [
            'unit_id' => $cancelUnit->id,
            'customer_id' => $customer->id,
        ])->assertCreated()->json('data.reservation.id');

        $this->assertDatabaseHas('units', [
            'id' => $cancelUnit->id,
            'status' => 'reserved',
        ]);

        $this->patchJson("/api/reservations/{$cancelReservationId}/cancel")
            ->assertOk();
        $this->assertDatabaseHas('reservations', [
            'id' => $cancelReservationId,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('units', [
            'id' => $cancelUnit->id,
            'status' => 'available',
        ]);

        $expireUnit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'reserved',
        );
        $expiringReservation = $this->createIntegrityReservation(
            $tenantId,
            $expireUnit->id,
            $customer->id,
            $actor->id,
            attributes: [
                'expires_at' => CarbonImmutable::parse('2026-08-14T09:00:00Z'),
            ],
        );
        app(ExpireReservationAction::class)->execute(
            $expiringReservation->id,
            CarbonImmutable::parse('2026-08-14T09:01:00Z'),
        );
        $this->assertDatabaseHas('reservations', [
            'id' => $expiringReservation->id,
            'status' => 'expired',
        ]);
        $this->assertDatabaseHas('units', [
            'id' => $expireUnit->id,
            'status' => 'available',
        ]);

        $contractUnit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'reserved',
        );
        $contractReservation = $this->createIntegrityReservation(
            $tenantId,
            $contractUnit->id,
            $customer->id,
            $actor->id,
        );
        $contract = $this->createIntegrityContract(
            $tenantId,
            $contractReservation->id,
            $actor->id,
        );

        $this->patchJson("/api/contracts/{$contract->id}/activate")
            ->assertOk();
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $contractReservation->id,
            'status' => 'converted',
            'updated_by' => $actor->id,
        ]);
        $this->assertDatabaseHas('units', [
            'id' => $contractUnit->id,
            'status' => 'sold',
            'updated_by' => $actor->id,
        ]);
    }

    public function test_archived_projects_are_not_reservation_eligible(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
        );
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        $this->patchJson("/api/projects/{$project->id}/archive")
            ->assertOk();

        $this->getJson(
            "/api/reservations/available-units?project_id={$project->id}"
        )
            ->assertOk()
            ->assertJsonCount(0, 'data.units');

        $this->postJson('/api/reservations', [
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_id']);

        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'status' => 'available',
        ]);
    }

    public function test_terminal_reservations_reject_user_mutation_and_contract_conversion(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        foreach (['cancelled', 'expired', 'converted'] as $status) {
            $unitStatus = $status === 'converted' ? 'sold' : 'available';
            $unit = $this->createIntegrityUnit(
                $tenantId,
                $project->id,
                $actor->id,
                $unitStatus,
            );
            $reservation = $this->createIntegrityReservation(
                $tenantId,
                $unit->id,
                $customer->id,
                $actor->id,
                $status,
            );

            $this->patchJson("/api/reservations/{$reservation->id}", [
                'notes' => 'terminal mutation',
            ])->assertUnprocessable();

            $this->patchJson("/api/reservations/{$reservation->id}/cancel")
                ->assertUnprocessable();

            $this->postJson('/api/contracts', [
                'reservation_id' => $reservation->id,
                'total_amount' => '450000.00',
            ])->assertUnprocessable();
        }
    }

    public function test_cancel_and_expire_fail_closed_when_unit_is_not_reserved(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        Sanctum::actingAs($actor);

        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        $cancelUnit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
        );
        $cancelReservation = $this->createIntegrityReservation(
            $tenantId,
            $cancelUnit->id,
            $customer->id,
            $actor->id,
        );

        $this->patchJson("/api/reservations/{$cancelReservation->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reservation']);
        $this->assertDatabaseHas('reservations', [
            'id' => $cancelReservation->id,
            'status' => 'active',
        ]);

        $expireUnit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
        );
        $expireReservation = $this->createIntegrityReservation(
            $tenantId,
            $expireUnit->id,
            $customer->id,
            $actor->id,
            attributes: [
                'expires_at' => CarbonImmutable::parse('2026-08-14T09:00:00Z'),
            ],
        );

        $this->expectException(ReservationUnitStateException::class);

        try {
            app(ExpireReservationAction::class)->execute(
                $expireReservation->id,
                CarbonImmutable::parse('2026-08-14T09:01:00Z'),
            );
        } finally {
            $this->assertDatabaseHas('reservations', [
                'id' => $expireReservation->id,
                'status' => 'active',
            ]);
            $this->assertDatabaseHas('units', [
                'id' => $expireUnit->id,
                'status' => 'available',
            ]);
        }
    }

    public function test_contract_activation_rolls_back_all_states_when_unit_transition_fails(): void
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = $this->integrityTenantId($actor);
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit(
            $tenantId,
            $project->id,
            $actor->id,
            'reserved',
        );
        $reservation = $this->createIntegrityReservation(
            $tenantId,
            $unit->id,
            $customer->id,
            $actor->id,
        );
        $contract = $this->createIntegrityContract(
            $tenantId,
            $reservation->id,
            $actor->id,
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION cp9_reject_unit_sold()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.status = 'sold' THEN
                    RAISE EXCEPTION 'intentional CP9 unit transition failure';
                END IF;
                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER cp9_reject_unit_sold_trigger
            BEFORE UPDATE OF status ON units
            FOR EACH ROW
            EXECUTE PROCEDURE cp9_reject_unit_sold()
        SQL);

        try {
            app(ActivateContractAction::class)->execute(
                $tenantId,
                $contract->id,
                $actor->id,
            );
            $this->fail('Expected the unit transition trigger to abort activation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'intentional CP9 unit transition failure',
                $exception->getMessage(),
            );
        } finally {
            DB::statement(
                'DROP TRIGGER IF EXISTS cp9_reject_unit_sold_trigger ON units'
            );
            DB::statement('DROP FUNCTION IF EXISTS cp9_reject_unit_sold()');
        }

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'status' => 'reserved',
        ]);
    }
}
