<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Shared\Exceptions\TenantAccessDeniedException;
use App\Modules\Shared\Exceptions\TenantMembershipInvitedException;
use App\Modules\Shared\Exceptions\TenantMembershipMissingException;
use App\Modules\Shared\Exceptions\TenantMembershipPausedException;
use App\Modules\Shared\Exceptions\TenantMembershipRemovedException;
use App\Modules\Shared\Exceptions\TenantMembershipSuspendedException;
use App\Modules\Shared\Exceptions\UserAccessDeniedException;
use App\Modules\Shared\Services\ResolveActiveMembership;
use App\Modules\Shared\Support\LifecycleAccessDenialResponder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveTenantMembership
{
    public function __construct(
        private readonly ResolveActiveMembership $resolver,
        private readonly LifecycleAccessDenialResponder $denials,
    ) {
    }

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        try {
            $membership = $this->resolver->handle($user);

            $request->attributes->set(
                'tenant_membership',
                $membership,
            );

            return $next($request);
        } catch (
            TenantMembershipMissingException
            | TenantMembershipInvitedException
            | TenantMembershipPausedException
            | TenantMembershipSuspendedException
            | TenantMembershipRemovedException
            | UserAccessDeniedException
            | TenantAccessDeniedException $exception
        ) {
            return $this->denials->respond($exception);
        }
    }
}
