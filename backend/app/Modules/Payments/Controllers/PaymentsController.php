<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Payments\Actions\AllocatePaymentAction;
use App\Modules\Payments\Actions\CancelPaymentAction;
use App\Modules\Payments\Actions\CancelPaymentAllocationAction;
use App\Modules\Payments\Actions\RecordPaymentAction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaymentsController extends Controller
{
    public function __construct(private readonly ResolveActiveMembership $memberships, private readonly ReceivablesAuthorization $authorization) {}

    public function store(Request $request, RecordPaymentAction $action): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request);
        $data = $request->validate(['payment_operation_id' => ['required', 'ulid'], 'customer_id' => ['required', 'ulid'], 'amount' => ['required'], 'currency' => ['required', 'in:SAR,USD'], 'received_on' => ['required', 'date_format:Y-m-d'], 'method' => ['nullable', 'string', 'max:100'], 'reference' => ['nullable', 'string', 'max:255']]);
        $id = $action->execute($tenantId, $actor, $data);

        return response()->json(['data' => ['payment' => $this->payment($tenantId, $id)]], 201);
    }

    public function allocate(Request $request, AllocatePaymentAction $action): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request);
        $data = $request->validate(['payment_id' => ['required', 'ulid'], 'receivable_id' => ['required', 'ulid'], 'allocation_operation_id' => ['required', 'ulid'], 'amount' => ['required']]);
        $id = $action->execute($tenantId, $actor, $data);

        return response()->json(['data' => ['allocation' => $this->allocation($tenantId, $id)]], 201);
    }

    public function cancel(Request $request, string $payment, CancelPaymentAction $action): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request);
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:500']]);
        $action->execute($tenantId, $payment, $actor, $data['cancellation_reason']);

        return response()->json(['data' => ['payment' => $this->payment($tenantId, $payment)]]);
    }

    public function cancelAllocation(Request $request, string $allocation, CancelPaymentAllocationAction $action): JsonResponse
    {
        [$tenantId, $actor] = $this->context($request);
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:500']]);
        $action->execute($tenantId, $allocation, $actor, $data['cancellation_reason']);

        return response()->json(['data' => ['allocation' => $this->allocation($tenantId, $allocation)]]);
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

    private function payment(string $tenantId, string $id): object
    {
        return DB::table('payments')->where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
    }

    private function allocation(string $tenantId, string $id): object
    {
        return DB::table('payment_allocations')->where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();
    }
}
