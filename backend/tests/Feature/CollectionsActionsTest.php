<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Collections\Actions\AmendCollectionScheduleAction;
use App\Modules\Collections\Actions\FinalizeCollectionScheduleAction;
use App\Modules\Collections\Actions\SaveDraftCollectionScheduleAction;
use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Exceptions\CollectionScheduleChangedSinceLoadedException;
use App\Modules\Collections\Exceptions\ContractNotEligibleForAmendmentException;
use App\Modules\Collections\Exceptions\InvalidCancellationReasonException;
use App\Modules\Collections\Exceptions\InvalidExpectedActiveCollectionIdsException;
use App\Modules\Collections\Models\Collection;
use App\Modules\Contracts\Enums\ContractStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesCollectionScheduleFixtures;

final class CollectionsActionsTest extends ApiTestCase
{
    use CreatesCollectionScheduleFixtures;

    public function test_save_draft_persists_the_requested_schedule_atomically(): void
    {
        $context = $this->createCollectionContractContext();

        $result = (new SaveDraftCollectionScheduleAction)->execute(
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

        $updated = (new SaveDraftCollectionScheduleAction)->execute(
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
        $save = new SaveDraftCollectionScheduleAction;

        $save->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '400.00', '2026-08-01'),
                $this->collectionLine(null, 2, '600.00', '2026-09-01'),
            ],
        );

        $result = (new FinalizeCollectionScheduleAction)->execute(
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

    public function test_save_draft_can_replace_and_resequence_multiple_existing_rows_without_index_collisions(): void
    {
        $context = $this->createCollectionContractContext();
        $initial = (new SaveDraftCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '200.00', '2026-08-01', 'الأول'),
                $this->collectionLine(null, 2, '300.00', '2026-09-01', 'الثاني'),
                $this->collectionLine(null, 3, '500.00', '2026-10-01', 'الثالث'),
            ],
        );

        $firstId = $initial->collections[0]->id;
        $secondId = $initial->collections[1]->id;
        $thirdId = $initial->collections[2]->id;

