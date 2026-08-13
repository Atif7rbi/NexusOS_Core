<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\TenantUser;
use App\Models\User;
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
        $user = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
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

    protected function createCollectionContract(): string
    {
        $userId = auth()->id();
        $contract = new Contract([
            'tenant_id' => $this->collectionTenantIdFor($userId),
            'reservation_id' => $this->createCollectionReservation(),
            'status' => 'draft',
            'total_amount' => '1000.00',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $contract->currency = 'SAR';
        $contract->save();

        return (string) $contract->id;
    }

    private function createCollectionReservation(): string
    {
        return (string) Reservation::query()->create([
            'tenant_id' => $this->collectionTenantIdFor(auth()->id()),
            'unit_id' => $this->createCollectionUnit(),
            'customer_id' => $this->createCollectionCustomer(),
            'status' => 'active',
            'reserved_at' => now(),
            'expires_at' => now()->addHours(48),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ])->id;
    }

    private function createCollectionUnit(): string
    {
        $this->collectionFixtureUnitCount++;

        return (string) Unit::query()->create([
            'tenant_id' => $this->collectionTenantIdFor(auth()->id()),
            'project_id' => $this->createCollectionProject(),
            'unit_number' => "INT-{$this->collectionFixtureUnitCount}",
            'unit_type' => 'apartment',
            'status' => 'reserved',
            'selling_price' => 500000,
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ])->id;
    }

    private function createCollectionCustomer(): string
    {
        $this->collectionFixtureCustomerCount++;

        return (string) Customer::query()->create([
            'tenant_id' => $this->collectionTenantIdFor(auth()->id()),
            'type' => 'individual',
            'category' => 'buyer',
            'status' => 'customer',
            'name' => "عميل تحصيل تكاملي {$this->collectionFixtureCustomerCount}",
            'phone' => '057000'.str_pad(
                (string) $this->collectionFixtureCustomerCount,
                4,
                '0',
                STR_PAD_LEFT,
            ),
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ])->id;
    }

    private function createCollectionProject(): string
    {
        $this->collectionFixtureProjectCount++;

        $year = now('Asia/Riyadh')->year;
        $sequence = (int) Project::query()
            ->where('project_number_year', $year)
            ->max('project_sequence_number') + 1;

        return (string) Project::query()->create([
            'tenant_id' => $this->collectionTenantIdFor(auth()->id()),
            'project_number' => sprintf('COL-%d-%03d', $year, $sequence),
            'project_number_year' => $year,
            'project_sequence_number' => $sequence,
            'name' => "مشروع تحصيل تكاملي {$this->collectionFixtureProjectCount}",
            'project_type' => 'residential',
            'city' => 'الرياض',
            'status' => ProjectStatus::Active->value,
            'country_code' => 'SA',
            'currency' => 'SAR',
            'data_origin' => 'user',
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ])->id;
    }

    private function collectionTenantIdFor(int $userId): string
    {
        return (string) TenantUser::query()
            ->where('user_id', $userId)
            ->value('tenant_id');
    }
}
