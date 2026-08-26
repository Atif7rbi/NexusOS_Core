<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Collections\Exceptions\CollectionHasEffectiveReceivableException;
use App\Modules\Contracts\Actions\ActivateContractAction;
use App\Modules\Contracts\Actions\CancelContractAction;
use App\Modules\Contracts\Actions\CompleteContractAction;
use App\Modules\Contracts\Actions\CreateContractAction;
use App\Modules\Contracts\Actions\UpdateContractAction;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Exceptions\ContractAlreadyExistsException;
use App\Modules\Contracts\Exceptions\ContractCannotBeCancelledException;
use App\Modules\Contracts\Exceptions\ContractNotActiveException;
use App\Modules\Contracts\Exceptions\ContractNotDraftException;
use App\Modules\Contracts\Exceptions\ContractReservationNotActiveException;
use App\Modules\Contracts\Exceptions\ContractReservationStateException;
use App\Modules\Contracts\Exceptions\ContractUnitStateException;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Requests\StoreContractRequest;
use App\Modules\Contracts\Requests\UpdateContractRequest;
use App\Modules\Contracts\Resources\ContractResource;
use App\Modules\Contracts\Support\ContractAuthorization;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContractController extends Controller
{
    public function __construct(
        private readonly ResolveActiveMembership $resolveActiveMembership,
        private readonly ContractAuthorization $authorization,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle($request->user());
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(ContractStatus::class)],
            'reservation_id' => ['sometimes', 'nullable', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Contract::query()->with([
            'reservation:id,unit_id,customer_id,status,created_by',
            'reservation.unit:id,project_id,unit_number,status',
            'reservation.unit.project:id,project_manager_id',
            'reservation.customer:id,name,status',
        ])->where('tenant_id', $membership->tenant_id)->latest();

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->whereHas('reservation', function ($reservation) use ($search): void {
                $reservation->whereHas('unit', fn ($unit) => $unit->where('unit_number', 'ilike', "%{$search}%"))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'ilike', "%{$search}%"));
            });
        }
        foreach (['status', 'reservation_id'] as $filter) {
            if (!empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        $contracts = $query->paginate((int) ($validated['per_page'] ?? 20));
        $summary = Contract::query()->where('tenant_id', $membership->tenant_id)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'draft') as draft")
            ->selectRaw("count(*) filter (where status = 'active') as active")
            ->selectRaw("count(*) filter (where status = 'completed') as completed")
            ->selectRaw("count(*) filter (where status = 'cancelled') as cancelled")
            ->firstOrFail();

        return response()->json(['data' => [
            'contracts' => ContractResource::collection($contracts)->response()->getData(true),
            'summary' => [
                'total' => (int) $summary->total,
                'draft' => (int) $summary->draft,
                'active' => (int) $summary->active,
                'completed' => (int) $summary->completed,
                'cancelled' => (int) $summary->cancelled,
            ],
        ]]);
    }

    public function store(StoreContractRequest $request, CreateContractAction $action): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle($request->user());
        $this->authorization->assertCanCreate(
            $request->user(),
            $this->findReservation(
                (string) $membership->tenant_id,
                $request->validated('reservation_id'),
            ),
        );
        try {
            $contract = $action->execute(
                (string) $membership->tenant_id,
                $request->user()->id,
                $request->validated('reservation_id'),
                $request->validated('total_amount'),
            );
        } catch (ContractReservationNotActiveException $exception) {
            $this->throwValidationException('reservation_id', $exception->getMessage());
        } catch (ContractAlreadyExistsException $exception) {
            $this->throwValidationException('reservation_id', $exception->getMessage());
        }

        return response()->json([
            'message' => 'تم إنشاء العقد بنجاح.',
            'data' => ['contract' => (new ContractResource($contract))->resolve()],
        ], 201);
    }

    public function show(Request $request, string $contract): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle($request->user());
        $record = Contract::query()
            ->with(['reservation.unit', 'reservation.customer'])
            ->where('tenant_id', $membership->tenant_id)
            ->whereKey($contract)
            ->firstOrFail();

        return response()->json([
            'data' => ['contract' => (new ContractResource($record))->resolve()],
        ]);
    }

    public function update(UpdateContractRequest $request, string $contract, UpdateContractAction $action): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle($request->user());
        $this->authorization->assertCanUpdateOrActivate(
            $request->user(),
            $this->findContract((string) $membership->tenant_id, $contract),
        );
        try {
            $record = $action->execute(
                (string) $membership->tenant_id,
                $contract,
                $request->user()->id,
                $request->validated('total_amount'),
            );
        } catch (ContractNotDraftException $exception) {
            $this->throwValidationException('contract', $exception->getMessage());
        }

        return response()->json([
            'message' => 'تم تحديث العقد بنجاح.',
            'data' => ['contract' => (new ContractResource($record))->resolve()],
        ]);
    }

    public function activate(Request $request, string $contract, ActivateContractAction $action): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle($request->user());
        $this->authorization->assertCanUpdateOrActivate(
            $request->user(),
            $this->findContract((string) $membership->tenant_id, $contract),
        );
        try {
            $record = $action->execute((string) $membership->tenant_id, $contract, $request->user()->id);
        } catch (ContractNotDraftException|ContractReservationStateException|ContractUnitStateException $exception) {
            $this->throwValidationException('contract', $exception->getMessage());
        }

        return response()->json([
            'message' => 'تم تفعيل العقد بنجاح.',
            'data' => ['contract' => (new ContractResource($record))->resolve()],
        ]);
    }

    public function complete(Request $request, string $contract, CompleteContractAction $action): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle($request->user());
        $this->findContract((string) $membership->tenant_id, $contract);
        $this->authorization->assertCanComplete($request->user());
        try {
            $record = $action->execute((string) $membership->tenant_id, $contract, $request->user()->id);
        } catch (ContractNotActiveException $exception) {
            $this->throwValidationException('contract', $exception->getMessage());
        }

        return response()->json([
            'message' => 'تم إكمال العقد بنجاح.',
            'data' => ['contract' => (new ContractResource($record))->resolve()],
        ]);
    }

    public function cancel(Request $request, string $contract, CancelContractAction $action): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle($request->user());
        $this->authorization->assertCanCancel(
            $request->user(),
            $this->findContract((string) $membership->tenant_id, $contract),
        );
        try {
            $record = $action->execute((string) $membership->tenant_id, $contract, $request->user()->id);
        } catch (ContractCannotBeCancelledException|ContractReservationStateException|ContractUnitStateException|CollectionHasEffectiveReceivableException $exception) {
            $this->throwValidationException('contract', $exception->getMessage());
        }

        return response()->json([
            'message' => 'تم إلغاء العقد بنجاح.',
            'data' => ['contract' => (new ContractResource($record))->resolve()],
        ]);
    }

    private function throwValidationException(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function findReservation(string $tenantId, string $reservation): Reservation
    {
        return Reservation::query()
            ->with('unit.project:id,project_manager_id')
            ->where('tenant_id', $tenantId)
            ->whereKey($reservation)
            ->firstOrFail();
    }

    private function findContract(string $tenantId, string $contract): Contract
    {
        return Contract::query()
            ->with('reservation.unit.project:id,project_manager_id')
            ->where('tenant_id', $tenantId)
            ->whereKey($contract)
            ->firstOrFail();
    }
}
