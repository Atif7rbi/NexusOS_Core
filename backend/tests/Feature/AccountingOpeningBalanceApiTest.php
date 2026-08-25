<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

final class AccountingOpeningBalanceApiTest extends AccountingApiTestCase
{
    public function test_opening_balance_api_supports_create_list_show_update_and_delete_draft(): void
    {
        [, $admin, , $debit, $credit] = $this->ready('O');
        $this->acting($admin);
        $operation = $this->postJson('/api/accounting/opening-balance', ['accounting_date' => '2026-01-01', 'lines' => $this->linePayload($debit, $credit)])
            ->assertCreated()->assertJsonPath('data.opening_balance.status', 'draft')->json('data.opening_balance.operation_id');

        $this->getJson('/api/accounting/opening-balance?status=draft')->assertOk()->assertJsonPath('data.opening_balances.data.0.operation_id', $operation);
        $this->getJson("/api/accounting/opening-balance/{$operation}")->assertOk()->assertJsonPath('data.opening_balance.root_journal.lines.0.debit', '100.00');
        $this->patchJson("/api/accounting/opening-balance/{$operation}", ['accounting_date' => '2026-01-02', 'lines' => $this->linePayload($debit, $credit, '125.50')])
            ->assertOk()->assertJsonPath('data.opening_balance.accounting_date', '2026-01-02')->assertJsonPath('data.opening_balance.root_journal.lines.0.debit', '125.50');
        $this->deleteJson("/api/accounting/opening-balance/{$operation}")->assertNoContent();
        self::assertFalse(DB::table('opening_balance_operations')->where('id', $operation)->exists());
    }

    public function test_opening_post_slot_reversal_replacement_reactivation_and_historical_floor_are_end_to_end(): void
    {
        [, $admin, , $debit, $credit] = $this->ready('F');
        $this->acting($admin);

        $first = $this->postJson('/api/accounting/opening-balance', ['accounting_date' => '2026-01-01', 'lines' => $this->linePayload($debit, $credit)])
            ->assertCreated()->json('data.opening_balance.operation_id');
        $this->postJson('/api/accounting/opening-balance', ['accounting_date' => '2026-01-01', 'lines' => $this->linePayload($debit, $credit)])
            ->assertConflict();
        $rootOne = $this->postJson("/api/accounting/opening-balance/{$first}/post")->assertOk()
            ->assertJsonPath('data.opening_balance.effect_state', 'effective')->json('data.result.journal_entry_id');
        $neutralizerOne = $this->postJson("/api/accounting/journals/{$rootOne}/reverse", ['entry_date' => '2026-03-01', 'reason' => 'Replace opening'])
            ->assertOk()->json('data.result.journal_entry_id');
        $this->getJson("/api/accounting/opening-balance/{$first}")->assertOk()->assertJsonPath('data.opening_balance.effect_state', 'neutralized');

        $replacement = $this->postJson('/api/accounting/opening-balance', ['accounting_date' => '2026-03-01', 'lines' => $this->linePayload($debit, $credit, '110.00')])
            ->assertCreated()->json('data.opening_balance.operation_id');
        $rootTwo = $this->postJson("/api/accounting/opening-balance/{$replacement}/post")->assertOk()->json('data.result.journal_entry_id');
        $this->postJson("/api/accounting/journals/{$rootTwo}/reverse", ['entry_date' => '2026-04-01', 'reason' => 'Neutralize replacement'])
            ->assertOk();

        $this->postJson("/api/accounting/journals/{$neutralizerOne}/reverse", ['entry_date' => '2026-03-15', 'reason' => 'Backdated reactivation'])
            ->assertUnprocessable();
        $this->postJson("/api/accounting/journals/{$neutralizerOne}/reverse", ['entry_date' => '2026-04-01', 'reason' => 'Valid reactivation'])
            ->assertOk();
        $this->getJson("/api/accounting/opening-balance/{$first}")->assertOk()
            ->assertJsonPath('data.opening_balance.effect_state', 'effective');
    }

    public function test_opening_posted_operation_cannot_be_edited_or_deleted_and_cross_tenant_access_is_hidden(): void
    {
        [, $adminA, , $debit, $credit] = $this->ready('T');
        $this->acting($adminA);
        $operation = $this->postJson('/api/accounting/opening-balance', ['accounting_date' => '2026-01-01', 'lines' => $this->linePayload($debit, $credit)])->json('data.opening_balance.operation_id');
        $this->postJson("/api/accounting/opening-balance/{$operation}/post")->assertOk();
        $this->patchJson("/api/accounting/opening-balance/{$operation}", ['accounting_date' => '2026-01-02', 'lines' => $this->linePayload($debit, $credit)])->assertUnprocessable();
        $this->deleteJson("/api/accounting/opening-balance/{$operation}")->assertUnprocessable();

        [, $adminB] = $this->accountingActor();
        $this->acting($adminB);
        $this->getJson("/api/accounting/opening-balance/{$operation}")->assertNotFound();
        $this->postJson("/api/accounting/opening-balance/{$operation}/post")->assertNotFound();
    }
}
