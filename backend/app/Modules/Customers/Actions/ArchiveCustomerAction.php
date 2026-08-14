<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\CustomerAlreadyArchivedException;
use App\Modules\Customers\Exceptions\CustomerHasLiveDependenciesException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Reservation;
use Illuminate\Support\Facades\DB;

final class ArchiveCustomerAction
{
    public function execute(
        string $tenantId,
        string $customerId,
        int|string $actorId,
    ): Customer {
        return DB::transaction(function () use (
            $tenantId,
            $customerId,
            $actorId,
        ): Customer {
            $customer = Customer::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($customerId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($customer->isArchived()) {
                throw new CustomerAlreadyArchivedException();
            }

            $hasActiveReservation = Reservation::query()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->where('status', ReservationStatus::Active->value)
                ->exists();

            $hasLiveContract = Contract::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('status', [
                    ContractStatus::Draft->value,
                    ContractStatus::Active->value,
                ])
                ->whereHas(
                    'reservation',
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('customer_id', $customerId),
                )
                ->exists();

            if ($hasActiveReservation || $hasLiveContract) {
                throw new CustomerHasLiveDependenciesException();
            }

            $customer->forceFill([
                'status' => CustomerStatus::Archived,
                'archived_at' => now(),
                'archived_by' => $actorId,
                'restored_by' => null,
                'updated_by' => $actorId,
            ])->save();

            return $customer;
        });
    }
}
