<?php

declare(strict_types=1);

namespace App\Modules\ContractualBilling\Support;

use App\Models\User;
use App\Modules\ContractualBilling\Exceptions\ContractualBillingAccessDenied;
use App\Modules\Receivables\Exceptions\ReceivablesAccessDenied;
use App\Modules\Receivables\Support\ReceivablesAuthorization;

final class ContractualBillingAuthorization
{
    public function __construct(
        private readonly ReceivablesAuthorization $authorization,
    ) {}

    public function authorize(string $tenantId, User $actor): void
    {
        try {
            $this->authorization->authorize($tenantId, $actor);
        } catch (ReceivablesAccessDenied $exception) {
            throw new ContractualBillingAccessDenied(
                'Actor is not authorized for Contractual Billing.',
                previous: $exception,
            );
        }
    }

    public function authorizeTransactional(
        string $tenantId,
        User $actor,
    ): void {
        try {
            $this->authorization->authorizeTransactional(
                $tenantId,
                $actor,
            );
        } catch (ReceivablesAccessDenied $exception) {
            throw new ContractualBillingAccessDenied(
                'Actor is not authorized for Contractual Billing.',
                previous: $exception,
            );
        }
    }
}
