<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http;

use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\Payments\Exceptions\PaymentsException;
use App\Modules\Payments\Exceptions\PaymentsValidationFailed;
use App\Modules\Shared\Support\ApiErrorResponse;
use Illuminate\Http\JsonResponse;

final class PaymentsExceptionResponder
{
    public function __construct(private readonly ApiErrorResponse $errors) {}

    public function respond(PaymentsException $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof PaymentsConflict => $this->errors->make(409, 'payments_conflict', $exception->getMessage()),
            $exception instanceof PaymentsValidationFailed => response()->json(['message' => 'The given data was invalid.', 'errors' => ['payment' => [$exception->getMessage()]]], 422),
            default => $this->errors->make(500, 'payments_failure', 'Unable to complete the Payments request.'),
        };
    }
}
