<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Support;

use App\Modules\ContractConsideration\Exceptions\ContractConsiderationIntegrityFailed;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Historical replay under the already locked Contract and Position. Never reconstructs missing rows. */
final class ContractConsiderationHistory
{
    public function replay(string $tenantId, object $position): array
    {
        $transitions = DB::table('contract_consideration_transitions')->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)->orderBy('economic_date')->orderBy('semantic_precedence')
            ->orderBy('source_id')->get();
        foreach ($transitions as $transition) {
            $handover = $transition->source_type === 'UNIT_HANDOVER_ACCEPTANCE';
            $table = $handover ? 'unit_handover_acceptances' : 'contractual_billing_entitlements';
            $source = DB::table($table)->where('tenant_id', $tenantId)->where('id', $transition->source_id)->lockForUpdate()->first();
            if ($source === null || $source->contract_id !== $position->contract_id || $source->status !== $transition->status
                || $source->currency !== $transition->currency
                || ($handover ? $source->performance_date : $source->economic_date) !== $transition->economic_date
                || ! BigDecimal::of($handover ? $source->performance_amount : $source->amount)->isEqualTo($transition->transition_amount)
                || ($transition->status === 'reversed' && $source->reversal_operation_id !== $transition->reversal_source_operation_id)) {
                $this->invalid('Transition differs from canonical source history.');
            }
        }
        // Source locks precede historical graph locks; all graph writers hold the same root.
        $transitions = DB::table('contract_consideration_transitions')->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)->orderBy('economic_date')->orderBy('semantic_precedence')
            ->orderBy('source_id')->get();
        $lots = DB::table('contract_consideration_lots')->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)->orderBy('id')->get();
        $edges = DB::table('contract_consideration_transition_lots')->where('tenant_id', $tenantId)
            ->where('position_id', $position->id)->orderBy('transition_id')->orderBy('lot_id')->orderBy('id')->get();
        $byTransition = $transitions->keyBy('id');
        $byLot = $lots->keyBy('id');
        $genesis = $lots->where('lot_kind', 'GENESIS');
        if ($genesis->count() !== 1 || ! BigDecimal::of($genesis->first()->amount)->isEqualTo($position->consideration_amount)) {
            $this->invalid('Missing or inconsistent historical genesis.');
        }
        $incoming = $outgoing = $consumed = $produced = [];
        foreach ($edges as $edge) {
            $owner = $byTransition->get($edge->transition_id);
            $from = $byLot->get($edge->lot_id);
            $to = $byLot->get($edge->successor_lot_id);
            if ($owner === null || $from === null || $to === null || $to->transition_id !== $owner->id) {
                $this->invalid('Missing historical consumption provenance.');
            }
            $prior = $byTransition->get($from->transition_id);
            if ($prior !== null && ($this->order($prior) >= $this->order($owner)
                || ($owner->status === 'effective' && $prior->status !== 'effective'))) {
                $this->invalid('Historical consumption has invalid canonical order or lifecycle.');
            }
            $grammar = $owner->source_type === 'UNIT_HANDOVER_ACCEPTANCE'
                ? ['UNPERFORMED_UNBILLED' => 'EARNED_UNBILLED', 'BILLED_UNEARNED' => 'BILLED_EARNED']
                : ['UNPERFORMED_UNBILLED' => 'BILLED_UNEARNED', 'EARNED_UNBILLED' => 'BILLED_EARNED'];
            if (($grammar[$from->semantic_position] ?? null) !== $to->semantic_position) {
                $this->invalid('Historical consumption violates source movement grammar.');
            }
            $this->add($incoming, $to->id, $edge->consumed_amount);
            $this->add($consumed, $owner->id, $edge->consumed_amount);
            if ($owner->status === 'effective') {
                $this->add($outgoing, $from->id, $edge->consumed_amount);
            }
        }
        foreach ($lots as $lot) {
            if (BigDecimal::of($outgoing[$lot->id] ?? '0')->isGreaterThan($lot->amount)) {
                $this->invalid('Historical lot is over-consumed.');
            }
            if ($lot->transition_id !== null) {
                if (! BigDecimal::of($incoming[$lot->id] ?? '0')->isEqualTo($lot->amount)) {
                    $this->invalid('Historical output is missing exact incoming consumption.');
                }
                $this->add($produced, $lot->transition_id, $lot->amount);
            } elseif (isset($incoming[$lot->id])) {
                $this->invalid('Genesis cannot have incoming consumption.');
            }
        }
        foreach ($transitions as $transition) {
            if (! BigDecimal::of($consumed[$transition->id] ?? '0')->isEqualTo($transition->transition_amount)
                || ! BigDecimal::of($produced[$transition->id] ?? '0')->isEqualTo($transition->transition_amount)) {
                $this->invalid('Transition has partial historical truth.');
            }
        }

        return [
            'position' => (array) $position,
            'transitions' => $transitions->map(fn (object $row): array => (array) $row)->all(),
            'lots' => $lots->map(fn (object $row): array => (array) $row)->all(),
            'transition_lots' => $edges->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    private function order(object $transition): array
    {
        return [$transition->economic_date, (int) $transition->semantic_precedence, $transition->source_id];
    }

    private function add(array &$totals, string $id, string $amount): void
    {
        $totals[$id] = (string) BigDecimal::of($totals[$id] ?? '0')->plus($amount);
    }

    private function invalid(string $message): never
    {
        throw new ContractConsiderationIntegrityFailed($message);
    }
}
