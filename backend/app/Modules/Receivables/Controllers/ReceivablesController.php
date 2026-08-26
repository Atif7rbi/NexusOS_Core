<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ReceivableAccounting\Services\ReceivableCancellationOrchestrator;
use App\Modules\Receivables\Actions\RecognizeReceivableAction;
use App\Modules\Receivables\Requests\CancelReceivableRequest;
use App\Modules\Receivables\Requests\IndexReceivablesRequest;
use App\Modules\Receivables\Requests\RecognizeReceivableRequest;
use App\Modules\Receivables\Services\ReceivablesReadService;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReceivablesController extends Controller
{
    public function __construct(private readonly ResolveActiveMembership $memberships, private readonly ReceivablesAuthorization $authorization) {}

    public function index(IndexReceivablesRequest $request, ReceivablesReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request);

        return response()->json(['data' => ['receivables' => $reads->index($tenantId, $request->validated())]]);
    }

    public function store(RecognizeReceivableRequest $request, RecognizeReceivableAction $action, ReceivablesReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request);
        $id = $action->execute($tenantId, $actor, $request->validated());

        return response()->json(['data' => ['receivable' => $reads->find($tenantId, $id)]], 201);
    }

    public function show(Request $request, string $receivable, ReceivablesReadService $reads): JsonResponse
    {
        [$tenantId] = $this->context($request);

        return response()->json(['data' => ['receivable' => $reads->find($tenantId, $receivable)]]);
    }

    public function cancel(CancelReceivableRequest $request, string $receivable, ReceivableCancellationOrchestrator $action, ReceivablesReadService $reads): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request);
        $action->execute($tenantId, $receivable, $actor, $request->validated('cancelled_at'), $request->validated('cancellation_reason'), $request->validated('reversal_date'));

        return response()->json(['data' => ['receivable' => $reads->find($tenantId, $receivable)]]);
    }

    /** @return array{string,User} */
    private function context(Request $request): array
    {
        /** @var User $actor */
        $actor = $request->user();
        $membership = $this->memberships->handle($actor);
        $tenantId = (string) $membership->tenant_id;
        $this->authorization->authorize($tenantId, $actor);

        return [$tenantId, $actor];
    }
}
