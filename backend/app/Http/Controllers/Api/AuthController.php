<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Models\TenantUser;
use App\Modules\Entitlements\Services\ResolveEffectiveTenantModules;
use App\Modules\Shared\Exceptions\TenantAccessDeniedException;
use App\Modules\Shared\Exceptions\TenantMembershipInvitedException;
use App\Modules\Shared\Exceptions\TenantMembershipMissingException;
use App\Modules\Shared\Exceptions\TenantMembershipPausedException;
use App\Modules\Shared\Exceptions\TenantMembershipRemovedException;
use App\Modules\Shared\Exceptions\TenantMembershipSuspendedException;
use App\Modules\Shared\Exceptions\UserAccessDeniedException;
use App\Modules\Shared\Services\ResolveActiveMembership;
use App\Modules\Shared\Support\LifecycleAccessDenialResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly ResolveActiveMembership $resolver,
        private readonly ResolveEffectiveTenantModules $entitlements,
        private readonly LifecycleAccessDenialResponder $denials,
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->string('email'))
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        try {
            $membership = $this->resolver->handle($user);
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

        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        $token = $user->createToken('nexusos')->plainTextToken;

        return response()->json([
            'message' => 'تم تسجيل الدخول بنجاح.',
            'data' => [
                'token' => $token,
                'user' => $user->fresh(),
                'effective_modules' => $this->entitlements->handle(
                    (string) $membership->tenant_id,
                ),
            ],
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        $membership = $request->attributes->get('tenant_membership');

        if (! $membership instanceof TenantUser) {
            throw new \LogicException('Active Tenant membership was not resolved.');
        }

        return response()->json([
            'data' => [
                'user' => $request->user(),
                'effective_modules' => $this->entitlements->handle(
                    (string) $membership->tenant_id,
                ),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()
            ->currentAccessToken()
            ?->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج بنجاح.',
        ]);
    }

}
