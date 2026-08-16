<?php

declare(strict_types=1);

namespace App\Modules\Entitlements\Services;

use App\Models\Module;
use App\Models\TenantLicense;
use App\Models\TenantModule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ResolveEffectiveTenantModules
{
    /** @return list<string> */
    public function handle(string $tenantId): array
    {
        $modules = Module::query()
            ->where('status', Module::STATUS_ACTIVE)
            ->orderBy('key')
            ->get(['id', 'key']);

        if ($modules->isEmpty()) {
            return [];
        }

        $overrides = TenantModule::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('module_id', $modules->pluck('id'))
            ->pluck('state', 'module_id');

        $licensedModuleIds = $this->currentLicensedModuleIds($tenantId);

        return $modules
            ->filter(function (Module $module) use ($overrides, $licensedModuleIds): bool {
                $override = $overrides->get($module->id);

                if ($override === TenantModule::STATE_DISABLED) {
                    return false;
                }

                if ($override === TenantModule::STATE_ENABLED) {
                    return true;
                }

                return $licensedModuleIds->contains((string) $module->id);
            })
            ->pluck('key')
            ->values()
            ->all();
    }

    public function isEntitled(string $tenantId, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->handle($tenantId), true);
    }

    /** @return Collection<int, string> */
    private function currentLicensedModuleIds(string $tenantId): Collection
    {
        $now = CarbonImmutable::now('UTC');

        $license = TenantLicense::query()
            ->with('plan.modules:id')
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

        if ($license === null) {
            return collect();
        }

        return $license->plan->modules
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id);
    }
}
