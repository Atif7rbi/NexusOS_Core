<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ContractConsideration\Actions\AdoptContractConsideration;
use App\Modules\ContractConsideration\Actions\ReplayContractConsideration;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationAccessDenied;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationConflict;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationValidationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesContractConsiderationFixtures;
use Tests\TestCase;

final class ContractConsiderationAdoptionTest extends TestCase
{
    use CreatesContractConsiderationFixtures;

    use RefreshDatabase;

    public function test_adoption_commits_exact_capacity_and_one_genesis_and_replays_original_ids(): void
    {
        $c = $this->considerationContext();
        $first = $this->adopt($c);
        self::assertSame($first, $this->adopt($c));
        self::assertSame('committed', $first['status']);
        $root = DB::table('contract_consideration_positions')->where('id', $first['position_id'])->first();
        self::assertSame('1000.00', $root->consideration_amount);
        self::assertSame('ADOPTED', $root->status);
        self::assertSame($c['operation_id'], $root->coordination_adoption_operation_id);
        self::assertSame('CLEAN_NO_PRIOR_SUPPORTED_SOURCES', $root->adoption_basis);
        $graph = app(ReplayContractConsideration::class)->execute($c['tenant_id'], $c['actor'], $this->adoptionInput($c));
        self::assertCount(1, $graph['lots']);
        self::assertSame($first['genesis_lot_id'], $graph['lots'][0]['id']);
        self::assertSame('UNPERFORMED_UNBILLED', $graph['lots'][0]['semantic_position']);
        self::assertSame([], $graph['transitions']);
        self::assertSame([], $graph['transition_lots']);
    }

    public function test_other_operation_cannot_readopt_the_same_contract(): void
    {
        $c = $this->considerationContext();
        $this->adopt($c);
        $c['operation_id'] = (string) Str::ulid();
        $this->expectException(ContractConsiderationConflict::class);
        $this->adopt($c);
    }

    public function test_cross_tenant_actor_cannot_adopt_or_replay(): void
    {
        $c = $this->considerationContext();
        $other = $this->considerationContext();
        $this->expectException(ContractConsiderationAccessDenied::class);
        app(AdoptContractConsideration::class)->execute($c['tenant_id'], $other['actor'], $this->adoptionInput($c));
    }

    public function test_caller_cannot_supply_capacity_or_scope(): void
    {
        $c = $this->considerationContext();
        $this->expectException(ContractConsiderationValidationFailed::class);
        app(AdoptContractConsideration::class)->execute($c['tenant_id'], $c['actor'], $this->adoptionInput($c) + ['consideration_amount' => '1.00']);
    }

    public function test_invalid_operation_ulid_is_rejected(): void
    {
        $c = $this->considerationContext();
        $c['operation_id'] = 'not-an-operation';
        $this->expectException(ContractConsiderationValidationFailed::class);
        $this->adopt($c);
    }

    public function test_current_authority_is_required_even_for_exact_replay(): void
    {
        $c = $this->considerationContext();
        $this->adopt($c);
        DB::table('tenant_users')->where('tenant_id', $c['tenant_id'])->where('user_id', $c['actor']->id)->update(['status' => 'suspended']);
        $this->expectException(ContractConsiderationAccessDenied::class);
        $this->adopt($c);
    }

    public function test_effective_handover_prevents_clean_adoption(): void
    {
        $c = $this->considerationContext();
        DB::transaction(fn () => $this->handoverSource($c));
        $this->expectException(ContractConsiderationConflict::class);
        $this->adopt($c);
    }

    public function test_effective_billing_prevents_clean_adoption(): void
    {
        $c = $this->considerationContext();
        $ids = $this->billingObligations($c);
        $this->billingSource($c, $ids[0]);
        $this->expectException(ContractConsiderationConflict::class);
        $this->adopt($c);
    }

    public function test_reversed_source_history_does_not_block_clean_adoption(): void
    {
        $c = $this->considerationContext();
        $source = DB::transaction(fn () => $this->handoverSource($c));
        DB::transaction(fn () => $this->reverseHandover($c, $source, null));
        self::assertSame('committed', $this->adopt($c)['status']);
        self::assertSame('reversed', DB::table('unit_handover_acceptances')->where('id', $source['id'])->value('status'));
    }

}
