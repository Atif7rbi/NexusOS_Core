<?php

declare(strict_types=1);

namespace App\Modules\Customers\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Actions\ArchiveCustomerAction;
use App\Modules\Customers\Actions\CreateCustomerAction;
use App\Modules\Customers\Actions\RestoreCustomerAction;
use App\Modules\Customers\Actions\UpdateCustomerAction;
use App\Modules\Customers\Enums\CustomerCategory;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Exceptions\ArchivedCustomerCannotBeUpdatedException;
use App\Modules\Customers\Exceptions\CustomerAlreadyArchivedException;
use App\Modules\Customers\Exceptions\CustomerNotArchivedException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Requests\StoreCustomerRequest;
use App\Modules\Customers\Requests\UpdateCustomerRequest;
use App\Modules\Customers\Support\CustomerBusinessContextQuery;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CustomerController extends Controller
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
            'type' => [
                'sometimes',
                'nullable',
                Rule::enum(CustomerType::class),
            ],
            'category' => [
                'sometimes',
                'nullable',
                Rule::enum(CustomerCategory::class),
            ],
            'status' => [
                'sometimes',
                'nullable',
                Rule::enum(CustomerStatus::class),
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = Customer::query()
            ->where(
                'tenant_id',
                $membership->tenant_id
            )
            ->latest();

        if (
            isset($validated['search'])
            && trim($validated['search']) !== ''
        ) {
            $search = trim($validated['search']);

            $query->where(
                function (Builder $nested) use ($search): void {
                    $nested
                        ->where(
                            'name',
                            'ilike',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'phone',
                            'ilike',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'email',
                            'ilike',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'national_id',
                            'ilike',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'commercial_registration_number',
                            'ilike',
                            "%{$search}%"
                        );
                }
            );
        }

        if (! empty($validated['type'])) {
            $query->where(
                'type',
                $validated['type']
            );
        }

        if (! empty($validated['category'])) {
            $query->where(
                'category',
                $validated['category']
            );
        }

        if (! empty($validated['status'])) {
            $query->where(
                'status',
                $validated['status']
            );
        }

        $customers = $query->paginate(
            perPage: (int) ($validated['per_page'] ?? 20)
        );

        $summary = Customer::query()
            ->where('tenant_id', $membership->tenant_id)
            ->selectRaw('count(*) as total')
            ->selectRaw(
                'count(*) filter (where status = ?) as customers',
                [CustomerStatus::Customer->value]
            )
            ->selectRaw(
                'count(*) filter (where status = ?) as leads',
                [CustomerStatus::Lead->value]
            )
            ->selectRaw(
                'count(*) filter (where status = ?) as archived',
                [CustomerStatus::Archived->value]
            )
            ->firstOrFail();

        return response()->json([
            'data' => [
                'customers' => $customers,
                'summary' => [
                    'total' => (int) $summary->total,
                    'customers' => (int) $summary->customers,
                    'leads' => (int) $summary->leads,
                    'archived' => (int) $summary->archived,
                ],
            ],
        ]);
    }

    public function store(
        StoreCustomerRequest $request,
        CreateCustomerAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        $customer = $action->execute(
            tenantId: (string) $membership->tenant_id,
            actorId: $request->user()->id,
            data: $request->validated(),
        );

        return response()->json([
            'message' => 'تم إنشاء العميل بنجاح.',
            'data' => [
                'customer' => $customer,
            ],
        ], 201);
    }

    public function show(
        Request $request,
        string $customer,
        CustomerBusinessContextQuery $businessContextQuery,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        $customerRecord = Customer::query()
            ->where(
                'tenant_id',
                $membership->tenant_id
            )
            ->whereKey($customer)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'customer' => $customerRecord,
                'business_context' => $businessContextQuery->execute(
                    (string) $membership->tenant_id,
                    (string) $customerRecord->id,
                ),
            ],
        ]);
    }

    public function update(
        UpdateCustomerRequest $request,
        string $customer,
        UpdateCustomerAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        try {
            $customerRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                customerId: $customer,
                actorId: $request->user()->id,
                data: $request->validated(),
            );
        } catch (
            ArchivedCustomerCannotBeUpdatedException $exception
        ) {
            $this->throwCustomerValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تم تحديث العميل بنجاح.',
            'data' => [
                'customer' => $customerRecord,
            ],
        ]);
    }

    public function archive(
        Request $request,
        string $customer,
        ArchiveCustomerAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        try {
            $customerRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                customerId: $customer,
                actorId: $request->user()->id,
            );
        } catch (
            CustomerAlreadyArchivedException $exception
        ) {
            $this->throwCustomerValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تمت أرشفة العميل بنجاح.',
            'data' => [
                'customer' => $customerRecord,
            ],
        ]);
    }

    public function restore(
        Request $request,
        string $customer,
        RestoreCustomerAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user()
        );

        try {
            $customerRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                customerId: $customer,
                actorId: $request->user()->id,
            );
        } catch (
            CustomerNotArchivedException $exception
        ) {
            $this->throwCustomerValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تمت استعادة العميل بنجاح.',
            'data' => [
                'customer' => $customerRecord,
            ],
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function throwCustomerValidationException(
        string $message,
    ): never {
        throw ValidationException::withMessages([
            'customer' => [
                $message,
            ],
        ]);
    }
}
