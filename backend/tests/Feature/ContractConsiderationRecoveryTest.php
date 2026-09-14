<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ContractConsideration\Actions\RecoverContractConsiderationAdoption;
use App\Modules\ContractConsideration\Actions\ReplayContractConsideration;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationAccessDenied;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ContractConsiderationRecoveryTest extends TestCase
{
    use CreatesContractConsiderationFixtures;

    private function recover(array $c): array
    {
        return app(RecoverContractConsiderationAdoption::class)->execute($c['tenant_id'], $c['actor'], $this->adoptionInput($c));
    }

    public function test_absent_clean_operation_is_retryable_without_writing(): void
    {
        $c = $this->considerationContext();
        self::assertSame(['status' => 'retryable', 'position_id' => null, 'genesis_lot_id' => null], $this->recover($c));
        self::assertSame(0, DB::table('contract_consideration_positions')->where('tenant_id', $c['tenant_id'])->count());
    }

    public function test_lost_response_recovers_original_ids_and_is_idempotent(): void
    {
        $c = $this->considerationContext();
        $committed = $this->adopt($c);
        self::assertSame($committed, $this->recover($c));
        self::assertSame($committed, $this->recover($c));
    }

    public function test_rolled_back_adoption_can_retry_with_the_same_operation(): void
    {
        $c = $this->considerationContext();
        DB::beginTransaction();
        $this->adopt($c);
        DB::rollBack();
        self::assertSame('retryable', $this->recover($c)['status']);
        $committed = $this->adopt($c);
        self::assertSame($committed, $this->recover($c));
    }

    public function test_recovery_requires_a_fresh_transaction_boundary(): void
    {
        $c = $this->considerationContext();
        DB::beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            $this->recover($c);
        } finally {
            DB::rollBack();
        }
    }

    public function test_different_operation_is_conflict_not_retryable(): void
    {
        $c = $this->considerationContext();
        $this->adopt($c);
        $c['operation_id'] = (string) Str::ulid();
        $this->expectException(ContractConsiderationConflict::class);
        $this->recover($c);
    }

    public function test_recovery_rechecks_current_authority(): void
    {
        $c = $this->considerationContext();
        $this->adopt($c);
        DB::table('tenant_users')->where('tenant_id', $c['tenant_id'])->where('user_id', $c['actor']->id)->update(['status' => 'suspended']);
        $this->expectException(ContractConsiderationAccessDenied::class);
        $this->recover($c);
    }

    public function test_recovery_rejects_absent_adoption_after_supported_source_activation(): void
    {
        $c = $this->considerationContext();
        DB::transaction(fn () => $this->handoverSource($c));
        $this->expectException(ContractConsiderationConflict::class);
        $this->recover($c);
    }

    public function test_replay_after_source_reversal_retains_exact_historical_graph(): void
    {
        $c = $this->considerationContext();
        $adoption = $this->adopt($c);
        [$source, $graph] = DB::transaction(function () use ($c, $adoption): array {
            $source = $this->handoverSource($c);

            return [$source, $this->transition($c, $adoption, $source, $adoption['genesis_lot_id'], 'EARNED_UNBILLED')];
        });
        $replay = app(ReplayContractConsideration::class);
        $before = $replay->execute($c['tenant_id'], $c['actor'], $this->adoptionInput($c));
        DB::transaction(fn () => $this->reverseHandover($c, $source, $graph));
        $after = $replay->execute($c['tenant_id'], $c['actor'], $this->adoptionInput($c));
        self::assertSame($before['lots'], $after['lots']);
        self::assertSame($before['transition_lots'], $after['transition_lots']);
        self::assertSame($before['transitions'][0]['id'], $after['transitions'][0]['id']);
        self::assertSame('reversed', $after['transitions'][0]['status']);
        self::assertSame($adoption, $this->recover($c));
        self::assertSame($after, $replay->execute($c['tenant_id'], $c['actor'], $this->adoptionInput($c)));
    }
}
