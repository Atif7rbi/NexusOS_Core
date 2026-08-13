<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class ProjectAuthorization
{
    public function assertCanCreate(
        User $actor,
        string $tenantId,
        ?int $projectManagerId,
    ): void {
        if (TenantAdministratorAuthority::allows($actor)) {
            $this->assertEligibleProjectManager($tenantId, $projectManagerId);

            return;
        }

        if ($actor->role !== User::ROLE_PROJECT_MANAGER) {
            $this->deny();
        }

        if ($projectManagerId !== null && $projectManagerId !== $actor->id) {
            $this->deny();
        }

        $this->assertEligibleProjectManager($tenantId, $projectManagerId);
    }

    public function assertCanUpdate(
        User $actor,
        string $tenantId,
        Project $project,
        bool $changesProjectManager,
        ?int $projectManagerId,
    ): void {
        if (TenantAdministratorAuthority::allows($actor)) {
            if ($changesProjectManager) {
                $this->assertEligibleProjectManager(
                    $tenantId,
                    $projectManagerId,
                );
            }

            return;
        }

        if (! $this->isOwnProject($actor, $project)) {
            $this->deny();
        }

        if ($changesProjectManager) {
            $this->deny();
        }
    }

    public function assertCanRunOperationalLifecycle(
        User $actor,
        Project $project,
    ): void {
        if (
            TenantAdministratorAuthority::allows($actor)
            || $this->isOwnProject($actor, $project)
        ) {
            return;
        }

        $this->deny();
    }

    public function assertCanCancel(User $actor): void
    {
        if (! TenantAdministratorAuthority::allows($actor)) {
            $this->deny();
        }
    }

    public function assertCanArchiveOrRestore(User $actor): void
    {
        if (! TenantAdministratorAuthority::allows($actor)) {
            $this->deny();
        }
    }

    public function canManageProject(User $actor, Project $project): bool
    {
        return TenantAdministratorAuthority::allows($actor)
            || $this->isOwnProject($actor, $project);
    }

    private function isOwnProject(User $actor, Project $project): bool
    {
        return $actor->role === User::ROLE_PROJECT_MANAGER
            && (int) $project->project_manager_id === (int) $actor->id;
    }

    private function assertEligibleProjectManager(
        string $tenantId,
        ?int $projectManagerId,
    ): void {
        if ($projectManagerId === null) {
            return;
        }

        $isEligible = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $projectManagerId)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->whereHas(
                'user',
                fn ($query) => $query->where(
                    'role',
                    User::ROLE_PROJECT_MANAGER,
                ),
            )
            ->exists();

        if (! $isEligible) {
            throw ValidationException::withMessages([
                'project_manager_id' => [
                    'يجب أن يكون مدير المشروع عضوًا نشطًا بدور مدير مشروع في هذه الشركة.',
                ],
            ]);
        }
    }

    /** @throws AuthorizationException */
    private function deny(): never
    {
        throw new AuthorizationException(
            'ليس لديك صلاحية لتنفيذ هذا الإجراء على المشروع.',
        );
    }
}
