<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Exceptions\AccountingAccessDenied;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use Illuminate\Support\Facades\DB;

final class AccountingAuthorization
{
    public function authorize(string $tenantId, User $actor, string $capability): void
    {
        if (! $actor->isActive()) {
            throw new AccountingAccessDenied('Accounting requires an active actor.');
        }
        $membership = DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $actor->id)->first();
        if ($membership === null || $membership->status !== TenantUser::STATUS_ACTIVE) {
            throw new AccountingAccessDenied('Accounting requires active same-tenant membership.');
        }
        $administratorOnly = ['activate', 'reopen_period'];
        $allowed = in_array($capability, $administratorOnly, true)
            ? TenantAdministratorAuthority::allows($actor)
            : TenantAdministratorAuthority::allows($actor) || $actor->role === User::ROLE_ACCOUNTANT;
        if (! $allowed) {
            throw new AccountingAccessDenied('Actor is not authorized for this Accounting capability.');
        }
    }
}
