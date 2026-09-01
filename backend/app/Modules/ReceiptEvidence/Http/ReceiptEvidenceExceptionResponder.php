<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Http;

use App\Modules\Accounting\Exceptions\AccountingAccessDenied;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceException;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceValidationFailed;
use App\Modules\Shared\Support\ApiErrorResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

final class ReceiptEvidenceExceptionResponder
{
    public function __construct(private readonly ApiErrorResponse $errors) {}

    public function respond(\Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof ModelNotFoundException => $this->errors->make(404, 'receipt_evidence_not_found', 'Receipt Evidence resource was not found.'),
            $exception instanceof AccountingAccessDenied => $this->errors->make(403, 'receipt_evidence_forbidden', 'Receipt Evidence action is not authorized.'),
            $exception instanceof AccountingConflict => $this->errors->make(409, 'receipt_evidence_conflict', $exception->getMessage()),
            $exception instanceof AccountingValidationFailed => response()->json(['message' => 'The given data was invalid.', 'errors' => ['receipt_evidence' => [$exception->getMessage()]]], 422),
            $exception instanceof ReceiptEvidenceConflict => $this->errors->make(409, 'receipt_evidence_conflict', $exception->getMessage()),
            $exception instanceof ReceiptEvidenceValidationFailed => response()->json(['message' => 'The given data was invalid.', 'errors' => ['receipt_evidence' => [$exception->getMessage()]]], 422),
            $exception instanceof ReceiptEvidenceException => $this->errors->make(500, 'receipt_evidence_failure', 'Unable to complete the Receipt Evidence request.'),
            default => throw $exception,
        };
    }
}
