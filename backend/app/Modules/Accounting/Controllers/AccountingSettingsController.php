<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Requests\ActivateAccountingRequest;
use App\Modules\Accounting\Services\AccountingReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountingSettingsController extends AccountingController
{
    public function show(Request $request, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['settings' => $reads->settings($tenantId)]]);
    }

    public function activate(ActivateAccountingRequest $request, ActivateAccountingAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'activate');
        $action->execute($tenantId, $actor, (bool) $request->validated('idempotent', false));

        return response()->json(['data' => ['settings' => $reads->settings($tenantId)]], 201);
    }
}
