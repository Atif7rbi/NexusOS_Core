<?php

declare(strict_types=1);

namespace App\Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Actions\ArchiveLeadAction;
use App\Modules\Leads\Actions\AssignLeadAction;
use App\Modules\Leads\Actions\ClaimLeadAction;
use App\Modules\Leads\Actions\ConvertLeadToCustomerAction;
use App\Modules\Leads\Actions\CreateLeadAction;
use App\Modules\Leads\Actions\MoveLeadStageAction;
use App\Modules\Leads\Actions\MoveLeadToLostAction;
use App\Modules\Leads\Actions\ReopenLeadAction;
use App\Modules\Leads\Actions\RestoreLeadAction;
use App\Modules\Leads\Actions\UpdateLeadAction;
use App\Modules\Leads\Enums\ArchiveReason;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Enums\LostReason;
use App\Modules\Leads\Exceptions\LeadNotFoundException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Exceptions\LeadActionNotAuthorizedException;
use App\Modules\Leads\Requests\ArchiveLeadRequest;
use App\Modules\Leads\Requests\AssignLeadRequest;
use App\Modules\Leads\Requests\ClaimLeadRequest;
use App\Modules\Leads\Requests\ConvertLeadRequest;
use App\Modules\Leads\Requests\IndexLeadRequest;
use App\Modules\Leads\Requests\MoveLeadStageRequest;
use App\Modules\Leads\Requests\MoveLeadToLostRequest;
use App\Modules\Leads\Requests\ReopenLeadRequest;
use App\Modules\Leads\Requests\RestoreLeadRequest;
use App\Modules\Leads\Requests\StoreLeadRequest;
use App\Modules\Leads\Requests\UpdateLeadRequest;
use App\Modules\Leads\Support\LeadAuthorization;
use App\Modules\Leads\Support\LeadResponseAssembler;
use App\Modules\Leads\Support\LeadsIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeadController extends Controller
{
    public function __construct(
        private readonly LeadAuthorization $authorization,
        private readonly LeadResponseAssembler $assembler,
        private readonly LeadsIndexQuery $indexQuery,
    ) {
    }

    public function index(IndexLeadRequest $request): JsonResponse
    {
        $membership = $this->membership($request);

        return response()->json([
            'data' => $this->indexQuery->execute(
                tenantId: (string) $membership->tenant_id,
                tenantTimezone: (string) $membership->tenant->timezone,
                actor: $this->actor($request),
                filters: $request->validated(),
            ),
        ]);
    }

    public function store(
        StoreLeadRequest $request,
        CreateLeadAction $action,
    ): JsonResponse {
        $lead = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            actor: $this->actor($request),
            data: $request->validated(),
        );

        return response()->json([
            'message' => 'تم إنشاء العميل المحتمل بنجاح.',
            'data' => [
                'lead' => $this->assembler->lead($lead, $this->actor($request)),
            ],
        ], 201);
    }

    public function show(Request $request, string $lead): JsonResponse
    {
        $record = $this->visibleLead($request, $lead);

        return response()->json([
            'data' => [
                'lead' => $this->assembler->lead($record, $this->actor($request)),
            ],
        ]);
    }

    public function update(
        UpdateLeadRequest $request,
        string $lead,
        UpdateLeadAction $action,
    ): JsonResponse {
        $record = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
            data: $request->validated(),
        );

        return $this->success($request, $record, 'تم تحديث العميل المحتمل بنجاح.');
    }

    public function moveStage(
        MoveLeadStageRequest $request,
        string $lead,
        MoveLeadStageAction $action,
    ): JsonResponse {
        $record = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
            targetStage: LeadStage::from((string) $request->validated('stage')),
        );

        return $this->success($request, $record, 'تم تحديث مرحلة العميل المحتمل بنجاح.');
    }

    public function claim(
        ClaimLeadRequest $request,
        string $lead,
        ClaimLeadAction $action,
    ): JsonResponse {
        $record = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
        );

        return $this->success($request, $record, 'تم استلام العميل المحتمل بنجاح.');
    }

    public function assign(
        AssignLeadRequest $request,
        string $lead,
        AssignLeadAction $action,
    ): JsonResponse {
        $record = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
            assignedTo: $request->validated('assigned_to') === null
                ? null
                : (int) $request->validated('assigned_to'),
        );

        return $this->success($request, $record, 'تم تحديث إسناد العميل المحتمل بنجاح.');
    }

    public function lose(
        MoveLeadToLostRequest $request,
        string $lead,
        MoveLeadToLostAction $action,
    ): JsonResponse {
        $record = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
            reason: LostReason::from((string) $request->validated('lost_reason')),
            reasonDetail: $request->validated('lost_reason_detail'),
        );

        return $this->success($request, $record, 'تم نقل العميل المحتمل إلى مفقود.');
    }

    public function reopen(
        ReopenLeadRequest $request,
        string $lead,
        ReopenLeadAction $action,
    ): JsonResponse {
        $record = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
        );

        return $this->success($request, $record, 'تمت إعادة فتح العميل المحتمل بنجاح.');
    }

    public function archive(
        ArchiveLeadRequest $request,
        string $lead,
        ArchiveLeadAction $action,
    ): JsonResponse {
        $record = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
            reason: ArchiveReason::from((string) $request->validated('archive_reason')),
            reasonDetail: $request->validated('archive_reason_detail'),
        );

        return $this->success($request, $record, 'تمت أرشفة العميل المحتمل بنجاح.');
    }

    public function restore(
        RestoreLeadRequest $request,
        string $lead,
        RestoreLeadAction $action,
    ): JsonResponse {
        $record = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
        );

        return $this->success($request, $record, 'تمت استعادة العميل المحتمل بنجاح.');
    }

    private function visibleLead(Request $request, string $leadId): Lead
    {
        $membership = $this->membership($request);
        $actor = $this->actor($request);
        $this->authorization->assertActor($actor, (string) $membership->tenant_id);
        $query = Lead::query()
            ->forTenant((string) $membership->tenant_id)
            ->visibleTo($actor)
            ->whereKey($leadId);

        if (
            $this->authorization->isAdministrator($actor)
            && $request->boolean('archived')
        ) {
            $query->archivedOnly();
        } else {
            $query->activeOnly();
        }

        $lead = $query->first();

        if ($lead === null) {
            throw new LeadNotFoundException();
        }

        return $lead;
    }

    private function membership(Request $request): TenantUser
    {
        $membership = $request->attributes->get('tenant_membership');

        if (! $membership instanceof TenantUser) {
            throw new \LogicException('Active Tenant membership was not resolved.');
        }

        $membership->loadMissing('tenant');

        return $membership;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new \LogicException('Authenticated CRM actor was not resolved.');
        }

        return $actor;
    }

    public function convert(
        ConvertLeadRequest $request,
        string $lead,
        ConvertLeadToCustomerAction $action,
    ): JsonResponse {
        $membership = $this->membership($request);
        $actor      = $this->actor($request);

        // Authorization: administrator, or sales/employee on own Lead
        if ($actor->role !== User::ROLE_ADMINISTRATOR) {
            $leadRecord = Lead::query()
                ->where('tenant_id', (string) $membership->tenant_id)
                ->whereKey($lead)
                ->first();

            if ($leadRecord === null || $leadRecord->assigned_to !== $actor->id) {
                throw new LeadActionNotAuthorizedException;
            }
        }

        $record = $action->execute(
            tenantId: (string) $membership->tenant_id,
            leadId: $lead,
            actorId: $actor->id,
            conversionIntent: (string) $request->input('conversion_intent'),
            existingCustomerId: $request->input('existing_customer_id'),
            customerData: array_filter([
                'type'     => $request->input('type'),
                'category' => $request->input('category'),
            ]),
        );

        return $this->success($request, $record, 'تم تحويل العميل المحتمل إلى عميل بنجاح.');
    }

    private function success(
        Request $request,
        Lead $lead,
        string $message,
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'data' => [
                'lead' => $this->assembler->lead($lead, $this->actor($request)),
            ],
        ]);
    }
}
