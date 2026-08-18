<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Collections\Actions\SaveDraftCollectionScheduleAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesCollectionScheduleFixtures;

final class CollectionScheduleAuditPersistenceTest extends ApiTestCase
{
    use CreatesCollectionScheduleFixtures;

    public function test_collection_action_persists_durable_audit_in_same_database(): void
    {
        $context = $this->createCollectionContractContext();

        (new SaveDraftCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
            ],
        );

        $this->assertDatabaseHas('collection_schedule_audits', [
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'event' => 'collection_draft_schedule_saved',
            'actor_id' => $context['user_id'],
        ]);
    }

    public function test_collection_audit_rows_are_immutable_at_database_boundary(): void
    {
        $context = $this->createCollectionContractContext();

        (new SaveDraftCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '1000.00', '2026-08-01'),
            ],
        );

        $auditId = DB::table('collection_schedule_audits')->value('id');

        $this->expectException(QueryException::class);

        DB::table('collection_schedule_audits')
            ->where('id', $auditId)
            ->update(['event' => 'tampered']);
    }
}
