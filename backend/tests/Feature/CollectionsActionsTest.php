<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Collections\Actions\AmendCollectionScheduleAction;
use App\Modules\Collections\Actions\FinalizeCollectionScheduleAction;
use App\Modules\Collections\Actions\SaveDraftCollectionScheduleAction;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Models\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCollectionScheduleFixtures;

final class CollectionsActionsTest extends ApiTestCase
{
    use CreatesCollectionScheduleFixtures;

    public function test_save_draft_persists_the_requested_schedule_atomically(): void
    {
        $context = $this->createCollectionContractContext();

        $result = (new SaveDraftCollectionScheduleAction())->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '400.00', '2026-08-01', 'الدفعة الأولى'),
                $this->collectionLine(null, 2, '600.00', '2026-09-01', 'الدفعة الثانية'),
            ],
        );

        $this->assertSame(DerivedScheduleState::Draft, $result->derivedState);
        $this->assertCount(2, $result->collections);
        $this->assertSame(CollectionStatus::Draft, $result->collections[0]->status);
        $this->assertNull($result->collections[0]->scheduled_at);

        $firstId = $result->collections[0]->id;
        $secondId = $result->collections[1]->id;

        $updated = (new SaveDraftCollectionScheduleAction())->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '500.00', '2026-08-01', 'دفعة بديلة'),
                $this->collectionLine($firstId, 2, '500.00', '2026-09-01', 'الدفعة الأولى المعدلة'),
            ],
        );

        $this->assertSame(DerivedScheduleState::Draft, $updated->derivedState);
        $this->assertCount(2, $updated->collections);
        $this->assertDatabaseMissing('collections', ['id' => $secondId]);
        $this->assertDatabaseHas('collections', [
            'id' => $firstId,
            'sequence' => 2,
            'amount' => 500.00,
            'updated_by' => $context['user_id'],
        ]);
    }

    public function test_finalization_converts_every_draft_line_and_enforces_the_real_total(): void
    {
        $context = $this->createCollectionContractContext();
        $save = new SaveDraftCollectionScheduleAction();

        $save->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '400.00', '2026-08-01'),
                $this->collectionLine(null, 2, '600.00', '2026-09-01'),
            ],
        );

        $result = (new FinalizeCollectionScheduleAction())->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
        );

        $this->assertSame(DerivedScheduleState::Scheduled, $result->derivedState);
        $this->assertCount(2, $result->collections);

        foreach ($result->collections as $collection) {
            $this->assertSame(CollectionStatus::Scheduled, $collection->status);
            $this->assertNotNull($collection->scheduled_at);
            $this->assertSame($context['user_id'], $collection->scheduled_by);
        }

        $total = DB::table('collections')
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', 'scheduled')
            ->sum('amount');

        $this->assertSame('1000.00', number_format((float) $total, 2, '.', ''));
    }

    public function test_amendment_cancels_history_and_creates_a_valid_replacement_schedule(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);

        $result = (new AmendCollectionScheduleAction())->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '250.00', '2026-08-01', 'الدفعة المعدلة الأولى'),
                $this->collectionLine(null, 2, '750.00', '2026-09-01', 'الدفعة المعدلة الثانية'),
            ],
            'إعادة جدولة معتمدة',
        );

        $this->assertSame(DerivedScheduleState::Scheduled, $result->derivedState);
        $this->assertCount(2, $result->collections);
        $this->assertDatabaseCount('collections', 4);
        $this->assertSame(2, Collection::query()
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', CollectionStatus::Cancelled->value)
            ->count());

        $activeTotal = DB::table('collections')
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', CollectionStatus::Scheduled->value)
            ->sum('amount');

        $this->assertSame('1000.00', number_format((float) $activeTotal, 2, '.', ''));
    }

    /**
     * @param array{tenant_id: string, contract_id: string, user_id: int} $context
     */
    private function saveAndFinalize(array $context): void
    {
        (new SaveDraftCollectionScheduleAction())->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '500.00', '2026-08-01'),
                $this->collectionLine(null, 2, '500.00', '2026-09-01'),
            ],
        );

        (new FinalizeCollectionScheduleAction())->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
        );
    }
}
