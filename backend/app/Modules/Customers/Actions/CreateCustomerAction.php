<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Illuminate\Support\Facades\DB;

final class CreateCustomerAction
{
    public function __construct(private readonly SaudiMobileNormalizer $phoneNormalizer) {}
    public function execute(
        string $tenantId,
        int|string $actorId,
        array $data,
    ): Customer {
        $data['phone'] = $this->phoneNormalizer->normalizeRequired($data['phone'] ?? null);
        return DB::transaction(function () use (
            $tenantId,
            $actorId,
            $data,
        ): Customer {
            unset(
                $data['tenant_id'],
                $data['created_by'],
                $data['updated_by'],
                $data['archived_by'],
                $data['restored_by'],
                $data['archived_at'],
            );

            $data['tenant_id'] = $tenantId;
            $data['status'] ??= CustomerStatus::Lead->value;
            $data['created_by'] = $actorId;
            $data['updated_by'] = $actorId;

            return Customer::query()->create($data);
        });
    }
}
