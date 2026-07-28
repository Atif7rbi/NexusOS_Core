<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\TenantUser;
use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Collections\Models\Collection;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

trait CreatesCollectionScheduleFixtures
{
    private int $collectionFixtureProjectCount = 0;
    private int $collectionFixtureCustomerCount = 0;
    private int $collectionFixtureUnitCount = 0;

    /**
     * @return array{tenant_id: string, contract_id: string, user_id: int}
     */
    protected function createCollectionContractContext(): array
    {
        $user = $this->createActiveUser();
        Sanctum::actingAs($user);

        return [
            'tenant_id' => $this->collectionTenantIdFor($user->id),
            'contract_id' => $this->createCollectionContract(),
            'user_id' => $user->id,
        ];
    }

    protected function collectionLine(
        ?string $id,
        int $sequence,
        string $amount,
        string $dueDate,
        string $title = 'قسط',
        ?string $notes = null,
    ): CollectionLineData {
        return new CollectionLineData(
            id: $id,
            sequence: $sequence,
            title: $title,
            amount: $amount,
            dueDate: CarbonImmutable::parse($dueDate),
            notes: $notes,
        );
    }

    /**
     * @return array<int, Collection>
     */
    protected function activeCollections(string $tenantId, string $contractId): array
    {
        return Collection::query()
            ->where('tenant_id', $tenantId)
            ->where('contract_id', $contractId)
            ->where('status', '!=', 'cancelled')
            ->orderBy('sequence')
            ->get()
            ->all();
    }

    private function createCollectionContract(): string
    {
        return (string) $this->postJson('/api/contracts', [
            'reservation_id' => $this->createCollectionReservation(),
            'total_amount' => '1000.00',
        ])->assertCreated()->json('data.contract.id');
    }

    private function createCollectionReservation(): string
    {
        return (string) $this->postJson('/api/reservations', [
            'unit_id' => $this->createCollectionUnit(),
            'customer_id' => $this->createCollectionCustomer(),
        ])->assertCreated()->json('data.reservation.id');
    }

    private function createCollectionUnit(): string
    {
        $this->collectionFixtureUnitCount++;

        return (string) $this->postJson('/api/units', [
            'project_id' => $this->createCollectionProject(),
            'unit_number' => "INT-{$this->collectionFixtureUnitCount}",
            'unit_type' => 'apartment',
            'selling_price' => 500000,
        ])->assertCreated()->json('data.unit.id');
    }

    private function createCollectionCustomer(): string
    {
        $this->collectionFixtureCustomerCount++;

        return (string) $this->postJson('/api/customers', [
            'type' => 'individual',
            'category' => 'buyer',
            'name' => "عميل تحصيل تكاملي {$this->collectionFixtureCustomerCount}",
            'phone' => '057000'.str_pad(
                (string) $this->collectionFixtureCustomerCount,
                4,
                '0',
                STR_PAD_LEFT,
            ),
        ])->assertCreated()->json('data.customer.id');
    }

    private function createCollectionProject(): string
    {
        $this->collectionFixtureProjectCount++;

        $projectId = (string) $this->postJson('/api/projects', [
            'name' => "مشروع تحصيل تكاملي {$this->collectionFixtureProjectCount}",
            'project_type' => 'residential',
            'city' => 'الرياض',
        ])->assertCreated()->json('data.project.id');

        Project::query()->whereKey($projectId)->update([
            'status' => ProjectStatus::Active->value,
        ]);

        return $projectId;
    }

    private function collectionTenantIdFor(int $userId): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $userId)
            ->value('tenant_id');
    }
}
