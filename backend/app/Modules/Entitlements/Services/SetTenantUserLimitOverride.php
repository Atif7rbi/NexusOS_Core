<?php

declare(strict_types=1);

namespace App\Modules\Entitlements\Services;

use App\Models\TenantLicense;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SetTenantUserLimitOverride
{
    public function __construct(
        private readonly ResolveCurrentTenantLicense $licenseResolver,
    ) {
    }

    public function handle(string $tenantId, ?int $usersLimitOverride): TenantLicense
    {
        if ($usersLimitOverride !== null && $usersLimitOverride <= 0) {
            throw new DomainException('users_limit_override must be a positive integer when supplied.');
        }

        return DB::transaction(function () use ($tenantId, $usersLimitOverride): TenantLicense {
            $current = $this->licenseResolver->handle($tenantId);

            if ($current === null) {
                throw new DomainException("Tenant [{$tenantId}] has no current entitled license.");
            }

            $license = TenantLicense::query()
                ->whereKey($current->id)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $license->forceFill([
                'users_limit_override' => $usersLimitOverride,
            ])->save();

            return $license->fresh('plan');
        });
    }
}
