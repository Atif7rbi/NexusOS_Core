<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use Illuminate\Support\Facades\DB;

final class AccountingJournalsApiTest extends AccountingApiTestCase
{
    public function test_manual_journal_api_supports_empty_and_lined_drafts_update_list_show_and_delete(): void
    {
        [$tenant, $admin, , $debit, $credit] = $this->ready('M');
        $this->acting($admin);

        $empty = $this->postJson('/api/accounting/journals', ['entry_date' => '2026-02-01', 'description' => 'Empty draft'])
            ->assertCreated()->assertJsonPath('data.journal.origin', 'manual')->assertJsonCount(0, 'data.journal.lines')->json('data.journal.id');
        $lined = $this->postJson('/api/accounting/journals', ['entry_date' => '2026-03-01', 'description' => 'Lined draft', 'lines' => $this->linePayload($debit, $credit)])
            ->assertCreated()->assertJsonPath('data.journal.lines.0.debit', '100.00')->json('data.journal.id');

        $this->patchJson("/api/accounting/journals/{$lined}", ['entry_date' => '2026-03-02', 'description' => 'Updated', 'lines' => $this->linePayload($debit, $credit, '75.25')])
            ->assertOk()->assertJsonPath('data.journal.entry_date', '2026-03-02')->assertJsonPath('data.journal.lines.0.debit', '75.25');
        $this->getJson('/api/accounting/journals?status=draft&origin=manual')->assertOk();
        $this->getJson("/api/accounting/journals/{$lined}")->assertOk()->assertJsonPath('data.journal.lines.1.line_number', 2);
        $this->deleteJson("/api/accounting/journals/{$empty}")->assertNoContent();
        self::assertFalse(DB::table('journal_entries')->where('id', $empty)->exists());
    }

    public function test_posting_allocates_stable_jrn_and_posted_journal_is_immutable(): void
    {
        [, $admin, , $debit, $credit] = $this->ready('P');
        $this->acting($admin);
        $journal = $this->postJson('/api/accounting/journals', ['entry_date' => '2026-03-01', 'description' => 'Post me', 'lines' => $this->linePayload($debit, $credit)])
            ->assertCreated()->json('data.journal.id');

        $this->postJson("/api/accounting/journals/{$journal}/post")->assertOk()
            ->assertJsonPath('data.result.journal_number', 'JRN-2026-001')
            ->assertJsonPath('data.journal.status', 'posted');
        $this->patchJson("/api/accounting/journals/{$journal}", ['entry_date' => '2026-03-02', 'description' => 'No', 'lines' => $this->linePayload($debit, $credit)])
            ->assertUnprocessable();
        $this->deleteJson("/api/accounting/journals/{$journal}")->assertUnprocessable();
    }

    public function test_posting_rejects_unbalanced_closed_period_archived_account_and_float_money_without_leaks(): void
    {
        [$tenant, $admin, $period, $debit, $credit] = $this->ready('V');
        $this->acting($admin);
        $unbalanced = $this->postJson('/api/accounting/journals', [
            'entry_date' => '2026-03-01', 'description' => 'Bad',
            'lines' => [['account_id' => $debit, 'debit' => '10', 'credit' => '0'], ['account_id' => $credit, 'debit' => '0', 'credit' => '9']],
        ])->assertCreated()->json('data.journal.id');
        $response = $this->postJson("/api/accounting/journals/{$unbalanced}/post")->assertUnprocessable();
        self::assertStringNotContainsString('SQLSTATE', $response->getContent());

        $this->postJson('/api/accounting/journals', ['entry_date' => '2026-03-01', 'description' => 'Float', 'lines' => [['account_id' => $debit, 'debit' => 1.2, 'credit' => 0], ['account_id' => $credit, 'debit' => 0, 'credit' => 1.2]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.debit', 'lines.1.credit']);

        $closed = $this->postJson('/api/accounting/journals', ['entry_date' => '2026-04-01', 'description' => 'Closed', 'lines' => $this->linePayload($debit, $credit)])->json('data.journal.id');
        app(ManageAccountingPeriodAction::class)->close((string) $tenant->id, $period, $admin);
        $this->postJson("/api/accounting/journals/{$closed}/post")->assertUnprocessable();
        app(ManageAccountingPeriodAction::class)->reopen((string) $tenant->id, $period, $admin, 'Continue testing');

        $archived = $this->postJson('/api/accounting/journals', ['entry_date' => '2026-05-01', 'description' => 'Archived', 'lines' => $this->linePayload($debit, $credit)])->json('data.journal.id');
        app(ManageAccountAction::class)->archive((string) $tenant->id, $debit, $admin);
        $this->postJson("/api/accounting/journals/{$archived}/post")->assertUnprocessable();
    }

    public function test_exact_reversal_supports_reversal_of_reversal_archived_accounts_and_rejects_second_target_and_bad_date(): void
    {
        [$tenant, $admin, , $debit, $credit] = $this->ready('X');
        $posted = $this->posted($tenant, $admin, $debit, $credit);
        app(ManageAccountAction::class)->archive((string) $tenant->id, $debit, $admin);
        $this->acting($admin);

        $badDate = $this->postJson("/api/accounting/journals/{$posted->journalEntryId}/reverse", ['entry_date' => '2026-02-28', 'reason' => 'Backdated'])
            ->assertUnprocessable();
        self::assertStringNotContainsString('SQLSTATE', $badDate->getContent());
        $this->postJson("/api/accounting/journals/{$posted->journalEntryId}/reverse", ['entry_date' => '2026-03-02'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $reversal = $this->postJson("/api/accounting/journals/{$posted->journalEntryId}/reverse", ['entry_date' => '2026-03-02', 'reason' => 'Correction'])
            ->assertOk()->assertJsonPath('data.journal.lines.0.debit', '0.00')->assertJsonPath('data.journal.lines.0.credit', '100.00')
            ->json('data.journal.id');
        $this->postJson("/api/accounting/journals/{$posted->journalEntryId}/reverse", ['entry_date' => '2026-03-03', 'reason' => 'Duplicate'])
            ->assertConflict();
        $this->postJson("/api/accounting/journals/{$reversal}/reverse", ['entry_date' => '2026-03-03', 'reason' => 'Reinstate'])
            ->assertOk()->assertJsonPath('data.journal.origin', 'reversal');
    }

    public function test_journal_routes_are_tenant_scoped(): void
    {
        [$tenantA, $adminA, , $debit, $credit] = $this->ready('A');
        $posted = $this->posted($tenantA, $adminA, $debit, $credit);
        [, $adminB] = $this->accountingActor();
        $this->acting($adminB);

        $this->getJson("/api/accounting/journals/{$posted->journalEntryId}")->assertNotFound();
        $this->postJson("/api/accounting/journals/{$posted->journalEntryId}/reverse", ['entry_date' => '2026-03-02', 'reason' => 'Leak'])->assertNotFound();
    }
}
