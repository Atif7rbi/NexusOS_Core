<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Actions\ManageOpeningBalanceAction;
use App\Modules\Accounting\Http\AccountingLineMapper;
use App\Modules\Accounting\Requests\IndexOpeningBalancesRequest;
use App\Modules\Accounting\Requests\WriteOpeningBalanceRequest;
use App\Modules\Accounting\Services\AccountingReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class OpeningBalanceController extends AccountingController
{
    public function index(IndexOpeningBalancesRequest $request, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['opening_balances' => $reads->openingBalances($tenantId, $request->validated())]]);
    }

    public function store(WriteOpeningBalanceRequest $request, ManageOpeningBalanceAction $action, AccountingLineMapper $lines, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'manage_opening_balance');
        $data = $request->validated();
        $id = $action->create($tenantId, $actor, $data['accounting_date'], $lines->map($data['lines']));

        return response()->json(['data' => ['opening_balance' => $reads->openingBalance($tenantId, $id)]], 201);
    }

    public function show(Request $request, string $operation, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['opening_balance' => $reads->openingBalance($tenantId, $operation)]]);
    }

    public function update(WriteOpeningBalanceRequest $request, string $operation, ManageOpeningBalanceAction $action, AccountingLineMapper $lines, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'manage_opening_balance');
        $reads->openingBalance($tenantId, $operation);
        $data = $request->validated();
        $action->update($tenantId, $operation, $actor, $data['accounting_date'], $lines->map($data['lines']));

        return response()->json(['data' => ['opening_balance' => $reads->openingBalance($tenantId, $operation)]]);
    }

    public function destroy(Request $request, string $operation, ManageOpeningBalanceAction $action, AccountingReadService $reads): Response
    {
        [$tenantId, $actor] = $this->context($request, 'manage_opening_balance');
        $reads->openingBalance($tenantId, $operation);
        $action->delete($tenantId, $operation, $actor);

        return response()->noContent();
    }

    public function post(Request $request, string $operation, ManageOpeningBalanceAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'manage_opening_balance');
        $reads->openingBalance($tenantId, $operation);
        $result = $action->post($tenantId, $operation, $actor);

        return response()->json(['data' => ['result' => ['journal_entry_id' => $result->journalEntryId, 'journal_number' => $result->journalNumber, 'idempotent_replay' => $result->idempotentReplay], 'opening_balance' => $reads->openingBalance($tenantId, $operation)]]);
    }
}
