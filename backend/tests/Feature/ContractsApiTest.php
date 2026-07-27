<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Units\Models\Unit;
use Laravel\Sanctum\Sanctum;

final class ContractsApiTest extends ApiTestCase
{
    private int $projectCount = 0;
    private int $customerCount = 0;
    private int $unitCount = 0;

    public function test_guest_cannot_access_contracts(): void
    {
        $id = '01J00000000000000000000000';
        $this->getJson('/api/contracts')->assertUnauthorized();
        $this->postJson('/api/contracts', [])->assertUnauthorized();
        $this->getJson("/api/contracts/{$id}")->assertUnauthorized();
        $this->patchJson("/api/contracts/{$id}", [])->assertUnauthorized();
        $this->patchJson("/api/contracts/{$id}/activate")->assertUnauthorized();
        $this->patchJson("/api/contracts/{$id}/complete")->assertUnauthorized();
        $this->patchJson("/api/contracts/{$id}/cancel")->assertUnauthorized();
    }

    // --- Creation ---

    public function test_contract_can_be_created_as_draft_from_an_active_reservation_with_negotiated_amount(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();

        $response = $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.contract.status', 'draft')
            ->assertJsonPath('data.contract.reservation_id', $reservationId)
            ->assertJsonPath('data.contract.total_amount', '450000.00');

        $this->assertDatabaseHas('contracts', [
            'reservation_id' => $reservationId,
            'status' => 'draft',
            'total_amount' => 450000.00,
        ]);
    }

