<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Collections\Models\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesDomainIntegrityFixtures;

final class ReceivablesApiTest extends ApiTestCase
{
    use CreatesDomainIntegrityFixtures;

    public function test_recognition_creates_exact_recognized_receivable_with_required_snapshot_truth(): void
    {
        [$actor, $tenantId, $customerId] = $this->context();
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/receivables', $this->payload($customerId, '123.40'));

        $response->assertCreated()
            ->assertJsonPath('data.receivable.status', 'recognized')
            ->assertJsonPath('data.receivable.recognized_amount', '123.40')
            ->assertJsonPath('data.receivable.currency', 'SAR')
            ->assertJsonPath('data.receivable.due_date', '2026-09-30')
            ->assertJsonPath('data.receivable.contract_id', null)
            ->assertJsonPath('data.receivable.collection_id', null);
        $this->assertDatabaseHas('receivables', ['tenant_id' => $tenantId, 'customer_id' => $customerId, 'status' => 'recognized']);
    }

    public function test_recognition_operation_is_required_idempotent_and_conflicts_on_different_facts(): void
    {
        [$actor, , $customerId] = $this->context();
        [, , $otherCustomerId] = $this->context();
        Sanctum::actingAs($actor);
        $input = $this->payload($customerId);
        $this->postJson('/api/receivables', array_diff_key($input, ['recognition_operation_id' => true]))->assertUnprocessable();

        $first = $this->postJson('/api/receivables', $input)->assertCreated()->json('data.receivable.id');
        $second = $this->postJson('/api/receivables', $input)->assertCreated()->json('data.receivable.id');
        self::assertSame($first, $second);

        foreach ([
            ['recognized_amount' => '101.00'],
            ['customer_id' => $otherCustomerId],
            ['due_date' => '2026-10-01'],
            ['contract_id' => (string) Str::ulid()],
        ] as $difference) {
            $this->postJson('/api/receivables', array_merge($input, $difference))->assertConflict();
        }

        [$otherActor, , $otherTenantCustomer] = $this->context();
        Sanctum::actingAs($otherActor);
        $this->postJson('/api/receivables', array_merge($input, ['customer_id' => $otherTenantCustomer]))->assertCreated();
    }

    public function test_allowed_non_sar_business_currency_is_preserved_without_accounting_side_effects(): void
    {
        [$actor, , $customerId] = $this->context();
        Sanctum::actingAs($actor);
        $beforeJournals = (int) \DB::table('journal_entries')->count();

        $payload = $this->payload($customerId, '10.20');
        $payload['currency'] = 'USD';
        $this->postJson('/api/receivables', $payload)->assertCreated()->assertJsonPath('data.receivable.currency', 'USD');

        self::assertSame($beforeJournals, (int) \DB::table('journal_entries')->count());
        self::assertSame(0, (int) \DB::table('accounting_audits')->count());
    }

