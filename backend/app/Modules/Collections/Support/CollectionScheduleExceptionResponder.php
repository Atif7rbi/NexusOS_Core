<?php

declare(strict_types=1);

namespace App\Modules\Collections\Support;

use App\Modules\Collections\Exceptions\BlankCollectionTitleException;
use App\Modules\Collections\Exceptions\CollectionHasEffectiveReceivableException;
use App\Modules\Collections\Exceptions\CollectionNotFoundException;
use App\Modules\Collections\Exceptions\CollectionScheduleChangedSinceLoadedException;
use App\Modules\Collections\Exceptions\ContractNotEligibleForAmendmentException;
use App\Modules\Collections\Exceptions\ContractNotEligibleForDraftEditException;
use App\Modules\Collections\Exceptions\ContractNotEligibleForFinalizationException;
use App\Modules\Collections\Exceptions\DuplicateActiveSequenceException;
use App\Modules\Collections\Exceptions\ExcessDecimalPrecisionException;
use App\Modules\Collections\Exceptions\InvalidCancellationReasonException;
use App\Modules\Collections\Exceptions\InvalidCollectionAmountException;
use App\Modules\Collections\Exceptions\InvalidCollectionSequenceException;
use App\Modules\Collections\Exceptions\InvalidExpectedActiveCollectionIdsException;
use App\Modules\Collections\Exceptions\NoActiveScheduleToAmendException;
use App\Modules\Collections\Exceptions\NonDecreasingDueDateViolationException;
use App\Modules\Collections\Exceptions\ScheduleNotInDraftStateException;
use App\Modules\Collections\Exceptions\ScheduleTotalMismatchException;
use App\Modules\Collections\Http\Exceptions\CollectionScheduleIntegrityViolationException;
use App\Modules\Collections\Http\Exceptions\RoleNotAuthorizedException;
use App\Modules\Shared\Support\ApiErrorResponse;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Throwable;

final class CollectionScheduleExceptionResponder
{
    public function __construct(
        private readonly ApiErrorResponse $errors,
    ) {
    }

    public function from(Throwable $exception): ?JsonResponse
    {
        [$status, $code] = match (true) {
            $exception instanceof RoleNotAuthorizedException => [403, 'role_not_authorized'],
            $exception instanceof ModelNotFoundException => [404, 'contract_not_found'],
            $exception instanceof ContractNotEligibleForDraftEditException => [409, 'contract_not_eligible_for_draft_edit'],
            $exception instanceof ScheduleNotInDraftStateException => [409, 'schedule_not_in_draft_state'],
            $exception instanceof ContractNotEligibleForFinalizationException => [409, 'contract_not_eligible_for_finalization'],
            $exception instanceof ContractNotEligibleForAmendmentException => [409, 'contract_not_eligible_for_amendment'],
            $exception instanceof NoActiveScheduleToAmendException => [409, 'no_active_schedule_to_amend'],
            $exception instanceof CollectionScheduleIntegrityViolationException => [409, 'collection_schedule_integrity_violation'],
            $exception instanceof CollectionScheduleChangedSinceLoadedException => [409, 'collection_schedule_changed_since_loaded'],
            $exception instanceof CollectionHasEffectiveReceivableException => [409, 'collection_has_effective_receivable'],
            $exception instanceof InvalidCollectionSequenceException => [422, 'invalid_collection_sequence'],
            $exception instanceof BlankCollectionTitleException => [422, 'blank_collection_title'],
            $exception instanceof InvalidCollectionAmountException => [422, 'invalid_collection_amount'],
            $exception instanceof ExcessDecimalPrecisionException => [422, 'excess_decimal_precision'],
            $exception instanceof DuplicateActiveSequenceException => [422, 'duplicate_collection_sequence'],
            $exception instanceof NonDecreasingDueDateViolationException => [422, 'non_decreasing_due_date_violation'],
            $exception instanceof ScheduleTotalMismatchException => [422, 'schedule_total_mismatch'],
            $exception instanceof InvalidCancellationReasonException => [422, 'invalid_cancellation_reason'],
            $exception instanceof CollectionNotFoundException => [422, 'invalid_collection_reference'],
            $exception instanceof InvalidExpectedActiveCollectionIdsException => [422, 'invalid_expected_active_collection_ids'],
            $exception instanceof QueryException && $this->isTransientPostgresFailure($exception) => [503, 'service_unavailable'],
            default => [null, null],
        };

        if ($status === null || $code === null) {
            return null;
        }

        $message = match ($code) {
            'contract_not_found' => 'The Contract does not exist or is not available in the active Tenant.',
            'service_unavailable' => 'The Collection Schedule service is temporarily unavailable.',
            default => $exception->getMessage(),
        };

        return $this->error($status, $code, $message);
    }

    public function error(int $status, string $code, string $message): JsonResponse
    {
        return $this->errors->make($status, $code, $message);
    }

    private function isTransientPostgresFailure(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        return in_array($sqlState, ['40P01', '55P03', '40001'], true);
    }
}
