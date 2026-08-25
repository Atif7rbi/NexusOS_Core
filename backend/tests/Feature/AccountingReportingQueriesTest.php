<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Queries\BalanceSheetQuery;
use App\Modules\Accounting\Queries\GeneralLedgerQuery;
use App\Modules\Accounting\Queries\IncomeStatementQuery;
use App\Modules\Accounting\Queries\TrialBalanceQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccountingReportingQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_balance_is_posted_only_as_of_exact_tenant_scoped_and_includes_archived_history(): void
    {
        [$tenant,$actor]=$this->ready();
        $cash=$this->account($tenant,$actor,'1000','asset','current_asset');
        $equity=$this->account($tenant,$actor,'3000','equity','equity');
        $query=app(TrialBalanceQuery::class);

        $empty=$query->execute((string) $tenant->id,'2025-12-31',includeZero:true);
        self::assertCount(2,$empty['rows']);
        self::assertSame('0.00',$empty['debit_total']);

        $this->draftJournal($tenant,$actor,'2026-01-01',[[$cash,'999.00','0'],[$equity,'0','999.00']]);
        $first=$this->postJournal($tenant,$actor,'2026-01-01',[[$cash,'0.10','0'],[$equity,'0','0.10']]);
        $second=$this->postJournal($tenant,$actor,'2026-01-02',[[$cash,'0.20','0'],[$equity,'0','0.20']]);
        $this->postJournal($tenant,$actor,'2027-01-01',[[$cash,'5.00','0'],[$equity,'0','5.00']]);
        app(ReverseJournalAction::class)->execute((string) $tenant->id,$second,$actor,'2026-01-03','Reference reversal');
        app(ManageAccountAction::class)->archive((string) $tenant->id,$cash,$actor);

        [$other,$otherActor]=$this->ready();
        $otherCash=$this->account($other,$otherActor,'1000','asset','current_asset');
        $otherEquity=$this->account($other,$otherActor,'3000','equity','equity');
        $this->postJournal($other,$otherActor,'2026-01-01',[[$otherCash,'50.00','0'],[$otherEquity,'0','50.00']]);

        $beforeReversal=$query->execute((string) $tenant->id,'2026-01-02');
        self::assertSame('0.30',$beforeReversal['debit_total']);
        $result=$query->execute((string) $tenant->id,'2026-12-31');
        self::assertSame('0.50',$result['debit_total']);
        self::assertSame('0.50',$result['credit_total']);
        self::assertTrue($result['is_balanced']);
        self::assertSame(['1000','3000'],array_column($result['rows'],'code'));
        self::assertSame('archived',$result['rows'][0]['status']);
        self::assertSame('0.10',$result['rows'][0]['signed_balance']);
        self::assertSame('0.10',$result['rows'][1]['normal_balance']);
        self::assertCount(1,$query->execute((string) $tenant->id,'2026-12-31',accountType:'asset')['rows']);
        self::assertCount(1,$query->execute((string) $tenant->id,'2026-12-31',classification:'equity')['rows']);
        self::assertSame($first,DB::table('journal_entries')->where('id',$first)->value('id'));
    }

    public function test_income_statement_uses_all_frozen_classifications_and_inclusive_ranges(): void
    {
        [$tenant,$actor]=$this->ready();
        $cash=$this->account($tenant,$actor,'1000','asset','current_asset');
        $operatingRevenue=$this->account($tenant,$actor,'4000','revenue','operating_revenue');
        $otherRevenue=$this->account($tenant,$actor,'4100','revenue','other_revenue');
        $cost=$this->account($tenant,$actor,'5000','expense','cost_of_revenue');
        $operatingExpense=$this->account($tenant,$actor,'5100','expense','operating_expense');
        $finance=$this->account($tenant,$actor,'5200','expense','finance_cost');
        $otherExpense=$this->account($tenant,$actor,'5300','expense','other_expense');

        $this->postJournal($tenant,$actor,'2026-01-01',[[$cash,'100.00','0'],[$operatingRevenue,'0','100.00']]);
        $reversed=$this->postJournal($tenant,$actor,'2026-01-02',[[$cash,'20.00','0'],[$otherRevenue,'0','20.00']]);
        app(ReverseJournalAction::class)->execute((string) $tenant->id,$reversed,$actor,'2026-01-03','Remove other revenue');
        $this->postJournal($tenant,$actor,'2026-01-31',[[$cost,'10.00','0'],[$cash,'0','10.00']]);
        $this->postJournal($tenant,$actor,'2026-02-01',[[$operatingExpense,'20.00','0'],[$cash,'0','20.00']]);
        $this->postJournal($tenant,$actor,'2026-02-02',[[$finance,'5.00','0'],[$cash,'0','5.00']]);
        $this->postJournal($tenant,$actor,'2026-02-03',[[$otherExpense,'3.00','0'],[$cash,'0','3.00']]);
        $this->draftJournal($tenant,$actor,'2026-02-03',[[$otherExpense,'900.00','0'],[$cash,'0','900.00']]);

        $result=app(IncomeStatementQuery::class)->execute((string) $tenant->id,'2026-01-01','2026-02-03');
        self::assertSame('100.00',$result['operating_revenue']);
        self::assertSame('0.00',$result['other_revenue']);
        self::assertSame('10.00',$result['cost_of_revenue']);
        self::assertSame('20.00',$result['operating_expense']);
        self::assertSame('5.00',$result['finance_cost']);
        self::assertSame('3.00',$result['other_expense']);
        self::assertSame('100.00',$result['revenue']);
        self::assertSame('38.00',$result['expense']);
        self::assertSame('62.00',$result['net_income']);

        $boundary=app(IncomeStatementQuery::class)->execute((string) $tenant->id,'2026-01-31','2026-01-31');
        self::assertSame('10.00',$boundary['cost_of_revenue']);
    }

    public function test_balance_sheet_handles_unclosed_expense_complete_and_partial_closing_without_double_counting(): void
    {
        [$tenant,$actor]=$this->ready();
        $cash=$this->account($tenant,$actor,'1000','asset','current_asset');
        $revenue=$this->account($tenant,$actor,'4000','revenue','operating_revenue');
        $expense=$this->account($tenant,$actor,'5000','expense','operating_expense');
        $equity=$this->account($tenant,$actor,'3000','equity','equity');
        $query=app(BalanceSheetQuery::class);

        $this->postJournal($tenant,$actor,'2026-01-01',[[$cash,'100.00','0'],[$revenue,'0','100.00']]);
        $noClosing=$query->execute((string) $tenant->id,'2026-01-01');
        self::assertSame('100.00',$noClosing['assets']);
        self::assertSame('100.00',$noClosing['derived_unclosed_earnings']);
        self::assertTrue($noClosing['is_balanced']);

        $this->postJournal($tenant,$actor,'2026-01-02',[[$expense,'40.00','0'],[$cash,'0','40.00']]);
        app(ManageAccountAction::class)->archive((string) $tenant->id,$cash,$actor);
        $expenseCase=$query->execute((string) $tenant->id,'2026-01-02');
        self::assertSame('60.00',$expenseCase['assets']);
        self::assertSame('60.00',$expenseCase['derived_unclosed_earnings']);
        self::assertTrue($expenseCase['is_balanced']);

        $this->postJournal($tenant,$actor,'2026-12-31',[[$revenue,'100.00','0'],[$expense,'0','40.00'],[$equity,'0','60.00']]);
        $closed=$query->execute((string) $tenant->id,'2026-12-31');
        self::assertSame('0.00',$closed['derived_unclosed_earnings']);
        self::assertSame('60.00',$closed['equity']);
        self::assertTrue($closed['is_balanced']);

        [$partialTenant,$partialActor]=$this->ready();
        $partialCash=$this->account($partialTenant,$partialActor,'1000','asset','current_asset');
        $partialRevenue=$this->account($partialTenant,$partialActor,'4000','revenue','operating_revenue');
        $partialEquity=$this->account($partialTenant,$partialActor,'3000','equity','equity');
        $this->postJournal($partialTenant,$partialActor,'2026-01-01',[[$partialCash,'100.00','0'],[$partialRevenue,'0','100.00']]);
        $this->postJournal($partialTenant,$partialActor,'2026-12-31',[[$partialRevenue,'40.00','0'],[$partialEquity,'0','40.00']]);
        $partial=$query->execute((string) $partialTenant->id,'2026-12-31');
        self::assertSame('40.00',$partial['equity']);
        self::assertSame('60.00',$partial['derived_unclosed_earnings']);
        self::assertTrue($partial['is_balanced']);
    }

    public function test_general_ledger_has_exact_normal_side_opening_running_closing_and_deterministic_order(): void
    {
        [$tenant,$actor]=$this->ready();
        $cash=$this->account($tenant,$actor,'1000','asset','current_asset');
        $equity=$this->account($tenant,$actor,'3000','equity','equity');
        $this->postJournal($tenant,$actor,'2026-01-01',[[$cash,'100.00','0'],[$equity,'0','100.00']]);
        $creditJournal=$this->postJournal($tenant,$actor,'2026-02-01',[[$equity,'20.00','0'],[$cash,'0','20.00']]);
        $this->postJournal($tenant,$actor,'2026-02-01',[[$cash,'5.00','0'],[$equity,'0','5.00']]);
        app(ReverseJournalAction::class)->execute((string) $tenant->id,$creditJournal,$actor,'2026-02-02','Restore cash');
        $this->draftJournal($tenant,$actor,'2026-02-02',[[$cash,'999.00','0'],[$equity,'0','999.00']]);

        $asset=app(GeneralLedgerQuery::class)->execute((string) $tenant->id,$cash,'2026-02-01','2026-02-28');
        self::assertSame('100.00',$asset['opening_balance']);
        self::assertSame(['80.00','85.00','105.00'],array_column($asset['movements'],'running_balance'));
        self::assertSame('105.00',$asset['closing_balance']);
        self::assertSame(['2026-02-01','2026-02-01','2026-02-02'],array_column($asset['movements'],'entry_date'));

        $creditNormal=app(GeneralLedgerQuery::class)->execute((string) $tenant->id,$equity,'2026-02-01','2026-02-01');
        self::assertSame('100.00',$creditNormal['opening_balance']);
        self::assertSame('85.00',$creditNormal['closing_balance']);
        $empty=app(GeneralLedgerQuery::class)->execute((string) $tenant->id,$cash,'2026-03-01','2026-03-31');
        self::assertSame('105.00',$empty['opening_balance']);
        self::assertSame([],$empty['movements']);
        self::assertSame('105.00',$empty['closing_balance']);

        [$other]=$this->ready();
        $this->expectException(ModelNotFoundException::class);
        app(GeneralLedgerQuery::class)->execute((string) $other->id,$cash,'2026-01-01','2026-12-31');
    }

    public function test_reference_queries_run_with_runtime_role_select_privileges(): void
    {
        [$tenant,$actor]=$this->ready();
        $cash=$this->account($tenant,$actor,'1000','asset','current_asset');
        $revenue=$this->account($tenant,$actor,'4000','revenue','operating_revenue');
        $this->postJournal($tenant,$actor,'2026-01-01',[[$cash,'10.00','0'],[$revenue,'0','10.00']]);
        $role=(string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');

        DB::statement('SET ROLE "'.$role.'"');
        try {
            self::assertTrue(app(TrialBalanceQuery::class)->execute((string) $tenant->id,'2026-12-31')['is_balanced']);
            self::assertSame('10.00',app(BalanceSheetQuery::class)->execute((string) $tenant->id,'2026-12-31')['assets']);
            self::assertSame('10.00',app(IncomeStatementQuery::class)->execute((string) $tenant->id,'2026-01-01','2026-12-31')['net_income']);
            self::assertSame('10.00',app(GeneralLedgerQuery::class)->execute((string) $tenant->id,$cash,'2026-01-01','2026-12-31')['closing_balance']);
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    /** @return array{Tenant,User} */
    private function ready(): array
    {
        $tenant=Tenant::factory()->create(['currency'=>'SAR']);
        $actor=User::factory()->create(['role'=>User::ROLE_ADMINISTRATOR,'status'=>User::STATUS_ACTIVE]);
        TenantUser::factory()->forTenant($tenant)->forUser($actor)->active()->create();
        app(ActivateAccountingAction::class)->execute((string) $tenant->id,$actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id,$actor,'2025-01-01','2027-12-31');

        return [$tenant,$actor];
    }

    private function account(Tenant $tenant, User $actor, string $code, string $type, string $classification): string
    {
        return app(ManageAccountAction::class)->create((string) $tenant->id,$actor,[
            'code'=>$code,'name'=>'Account '.$code,'description'=>null,'kind'=>'posting',
            'account_type'=>$type,'classification'=>$classification,'parent_id'=>null,
        ]);
    }

    /** @param list<array{string,string,string}> $lines */
    private function draftJournal(Tenant $tenant, User $actor, string $date, array $lines): string
    {
        return app(ManageManualJournalAction::class)->create((string) $tenant->id,$actor,$date,'Reporting fixture',array_map(
            static fn (array $line): JournalLineData => new JournalLineData($line[0],$line[1],$line[2]),
            $lines,
        ));
    }

    /** @param list<array{string,string,string}> $lines */
    private function postJournal(Tenant $tenant, User $actor, string $date, array $lines): string
    {
        $journal=$this->draftJournal($tenant,$actor,$date,$lines);
        app(ManageManualJournalAction::class)->post((string) $tenant->id,$journal,$actor);

        return $journal;
    }
}
