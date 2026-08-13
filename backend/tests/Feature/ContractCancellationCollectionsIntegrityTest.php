<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * CP6 (Contract Cancellation -> Collections Integrity).
 *
 * This file is intentionally isolated from ContractsApiTest (Track A /
 * CP3 owns that file). It exercises only the lifecycle/integrity cascade
 * implemented in CancelContractAction: cancelling a Contract must, in the
 * same transaction, cancel every currently-active Collection row for it.
 *
 * Authorization is deliberately NOT under test here (that is CP3's
 * responsibility). An Administrator actor is used purely to invoke the
 * lifecycle endpoint; every commercial prerequisite (Project/Unit/
 * Reservation/Contract/Collection) is constructed directly at the DB
 * layer, never through a protected API, so this file has no dependency
 * on Track A's authorization work or on Tests\Support\
 * CreatesCollectionScheduleFixtures (which Track A is auditing
 * separately).
 */
final class ContractCancellationCollectionsIntegrityTest extends ApiTestCase
{
    private int $sequence = 0;

    // --- A: Draft Contract + active draft Collection Schedule ---

    public function test_draft_contract_cancellation_cancels_its_active_draft_schedule(): void
    {
        [$tenantId, $actor] = $this->authenticateAsAdministrator();
        [$contractId] = $this->createCommercialChain($tenantId, $actor->id, contractStatus: 'draft');
        $lineId = $this->insertCollectionLine($tenantId, $contractId, $actor->id, 'draft', 1);

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'cancelled');

        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'status' => 'cancelled']);
        $line = DB::table('collections')->where('id', $lineId)->first();
        $this->assertSame('cancelled', $line->status);
        $this->assertNotNull($line->cancelled_at);
        $this->assertSame($actor->id, $line->cancelled_by);
        $this->assertNotNull($line->cancellation_reason);
        $this->assertNotSame('', trim((string) $line->cancellation_reason));
    }

    // --- B: Active Contract + scheduled Collection Schedule ---

    public function test_active_contract_cancellation_cancels_its_active_scheduled_schedule(): void
    {
        [$tenantId, $actor] = $this->authenticateAsAdministrator();
        [$contractId] = $this->createCommercialChain($tenantId, $actor->id, contractStatus: 'active');
        $lineId = $this->insertCollectionLine($tenantId, $contractId, $actor->id, 'scheduled', 1);

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'cancelled');

        $line = DB::table('collections')->where('id', $lineId)->first();
        $this->assertSame('cancelled', $line->status);
        $this->assertNotNull($line->cancelled_at);
        $this->assertSame($actor->id, $line->cancelled_by);
    }

    // --- C: Existing cancelled / historical schedule rows are untouched ---

    public function test_historical_cancelled_rows_are_left_untouched(): void
    {
        [$tenantId, $actor] = $this->authenticateAsAdministrator();
        [$contractId] = $this->createCommercialChain($tenantId, $actor->id, contractStatus: 'draft');

        $historicalCancelledAt = CarbonImmutable::parse('2026-08-01T09:00:00Z');
        $historicalId = $this->insertCollectionLine(
            $tenantId,
            $contractId,
            $actor->id,
            'cancelled',
            1,
            cancelledAt: $historicalCancelledAt,
            cancellationReason: 'استبدال سابق ضمن تعديل',
        );
        $activeId = $this->insertCollectionLine($tenantId, $contractId, $actor->id, 'draft', 2);

        $this->patchJson("/api/contracts/{$contractId}/cancel")->assertOk();

        $historical = DB::table('collections')->where('id', $historicalId)->first();
        $this->assertSame('cancelled', $historical->status);
        $this->assertSame('استبدال سابق ضمن تعديل', $historical->cancellation_reason);
        $this->assertSame(
            $historicalCancelledAt->toDateTimeString(),
            CarbonImmutable::parse($historical->cancelled_at)->toDateTimeString(),
        );

        $active = DB::table('collections')->where('id', $activeId)->first();
        $this->assertSame('cancelled', $active->status);
    }

    // --- D: No active Collection Schedule -> Contract cancellation succeeds ---

    public function test_contract_cancellation_succeeds_with_no_collection_schedule(): void
    {
        [$tenantId, $actor] = $this->authenticateAsAdministrator();
        [$contractId] = $this->createCommercialChain($tenantId, $actor->id, contractStatus: 'draft');

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'cancelled');
    }

    // --- E: Multiple schedule versions: only the active version is cancelled ---

    public function test_only_the_active_schedule_version_is_cancelled_among_multiple_versions(): void
    {
        [$tenantId, $actor] = $this->authenticateAsAdministrator();
        [$contractId] = $this->createCommercialChain($tenantId, $actor->id, contractStatus: 'active');

        // Version 1 (replaced by an earlier amendment): historical, cancelled.
        $replacedAt = CarbonImmutable::parse('2026-08-01T09:00:00Z');
        $replacedId = $this->insertCollectionLine(
            $tenantId,
            $contractId,
            $actor->id,
            'cancelled',
            1,
            cancelledAt: $replacedAt,
            cancellationReason: 'استبدال ضمن تعديل سابق',
        );

        // Version 2 (current active version): scheduled.
        $currentId = $this->insertCollectionLine($tenantId, $contractId, $actor->id, 'scheduled', 2);

        $this->patchJson("/api/contracts/{$contractId}/cancel")->assertOk();

        $replaced = DB::table('collections')->where('id', $replacedId)->first();
        $this->assertSame('cancelled', $replaced->status);
        $this->assertSame('استبدال ضمن تعديل سابق', $replaced->cancellation_reason);
        $this->assertSame(
            $replacedAt->toDateTimeString(),
            CarbonImmutable::parse($replaced->cancelled_at)->toDateTimeString(),
        );

        $current = DB::table('collections')->where('id', $currentId)->first();
        $this->assertSame('cancelled', $current->status);
        $this->assertNotSame('استبدال ضمن تعديل سابق', $current->cancellation_reason);
    }

    // --- F: After cancellation, next_scheduled_collection resolves to null ---

    public function test_next_scheduled_collection_resolves_to_null_after_cancellation(): void
    {
        [$tenantId, $actor] = $this->authenticateAsAdministrator();
        [$contractId] = $this->createCommercialChain($tenantId, $actor->id, contractStatus: 'active');
        $this->insertCollectionLine($tenantId, $contractId, $actor->id, 'scheduled', 1);

        $this->patchJson("/api/contracts/{$contractId}/cancel")->assertOk();

        $item = collect(
            $this->getJson('/api/collections?per_page=100')->assertOk()->json('data.items')
        )->firstWhere('contract_id', $contractId);

        $this->assertNotNull($item);
        $this->assertSame('cancelled', $item['schedule_state']);
        $this->assertNull($item['next_scheduled_collection']);
    }

    // --- G: Existing idempotency semantics of CancelContractAction are preserved ---

    public function test_cancelling_an_already_cancelled_contract_is_rejected_and_schedule_is_unaffected(): void
    {
        [$tenantId, $actor] = $this->authenticateAsAdministrator();
        [$contractId] = $this->createCommercialChain($tenantId, $actor->id, contractStatus: 'draft');
        $lineId = $this->insertCollectionLine($tenantId, $contractId, $actor->id, 'draft', 1);

        $this->patchJson("/api/contracts/{$contractId}/cancel")->assertOk();
        $firstCancelledAt = DB::table('collections')->where('id', $lineId)->value('cancelled_at');

        $this->patchJson("/api/contracts/{$contractId}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contract']);

        // The second (rejected) call must not re-touch the already-cancelled line.
        $this->assertSame(
            $firstCancelledAt,
            DB::table('collections')->where('id', $lineId)->value('cancelled_at'),
        );
    }

    // --- Fixtures (DB layer only, no protected API calls) ---

    /** @return array{0: string, 1: User} */
    private function authenticateAsAdministrator(): array
    {
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        Sanctum::actingAs($user);

        $tenantId = (string) TenantUser::query()
            ->where('user_id', $user->id)
            ->value('tenant_id');

        return [$tenantId, $user];
    }

    /**
     * Builds Project -> Unit -> Reservation -> Contract directly at the DB
     * layer, in the Unit/Reservation state that CancelContractAction's own
     * invariants require for the requested Contract status:
     *   draft  -> reservation active,    unit reserved
     *   active -> reservation converted, unit sold
     *
     * @return array{0: string} the Contract id
     */
    private function createCommercialChain(
        string $tenantId,
        int $userId,
        string $contractStatus,
    ): array {
        $this->sequence++;
        $now = CarbonImmutable::parse('2026-08-10T08:00:00Z');

        $projectId = (string) Str::ulid();
        $year = $now->year;
        $sequenceNumber = ((int) DB::table('projects')
            ->where('project_number_year', $year)
            ->max('project_sequence_number')) + 1;

        DB::table('projects')->insert([
            'id' => $projectId,
            'tenant_id' => $tenantId,
            'project_number' => sprintf('CP6-%d-%d', $year, $sequenceNumber),
            'project_number_year' => $year,
            'project_sequence_number' => $sequenceNumber,
            'name' => "مشروع تكامل CP6 {$this->sequence}",
            'project_type' => 'residential',
            'status' => 'active',
            'city' => 'الرياض',
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $unitId = (string) Str::ulid();
        $unitStatus = $contractStatus === 'active' ? 'sold' : 'reserved';

        DB::table('units')->insert([
            'id' => $unitId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'unit_number' => "CP6-{$this->sequence}",
            'unit_type' => 'apartment',
            'status' => $unitStatus,
            'selling_price' => '500000.00',
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $customerId = (string) Str::ulid();

        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $tenantId,
            'type' => 'individual',
            'category' => 'buyer',
            'status' => 'customer',
            'name' => "عميل تكامل CP6 {$this->sequence}",
            'phone' => '059'.str_pad((string) $this->sequence, 7, '0', STR_PAD_LEFT),
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $reservationId = (string) Str::ulid();
        $reservationStatus = $contractStatus === 'active' ? 'converted' : 'active';

        DB::table('reservations')->insert([
            'id' => $reservationId,
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'customer_id' => $customerId,
            'status' => $reservationStatus,
            'reserved_at' => $now,
            'expires_at' => $now->addMonth(),
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $contractId = (string) Str::ulid();

        DB::table('contracts')->insert([
            'id' => $contractId,
            'tenant_id' => $tenantId,
            'reservation_id' => $reservationId,
            'status' => $contractStatus,
            'total_amount' => '500000.00',
            'currency' => 'SAR',
            'activated_at' => $contractStatus === 'active' ? $now : null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$contractId];
    }

    private function insertCollectionLine(
        string $tenantId,
        string $contractId,
        int $userId,
        string $status,
        int $sequence,
        ?CarbonImmutable $cancelledAt = null,
        ?string $cancellationReason = null,
    ): string {
        $now = CarbonImmutable::parse('2026-08-10T09:00:00Z');
        $scheduled = $status === 'scheduled';
        $cancelled = $status === 'cancelled';
        $id = (string) Str::ulid();

        DB::table('collections')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'contract_id' => $contractId,
            'sequence' => $sequence,
            'title' => "بند تكامل CP6 {$sequence}",
            'amount' => '1000.00',
            'due_date' => $now->addMonths($sequence)->toDateString(),
            'notes' => null,
            'status' => $status,
            'scheduled_at' => $scheduled ? $now : null,
            'scheduled_by' => $scheduled ? $userId : null,
            'cancelled_at' => $cancelled ? ($cancelledAt ?? $now) : null,
            'cancelled_by' => $cancelled ? $userId : null,
            'cancellation_reason' => $cancelled ? ($cancellationReason ?? 'إلغاء للاختبار') : null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }
}
