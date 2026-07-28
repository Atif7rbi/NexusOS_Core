<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Collections\Actions\AmendCollectionScheduleAction;
use App\Modules\Collections\Actions\FinalizeCollectionScheduleAction;
use App\Modules\Collections\Actions\SaveDraftCollectionScheduleAction;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Exceptions\ScheduleTotalMismatchException;
use App\Modules\Collections\Models\Collection;
use RuntimeException;
use Tests\Support\CreatesCollectionScheduleFixtures;
use Tests\Support\ThrowingCollectionAuditRecorder;

final class CollectionsTransactionRollbackTest extends ApiTestCase
{
    use CreatesCollectionScheduleFixtures;

    public function test_save_draft_audit_failure_rolls_back_creates_updates_deletes_and_resequencing(): void
    {
        $context = $this->createCollectionContractContext();
        $save = new SaveDraftCollectionScheduleAction();
        $initial = $save->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '400.00', '2026-08-01', 'الأول'),
                $this->collectionLine(null, 2, '600.00', '2026-09-01', 'الثاني'),
            ],
        );

        $first = $initial->collections[0];
        $second = $initial->collections[1];
        $audit = new ThrowingCollectionAuditRecorder();

        try {
            (new SaveDraftCollectionScheduleAction(auditRecorder: $audit))->execute(
                $context['tenant_id'],
                $context['contract_id'],
                $context['user_id'],
                [
                    $this->collectionLine(null, 1, '300.00', '2026-08-01', 'جديد'),
                    $this->collectionLine($first->id, 2, '400.00', '2026-09-01', 'الأول المعدل'),
                ],
            );
            $this->fail('Expected the audit recorder to abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Intentional collection audit failure.', $exception->getMessage());
        }

        $this->assertTrue($audit->wasCalled);
        $this->assertDatabaseCount('collections', 2);
        $this->assertDatabaseHas('collections', [
            'id' => $first->id,
            'sequence' => 1,
            'amount' => 400.00,
            'title' => 'الأول',
        ]);
        $this->assertDatabaseHas('collections', [
            'id' => $second->id,
            'sequence' => 2,
            'amount' => 600.00,
            'title' => 'الثاني',
        ]);
    }

    public function test_finalization_total_mismatch_leaves_the_draft_schedule_unchanged(): void
    {
        $context = $this->createCollectionContractContext();

        (new SaveDraftCollectionScheduleAction())->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [$this->collectionLine(null, 1, '999.00', '2026-08-01')],
        );

        $this->expectException(ScheduleTotalMismatchException::class);

        try {
            (new FinalizeCollectionScheduleAction())->execute(
                $context['tenant_id'],
                $context['contract_id'],
                $context['user_id'],
            );
        } finally {
            $this->assertDatabaseHas('collections', [
                'tenant_id' => $context['tenant_id'],
                'contract_id' => $context['contract_id'],
                'status' => CollectionStatus::Draft->value,
                'scheduled_at' => null,
                'scheduled_by' => null,
            ]);
            $this->assertDatabaseCount('collections', 1);
        }
    }

    public function test_amendment_total_mismatch_restores_the_original_active_schedule(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);
        $originalIds = Collection::query()
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->pluck('id')
            ->all();

        $this->expectException(ScheduleTotalMismatchException::class);

        try {
            (new AmendCollectionScheduleAction())->execute(
                $context['tenant_id'],
                $context['contract_id'],
                $context['user_id'],
                [
                    $this->collectionLine(null, 1, '200.00', '2026-08-01'),
                    $this->collectionLine(null, 2, '700.00', '2026-09-01'),
                ],
                'محاولة غير مكتملة',
            );
        } finally {
            $this->assertDatabaseCount('collections', 2);
            foreach ($originalIds as $id) {
                $this->assertDatabaseHas('collections', [
                    'id' => $id,
                    'status' => CollectionStatus::Scheduled->value,
                    'cancelled_at' => null,
                ]);
            }
        }
    }

    public function test_finalization_audit_failure_rolls_back_every_state_change(): void
    {
        $context = $this->createCollectionContractContext();
        (new SaveDraftCollectionScheduleAction())->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '500.00', '2026-08-01'),
                $this->collectionLine(null, 2, '500.00', '2026-09-01'),
            ],
        );

        $audit = new ThrowingCollectionAuditRecorder();

        try {
            (new FinalizeCollectionScheduleAction(auditRecorder: $audit))->execute(
                $context['tenant_id'],
                $context['contract_id'],
                $context['user_id'],
            );
            $this->fail('Expected the audit recorder to abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Intentional collection audit failure.', $exception->getMessage());
        }

        $this->assertTrue($audit->wasCalled);
        $this->assertSame(2, Collection::query()
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', CollectionStatus::Draft->value)
            ->count());
        $this->assertSame(0, Collection::query()
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', CollectionStatus::Scheduled->value)
            ->count());
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
