<?php

declare(strict_types=1);

namespace App\Modules\Entitlements\Services;

use App\Models\TenantLicense;
use Carbon\CarbonImmutable;

final class ResolveCurrentTenantLicense
{
    public function handle(string $tenantId): ?TenantLicense
    {
        $now = CarbonImmutable::now('UTC');

        return TenantLicense::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->whereHas('plan', static fn ($query) => $query->where('status', 'active'))
            ->where(function ($query) use ($now): void {
                $query
                    ->where(function ($query) use ($now): void {
                        $query
                            ->whereIn('status', [
                                TenantLicense::STATUS_TRIAL,
                                TenantLicense::STATUS_ACTIVE,
                            ])
                            ->where('starts_at', '<=', $now)
                            ->where('ends_at', '>=', $now);
                    })
                    ->orWhere(function ($query) use ($now): void {
                        $query
                            ->where('status', TenantLicense::STATUS_GRACE)
                            ->where('starts_at', '<=', $now)
                            ->where('grace_ends_at', '>=', $now);
                    });
            })
            ->first();
    }
}