    public function test_recognition_requires_positive_exact_amount_customer_and_explicit_due_date(): void
    {
        [$actor, , $customerId] = $this->context();
        Sanctum::actingAs($actor);

        foreach (['0', '-1.00', '1.001', 1.2] as $amount) {
            $this->postJson('/api/receivables', $this->payload($customerId, $amount))->assertUnprocessable()->assertJsonValidationErrors('recognized_amount');
        }
        $missingDue = $this->payload($customerId);
        unset($missingDue['due_date']);
        $this->postJson('/api/receivables', $missingDue)->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $missingCustomer = $this->payload($customerId);
        unset($missingCustomer['customer_id']);
        $this->postJson('/api/receivables', $missingCustomer)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $withDraft = $this->payload($customerId) + ['status' => 'draft'];
        $this->postJson('/api/receivables', $withDraft)->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_nullable_and_same_tenant_contract_and_collection_references_are_supported(): void
    {
        [$actor, $tenantId, $customerId, $contractId, $collectionId] = $this->context(withSources: true);
        Sanctum::actingAs($actor);
        $payload = $this->payload($customerId) + ['contract_id' => $contractId, 'collection_id' => $collectionId];

        $this->postJson('/api/receivables', $payload)->assertCreated()
            ->assertJsonPath('data.receivable.contract_id', $contractId)
            ->assertJsonPath('data.receivable.collection_id', $collectionId);
        $this->assertDatabaseHas('receivables', ['tenant_id' => $tenantId, 'contract_id' => $contractId, 'collection_id' => $collectionId]);
    }

    public function test_cross_tenant_customer_contract_and_collection_are_rejected(): void
    {
        [$actor, , $customerId] = $this->context();
        [, , $otherCustomer, $otherContract, $otherCollection] = $this->context(withSources: true);
        Sanctum::actingAs($actor);

        $this->postJson('/api/receivables', $this->payload($otherCustomer))->assertUnprocessable();
        $this->postJson('/api/receivables', $this->payload($customerId) + ['contract_id' => $otherContract])->assertUnprocessable();
        $this->postJson('/api/receivables', $this->payload($customerId) + ['collection_id' => $otherCollection])->assertUnprocessable();
    }

    public function test_cancellation_is_terminal_requires_reason_and_preserves_financial_truth(): void
    {
        [$actor, , $customerId] = $this->context();
        Sanctum::actingAs($actor);
        $id = $this->postJson('/api/receivables', $this->payload($customerId, '99.99'))->assertCreated()->json('data.receivable.id');

        $this->postJson("/api/receivables/{$id}/cancel", ['cancelled_at' => '2026-08-26T12:00:00+00:00', 'cancellation_reason' => 'Recognition correction'])
            ->assertOk()->assertJsonPath('data.receivable.status', 'cancelled')->assertJsonPath('data.receivable.recognized_amount', '99.99');
        $this->postJson("/api/receivables/{$id}/cancel", ['cancelled_at' => '2026-08-26T13:00:00+00:00', 'cancellation_reason' => 'Again'])
            ->assertConflict()->assertJsonPath('error.code', 'receivables_conflict');

        $otherId = $this->postJson('/api/receivables', $this->payload($customerId))->assertCreated()->json('data.receivable.id');
        $this->postJson("/api/receivables/{$otherId}/cancel", ['cancelled_at' => '2026-08-26T12:00:00+00:00', 'cancellation_reason' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('cancellation_reason');
    }

    public function test_reads_are_tenant_isolated_and_mutations_require_authorized_active_membership(): void
    {
        [$actor, , $customerId] = $this->context();
        Sanctum::actingAs($actor);
        $id = $this->postJson('/api/receivables', $this->payload($customerId))->assertCreated()->json('data.receivable.id');

        [$other] = $this->context();
        Sanctum::actingAs($other);
        $this->getJson('/api/receivables')->assertOk()->assertJsonCount(0, 'data.receivables.data');
        $this->getJson("/api/receivables/{$id}")->assertNotFound();

        [$sales, , $salesCustomer] = $this->context(User::ROLE_SALES);
        Sanctum::actingAs($sales);
        $this->postJson('/api/receivables', $this->payload($salesCustomer))->assertForbidden();

        TenantUser::query()->where('user_id', $actor->id)->update(['status' => TenantUser::STATUS_PAUSED]);
        Sanctum::actingAs($actor);
        $this->postJson('/api/receivables', $this->payload($customerId))->assertForbidden();
    }

    private function payload(string $customerId, mixed $amount = '100.00'): array
    {
        return ['recognition_operation_id' => (string) Str::ulid(), 'customer_id' => $customerId, 'currency' => 'SAR', 'recognized_amount' => $amount, 'due_date' => '2026-09-30', 'recognized_at' => '2026-08-26T10:00:00+00:00'];
    }

    private function context(string $role = User::ROLE_ADMINISTRATOR, bool $withSources = false): array
    {
        $actor = $this->createActiveUser(['role' => $role]);
        $tenantId = $this->integrityTenantId($actor);
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        if (! $withSources) {
            return [$actor, $tenantId, (string) $customer->id];
        }
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'reserved');
        $reservation = $this->createIntegrityReservation($tenantId, (string) $unit->id, (string) $customer->id, $actor->id);
        $contract = $this->createIntegrityContract($tenantId, (string) $reservation->id, $actor->id);
        $collection = Collection::query()->create([
            'tenant_id' => $tenantId, 'contract_id' => $contract->id, 'sequence' => 1, 'title' => 'Installment',
            'amount' => '100.00', 'due_date' => '2026-09-30', 'status' => 'draft', 'created_by' => $actor->id,
        ]);

        return [$actor, $tenantId, (string) $customer->id, (string) $contract->id, (string) $collection->id];
    }
}
