<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TenantUser;
use App\Modules\Entitlements\Services\ResolveEffectiveTenantModules;
use App\Modules\Entitlements\Support\CommercialModuleCatalog;
use App\Modules\Shared\Support\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class EnsureModuleEntitled
{
    public function __construct(
        private readonly ResolveEffectiveTenantModules $resolver,
        private readonly CommercialModuleCatalog $catalog,
        private readonly ApiErrorResponse $errors,
    ) {
    }

    public function handle(
        Request $request,
        Closure $next,
        string $moduleKey,
        ?string $resourceParameter = null,
        ?string $resourceTable = null,
    ): Response {
        $membership = $request->attributes->get('tenant_membership');

        if (! $membership instanceof TenantUser) {
            throw new LogicException('Active Tenant membership was not resolved.');
        }

        if (! $this->catalog->contains($moduleKey)) {
            throw new LogicException("Unknown commercial module [{$moduleKey}].");
        }

        if (($resourceParameter === null) !== ($resourceTable === null)) {
            throw new LogicException(
                'Resource parameter and table must be configured together.',
            );
        }

        if ($resourceParameter !== null && $resourceTable !== null) {
            $resourceId = $request->route($resourceParameter);

            $exists = is_string($resourceId)
                && DB::table($resourceTable)
                    ->where('tenant_id', $membership->tenant_id)
                    ->where('id', $resourceId)
                    ->exists();

            if (! $exists) {
                return $this->errors->make(
                    404,
                    'resource_not_found',
                    'المورد المطلوب غير متاح.',
                );
            }
        }

        if (! $this->resolver->isEntitled((string) $membership->tenant_id, $moduleKey)) {
            return $this->errors->make(
                403,
                'module_not_entitled',
                'هذه الوحدة غير متاحة ضمن باقة المنشأة الحالية.',
            );
        }

        return $next($request);
    }
}
