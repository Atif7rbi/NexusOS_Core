<?php

namespace Tests\Unit\Collections;

use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Models\Collection;
use PHPUnit\Framework\TestCase;

class CollectionModelTest extends TestCase
{
    public function test_is_active_is_true_for_draft(): void
    {
        $collection = new Collection();
        $collection->status = CollectionStatus::Draft;

        $this->assertTrue($collection->isActive());
    }

    public function test_is_active_is_true_for_scheduled(): void
    {
        $collection = new Collection();
        $collection->status = CollectionStatus::Scheduled;

        $this->assertTrue($collection->isActive());
    }

    public function test_is_active_is_false_for_cancelled(): void
    {
        $collection = new Collection();
        $collection->status = CollectionStatus::Cancelled;

        $this->assertFalse($collection->isActive());
    }

    public function test_status_cast_returns_enum_instance(): void
    {
        $collection = new Collection();
        $collection->status = 'scheduled';

        $this->assertSame(CollectionStatus::Scheduled, $collection->status);
    }

    public function test_amount_is_cast_to_two_decimal_places(): void
    {
        $collection = new Collection();
        $collection->amount = '150';

        $this->assertSame('150.00', (string) $collection->amount);
    }

    public function test_fillable_matches_frozen_ddl_columns(): void
    {
        $expected = [
            'tenant_id',
            'contract_id',
            'sequence',
            'title',
            'amount',
            'due_date',
            'notes',
            'status',
            'scheduled_at',
            'scheduled_by',
            'cancelled_at',
            'cancelled_by',
            'cancellation_reason',
            'created_by',
            'updated_by',
        ];

        $collection = new Collection();

        $this->assertSame($expected, $collection->getFillable());
    }
}
