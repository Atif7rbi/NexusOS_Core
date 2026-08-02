<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Exceptions\ArchivedCustomerCannotBeUpdatedException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Illuminate\Support\Facades\DB;

final class UpdateCustomerAction
{
    public function __construct(private readonly SaudiMobileNormalizer $phoneNormalizer) {}
    public function execute(
        string $tenantId,
        string $customerId,
        int|string $actorId,
        array $data,
    ): Customer {
        if (array_key_exists('phone', $data)) $data['phone'] = $this->phoneNormalizer->normalizeRequired($data['phone']);
        return DB::transaction(function () use (
            $tenantId,
            $customerId,
            $actorId,
            $data,
        ): Customer {
            $customer = Customer::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($customerId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($customer->isArchived()) {
                throw new ArchivedCustomerCannotBeUpdatedException();
            }

            unset(
                $data['tenant_id'],
                $data['created_by'],
                $data['updated_by'],
                $data['archived_by'],
                $data['restored_by'],
                $data['archived_at'],
            );

            $effectiveType = array_key_exists('type', $data)
                ? CustomerType::from($data['type'])
                : $customer->type;

            if ($effectiveType === CustomerType::Individual) {
                if (
                    array_key_exists(
                        'commercial_registration_number',
                        $data
                    )
                    && $data['commercial_registration_number'] !== null
                ) {
                    $data['commercial_registration_number'] = null;
                }

                if (array_key_exists('type', $data)) {
                    $data['commercial_registration_number'] = null;
                }
            }

            if ($effectiveType === CustomerType::Company) {
                if (
                    array_key_exists('national_id', $data)
                    && $data['national_id'] !== null
                ) {
                    $data['national_id'] = null;
                }

                if (array_key_exists('type', $data)) {
                    $data['national_id'] = null;
                }
            }

            $data['updated_by'] = $actorId;

            $customer->fill($data);
            $customer->save();

            return $customer;
        });
    }
}
