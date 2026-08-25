<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Requests\IndexPeriodsRequest;
use App\Modules\Accounting\Requests\ReopenPeriodRequest;
use App\Modules\Accounting\Requests\WritePeriodRequest;
use App\Modules\Accounting\Services\AccountingReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountingPeriodController extends AccountingController
{
    public function index(IndexPeriodsRequest $request, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['periods' => $reads->periods($tenantId, $request->validated())]]);
    }

    public function store(WritePeriodRequest $request, ManageAccountingPeriodAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'close_period');
        $data = $request->validated();
        $id = $action->create($tenantId, $actor, $data['start_date'], $data['end_date']);

        return response()->json(['data' => ['period' => $reads->period($tenantId, $id)]], 201);
    }

    public function show(Request $request, string $period, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['period' => $reads->period($tenantId, $period)]]);
    }

    public function update(WritePeriodRequest $request, string $period, ManageAccountingPeriodAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'close_period');
        $reads->period($tenantId, $period);
        $data = $request->validated();
        $action->changeBoundaries($tenantId, $period, $actor, $data['start_date'], $data['end_date']);

        return response()->json(['data' => ['period' => $reads->period($tenantId, $period)]]);
    }

    public function close(Request $request, string $period, ManageAccountingPeriodAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'close_period');
        $reads->period($tenantId, $period);
        $action->close($tenantId, $period, $actor);

        return response()->json(['data' => ['period' => $reads->period($tenantId, $period)]]);
    }

    public function reopen(ReopenPeriodRequest $request, string $period, ManageAccountingPeriodAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'reopen_period');
        $reads->period($tenantId, $period);
        $action->reopen($tenantId, $period, $actor, $request->validated('reason'));

        return response()->json(['data' => ['period' => $reads->period($tenantId, $period)]]);
    }
}