        $result = (new SaveDraftCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine($thirdId, 1, '500.00', '2026-08-01', 'الثالث بعد الترتيب'),
                $this->collectionLine(null, 2, '250.00', '2026-09-01', 'بديل الثاني'),
                $this->collectionLine(null, 3, '250.00', '2026-10-01', 'بديل الأول'),
            ],
        );

        $this->assertCount(3, $result->collections);
        $this->assertDatabaseMissing('collections', ['id' => $firstId]);
        $this->assertDatabaseMissing('collections', ['id' => $secondId]);
        $this->assertDatabaseHas('collections', [
            'id' => $thirdId,
            'sequence' => 1,
            'title' => 'الثالث بعد الترتيب',
        ]);
        $this->assertSame([1, 2, 3], Collection::query()
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all());
    }

    public function test_amendment_cancels_history_and_creates_a_valid_replacement_schedule(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);
        $expectedIds = $this->activeCollectionIds($context);
        $audit = new class implements CollectionAuditRecorderInterface
        {
            /** @var array<string, mixed> */
            public array $context = [];

            public function record(
                string $tenantId,
                string $contractId,
                string $event,
                int|string $actorId,
                array $context = [],
            ): void {
                $this->context = $context;
            }
        };

        $result = (new AmendCollectionScheduleAction(auditRecorder: $audit))->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            $expectedIds,
            [
                $this->collectionLine(null, 1, '250.00', '2026-08-01', 'الدفعة المعدلة الأولى'),
                $this->collectionLine(null, 2, '750.00', '2026-09-01', 'الدفعة المعدلة الثانية'),
            ],
            '  إعادة جدولة معتمدة  ',
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
        $this->assertSame('إعادة جدولة معتمدة', $audit->context['cancellation_reason']);

        foreach ($expectedIds as $id) {
            $this->assertDatabaseHas('collections', [
                'id' => $id,
                'status' => CollectionStatus::Cancelled->value,
                'cancellation_reason' => 'إعادة جدولة معتمدة',
            ]);
        }
    }

    public function test_reverse_order_expected_ids_allow_amendment(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);

        $result = (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            array_reverse($this->activeCollectionIds($context)),
            [
                $this->collectionLine(null, 1, '350.00', '2026-08-01'),
                $this->collectionLine(null, 2, '650.00', '2026-09-01'),
            ],
            'تعديل بترتيب معرفات معكوس',
        );

        $this->assertSame(DerivedScheduleState::Scheduled, $result->derivedState);
        $this->assertCount(2, $result->collections);
    }

    public function test_lowercase_expected_ids_allow_amendment(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);

        $result = (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            array_map('strtolower', $this->activeCollectionIds($context)),
            [
                $this->collectionLine(null, 1, '300.00', '2026-08-01'),
                $this->collectionLine(null, 2, '700.00', '2026-09-01'),
            ],
            'تعديل بمعرفات مطبعة',
        );

        $this->assertSame(DerivedScheduleState::Scheduled, $result->derivedState);
        $this->assertCount(2, $result->collections);
    }

    public function test_missing_current_id_rejects_amendment_without_mutation_or_audit(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);
        $expectedIds = $this->activeCollectionIds($context);
        $audit = new class implements CollectionAuditRecorderInterface
        {
            public bool $wasCalled = false;

            public function record(
                string $tenantId,
                string $contractId,
                string $event,
                int|string $actorId,
                array $context = [],
            ): void {
                $this->wasCalled = true;
            }
        };

        try {
            (new AmendCollectionScheduleAction(auditRecorder: $audit))->execute(
                $context['tenant_id'],
                $context['contract_id'],
                $context['user_id'],
                [$expectedIds[0]],
                [
                    $this->collectionLine(null, 1, '400.00', '2026-08-01'),
                    $this->collectionLine(null, 2, '600.00', '2026-09-01'),
                ],
                'جيل قديم',
            );
            $this->fail('Expected stale generation to reject the Amendment.');
        } catch (CollectionScheduleChangedSinceLoadedException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($audit->wasCalled);
        $this->assertDatabaseCount('collections', 2);

        foreach ($expectedIds as $id) {
            $this->assertDatabaseHas('collections', [
                'id' => $id,
                'status' => CollectionStatus::Scheduled->value,
                'cancelled_at' => null,
            ]);
        }
    }

    public function test_extra_valid_id_is_a_generation_conflict(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);

        $this->expectException(CollectionScheduleChangedSinceLoadedException::class);

        (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [...$this->activeCollectionIds($context), (string) Str::ulid()],
            [
                $this->collectionLine(null, 1, '500.00', '2026-08-01'),
                $this->collectionLine(null, 2, '500.00', '2026-09-01'),
            ],
            'معرف إضافي',
        );
    }

    public function test_id_from_another_contract_is_a_generation_conflict(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);

        $otherContractId = (string) $this->postJson('/api/contracts', [
            'reservation_id' => $this->createCollectionReservation(),
            'total_amount' => '1000.00',
        ])->assertCreated()->json('data.contract.id');

        (new SaveDraftCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $otherContractId,
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
            ],
        );
        (new FinalizeCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $otherContractId,
            $context['user_id'],
        );

        $otherId = $this->activeCollectionIds([
            ...$context,
            'contract_id' => $otherContractId,
        ])[0];

        $this->expectException(CollectionScheduleChangedSinceLoadedException::class);

        (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [$otherId],
            [
                $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
            ],
            'معرف عقد آخر',
        );
    }

    public function test_id_from_another_tenant_is_a_generation_conflict(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);
        $otherContext = $this->createCollectionContractContext();
        $this->saveAndFinalize($otherContext);

        $this->expectException(CollectionScheduleChangedSinceLoadedException::class);

        (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [$this->activeCollectionIds($otherContext)[0]],
            [
                $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
            ],
            'معرف مستأجر آخر',
        );
    }

    public function test_successful_amendment_makes_the_previous_generation_stale(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);
        $originalIds = $this->activeCollectionIds($context);
        $action = new AmendCollectionScheduleAction;

        $action->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            $originalIds,
            [
                $this->collectionLine(null, 1, '400.00', '2026-08-01'),
                $this->collectionLine(null, 2, '600.00', '2026-09-01'),
            ],
            'التعديل الأول',
        );

        $this->expectException(CollectionScheduleChangedSinceLoadedException::class);

        $action->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            $originalIds,
            [
                $this->collectionLine(null, 1, '300.00', '2026-08-01'),
                $this->collectionLine(null, 2, '700.00', '2026-09-01'),
            ],
            'إعادة استخدام جيل قديم',
        );
    }

    public function test_structurally_invalid_expected_ids_do_not_mutate_schedule(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);
        $originalIds = $this->activeCollectionIds($context);
        $audit = new class implements CollectionAuditRecorderInterface
        {
            public bool $wasCalled = false;

            public function record(
                string $tenantId,
                string $contractId,
                string $event,
                int|string $actorId,
                array $context = [],
            ): void {
                $this->wasCalled = true;
            }
        };

        try {
            (new AmendCollectionScheduleAction(auditRecorder: $audit))->execute(
                $context['tenant_id'],
                $context['contract_id'],
                $context['user_id'],
                [],
                [
                    $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
                ],
                'طلب غير صالح',
            );
            $this->fail('Expected structural validation to reject empty expected IDs.');
        } catch (InvalidExpectedActiveCollectionIdsException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('collections', 2);
        $this->assertSame($originalIds, $this->activeCollectionIds($context));
        $this->assertFalse($audit->wasCalled);
    }

    public function test_cancellation_reason_limit_is_applied_after_trim(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);

        $this->expectException(InvalidCancellationReasonException::class);

        (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            $this->activeCollectionIds($context),
            [
                $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
            ],
            '  '.str_repeat('س', 501).'  ',
        );
    }

    public function test_whitespace_only_cancellation_reason_is_rejected_after_trim(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);

        $this->expectException(InvalidCancellationReasonException::class);

        (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            $this->activeCollectionIds($context),
            [
                $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
            ],
            " \t\n ",
        );
    }

    public function test_eligibility_error_precedes_generation_conflict(): void
    {
        $context = $this->createCollectionContractContext();
        $this->saveAndFinalize($context);
        $staleIds = [$this->activeCollectionIds($context)[0]];

        DB::table('contracts')
            ->where('tenant_id', $context['tenant_id'])
            ->where('id', $context['contract_id'])
            ->update([
                'status' => ContractStatus::Cancelled->value,
                'cancelled_at' => now(),
            ]);

        $this->expectException(ContractNotEligibleForAmendmentException::class);

        (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            $staleIds,
            [
                $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
            ],
            'الأهلية أولًا',
        );
    }

    /**
     * @param  array{tenant_id: string, contract_id: string, user_id: int}  $context
     */
    private function saveAndFinalize(array $context): void
    {
        (new SaveDraftCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '500.00', '2026-08-01'),
                $this->collectionLine(null, 2, '500.00', '2026-09-01'),
            ],
        );

        (new FinalizeCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
        );
    }

    /**
     * @param  array{tenant_id: string, contract_id: string, user_id: int}  $context
     * @return array<int, string>
     */
    private function activeCollectionIds(array $context): array
    {
        return array_map(
            static fn (Collection $collection): string => (string) $collection->id,
            $this->activeCollections($context['tenant_id'], $context['contract_id']),
        );
    }
}
