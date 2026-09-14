<?php

declare(strict_types=1);

namespace App\Modules\ContractConsideration\Support;

use App\Models\User;
use App\Modules\ContractConsideration\Exceptions\ContractConsiderationAccessDenied;
use App\Modules\Receivables\Exceptions\ReceivablesAccessDenied;
use App\Modules\Receivables\Support\ReceivablesAuthorization;

final class ContractConsiderationAuthorization
{
    public function __construct(
        private readonly ReceivablesAuthorization $receivablesAuthorization,
    ) {}

    public function authorize(string $tenantId, User $actor): void
    {
        try {
            $this->receivablesAuthorization
                ->authorizeTenantAdministrator($tenantId, $actor);
        } catch (ReceivablesAccessDenied $exception) {
            throw new ContractConsiderationAccessDenied(
                'Actor is not authorized for Contract Consideration adoption.',
                previous: $exception,
            );
        }
    }

    public function authorizeTransactional(string $tenantId, User $actor): void
    {
        try {
            $this->receivablesAuthorization
                ->authorizeTransactionalTenantAdministrator($tenantId, $actor);
        } catch (ReceivablesAccessDenied $exception) {
            throw new ContractConsiderationAccessDenied(
                'Actor is not authorized for Contract Consideration adoption.',
                previous: $exception,
            );
        }
    }
}