    public function test_contract_total_amount_is_not_copied_from_unit_selling_price(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '999999.99',
        ])->assertCreated()
            ->assertJsonPath('data.contract.total_amount', '999999.99');
    }

    public function test_a_reservation_can_only_produce_one_contract(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertCreated();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reservation_id']);
    }

    public function test_contract_cannot_be_created_from_a_non_active_reservation(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();
        $this->patchJson("/api/reservations/{$reservationId}/cancel")->assertOk();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reservation_id']);
    }

    public function test_a_replacement_contract_can_be_created_after_the_draft_contract_was_cancelled(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();

        $firstId = $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertCreated()->json('data.contract.id');
        $this->patchJson("/api/contracts/{$firstId}/cancel")->assertOk();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '460000.00',
        ])->assertCreated()
            ->assertJsonPath('data.contract.status', 'draft')
            ->assertJsonPath('data.contract.total_amount', '460000.00');

        $this->assertDatabaseHas('contracts', ['id' => $firstId, 'status' => 'cancelled']);
    }

    public function test_two_draft_contracts_cannot_coexist_for_the_same_reservation(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertCreated();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reservation_id']);
    }

    public function test_contract_creation_is_blocked_while_an_active_contract_exists_for_the_reservation(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();
        $contractId = $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertCreated()->json('data.contract.id');
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reservation_id']);
    }

    public function test_contract_creation_is_blocked_while_a_completed_contract_exists_for_the_reservation(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();
        $contractId = $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertCreated()->json('data.contract.id');
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();
        $this->patchJson("/api/contracts/{$contractId}/complete")->assertOk();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reservation_id']);
    }

    public function test_contracts_are_tenant_scoped_on_creation_and_access(): void
    {
        $tenantAUser = $this->createActiveUser();
        $tenantBUser = $this->createActiveUser();
        Sanctum::actingAs($tenantAUser);
        $reservationId = $this->createReservation();
        $contractId = $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertCreated()->json('data.contract.id');

        Sanctum::actingAs($tenantBUser);
        $this->getJson("/api/contracts/{$contractId}")->assertNotFound();
        $this->patchJson("/api/contracts/{$contractId}", ['total_amount' => '1.00'])->assertNotFound();
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertNotFound();

        $otherReservationId = $this->createReservation();
        $this->postJson('/api/contracts', [
            'reservation_id' => $otherReservationId,
            'total_amount' => '100.00',
        ])->assertCreated();
    }

    // --- Update ---

    public function test_draft_contract_total_amount_can_be_updated(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();

        $this->patchJson("/api/contracts/{$contractId}", ['total_amount' => '500000.00'])
            ->assertOk()
            ->assertJsonPath('data.contract.total_amount', '500000.00');

        $this->assertDatabaseHas('contracts', [
            'id' => $contractId,
            'total_amount' => 500000.00,
        ]);
    }

    public function test_active_contract_cannot_be_updated(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();

        $this->patchJson("/api/contracts/{$contractId}", ['total_amount' => '1.00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    public function test_completed_contract_cannot_be_updated(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();
        $this->patchJson("/api/contracts/{$contractId}/complete")->assertOk();

        $this->patchJson("/api/contracts/{$contractId}", ['total_amount' => '1.00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    public function test_cancelled_contract_cannot_be_updated(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $this->patchJson("/api/contracts/{$contractId}/cancel")->assertOk();

        $this->patchJson("/api/contracts/{$contractId}", ['total_amount' => '1.00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    // --- Activation ---

    public function test_draft_contract_with_active_reservation_and_reserved_unit_activates_successfully(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $reservation = Contract::query()->findOrFail($contractId)->reservation;
        $unitId = $reservation->unit_id;

        $this->patchJson("/api/contracts/{$contractId}/activate")
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'active')
            ->assertJsonPath('data.contract.activated_at', fn ($value) => $value !== null);

        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'status' => 'active']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'converted']);
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'sold']);
    }

    public function test_activation_fails_when_reservation_is_not_active(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $reservationId = Contract::query()->findOrFail($contractId)->reservation_id;

        // Force reservation into a non-active state directly (already converted by another means is not reachable,
        // so we simulate via cancellation, which the Reservations module still permits pre-activation).
        Reservation::query()->whereKey($reservationId)->update(['status' => 'expired']);

        $this->patchJson("/api/contracts/{$contractId}/activate")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);

        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'status' => 'draft']);
    }

    public function test_activation_fails_when_unit_is_not_reserved(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $unitId = Contract::query()->findOrFail($contractId)->reservation->unit_id;

        Unit::query()->whereKey($unitId)->update(['status' => 'available']);

        $this->patchJson("/api/contracts/{$contractId}/activate")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);

        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'status' => 'draft']);
    }

    public function test_only_draft_contracts_can_be_activated(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();

        $this->patchJson("/api/contracts/{$contractId}/activate")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    // --- Completion ---

    public function test_active_contract_can_be_completed_and_reservation_and_unit_are_unaffected(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $reservation = Contract::query()->findOrFail($contractId)->reservation;
        $unitId = $reservation->unit_id;
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();

        $this->patchJson("/api/contracts/{$contractId}/complete")
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'completed')
            ->assertJsonPath('data.contract.completed_at', fn ($value) => $value !== null);

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'converted']);
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'sold']);
    }

    public function test_draft_contract_cannot_be_completed(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();

        $this->patchJson("/api/contracts/{$contractId}/complete")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    public function test_completed_and_cancelled_contracts_cannot_be_completed_again(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);

        $completedId = $this->createContract();
        $this->patchJson("/api/contracts/{$completedId}/activate")->assertOk();
        $this->patchJson("/api/contracts/{$completedId}/complete")->assertOk();
        $this->patchJson("/api/contracts/{$completedId}/complete")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);

        $cancelledId = $this->createContract();
        $this->patchJson("/api/contracts/{$cancelledId}/cancel")->assertOk();
        $this->patchJson("/api/contracts/{$cancelledId}/complete")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    // --- Cancellation ---

    public function test_draft_contract_cancellation_leaves_reservation_active_and_unit_reserved(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $reservation = Contract::query()->findOrFail($contractId)->reservation;
        $unitId = $reservation->unit_id;

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'cancelled');

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'active']);
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'reserved']);
    }

    public function test_active_contract_cancellation_keeps_reservation_converted_and_releases_unit(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $reservation = Contract::query()->findOrFail($contractId)->reservation;
        $unitId = $reservation->unit_id;
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'cancelled');

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'converted']);
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'available']);
    }

    public function test_completed_contract_cannot_be_cancelled(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();
        $this->patchJson("/api/contracts/{$contractId}/complete")->assertOk();

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    public function test_active_cancellation_rejects_reservation_not_in_converted_state_and_rolls_back(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $reservation = Contract::query()->findOrFail($contractId)->reservation;
        $unitId = $reservation->unit_id;
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();

        // Corrupt the reservation state directly, bypassing the domain actions.
        Reservation::query()->whereKey($reservation->id)->update(['status' => 'active']);

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);

        // Nothing else was mutated: contract remains active, unit remains sold.
        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'status' => 'active']);
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'sold']);
    }

    public function test_active_cancellation_rejects_unit_not_in_sold_state_and_rolls_back(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $unitId = Contract::query()->findOrFail($contractId)->reservation->unit_id;
        $this->patchJson("/api/contracts/{$contractId}/activate")->assertOk();

        // Corrupt the unit state directly, bypassing the domain actions.
        Unit::query()->whereKey($unitId)->update(['status' => 'available']);

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);

        // Contract must remain active; the transaction rolled back entirely.
        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'status' => 'active']);
    }

    public function test_cancelled_contract_cannot_be_cancelled_again(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $contractId = $this->createContract();
        $this->patchJson("/api/contracts/{$contractId}/cancel")->assertOk();

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors(['contract']);
    }

    // --- API listing ---

    public function test_contracts_can_be_listed_filtered_and_summarized(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);

        $draftId = $this->createContract();
        $activeId = $this->createContract();
        $this->patchJson("/api/contracts/{$activeId}/activate")->assertOk();

        $this->getJson('/api/contracts?status=active&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.contracts.meta.total', 1)
            ->assertJsonPath('data.contracts.data.0.id', $activeId)
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.draft', 1)
            ->assertJsonPath('data.summary.active', 1);

        $this->assertNotNull($draftId);
    }

    public function test_contract_show_exposes_unit_and_customer_nested_under_reservation(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();
        $reservation = Reservation::query()->findOrFail($reservationId);
        $contractId = $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '450000.00',
        ])->assertCreated()->json('data.contract.id');

        $this->getJson("/api/contracts/{$contractId}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'contract' => [
                        'id', 'status', 'total_amount',
                        'reservation' => [
                            'id', 'status',
                            'unit' => ['id', 'project_id', 'unit_number', 'status'],
                            'customer' => ['id', 'name', 'status'],
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('data.contract.reservation.id', $reservationId)
            ->assertJsonPath('data.contract.reservation.unit.id', $reservation->unit_id)
            ->assertJsonPath('data.contract.reservation.customer.id', $reservation->customer_id);
    }

    public function test_validation_rejects_zero_or_negative_total_amount(): void
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);
        $reservationId = $this->createReservation();

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '0.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['total_amount']);

        $this->postJson('/api/contracts', [
            'reservation_id' => $reservationId,
            'total_amount' => '-10.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['total_amount']);
    }

    // --- Helpers ---

    private function createContract(): string
    {
        return (string) $this->postJson('/api/contracts', [
            'reservation_id' => $this->createReservation(),
            'total_amount' => '450000.00',
        ])->assertCreated()->json('data.contract.id');
    }

    private function createReservation(): string
    {
        return (string) $this->postJson('/api/reservations', [
            'unit_id' => $this->createAvailableUnit(),
            'customer_id' => $this->createCustomer(),
        ])->assertCreated()->json('data.reservation.id');
    }

    private function createAvailableUnit(bool $activeProject = true): string
    {
        $this->unitCount++;
        $projectId = $this->createProject($activeProject);
        return (string) $this->postJson('/api/units', [
            'project_id' => $projectId,
            'unit_number' => "C-{$this->unitCount}",
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');
    }

    private function createCustomer(): string
    {
        $this->customerCount++;
        return (string) $this->postJson('/api/customers', [
            'type' => 'individual', 'category' => 'buyer',
            'name' => "عميل العقد {$this->customerCount}",
            'phone' => '051000'.str_pad((string) $this->customerCount, 4, '0', STR_PAD_LEFT),
        ])->assertCreated()->json('data.customer.id');
    }

    private function createProject(bool $active): string
    {
        $this->projectCount++;
        $projectId = (string) $this->postJson('/api/projects', [
            'name' => "مشروع العقد {$this->projectCount}", 'project_type' => 'residential', 'city' => 'الرياض',
        ])->assertCreated()->json('data.project.id');
        if ($active) {
            Project::query()->whereKey($projectId)->update(['status' => ProjectStatus::Active->value]);
        }

        return $projectId;
    }
}
