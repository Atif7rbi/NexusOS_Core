<?php

namespace App\Modules\Projects\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Actions\ArchiveProjectAction;
use App\Modules\Projects\Actions\ActivateProjectAction;
use App\Modules\Projects\Actions\CancelProjectAction;
use App\Modules\Projects\Actions\CompleteProjectAction;
use App\Modules\Projects\Actions\CreateProjectAction;
use App\Modules\Projects\Actions\RevertProjectToDraftAction;
use App\Modules\Projects\Actions\RestoreProjectAction;
use App\Modules\Projects\Actions\UpdateProjectAction;
use App\Modules\Projects\Exceptions\ArchivedProjectCannotBeUpdatedException;
use App\Modules\Projects\Exceptions\FinalProjectCannotBeUpdatedException;
use App\Modules\Projects\Exceptions\InvalidProjectStatusTransitionException;
use App\Modules\Projects\Exceptions\ProjectAlreadyActiveException;
use App\Modules\Projects\Exceptions\ProjectAlreadyArchivedException;
use App\Modules\Projects\Exceptions\ProjectHasOperationalFootprintException;
use App\Modules\Projects\Exceptions\ProjectHasOutstandingCommitmentsException;
use App\Modules\Projects\Exceptions\ProjectNotArchivedException;
use App\Modules\Projects\Exceptions\ProjectNotOperationallyCompleteException;
use App\Modules\Projects\Exceptions\ProjectNotOperationallyReadyException;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Requests\StoreProjectRequest;
use App\Modules\Projects\Requests\UpdateProjectRequest;
use App\Modules\Projects\Support\ProjectAuthorization;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProjectController extends Controller
{
    public function __construct(
        private readonly ResolveActiveMembership $resolveActiveMembership,
        private readonly ProjectAuthorization $authorization,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );

        $filters = $request->validate([
            'status' => [
                'nullable',
                'string',
                Rule::in(array_map(
                    fn (ProjectStatus $status) => $status->value,
                    ProjectStatus::cases(),
                )),
            ],
        ]);

        $projects = Project::query()
            ->with('projectManager:id,name,email')
            ->where('tenant_id', $membership->tenant_id)
            ->when(
                $request->filled('search'),
                function ($query) use ($request): void {
                    $search = trim((string) $request->string('search'));

                    $query->where(function ($query) use ($search): void {
                        $query
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere(
                                'project_number',
                                'ilike',
                                "%{$search}%"
                            )
                            ->orWhere('city', 'ilike', "%{$search}%");
                    });
                }
            )
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where(
                    'status',
                    $filters['status']
                )
            )
            ->latest()
            ->orderByDesc('id')
            ->paginate(
                perPage: min(
                    max((int) $request->integer('per_page', 15), 1),
                    100,
                )
            )
            ->withQueryString();

        return response()->json([
            'data' => $projects,
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        CreateProjectAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );
        $data = $request->validated();

        $this->authorization->assertCanCreate(
            $request->user(),
            (string) $membership->tenant_id,
            isset($data['project_manager_id'])
                ? (int) $data['project_manager_id']
                : null,
        );

        $project = $action->execute(
            tenantId: (string) $membership->tenant_id,
            actorId: $request->user()->id,
            data: $data,
        )->load('projectManager:id,name,email');

        return response()->json([
            'message' => 'تم إنشاء المشروع بنجاح.',
            'data' => [
                'project' => $project,
            ],
        ], 201);
    }

    public function show(Request $request, string $project): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );

        $projectRecord = Project::query()
            ->where('tenant_id', $membership->tenant_id)
            ->whereKey($project)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'project' => $projectRecord->load(
                    'projectManager:id,name,email'
                ),
            ],
        ]);
    }

    public function update(
        UpdateProjectRequest $request,
        string $project,
        UpdateProjectAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );
        $data = $request->validated();
        $projectRecord = $this->findProject(
            (string) $membership->tenant_id,
            $project,
        );

        $this->authorization->assertCanUpdate(
            $request->user(),
            (string) $membership->tenant_id,
            $projectRecord,
            array_key_exists('project_manager_id', $data),
            isset($data['project_manager_id'])
                ? (int) $data['project_manager_id']
                : null,
        );

        try {
            $project = $action->execute(
                tenantId: (string) $membership->tenant_id,
                projectId: $project,
                actorId: $request->user()->id,
                data: $data,
            )->load('projectManager:id,name,email');
        } catch (
            ArchivedProjectCannotBeUpdatedException
            | FinalProjectCannotBeUpdatedException $exception
        ) {
            $this->throwProjectValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تم تحديث المشروع بنجاح.',
            'data' => [
                'project' => $project,
            ],
        ]);
    }

    public function archive(
        Request $request,
        string $project,
        ArchiveProjectAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );
        $this->authorization->assertCanArchiveOrRestore(
            $request->user(),
        );

        try {
            $projectRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                projectId: $project,
                actorId: $request->user()->id,
            );
        } catch (
            ProjectAlreadyArchivedException
            | InvalidProjectStatusTransitionException $exception
        ) {
            $this->throwProjectValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تمت أرشفة المشروع بنجاح.',
            'data' => [
                'project' => $projectRecord,
            ],
        ]);
    }

    public function restore(
        Request $request,
        string $project,
        RestoreProjectAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );
        $this->authorization->assertCanArchiveOrRestore(
            $request->user(),
        );

        try {
            $projectRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                projectId: $project,
                actorId: $request->user()->id,
            );
        } catch (ProjectNotArchivedException $exception) {
            $this->throwProjectValidationException(
                $exception->getMessage()
            );
        }

        return response()->json([
            'message' => 'تمت استعادة المشروع بنجاح.',
            'data' => [
                'project' => $projectRecord,
            ],
        ]);
    }

    public function activate(
        Request $request,
        string $project,
        ActivateProjectAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );
        $this->authorization->assertCanRunOperationalLifecycle(
            $request->user(),
            $this->findProject((string) $membership->tenant_id, $project),
        );

        try {
            $projectRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                projectId: $project,
                actorId: $request->user()->id,
            );
        } catch (
            ProjectAlreadyActiveException
            | InvalidProjectStatusTransitionException
            | ProjectNotOperationallyReadyException $exception
        ) {
            $this->throwProjectValidationException(
                $exception->getMessage()
            );
        }

        return $this->projectCommandResponse(
            'تم تفعيل المشروع بنجاح.',
            $projectRecord,
        );
    }

    public function revertToDraft(
        Request $request,
        string $project,
        RevertProjectToDraftAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );
        $this->authorization->assertCanRunOperationalLifecycle(
            $request->user(),
            $this->findProject((string) $membership->tenant_id, $project),
        );

        try {
            $projectRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                projectId: $project,
                actorId: $request->user()->id,
            );
        } catch (
            InvalidProjectStatusTransitionException
            | ProjectHasOperationalFootprintException $exception
        ) {
            $this->throwProjectValidationException(
                $exception->getMessage()
            );
        }

        return $this->projectCommandResponse(
            'تمت إعادة المشروع إلى مسودة بنجاح.',
            $projectRecord,
        );
    }

    public function complete(
        Request $request,
        string $project,
        CompleteProjectAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );
        $this->authorization->assertCanRunOperationalLifecycle(
            $request->user(),
            $this->findProject((string) $membership->tenant_id, $project),
        );

        try {
            $projectRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                projectId: $project,
                actorId: $request->user()->id,
            );
        } catch (ProjectNotOperationallyCompleteException $exception) {
            $this->throwProjectValidationException(
                $exception->getMessage()
            );
        }

        return $this->projectCommandResponse(
            'تم إكمال المشروع بنجاح.',
            $projectRecord,
        );
    }

    public function cancel(
        Request $request,
        string $project,
        CancelProjectAction $action,
    ): JsonResponse {
        $membership = $this->resolveActiveMembership->handle(
            $request->user(),
        );
        $this->authorization->assertCanCancel($request->user());

        try {
            $projectRecord = $action->execute(
                tenantId: (string) $membership->tenant_id,
                projectId: $project,
                actorId: $request->user()->id,
            );
        } catch (
            InvalidProjectStatusTransitionException
            | ProjectHasOutstandingCommitmentsException $exception
        ) {
            $this->throwProjectValidationException(
                $exception->getMessage()
            );
        }

        return $this->projectCommandResponse(
            'تم إلغاء المشروع بنجاح.',
            $projectRecord,
        );
    }

    /**
     * @throws ValidationException
     */
    private function throwProjectValidationException(
        string $message,
    ): never {
        throw ValidationException::withMessages([
            'project' => [$message],
        ]);
    }

    private function projectCommandResponse(
        string $message,
        Project $project,
    ): JsonResponse {
        return response()->json([
            'message' => $message,
            'data' => [
                'project' => $project,
            ],
        ]);
    }

    private function findProject(string $tenantId, string $projectId): Project
    {
        return Project::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($projectId)
            ->firstOrFail();
    }
}
