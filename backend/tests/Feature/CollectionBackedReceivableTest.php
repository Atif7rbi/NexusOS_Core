<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Collections\Actions\AmendCollectionScheduleAction;
use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Collections\Exceptions\CollectionHasEffectiveReceivableException;
use App\Modules\Collections\Models\Collection;
use App\Modules\Contracts\Actions\CancelContractAction;
use App\Modules\Receivables\Actions\CancelReceivableAction;
use App\Modules\Receivables\Actions\EstablishCollectionReceivable;
use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class CollectionBackedReceivableTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_establishment_derives_every_canonical_fact_from_the_scheduled_collection(): void
    {
        [$tenantId, $actor, $customerId, $contractId, $collection] = $this->context();
        $id = $this->establish($tenantId, $actor, (string) $collection->id);

        self::assertSame((string) $collection->id, DB::table('receivables')->where('id', $id)->value('collection_id'));
        self::assertSame($contractId, DB::table('receivables')->where('id', $id)->value('contract_id'));
        self::assertSame($customerId, DB::table('receivables')->where('id', $id)->value('customer_id'));
        self::assertSame('123.45', (string) DB::table('receivables')->where('id', $id)->value('recognized_amount'));
        self::assertSame('2026-09-30', (string) DB::table('receivables')->where('id', $id)->value('due_date'));
        self::assertSame('SAR', DB::table('receivables')->where('id', $id)->value('currency'));
        self::assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_only_a_scheduled_collection_can_be_established(): void
    {
        [$tenantId, $actor, , , $collection] = $this->context('draft');
        $this->expectException(ReceivablesConflict::class);
        $this->establish($tenantId, $actor, (string) $collection->id);
    }

    public function test_a_cancelled_collection_cannot_be_established(): void
    {
        [$tenantId, $actor, , , $collection] = $this->context('cancelled');
        $this->expectException(ReceivablesConflict::class);
        $this->establish($tenantId, $actor, (string) $collection->id);
    }

    public function test_same_operation_replays_and_different_canonical_result_conflicts(): void
    {
        [$tenantId, $actor, , , $collection] = $this->context();
        $operationId = (string) Str::ulid();
        $first = $this->establish($tenantId, $actor, (string) $collection->id, $operationId);
        self::assertSame($first, $this->establish($tenantId, $actor, (string) $collection->id, $operationId));

        $this->expectException(ReceivablesConflict::class);
        $this->establish($tenantId, $actor, (string) $collection->id, $operationId, '2026-08-26T10:00:01+00:00');
    }

    public function test_cancel_then_replacement_establishment_is_allowed_but_two_effective_rows_are_not(): void
    {
        [$tenantId, $actor, , , $collection] = $this->context();
        $first = $this->establish($tenantId, $actor, (string) $collection->id);

        $this->expectException(ReceivablesConflict::class);
        try {
            $this->establish($tenantId, $actor, (string) $collection->id);
        } finally {
            app(CancelReceivableAction::class)->execute($tenantId, $first, $actor, '2026-08-26T11:00:00+00:00', 'Explicit correction');
        }
    }

    public function test_after_explicit_cancellation_replacement_establishment_is_allowed(): void
    {
        [$tenantId, $actor, , , $collection] = $this->context();
        $first = $this->establish($tenantId, $actor, (string) $collection->id);
        app(CancelReceivableAction::class)->execute($tenantId, $first, $actor, '2026-08-26T11:00:00+00:00', 'Explicit correction');
        $replacement = $this->establish($tenantId, $actor, (string) $collection->id);

        self::assertNotSame($first, $replacement);
        self::assertSame(1, DB::table('receivables')->where('collection_id', $collection->id)->where('status', 'recognized')->count());
    }

    public function test_effective_receivable_blocks_amendment_and_contract_collection_cancellation_until_cancelled(): void
    {
        [$tenantId, $actor, , $contractId, $collection] = $this->context();
        $id = $this->establish($tenantId, $actor, (string) $collection->id);

        try {
            (new AmendCollectionScheduleAction)->execute($tenantId, $contractId, $actor->id, [(string) $collection->id], [], 'Correction');
            self::fail('Amendment was accepted despite an effective Receivable.');
        } catch (CollectionHasEffectiveReceivableException) {
            self::assertTrue(true);
        }
        $this->expectException(CollectionHasEffectiveReceivableException::class);
        try {
            app(CancelContractAction::class)->execute($tenantId, $contractId, $actor->id);
        } finally {
            app(CancelReceivableAction::class)->execute($tenantId, $id, $actor, '2026-08-26T11:00:00+00:00', 'Explicit correction');
        }
    }

    public function test_cancelled_receivable_allows_collection_amendment_and_contract_cancellation(): void
    {
        [$tenantId, $actor, , $contractId, $collection] = $this->context();
        $id = $this->establish($tenantId, $actor, (string) $collection->id);
        app(CancelReceivableAction::class)->execute($tenantId, $id, $actor, '2026-08-26T11:00:00+00:00', 'Explicit correction');

        (new AmendCollectionScheduleAction)->execute($tenantId, $contractId, $actor->id, [(string) $collection->id], [
            new CollectionLineData(null, 1, 'Replacement Collection', '123.45', CarbonImmutable::parse('2026-09-30'), null),
        ], 'Collection correction');
        self::assertSame('cancelled', DB::table('collections')->where('id', $collection->id)->value('status'));

        app(CancelContractAction::class)->execute($tenantId, $contractId, $actor->id);
        self::assertSame('cancelled', DB::table('contracts')->where('id', $contractId)->value('status'));
    }

    public function test_migration_accepts_cancelled_receivable_history_when_its_source_collection_was_later_cancelled(): void
    {
        $migration = $this->migration();
        $migration->down();
        [$tenantId, $actor, , , $collection] = $this->context();
        $id = $this->establish($tenantId, $actor, (string) $collection->id);
        app(CancelReceivableAction::class)->execute($tenantId, $id, $actor, '2026-08-26T11:00:00+00:00', 'Historical correction');
        $this->cancelSourceCollection($collection, $actor);

        DB::statement('SET CONSTRAINTS receivables_entitlement_final_state IMMEDIATE');
        $migration->up();

        self::assertSame('cancelled', DB::table('receivables')->where('id', $id)->value('status'));
        self::assertSame('cancelled', DB::table('collections')->where('id', $collection->id)->value('status'));
    }

    public function test_migration_fails_closed_when_an_effective_receivable_has_an_already_cancelled_source_collection(): void
    {
        $migration = $this->migration();
        $migration->down();
        [$tenantId, $actor, , , $collection] = $this->context();
        $this->establish($tenantId, $actor, (string) $collection->id);
        $this->cancelSourceCollection($collection, $actor);

        $this->expectException(\RuntimeException::class);
        $migration->up();
    }

    private function establish(string $tenantId, User $actor, string $collectionId, ?string $operationId = null, string $recognizedAt = '2026-08-26T10:00:00+00:00'): string
    {
        return app(EstablishCollectionReceivable::class)->execute($tenantId, $actor, $collectionId, $operationId ?? (string) Str::ulid(), $recognizedAt);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_26_020000_add_collection_backed_receivable_integrity.php');
    }

    private function cancelSourceCollection(Collection $collection, User $actor): void
    {
        DB::table('collections')->where('id', $collection->id)->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancellation_reason' => 'Historical source cancellation',
            'updated_at' => now(),
        ]);
    }

    private function context(string $collectionStatus = 'scheduled'): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = (string) TenantUser::query()->where('user_id', $actor->id)->value('tenant_id');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);
        $project = $this->createIntegrityProject($tenantId, $actor->id);
        $unit = $this->createIntegrityUnit($tenantId, (string) $project->id, $actor->id, 'sold');
        $reservation = $this->createIntegrityReservation($tenantId, (string) $unit->id, (string) $customer->id, $actor->id, 'converted');
        $contract = $this->createIntegrityContract($tenantId, (string) $reservation->id, $actor->id, 'active', ['total_amount' => '123.45']);
        $collection = Collection::query()->create([
            'tenant_id' => $tenantId, 'contract_id' => $contract->id, 'sequence' => 1, 'title' => 'Canonical Collection',
            'amount' => '123.45', 'due_date' => '2026-09-30', 'status' => $collectionStatus,
            'scheduled_at' => $collectionStatus === 'scheduled' ? now() : null,
            'scheduled_by' => $collectionStatus === 'scheduled' ? $actor->id : null,
            'cancelled_at' => $collectionStatus === 'cancelled' ? now() : null,
            'cancelled_by' => $collectionStatus === 'cancelled' ? $actor->id : null,
            'cancellation_reason' => $collectionStatus === 'cancelled' ? 'Fixture cancellation' : null,
            'created_by' => $actor->id,
        ]);

        return [$tenantId, $actor, (string) $customer->id, (string) $contract->id, $collection];
    }
}
