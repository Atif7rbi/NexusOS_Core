<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Middleware;

use App\Models\TenantUser;
use App\Modules\Collections\Support\CollectionScheduleExceptionResponder;
use App\Modules\Contracts\Models\Contract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveCollectionScheduleContract
{
    public function __construct(
        private readonly CollectionScheduleExceptionResponder $exceptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $membership = $request->attributes->get('tenant_membership');

        if (! $membership instanceof TenantUser) {
            throw new \LogicException('Active Tenant membership was not resolved.');
        }

        $contract = Contract::query()
            ->where('tenant_id', $membership->tenant_id)
            ->whereKey((string) $request->route('contract'))
            ->first();

        if ($contract === null) {
            return $this->exceptions->error(
                404,
                'contract_not_found',
                'The Contract does not exist or is not available in the active Tenant.',
            );
        }

        $request->attributes->set('collection_schedule_contract', $contract);

        return $next($request);
    }
}
