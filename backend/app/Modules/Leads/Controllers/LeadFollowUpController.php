<?php

declare(strict_types=1);

namespace App\Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Actions\CancelLeadFollowUpAction;
use App\Modules\Leads\Actions\CompleteLeadFollowUpAction;
use App\Modules\Leads\Actions\RescheduleLeadFollowUpAction;
use App\Modules\Leads\Actions\ScheduleLeadFollowUpAction;
use App\Modules\Leads\Enums\NextActionType;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Requests\CloseLeadFollowUpRequest;
use App\Modules\Leads\Requests\WriteLeadFollowUpRequest;
use App\Modules\Leads\Support\LeadResponseAssembler;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeadFollowUpController extends Controller
{
    public function __construct(private readonly LeadResponseAssembler $assembler)
    {
    }

    public function schedule(WriteLeadFollowUpRequest $request, string $lead, ScheduleLeadFollowUpAction $action): JsonResponse
    {
        return $this->write($request, $action->execute(
            $this->tenantId($request),
            $lead,
            $this->actor($request),
            CarbonImmutable::parse((string) $request->validated('next_follow_up_at')),
            NextActionType::from((string) $request->validated('next_action_type')),
            $request->validated('next_action_note'),
        ), 'تمت جدولة المتابعة بنجاح.');
    }

    public function reschedule(WriteLeadFollowUpRequest $request, string $lead, RescheduleLeadFollowUpAction $action): JsonResponse
    {
        return $this->write($request, $action->execute(
            $this->tenantId($request),
            $lead,
            $this->actor($request),
            CarbonImmutable::parse((string) $request->validated('next_follow_up_at')),
            NextActionType::from((string) $request->validated('next_action_type')),
            $request->validated('next_action_note'),
        ), 'تمت إعادة جدولة المتابعة بنجاح.');
    }

    public function complete(CloseLeadFollowUpRequest $request, string $lead, CompleteLeadFollowUpAction $action): JsonResponse
    {
        return $this->write($request, $action->execute(
            $this->tenantId($request),
            $lead,
            $this->actor($request),
            $request->validated('note'),
        ), 'تم إكمال المتابعة بنجاح.');
    }

    public function cancel(CloseLeadFollowUpRequest $request, string $lead, CancelLeadFollowUpAction $action): JsonResponse
    {
        return $this->write($request, $action->execute(
            $this->tenantId($request),
            $lead,
            $this->actor($request),
            $request->validated('note'),
        ), 'تم إلغاء المتابعة بنجاح.');
    }

    private function write(Request $request, Lead $lead, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => ['lead' => $this->assembler->lead($lead, $this->actor($request))],
        ]);
    }

    private function tenantId(Request $request): string
    {
        $membership = $request->attributes->get('tenant_membership');

        if (! $membership instanceof TenantUser) {
            throw new \LogicException('Active Tenant membership was not resolved.');
        }

        return (string) $membership->tenant_id;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new \LogicException('Authenticated CRM actor was not resolved.');
        }

        return $actor;
    }
}
