<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Queries\GeneralLedgerQuery;
use App\Modules\Accounting\Queries\TrialBalanceQuery;

final class AccountingGeneralLedgerTest extends AccountingApiTestCase
{
    public function test_dates_archived_empty_and_trial_balance_reconciliation(): void
    {
        [$tenant, $actor, , $cash, $equity] = $this->ready('GT');
        $empty = $this->account($tenant, $actor, '5999', 'expense', 'operating_expense');
        $this->posted($tenant, $actor, $cash, $equity, '2026-01-01');
        $this->posted($tenant, $actor, $cash, $equity, '2026-06-01');
        $this->posted($tenant, $actor, $cash, $equity, '2026-09-30');
        $this->posted($tenant, $actor, $cash, $equity, '2026-12-31');
        app(ManageAccountAction::class)->archive((string) $tenant->id, $cash, $actor);

        $ledger = app(GeneralLedgerQuery::class)->execute((string) $tenant->id, $cash, '2026-06-01', '2026-09-30');
        self::assertSame('100.00', $ledger['opening_balance']);
        self::assertCount(2, $ledger['movements']);
        self::assertSame(['2026-06-01', '2026-09-30'], array_column($ledger['movements'], 'entry_date'));
        self::assertSame('300.00', $ledger['closing_balance']);
        self::assertSame('archived', $ledger['account']['status']);
        self::assertSame('300.00', collect(app(TrialBalanceQuery::class)->execute((string) $tenant->id, '2026-09-30')['rows'])->firstWhere('account_id', $cash)['normal_balance']);

        $none = app(GeneralLedgerQuery::class)->execute((string) $tenant->id, $empty, '2026-01-01', '2026-12-31');
        self::assertSame('0.00', $none['opening_balance']);
        self::assertSame([], $none['movements']);
        self::assertSame('0.00', $none['closing_balance']);
    }

    public function test_exact_decimal_duplicate_lines_reversal_and_normal_sides_are_preserved(): void
    {
        [$tenant, $actor, , $cash, $equity] = $this->ready('GD');
        $this->postJournalWithLines((string) $tenant->id, $actor, '2026-01-01', 'Opening decimal', $this->lineData($cash, $equity, '0.10'));
        $journalId = $this->postJournalWithLines((string) $tenant->id, $actor, '2026-02-01', 'Duplicate cash lines', [
            new JournalLineData($cash, '0.10', '0', 'First cash line'),
            new JournalLineData($cash, '0.20', '0', 'Second cash line'),
            new JournalLineData($equity, '0', '0.30', 'Equity line'),
        ]);
        app(ReverseJournalAction::class)->execute((string) $tenant->id, $journalId, $actor, '2026-02-02', 'Reverse duplicate cash lines');

        $asset = app(GeneralLedgerQuery::class)->execute((string) $tenant->id, $cash, '2026-02-01', '2026-02-28');
        self::assertSame('0.10', $asset['opening_balance']);
        self::assertCount(4, $asset['movements']);
        self::assertSame(['0.10', '0.20', '0.00', '0.00'], array_column($asset['movements'], 'debit'));
        self::assertSame(['0.00', '0.00', '0.10', '0.20'], array_column($asset['movements'], 'credit'));
        self::assertSame(['0.20', '0.40', '0.30', '0.10'], array_column($asset['movements'], 'running_balance'));
        self::assertSame('0.10', $asset['closing_balance']);

        $creditNormal = app(GeneralLedgerQuery::class)->execute((string) $tenant->id, $equity, '2026-02-01', '2026-02-28');
        self::assertSame('0.10', $creditNormal['opening_balance']);
        self::assertSame('0.10', $creditNormal['closing_balance']);
    }

    /** @param list<JournalLineData> $lines */
    private function postJournalWithLines(string $tenantId, User $actor, string $date, string $description, array $lines): string
    {
        $id = app(ManageManualJournalAction::class)->create($tenantId, $actor, $date, $description, $lines);
        app(ManageManualJournalAction::class)->post($tenantId, $id, $actor);

        return $id;
    }
}
