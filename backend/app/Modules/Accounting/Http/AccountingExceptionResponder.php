<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http;

use App\Modules\Accounting\Exceptions\AccountingAccessDenied;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Exceptions\AccountingLockTimeout;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Shared\Support\ApiErrorResponse;
use Illuminate\Http\JsonResponse;

final class AccountingExceptionResponder
{
    public function __construct(private readonly ApiErrorResponse $errors) {}

    public function respond(AccountingException $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof AccountingAccessDenied => $this->errors->make(403, 'accounting_forbidden', 'This action is unauthorized.'),
            $exception instanceof AccountingConflict => $this->errors->make(409, 'accounting_conflict', $exception->getMessage()),
            $exception instanceof AccountingLockTimeout => $this->errors->make(409, 'accounting_busy', 'Accounting is busy. Retry the request.'),
            $exception instanceof AccountingValidationFailed => response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['accounting' => [$exception->getMessage()]],
            ], 422),
            default => $this->errors->make(500, 'accounting_failure', 'Unable to complete the Accounting request.'),
        };
    }
}
