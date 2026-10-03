<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Queries\BalanceSheetQuery;
use App\Modules\Accounting\Queries\CashFlowStatementQuery;
use App\Modules\Accounting\Queries\GeneralLedgerQuery;
use App\Modules\Accounting\Queries\IncomeStatementQuery;
use App\Modules\Accounting\Queries\TrialBalanceQuery;
use App\Modules\Accounting\Requests\BalanceSheetRequest;
use App\Modules\Accounting\Requests\CashFlowStatementRequest;
use App\Modules\Accounting\Requests\ClassificationCatalogRequest;
use App\Modules\Accounting\Requests\GeneralLedgerRequest;
use App\Modules\Accounting\Requests\IncomeStatementRequest;
use App\Modules\Accounting\Requests\TrialBalanceRequest;
use App\Modules\Accounting\Support\FinancialStatementClassificationCatalog;
use Illuminate\Http\JsonResponse;

final class AccountingReportController extends AccountingController
{
    public function balanceSheet(BalanceSheetRequest $request, BalanceSheetQuery $query): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');
        $filters = $request->validated();

        return response()->json(['data' => ['balance_sheet' => $query->execute($tenantId, $filters['as_of_date'])]]);
    }

    public function cashFlow(CashFlowStatementRequest $request, CashFlowStatementQuery $query): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');
        $filters = $request->validated();

        return response()->json(['data' => ['cash_flow' => $query->execute($tenantId, $filters['from_date'], $filters['to_date'])]]);
    }

    public function incomeStatement(IncomeStatementRequest $request, IncomeStatementQuery $query): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');
        $filters = $request->validated();

        return response()->json(['data' => ['income_statement' => $query->execute($tenantId, $filters['from_date'], $filters['to_date'])]]);
    }

    public function classifications(ClassificationCatalogRequest $request): JsonResponse
    {
        $this->context($request, 'view_ledger');

        return response()->json(['data' => ['classifications' => FinancialStatementClassificationCatalog::entries()]]);
    }

    public function generalLedger(GeneralLedgerRequest $request, string $account, GeneralLedgerQuery $query): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');
        $filters = $request->validated();

        return response()->json(['data' => ['general_ledger' => $query->execute($tenantId, $account, $filters['from_date'], $filters['to_date'])]]);
    }

    public function trialBalance(TrialBalanceRequest $request, TrialBalanceQuery $query): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');
        $filters = $request->validated();

        return response()->json(['data' => ['trial_balance' => $query->execute(
            $tenantId,
            $filters['as_of_date'],
            $filters['account_type'] ?? null,
            $filters['classification'] ?? null,
            (bool) ($filters['include_zero'] ?? false),
        )]]);
    }
}
