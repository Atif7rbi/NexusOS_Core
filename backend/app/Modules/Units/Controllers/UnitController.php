<?php

declare(strict_types=1);

namespace App\Modules\Units\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Services\ResolveActiveMembership;
use App\Modules\Units\Actions\ArchiveUnitAction;
use App\Modules\Units\Actions\CreateUnitAction;
use App\Modules\Units\Actions\RestoreUnitAction;
use App\Modules\Units\Actions\UpdateUnitAction;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Enums\UnitType;
use App\Modules\Units\Exceptions\ArchivedUnitCannotBeUpdatedException;
use App\Modules\Units\Exceptions\UnitAlreadyArchivedException;
use App\Modules\Units\Exceptions\UnitNotArchivedException;
use App\Modules\Units\Models\Unit;
use App\Modules\Units\Requests\StoreUnitRequest;
use App\Modules\Units\Requests\UpdateUnitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UnitController extends Controller
{
    public function __construct(
        private readonly ResolveActiveMembership $resolveActiveMembership,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        $validated = $request->validate([
            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],
            'project_id' => [
                'sometimes',
                'nullable',
                'ulid',
            ],
            'unit_type' => [
                'sometimes',
                'nullable',
                Rule::enum(UnitType::class),
            ],
            'status' => [
                'sometimes',
                'nullable',
                Rule::enum(UnitStatus::class),
            ],
            'archived' => [
                'sometimes',
                Rule::in(['true', 'false', '1', '0']),
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = Unit::query()
            ->with('project:id,project_number,name,currency')
            ->where('tenant_id', $membership->tenant_id)
            ->latest();

        $summaryQuery = Unit::query()
            ->where('tenant_id', $membership->tenant_id);

        if (
            isset($validated['search'])
            && trim($validated['search']) !== ''
        ) {
            $query->where(
                'unit_number',
                'ilike',
                '%'.trim($validated['search']).'%'
            );
        }

        if (! empty($validated['project_id'])) {
            $query->where('project_id', $validated['project_id']);
        }

        if (! empty($validated['unit_type'])) {
            $query->where('unit_type', $validated['unit_type']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (array_key_exists('archived', $validated)) {
            if ($request->boolean('archived')) {
                $query->whereNotNull('archived_at');
                $summaryQuery->whereNotNull('archived_at');
            } else {
                $query->whereNull('archived_at');
                $summaryQuery->whereNull('archived_at');
            }
        }

        $units = $query->paginate(
            perPage: (int) ($validated['per_page'] ?? 20)
        );

        $summary = $summaryQuery
            ->selectRaw('count(*) as total')
            ->selectRaw(
                'count(*) filter (where status = ?) as available',
                [UnitStatus::Available->value]
            )
            ->selectRaw(
                'count(*) filter (where status = ?) as reserved',
                [UnitStatus::Reserved->value]
            )
            ->selectRaw(
                'count(*) filter (where status = ?) as sold',
                [UnitStatus::Sold->value]
            )
            ->firstOrFail();

        return response()->json([
            'data' => [
                'units' => $units,
                'summary' => [
                    'total' => (int) $summary->total,
                    'available' => (int) $summary->available,
                    'reserved' => (int) $summary->reserved,
                    'sold' => (int) $summary->sold,
                ],
            ],
        ]);
    }

    public function store(
        StoreUnitRequest $request,
        CreateUnitAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        $unit = $action->execute(
            tenantId: (string) $membership->tenant_id,
            actorId: $request->user()->id,
            data: $request->validated(),
        );

        return response()->json([
            'message' => 'تم إنشاء الوحدة بنجاح.',
            'data' => [
                'unit' => $unit,
            ],
        ], 201);
    }

    public function show(
        Request $request,
        string $unit,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        $unitRecord = Unit::query()
            ->where('tenant_id', $membership->tenant_id)
            ->whereKey($unit)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'unit' => $unitRecord,
            ],
        ]);
    }

    public function update(
        UpdateUnitRequest $request,
        string $unit,
        UpdateUnitAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        try {
            $unitRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                unitId: $unit,
                actorId: $request->user()->id,
                data: $request->validated(),
            );
        } catch (ArchivedUnitCannotBeUpdatedException $exception) {
            $this->throwUnitValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تم تحديث الوحدة بنجاح.',
            'data' => [
                'unit' => $unitRecord,
            ],
        ]);
    }

    public function archive(
        Request $request,
        string $unit,
        ArchiveUnitAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        try {
            $unitRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                unitId: $unit,
                actorId: $request->user()->id,
            );
        } catch (UnitAlreadyArchivedException $exception) {
            $this->throwUnitValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تمت أرشفة الوحدة بنجاح.',
            'data' => [
                'unit' => $unitRecord,
            ],
        ]);
    }

    public function restore(
        Request $request,
        string $unit,
        RestoreUnitAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        try {
            $unitRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                unitId: $unit,
                actorId: $request->user()->id,
            );
        } catch (UnitNotArchivedException $exception) {
            $this->throwUnitValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تمت استعادة الوحدة بنجاح.',
            'data' => [
                'unit' => $unitRecord,
            ],
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function throwUnitValidationException(
        string $message,
    ): never {
        throw ValidationException::withMessages([
            'unit' => [$message],
        ]);
    }
}
