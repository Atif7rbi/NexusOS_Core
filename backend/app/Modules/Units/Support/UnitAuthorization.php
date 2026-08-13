<?php

declare(strict_types=1);

namespace App\Modules\Units\Support;

use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use App\Modules\Units\Models\Unit;
use Illuminate\Auth\Access\AuthorizationException;

final class UnitAuthorization
{
    public function assertCanCreate(User $actor, Project $project): void
    {
        if ($this->canManageProject($actor, $project)) {
            return;
        }

        $this->deny();
    }

    public function assertCanUpdate(
        User $actor,
        Unit $unit,
        ?Project $targetProject = null,
    ): void {
        $currentProject = $unit->project;

        if ($currentProject === null || ! $this->canManageProject($actor, $currentProject)) {
            $this->deny();
        }

        if (
            $targetProject !== null
            && ! $this->canManageProject($actor, $targetProject)
        ) {
            $this->deny();
        }
    }

    public function assertCanArchiveOrRestore(User $actor, Unit $unit): void
    {
        $this->assertCanUpdate($actor, $unit);
    }

    private function canManageProject(User $actor, Project $project): bool
    {
        return TenantAdministratorAuthority::allows($actor)
            || (
                $actor->role === User::ROLE_PROJECT_MANAGER
                && (int) $project->project_manager_id === (int) $actor->id
            );
    }

    /** @throws AuthorizationException */
    private function deny(): never
    {
        throw new AuthorizationException(
            'ليس لديك صلاحية لتنفيذ هذا الإجراء على الوحدة.',
        );
    }
}
