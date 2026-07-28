<?php

namespace Tests\Unit\Collections;

use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Models\Collection;
use App\Modules\Collections\Support\DerivedScheduleStateResolver;
use PHPUnit\Framework\TestCase;

class DerivedScheduleStateResolverTest extends TestCase
{
    private DerivedScheduleStateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new DerivedScheduleStateResolver();
    }

    public function test_no_collections_resolves_to_absent(): void
    {
        $this->assertSame(DerivedScheduleState::Absent, $this->resolver->resolve([]));
    }

    public function test_all_active_draft_resolves_to_draft(): void
    {
        $collections = [
            $this->makeCollection(CollectionStatus::Draft),
            $this->makeCollection(CollectionStatus::Draft),
        ];

        $this->assertSame(DerivedScheduleState::Draft, $this->resolver->resolve($collections));
    }

    public function test_all_active_scheduled_resolves_to_scheduled(): void
    {
        $collections = [
            $this->makeCollection(CollectionStatus::Scheduled),
            $this->makeCollection(CollectionStatus::Scheduled),
        ];

        $this->assertSame(DerivedScheduleState::Scheduled, $this->resolver->resolve($collections));
    }

    public function test_all_cancelled_resolves_to_cancelled(): void
    {
        $collections = [
            $this->makeCollection(CollectionStatus::Cancelled),
            $this->makeCollection(CollectionStatus::Cancelled),
        ];

        $this->assertSame(DerivedScheduleState::Cancelled, $this->resolver->resolve($collections));
    }

    public function test_cancelled_history_alongside_active_draft_is_still_draft(): void
    {
        $collections = [
            $this->makeCollection(CollectionStatus::Cancelled),
            $this->makeCollection(CollectionStatus::Draft),
        ];

        $this->assertSame(DerivedScheduleState::Draft, $this->resolver->resolve($collections));
    }

    public function test_mixed_active_draft_and_scheduled_is_invalid(): void
    {
        $collections = [
            $this->makeCollection(CollectionStatus::Draft),
            $this->makeCollection(CollectionStatus::Scheduled),
        ];

        $this->assertSame(DerivedScheduleState::Invalid, $this->resolver->resolve($collections));
    }

    public function test_contract_cancelled_before_any_collection_created_is_absent_not_cancelled(): void
    {
        // DDL spec section 28: cancelling a Contract before any Collection
        // exists must resolve to `absent`, not `cancelled`.
        $this->assertSame(DerivedScheduleState::Absent, $this->resolver->resolve([]));
    }

    public function test_active_only_filters_out_cancelled_rows(): void
    {
        $draft = $this->makeCollection(CollectionStatus::Draft);
        $cancelled = $this->makeCollection(CollectionStatus::Cancelled);

        $active = $this->resolver->activeOnly([$draft, $cancelled]);

        $this->assertSame([$draft], $active);
    }

    private function makeCollection(CollectionStatus $status): Collection
    {
        $collection = new Collection();
        $collection->status = $status;

        return $collection;
    }
}
