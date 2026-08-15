<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantLicense;
use App\Modules\Entitlements\Services\ProvisionPilotEntitlements;
use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            app(ProvisionPilotEntitlements::class)->handle(
                tenantReference: (string) $tenant->id,
                planKey: CommercialModuleCatalog::PILOT_FULL_PLAN,
                status: TenantLicense::STATUS_ACTIVE,
                startsAt: CarbonImmutable::now('UTC')->subDay(),
                endsAt: CarbonImmutable::now('UTC')->addYear(),
                graceEndsAt: null,
            );
        });
    }

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => Tenant::STATUS_ACTIVE,
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
            'currency' => 'SAR',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => Tenant::STATUS_ACTIVE,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'status' => Tenant::STATUS_PAUSED,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => Tenant::STATUS_SUSPENDED,
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn () => [
            'status' => Tenant::STATUS_REMOVED,
        ]);
    }
}
