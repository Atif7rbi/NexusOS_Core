<?php

declare(strict_types=1);

namespace App\Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Actions\AddLeadNoteAction;
use App\Modules\Leads\Exceptions\LeadNotFoundException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Requests\StoreLeadNoteRequest;
use App\Modules\Leads\Support\LeadAuthorization;
use App\Modules\Leads\Support\LeadResponseAssembler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeadActivityController extends Controller
{
    public function __construct(
        private readonly LeadAuthorization $authorization,
        private readonly LeadResponseAssembler $assembler,
    ) {
    }

    public function index(Request $request, string $lead): JsonResponse
    {
        $record = $this->visibleLead($request, $lead);
        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $activities = LeadActivity::query()
            ->with('creator:id,name')
            ->where('tenant_id', $record->tenant_id)
            ->where('lead_id', $record->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'activities' => collect($activities->items())
                    ->map(fn (LeadActivity $activity): array => $this->assembler->activity($activity))
                    ->values()
                    ->all(),
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'last_page' => $activities->lastPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                ],
            ],
        ]);
    }

    public function store(
        StoreLeadNoteRequest $request,
        string $lead,
        AddLeadNoteAction $action,
    ): JsonResponse {
        $activity = $action->execute(
            tenantId: (string) $this->membership($request)->tenant_id,
            leadId: $lead,
            actor: $this->actor($request),
            body: (string) $request->validated('body'),
        );

        return response()->json([
            'message' => 'تمت إضافة الملاحظة بنجاح.',
            'data' => [
                'activity' => $this->assembler->activity($activity),
            ],
        ], 201);
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
}
