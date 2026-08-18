<?php

namespace App\Modules\Users\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Entitlements\Services\ResolveTenantUserLimit;
use App\Modules\Leads\Support\EnsureTenantUserCanBePaused;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use App\Modules\Shared\Services\RevokeUserTokens;
use App\Modules\Shared\Support\ApiErrorResponse;
use App\Modules\Users\Requests\StoreTenantUserRequest;
use App\Modules\Users\Requests\UpdateTenantUserRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenantUserController extends Controller
{
    public function __construct(
        private readonly SaudiMobileNormalizer $phoneNormalizer,
        private readonly EnsureTenantUserCanBePaused $ensureCanBePaused,
        private readonly RevokeUserTokens $revokeTokens,
        private readonly ResolveTenantUserLimit $resolveUserLimit,
        private readonly ApiErrorResponse $apiError,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdministrator($request);
        $membership = $this->currentMembership($request);
        $seatPolicy = $this->resolveUserLimit->handle((string) $membership->tenant_id);

        $query = $this->tenantSeatQuery((string) $membership->tenant_id)
            ->with([
                'user:id,name,email,phone,role,last_login_at,created_at,updated_at',
            ])
            ->latest();

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));

            $query->whereHas(
                'user',
                function (Builder $userQuery) use ($search): void {
                    $userQuery->where(function (Builder $nested) use ($search): void {
                        $nested
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                            ->orWhere('phone', 'ilike', "%{$search}%");
                    });
                }
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                (string) $request->query('status')
            );
        }

        if ($request->filled('role')) {
            $query->whereHas(
                'user',
                fn (Builder $userQuery) => $userQuery->where(
                    'role',
                    (string) $request->query('role')
                )
            );
        }

        return response()->json([
            'data' => [
                'users' => $query
                    ->paginate(
                        perPage: min(
                            max((int) $request->query('per_page', 20), 1),
                            100
                        )
                    ),
                'summary' => [
                    'total' => $this->tenantSeatQuery((string) $membership->tenant_id)->count(),

                    'active' => $this->tenantSeatQuery((string) $membership->tenant_id)
                        ->where('status', TenantUser::STATUS_ACTIVE)
                        ->count(),

                    'paused' => $this->tenantSeatQuery((string) $membership->tenant_id)
                        ->whereIn('status', [
                            TenantUser::STATUS_PAUSED,
                            TenantUser::STATUS_SUSPENDED,
                        ])
                        ->count(),

                    'limit' => $seatPolicy['available'] ? $seatPolicy['limit'] : null,
                ],
            ],
        ]);
    }

    public function store(
        StoreTenantUserRequest $request
    ): JsonResponse {
        $this->ensureAdministrator($request);

        $actorMembership = $this->currentMembership($request);
        $validated = $request->validated();

        $membership = DB::transaction(
            function () use (
                $validated,
                $actorMembership,
                $request
            ): TenantUser {
                DB::table('tenants')
                    ->where('id', $actorMembership->tenant_id)
                    ->lockForUpdate()
                    ->first();

                $seatPolicy = $this->resolveUserLimit->handle(
                    (string) $actorMembership->tenant_id
                );

                if (! $seatPolicy['available']) {
                    throw new HttpResponseException(
                        $this->apiError->make(
                            409,
                            'user_seat_limit_unavailable',
                            'تعذر تحديد الحد التجاري للمستخدمين لهذا الحساب.',
                        )
                    );
                }

                $currentCount = $this->tenantSeatQuery(
                    (string) $actorMembership->tenant_id
                )->count();

                if (
                    $seatPolicy['limit'] !== null
                    && $currentCount >= $seatPolicy['limit']
                ) {
                    throw new HttpResponseException(
                        $this->apiError->make(
                            409,
                            'user_seat_limit_reached',
                            'تم الوصول إلى الحد المتاح للمستخدمين في الباقة الحالية.',
                            [
                                'limit' => $seatPolicy['limit'],
                                'current' => $currentCount,
                            ],
                        )
                    );
                }

                $user = User::query()->create([
                    'name' => trim($validated['name']),
                    'email' => mb_strtolower(
                        trim($validated['email'])
                    ),
                    'phone' => $this->phoneNormalizer->normalizeNullable($validated['phone'] ?? null),
                    'role' => $validated['role'],
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                    'password' => $validated['password'],
                ]);

                return TenantUser::query()->create([
                    'tenant_id' => $actorMembership->tenant_id,
                    'user_id' => $user->id,
                    'status' => TenantUser::STATUS_ACTIVE,
                    'invited_at' => null,
                    'joined_at' => now(),
                    'removed_at' => null,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
            }
        );

        return response()->json([
            'message' => 'تم إنشاء المستخدم بنجاح.',
            'data' => [
                'user' => $membership->load([
                    'user:id,name,email,phone,role,last_login_at,created_at,updated_at',
                ]),
            ],
        ], 201);
    }

    public function show(
        Request $request,
        TenantUser $user
    ): JsonResponse {
        $membership = $this->currentMembership($request);

        $this->ensureSameTenant($membership, $user);
        $this->ensureAdministrator($request);
        $this->ensureTenantManagedUser($user);

        return response()->json([
            'data' => [
                'user' => $user->load([
                    'user:id,name,email,phone,role,last_login_at,created_at,updated_at',
                    'creator:id,name',
                    'updater:id,name',
                ]),
            ],
        ]);
    }

    public function update(
        UpdateTenantUserRequest $request,
        TenantUser $user
    ): JsonResponse {
        $actorMembership = $this->currentMembership($request);

        $this->ensureSameTenant($actorMembership, $user);
        $this->ensureAdministrator($request);
        $this->ensureTenantManagedUser($user);
        $this->ensureProtectedOwnerMutation($request->user(), $user);

        $validated = $request->validated();

        if ($user->user_id === $request->user()->id) {
            if (
                array_key_exists('role', $validated) ||
                array_key_exists('status', $validated)
            ) {
                throw ValidationException::withMessages([
                    'user' => [
                        'لا يمكنك تغيير دورك أو حالة عضويتك من شاشة إدارة المستخدمين.',
                    ],
                ]);
            }
        }

        DB::transaction(
            function () use ($validated, $request, $user): void {
                $membership = TenantUser::query()
                    ->with('user')
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureTenantManagedUser($membership);
                $this->ensureProtectedOwnerMutation(
                    $request->user(),
                    $membership,
                );

                if (
                    ($validated['status'] ?? null) === TenantUser::STATUS_PAUSED
                    && $membership->status !== TenantUser::STATUS_PAUSED
                ) {
                    $this->ensureCanBePaused->handle($membership);
                }

                $identityData = [];

                foreach ([
                    'name',
                    'email',
                    'phone',
                    'role',
                    'password',
                ] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $identityData[$field] = $validated[$field];
                    }
                }

                if (isset($identityData['email'])) {
                    $identityData['email'] = mb_strtolower(
                        trim($identityData['email'])
                    );
                }
                if (array_key_exists('phone', $identityData)) {
                    $identityData['phone'] = $this->phoneNormalizer
                        ->normalizeNullable($identityData['phone']);
                }

                $roleChanged = array_key_exists('role', $identityData)
                    && $identityData['role'] !== $membership->user->role;
                $passwordChanged = array_key_exists('password', $identityData)
                    && $identityData['password'] !== null;

                if ($identityData !== []) {
                    $membership->user->update($identityData);
                }

                if (array_key_exists('status', $validated)) {
                    $membership->status = $validated['status'];

                    $membership->user->forceFill([
                        'status' => $validated['status']
                            === TenantUser::STATUS_SUSPENDED
                                ? User::STATUS_SUSPENDED
                                : User::STATUS_ACTIVE,
                    ])->save();
                }

                $accessRevoked = array_key_exists('status', $validated)
                    && $validated['status'] !== TenantUser::STATUS_ACTIVE;

                if ($roleChanged || $passwordChanged || $accessRevoked) {
                    $this->revokeTokens->all($membership->user);
                }

                $membership->updated_by = $request->user()->id;
                $membership->save();
            }
        );

        return response()->json([
            'message' => 'تم تحديث المستخدم بنجاح.',
            'data' => [
                'user' => $user->fresh()->load([
                    'user:id,name,email,phone,role,last_login_at,created_at,updated_at',
                ]),
            ],
        ]);
    }

    public function destroy(
        Request $request,
        TenantUser $user
    ): JsonResponse {
        $actorMembership = $this->currentMembership($request);

        $this->ensureSameTenant($actorMembership, $user);
        $this->ensureAdministrator($request);
        $this->ensureTenantManagedUser($user);

        if ($user->user_id === $request->user()->id) {
            throw ValidationException::withMessages([
                'user' => [
                    'لا يمكنك إزالة عضويتك من حسابك الحالي.',
                ],
            ]);
        }

        $this->ensureProtectedOwnerMutation($request->user(), $user);

        DB::transaction(function () use ($request, $user): void {
            $membership = TenantUser::query()
                ->with('user')
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureTenantManagedUser($membership);
            $this->ensureProtectedOwnerMutation(
                $request->user(),
                $membership,
            );

            $membership->forceFill([
                'status' => TenantUser::STATUS_REMOVED,
                'removed_at' => now(),
                'updated_by' => $request->user()->id,
            ])->save();

            $membership->user->forceFill([
                'status' => User::STATUS_ARCHIVED,
            ])->save();

            $this->revokeTokens->all($membership->user);
        });

        return response()->json([
            'message' => 'تم حذف عضوية المستخدم بنجاح.',
        ]);
    }

    private function ensureAdministrator(
        Request $request
    ): void {
        abort_unless(
            $request->user() instanceof User
                && TenantAdministratorAuthority::allows($request->user()),
            403,
            'ليس لديك صلاحية لإدارة المستخدمين.'
        );
    }

    private function currentMembership(
        Request $request
    ): TenantUser {
        $membership = TenantUser::query()
            ->where('user_id', $request->user()->id)
            ->where('status', TenantUser::STATUS_ACTIVE)
            ->first();

        if (! $membership) {
            throw ValidationException::withMessages([
                'membership' => [
                    'لا توجد عضوية شركة نشطة لهذا الحساب.',
                ],
            ]);
        }

        return $membership;
    }

    private function ensureSameTenant(
        TenantUser $actorMembership,
        TenantUser $targetMembership
    ): void {
        abort_unless(
            $actorMembership->tenant_id
                === $targetMembership->tenant_id,
            404
        );
    }

    private function ensureTenantManagedUser(TenantUser $membership): void
    {
        $membership->loadMissing('user:id,role');

        abort_if(
            $membership->user->isSystemOwner(),
            404,
        );
    }

    private function ensureProtectedOwnerMutation(
        User $actor,
        TenantUser $targetMembership,
    ): void {
        $targetMembership->loadMissing('user:id,role');

        abort_if(
            $targetMembership->user->isSystemOwner()
                && $targetMembership->user_id !== $actor->id,
            403,
            'لا يمكن إدارة مالك النظام من خلال شاشة إدارة المستخدمين.'
        );
    }

    private function tenantSeatQuery(string $tenantId): Builder
    {
        return TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', TenantUser::STATUS_REMOVED)
            ->whereHas(
                'user',
                static fn (Builder $query) => $query->where(
                    'role',
                    '!=',
                    User::ROLE_SYSTEM_OWNER,
                )
            );
    }
}
