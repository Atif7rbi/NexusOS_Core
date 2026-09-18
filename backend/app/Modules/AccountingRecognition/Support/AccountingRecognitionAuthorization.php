<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Support;

use App\Models\User;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionAccessDenied;
use App\Modules\Receivables\Exceptions\ReceivablesAccessDenied;
use App\Modules\Receivables\Support\ReceivablesAuthorization;

final class AccountingRecognitionAuthorization
{
    public function __construct(private readonly ReceivablesAuthorization $authorization) {}

    public function authorize(string $tenantId, User $actor): void
    {
        try {
            $this->authorization->authorizeTenantAdministrator($tenantId, $actor);
        } catch (ReceivablesAccessDenied $exception) {
            throw new AccountingRecognitionAccessDenied(
                'Actor is not authorized for Accounting Recognition configuration.',
                previous: $exception,
            );
        }
    }

    public function authorizeTransactional(string $tenantId, User $actor): void
    {
        try {
            $this->authorization->authorizeTransactionalTenantAdministrator($tenantId, $actor);
        } catch (ReceivablesAccessDenied $exception) {
            throw new AccountingRecognitionAccessDenied(
                'Actor is not authorized for Accounting Recognition configuration.',
                previous: $exception,
            );
        }
    }
}
