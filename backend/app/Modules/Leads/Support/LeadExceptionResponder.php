<?php

declare(strict_types=1);

namespace App\Modules\Leads\Support;

use App\Modules\Leads\Exceptions\LeadActionNotAuthorizedException;
use App\Modules\Leads\Exceptions\LeadAlreadyArchivedException;
use App\Modules\Leads\Exceptions\LeadAlreadyConvertedException;
use App\Modules\Leads\Exceptions\LeadAlreadyLostException;
use App\Modules\Leads\Exceptions\LeadAlreadyWonException;
use App\Modules\Leads\Exceptions\LeadAssigneeNotActiveException;
use App\Modules\Leads\Exceptions\LeadAssigneeRoleNotEligibleException;
use App\Modules\Leads\Exceptions\LeadClaimConflictException;
use App\Modules\Leads\Exceptions\LeadConversionCustomerArchivedException;
use App\Modules\Leads\Exceptions\LeadConversionCustomerChangedException;
use App\Modules\Leads\Exceptions\LeadConversionNewCustomerConflictException;
use App\Modules\Leads\Exceptions\LeadIsArchivedException;
use App\Modules\Leads\Exceptions\LeadNotArchivedException;
use App\Modules\Leads\Exceptions\LeadNotFoundException;
use App\Modules\Leads\Exceptions\LeadNotInOpenStageException;
use App\Modules\Leads\Exceptions\LeadNotLostException;
use App\Modules\Leads\Exceptions\LeadPhoneDuplicateDetectedException;
use App\Modules\Leads\Exceptions\LeadProjectIsArchivedException;
use App\Modules\Leads\Exceptions\LeadStageTransitionNotAllowedException;
use App\Modules\Leads\Exceptions\LeadUnitIsArchivedException;
use App\Modules\Leads\Exceptions\LeadUnitProjectMismatchException;
use App\Modules\Leads\Exceptions\LeadValidationException;
use App\Modules\Leads\Exceptions\LeadWonCannotBeArchivedException;
use App\Modules\Leads\Exceptions\UserHasOpenAssignedLeadsException;
use Illuminate\Http\JsonResponse;
use Throwable;

final class LeadExceptionResponder
{
    public function from(Throwable $exception): ?JsonResponse
    {
        [$status, $code] = match (true) {
            $exception instanceof LeadNotFoundException => [404, 'lead_not_found'],
            $exception instanceof LeadActionNotAuthorizedException => [403, 'lead_action_not_authorized'],
            $exception instanceof LeadNotInOpenStageException => [409, 'lead_not_in_open_stage'],
            $exception instanceof LeadStageTransitionNotAllowedException => [409, 'lead_stage_transition_not_allowed'],
            $exception instanceof LeadAlreadyWonException => [409, 'lead_already_won'],
            $exception instanceof LeadAlreadyLostException => [409, 'lead_already_lost'],
            $exception instanceof LeadNotLostException => [409, 'lead_not_lost'],
            $exception instanceof LeadAlreadyArchivedException => [409, 'lead_already_archived'],
            $exception instanceof LeadNotArchivedException => [409, 'lead_not_archived'],
            $exception instanceof LeadIsArchivedException => [409, 'lead_is_archived'],
            $exception instanceof LeadWonCannotBeArchivedException => [409, 'lead_won_cannot_be_archived'],
            $exception instanceof LeadAlreadyConvertedException => [409, 'lead_already_converted'],
            $exception instanceof LeadConversionCustomerArchivedException => [409, 'lead_conversion_customer_archived'],
            $exception instanceof LeadConversionCustomerChangedException => [409, 'lead_conversion_customer_changed'],
            $exception instanceof LeadConversionNewCustomerConflictException => [409, 'lead_conversion_new_customer_conflict'],
            $exception instanceof LeadClaimConflictException => [409, 'lead_claim_conflict'],
            $exception instanceof LeadPhoneDuplicateDetectedException => [409, 'lead_phone_duplicate_detected'],
            $exception instanceof UserHasOpenAssignedLeadsException => [409, 'user_has_open_assigned_leads'],
            $exception instanceof LeadUnitProjectMismatchException => [422, 'lead_unit_project_mismatch'],
            $exception instanceof LeadProjectIsArchivedException => [422, 'lead_project_is_archived'],
            $exception instanceof LeadUnitIsArchivedException => [422, 'lead_unit_is_archived'],
            $exception instanceof LeadAssigneeRoleNotEligibleException => [422, 'lead_assignee_role_not_eligible'],
            $exception instanceof LeadAssigneeNotActiveException => [422, 'lead_assignee_not_active'],
            $exception instanceof LeadValidationException => [422, 'validation_error'],
            default => [null, null],
        };

        if ($status === null || $code === null) {
            return null;
        }

        $error = [
            'code' => $code,
            'message' => $exception->getMessage(),
        ];

        if ($exception instanceof LeadConversionNewCustomerConflictException) {
            $error['conflicting_customer'] = [
                'id'     => $exception->conflictingCustomerId,
                'status' => $exception->conflictingCustomerStatus,
            ];
        }

        if ($exception instanceof LeadPhoneDuplicateDetectedException) {
            $error['matches'] = $exception->matches
                ->map(static fn ($lead): array => [
                    'id' => (string) $lead->id,
                    'name' => $lead->name,
                    'stage' => $lead->stage->value,
                    'assigned_to' => $lead->assignee === null
                        ? null
                        : [
                            'id' => (int) $lead->assignee->id,
                            'name' => $lead->assignee->name,
                        ],
                    'archived' => $lead->archived_at !== null,
                    'updated_at' => $lead->updated_at?->toISOString(),
                ])
                ->values()
                ->all();
        }

        $response = [
            'message' => $exception->getMessage(),
            'error' => $error,
        ];

        if ($exception instanceof LeadValidationException && $exception->errors !== []) {
            $response['errors'] = $exception->errors;
        }

        return response()->json($response, $status);
    }
}
