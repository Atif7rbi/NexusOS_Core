<?php

declare(strict_types=1);

namespace App\Modules\Entitlements\Services;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ProvisionPilotEntitlements
{
    private const PILOT_FULL_USERS_LIMIT = 5;

    public function __construct(
        private readonly CommercialModuleCatalog $catalog,
        private readonly ValidatePlanModuleDependencies $dependencies,
        private readonly ResolveEffectiveTenantModules $resolver,
    ) {
    }

    /**
     * @return array{tenant: Tenant, plan: Plan, license: TenantLicense, effective_modules: list<string>, created: bool}
     */
    public function handle(
        string $tenantReference,
        string $planKey,
        string $status,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?CarbonImmutable $graceEndsAt,
        ?int $usersLimitOverride = null,
    ): array {
        $planDefinition = $this->planDefinition($planKey);
        $this->assertPeriod($status, $startsAt, $endsAt, $graceEndsAt);

        if ($usersLimitOverride !== null && $usersLimitOverride <= 0) {
            throw new DomainException('users_limit_override must be a positive integer when supplied.');
        }

        return DB::transaction(function () use (
            $tenantReference,
            $planKey,
            $planDefinition,
            $status,
            $startsAt,
            $endsAt,
            $graceEndsAt,
            $usersLimitOverride,
        ): array {
            $tenant = Tenant::query()
                ->where(function ($query) use ($tenantReference): void {
                    $query
                        ->whereKey($tenantReference)
                        ->orWhere('slug', $tenantReference);
                })
                ->lockForUpdate()
                ->first();

            if ($tenant === null) {
                throw new DomainException(
                    "Tenant [{$tenantReference}] was not found.",
                );
            }

            $modules = $this->ensureCatalog();
            $moduleKeys = $this->catalog->keys();
            $this->dependencies->handle($moduleKeys);
            $plan = $this->ensureApprovedPlan($planKey, $planDefinition, $modules);

            $exact = TenantLicense::query()
                ->where('tenant_id', $tenant->id)
                ->where('plan_id', $plan->id)
                ->where('status', $status)
                ->where('starts_at', $startsAt)
                ->where('ends_at', $endsAt)
                ->when(
                    $graceEndsAt === null,
                    static fn ($query) => $query->whereNull('grace_ends_at'),
                    static fn ($query) => $query->where('grace_ends_at', $graceEndsAt),
                )
                ->when(
                    $usersLimitOverride === null,
                    static fn ($query) => $query->whereNull('users_limit_override'),
                    static fn ($query) => $query->where('users_limit_override', $usersLimitOverride),
                )
                ->lockForUpdate()
                ->first();

            if ($exact !== null) {
                return $this->verifiedResult($tenant, $plan, $exact, false);
            }

            $effectiveEnd = $status === TenantLicense::STATUS_GRACE
                ? $graceEndsAt
                : $endsAt;

            $conflicting = TenantLicense::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', TenantLicense::ENTITLED_STATUSES)
                ->where('starts_at', '<=', $effectiveEnd)
                ->whereRaw(
                    "CASE WHEN status = 'grace' THEN grace_ends_at ELSE ends_at END >= ?",
                    [$startsAt],
                )
                ->lockForUpdate()
                ->first();

            if ($conflicting !== null) {
                throw new DomainException(sprintf(
                    'Tenant [%s] already has an overlapping entitled license [%s].',
                    $tenant->id,
                    $conflicting->id,
                ));
            }

            $license = TenantLicense::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => $status,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'grace_ends_at' => $graceEndsAt,
                'users_limit_override' => $usersLimitOverride,
            ]);

            return $this->verifiedResult($tenant, $plan, $license, true);
        });
    }

    /** @return array{name_ar: string, name_en: string, users_limit: ?int} */
    private function planDefinition(string $planKey): array
    {
        return match ($planKey) {
            CommercialModuleCatalog::PILOT_FULL_PLAN => [
                'name_ar' => 'الباقة التجريبية الكاملة',
                'name_en' => 'Pilot Full',
                'users_limit' => self::PILOT_FULL_USERS_LIMIT,
            ],
            CommercialModuleCatalog::DEMO_FULL_PLAN => [
                'name_ar' => 'باقة العرض الكاملة',
                'name_en' => 'Demo Full',
                'users_limit' => null,
            ],
            default => throw new DomainException("Unsupported provisioning plan [{$planKey}]."),
        };
    }

    /** @return array<string, Module> */
    private function ensureCatalog(): array
    {
        $modules = [];

        foreach ($this->catalog->definitions() as $key => $definition) {
            $module = Module::query()->where('key', $key)->lockForUpdate()->first();

            if ($module === null) {
                $module = Module::query()->create([
                    'key' => $key,
                    'name_ar' => $definition['name_ar'],
                    'name_en' => $definition['name_en'],
                    'status' => Module::STATUS_ACTIVE,
                ]);
            } elseif (
                $module->name_ar !== $definition['name_ar']
                || $module->name_en !== $definition['name_en']
                || $module->status !== Module::STATUS_ACTIVE
            ) {
                throw new DomainException(
                    "Commercial module catalog entry [{$key}] conflicts with the approved definition.",
                );
            }

            $modules[$key] = $module;
        }

        return $modules;
    }

    /**
     * @param array{name_ar: string, name_en: string, users_limit: ?int} $definition
     * @param array<string, Module> $modules
     */
    private function ensureApprovedPlan(
        string $planKey,
        array $definition,
        array $modules,
    ): Plan {
        $plan = Plan::query()->where('key', $planKey)->lockForUpdate()->first();

        if ($plan === null) {
            $plan = Plan::query()->create([
                'key' => $planKey,
                'name_ar' => $definition['name_ar'],
                'name_en' => $definition['name_en'],
                'status' => Plan::STATUS_ACTIVE,
                'users_limit' => $definition['users_limit'],
            ]);
            $plan->modules()->attach(array_map(
                static fn (Module $module): string => (string) $module->id,
                $modules,
            ));

            return $plan;
        }

        if (
            $plan->name_ar !== $definition['name_ar']
            || $plan->name_en !== $definition['name_en']
            || $plan->status !== Plan::STATUS_ACTIVE
            || $plan->users_limit !== $definition['users_limit']
        ) {
            throw new DomainException(
                "The existing [{$planKey}] plan conflicts with the approved definition.",
            );
        }

        $actual = $plan->modules()->pluck('key')->sort()->values()->all();
        $expected = collect(array_keys($modules))->sort()->values()->all();

        if ($actual !== $expected) {
            throw new DomainException(
                "The existing [{$planKey}] plan module composition conflicts with the approved definition.",
            );
        }

        return $plan;
    }

    private function assertPeriod(
        string $status,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?CarbonImmutable $graceEndsAt,
    ): void {
        if (! in_array($status, TenantLicense::ENTITLED_STATUSES, true)) {
            throw new DomainException(
                'Provisioning status must be trial, active, or grace.',
            );
        }

        if (! $startsAt->lessThan($endsAt)) {
            throw new DomainException('starts_at must be earlier than ends_at.');
        }

        if ($status === TenantLicense::STATUS_GRACE) {
            if ($graceEndsAt === null || ! $graceEndsAt->greaterThan($endsAt)) {
                throw new DomainException(
                    'grace_ends_at is required for grace and must be later than ends_at.',
                );
            }
        } elseif ($graceEndsAt !== null) {
            throw new DomainException(
                'grace_ends_at must be omitted unless status is grace.',
            );
        }

        $now = CarbonImmutable::now('UTC');
        $effectiveEnd = $status === TenantLicense::STATUS_GRACE
            ? $graceEndsAt
            : $endsAt;

        if ($startsAt->greaterThan($now) || $effectiveEnd?->lessThan($now)) {
            throw new DomainException(
                'The supplied license period is not current.',
            );
        }
    }

    /**
     * @return array{tenant: Tenant, plan: Plan, license: TenantLicense, effective_modules: list<string>, created: bool}
     */
    private function verifiedResult(
        Tenant $tenant,
        Plan $plan,
        TenantLicense $license,
        bool $created,
    ): array {
        $effectiveModules = $this->resolver->handle((string) $tenant->id);
        $expected = $this->catalog->keys();
        sort($effectiveModules);
        sort($expected);

        if ($effectiveModules !== $expected) {
            throw new DomainException(
                "Provisioning verification failed: effective modules do not match [{$plan->key}].",
            );
        }

        return [
            'tenant' => $tenant,
            'plan' => $plan,
            'license' => $license,
            'effective_modules' => $effectiveModules,
            'created' => $created,
        ];
    }
}
