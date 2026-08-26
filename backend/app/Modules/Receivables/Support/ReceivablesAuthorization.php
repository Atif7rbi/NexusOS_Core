<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Support;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Receivables\Exceptions\ReceivablesAccessDenied;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use Illuminate\Support\Facades\DB;

final class ReceivablesAuthorization
{
    public function authorize(string $tenantId, User $actor): void
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        $membership = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $actor->id)->first();
        if (! $actor->isActive() || $tenant === null || $tenant->status !== Tenant::STATUS_ACTIVE
            || $membership === null || $membership->status !== TenantUser::STATUS_ACTIVE
            || ! $this->roleAllows($actor)) {
            throw new ReceivablesAccessDenied('Actor is not authorized for Receivables.');
        }
    }

    public function authorizeTransactional(string $tenantId, User $actor): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Transactional Receivables authorization requires an active transaction.');
        }

        $membership = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $actor->id)->lockForUpdate()->first();
        $lockedActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
        $tenant = DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
        if ($lockedActor === null || ! $lockedActor->isActive() || $tenant === null || $tenant->status !== Tenant::STATUS_ACTIVE
            || $membership === null || $membership->status !== TenantUser::STATUS_ACTIVE || ! $this->roleAllows($lockedActor)) {
            throw new ReceivablesAccessDenied('Actor is not authorized for Receivables.');
        }
    }

    private function roleAllows(User $actor): bool
    {
        return TenantAdministratorAuthority::allows($actor) || $actor->role === User::ROLE_ACCOUNTANT;
    }
}
