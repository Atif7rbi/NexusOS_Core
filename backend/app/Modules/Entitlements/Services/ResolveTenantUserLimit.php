<?php

declare(strict_types=1);

namespace App\Modules\Entitlements\Services;

final class ResolveTenantUserLimit
{
    public function __construct(
        private readonly ResolveCurrentTenantLicense $licenseResolver,
    ) {
    }

    /**
     * @return array{available: bool, limit: ?int}
     */
    public function handle(string $tenantId): array
    {
        $license = $this->licenseResolver->handle($tenantId);

        if ($license === null) {
            return [
                'available' => false,
                'limit' => null,
            ];
        }

        return [
            'available' => true,
            'limit' => $license->plan->users_limit === null
                ? null
                : (int) $license->plan->users_limit,
        ];
    }
}
