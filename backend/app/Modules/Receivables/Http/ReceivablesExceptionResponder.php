<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Http;

use App\Modules\Receivables\Exceptions\ReceivablesAccessDenied;
use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use App\Modules\Receivables\Exceptions\ReceivablesException;
use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use App\Modules\Shared\Support\ApiErrorResponse;
use Illuminate\Http\JsonResponse;

final class ReceivablesExceptionResponder
{
    public function __construct(private readonly ApiErrorResponse $errors) {}

    public function respond(ReceivablesException $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof ReceivablesAccessDenied => $this->errors->make(403, 'receivables_forbidden', 'This action is unauthorized.'),
            $exception instanceof ReceivablesConflict => $this->errors->make(409, 'receivables_conflict', $exception->getMessage()),
            $exception instanceof ReceivablesValidationFailed => response()->json(['message' => 'The given data was invalid.', 'errors' => ['receivable' => [$exception->getMessage()]]], 422),
            default => $this->errors->make(500, 'receivables_failure', 'Unable to complete the Receivables request.'),
        };
    }
}
