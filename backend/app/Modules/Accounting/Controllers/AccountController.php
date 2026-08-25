<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Requests\IndexAccountsRequest;
use App\Modules\Accounting\Requests\StoreAccountRequest;
use App\Modules\Accounting\Requests\UpdateAccountRequest;
use App\Modules\Accounting\Services\AccountingReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccountController extends AccountingController
{
    public function index(IndexAccountsRequest $request, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['accounts' => $reads->accounts($tenantId, $request->validated())]]);
    }

    public function store(StoreAccountRequest $request, ManageAccountAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'manage_chart');
        $id = $action->create($tenantId, $actor, $request->validated());

        return response()->json(['data' => ['account' => $reads->account($tenantId, $id)]], 201);
    }

    public function show(Request $request, string $account, AccountingReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request, 'view_ledger');

        return response()->json(['data' => ['account' => $reads->account($tenantId, $account)]]);
    }

    public function update(UpdateAccountRequest $request, string $account, ManageAccountAction $action, AccountingReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'manage_chart');
        $reads->account($tenantId, $account);
        $action->update($tenantId, $account, $actor, $request->validated());

        return response()->json(['data' => ['account' => $reads->account($tenantId, $account)]]);
    }

    public function archive(Request $request, string $account, ManageAccountAction $action, AccountingReadService $reads): JsonResponse
    {
        return $this->transition($request, $account, $action, $reads, true);
    }

    public function restore(Request $request, string $account, ManageAccountAction $action, AccountingReadService $reads): JsonResponse
    {
        return $this->transition($request, $account, $action, $reads, false);
    }

    private function transition(Request $request, string $account, ManageAccountAction $action, AccountingReadService $reads, bool $archive): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request, 'manage_chart');
        $reads->account($tenantId, $account);
        $archive ? $action->archive($tenantId, $account, $actor) : $action->restore($tenantId, $account, $actor);

        return response()->json(['data' => ['account' => $reads->account($tenantId, $account)]]);
    }
}
